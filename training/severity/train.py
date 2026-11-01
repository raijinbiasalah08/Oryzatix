"""Train a candidate MobileNetV2 segmentation model from reviewed rice masks."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps

SIZE = 224
SPLITS = ('train', 'validation', 'test')


def read_pair(row):
    with Image.open(row['image']) as source:
        image = ImageOps.exif_transpose(source).convert('RGB')
    with Image.open(row['mask']) as source:
        if source.mode not in ('L', 'P'):
            # Our annotation tool exports RGB with identical class-ID channels.
            rgb = np.asarray(source.convert('RGB'))
            if not np.all(rgb == rgb[..., :1]):
                raise ValueError('Mask must contain grayscale class IDs 0, 1, 2.')
            mask = Image.fromarray(rgb[..., 0])
        else:
            mask = Image.fromarray(np.asarray(source).astype(np.uint8))
    if image.size != mask.size:
        raise ValueError('Mask dimensions must match the EXIF-corrected image.')
    ids = set(np.unique(np.asarray(mask)).tolist())
    if not ids <= {0, 1, 2} or not ids & {1, 2}:
        raise ValueError('Mask must include leaf pixels and only class IDs 0, 1, 2.')
    return image, mask


def load_manifest(path):
    path = Path(os.path.abspath(path))
    root = Path(os.path.abspath(path.parent))
    rows = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(rows, list) or not rows:
        raise ValueError('Manifest must contain reviewed image/mask records.')
    seen, groups, result = set(), {}, []
    training_classes = set()
    for number, original in enumerate(rows, 1):
        row = dict(original)
        if row.get('reviewed') is not True or not str(row.get('reviewer', '')).strip():
            raise ValueError(f'Record {number}: actual reviewer approval is required.')
        if row.get('group_confirmed') is not True or str(row.get('group_id', '')).startswith('UNCONFIRMED-'):
            raise ValueError(f'Record {number}: confirm plant/capture grouping before training.')
        split, group = row.get('split'), row.get('group_id')
        if split not in SPLITS or not isinstance(group, str) or not group.strip() or not row.get('disease'):
            raise ValueError(f'Record {number}: split, disease, and group_id are required.')
        if group in groups and groups[group] != split:
            raise ValueError(f'Group {group} crosses dataset splits.')
        groups[group] = split
        for key in ('image', 'mask'):
            relative = Path(str(row[key]))
            if relative.is_absolute():
                raise ValueError(f'Record {number}: {key} paths must be relative to the manifest folder.')
            resolved = Path(os.path.abspath(root / relative))
            if os.path.commonpath((str(root), str(resolved))) != str(root) or not resolved.is_file():
                raise ValueError(f'Record {number}: {key} is missing or outside the manifest folder.')
            row[key] = str(resolved)
        image, mask = read_pair(row)
        digest = hashlib.sha256(str(image.size).encode() + image.tobytes()).hexdigest()
        if digest in seen:
            raise ValueError(f'Record {number}: duplicate decoded image.')
        seen.add(digest)
        if split == 'train':
            training_classes.update(np.unique(np.asarray(mask)).tolist())
        result.append(row)
    if {r['split'] for r in result} != set(SPLITS):
        raise ValueError('Separate train, validation, and test records are required.')
    if training_classes != {0, 1, 2}:
        raise ValueError('Training masks must cover background, unaffected, and affected classes.')
    return result


def resized_pair(row):
    image, mask = read_pair(row)
    return (np.asarray(image.resize((SIZE, SIZE), Image.Resampling.BILINEAR), dtype=np.float32),
            np.asarray(mask.resize((SIZE, SIZE), Image.Resampling.NEAREST), dtype=np.int32))


def area_percent(mask):
    leaf = np.count_nonzero(mask > 0)
    return 100.0 * np.count_nonzero(mask == 2) / leaf if leaf else None


def band(area):
    return 'mild' if area <= 25 else 'moderate' if area <= 60 else 'severe'


def build_model(tf, weights='imagenet'):
    base = tf.keras.applications.MobileNetV2(input_shape=(SIZE, SIZE, 3), include_top=False, weights=weights)
    names = ['block_1_expand_relu', 'block_3_expand_relu', 'block_6_expand_relu', 'block_13_expand_relu', 'block_16_project']
    encoder = tf.keras.Model(base.input, [base.get_layer(name).output for name in names])
    encoder.trainable = False
    inputs = tf.keras.Input((SIZE, SIZE, 3), name='rgb_0_255')
    features = encoder(tf.keras.layers.Rescaling(1 / 127.5, offset=-1)(inputs), training=False)
    x = features[-1]
    for skip, filters in zip(reversed(features[:-1]), (256, 128, 64, 32)):
        x = tf.keras.layers.UpSampling2D(interpolation='bilinear')(x)
        x = tf.keras.layers.Concatenate()([x, skip])
        x = tf.keras.layers.Conv2D(filters, 3, padding='same', activation='relu')(x)
    x = tf.keras.layers.UpSampling2D(interpolation='bilinear')(x)
    outputs = tf.keras.layers.Conv2D(3, 1, activation='softmax', name='tissue_probabilities')(x)
    return tf.keras.Model(inputs, outputs)


def export_tflite(tf, model):
    # Match the existing validator exporter; avoid Keras 3 save-spec fallback.
    from tensorflow.python.framework.convert_to_constants import convert_variables_to_constants_v2
    @tf.function(input_signature=[tf.TensorSpec((1, SIZE, SIZE, 3), tf.float32)])
    def serve(images):
        return model(images, training=False)
    frozen = convert_variables_to_constants_v2(serve.get_concrete_function())
    return tf.lite.TFLiteConverter.from_concrete_functions([frozen]).convert()


def dataset(tf, rows, split, batch):
    selected = [r for r in rows if r['split'] == split]
    def generate():
        for row in selected:
            yield resized_pair(row)
    ds = tf.data.Dataset.from_generator(generate, output_signature=(
        tf.TensorSpec((SIZE, SIZE, 3), tf.float32), tf.TensorSpec((SIZE, SIZE), tf.int32)))
    if split == 'train':
        ds = ds.shuffle(min(256, len(selected)), seed=42)
    return ds.batch(batch).prefetch(1)


def evaluate_tflite(tf, model_path, rows):
    interpreter = tf.lite.Interpreter(model_path=str(model_path))
    interpreter.allocate_tensors()
    inp, out = interpreter.get_input_details()[0], interpreter.get_output_details()[0]
    tissue_confusion = np.zeros((3, 3), dtype=np.int64)
    level_confusion = np.zeros((3, 3), dtype=np.int64)
    levels = ('mild', 'moderate', 'severe')
    level_ids = {level: index for index, level in enumerate(levels)}
    level_records, disease_counts = [], {}
    test_segmentation_images = 0
    for row in rows:
        if row['split'] != 'test':
            continue
        test_segmentation_images += 1
        image, truth = resized_pair(row)
        interpreter.set_tensor(inp['index'], image[None])
        interpreter.invoke()
        probabilities = interpreter.get_tensor(out['index'])[0]
        if probabilities.shape != (SIZE, SIZE, 3) or not np.isfinite(probabilities).all():
            raise ValueError('Invalid exported model output.')
        predicted = probabilities.argmax(axis=-1)
        tissue_confusion += np.bincount((truth * 3 + predicted).ravel(), minlength=9).reshape(3, 3)
        if str(row['disease']).strip().lower() == 'healthy':
            continue
        reference_area, predicted_area = area_percent(truth), area_percent(predicted)
        reference_level = band(reference_area) if reference_area is not None else None
        predicted_level = band(predicted_area) if predicted_area is not None else None
        agrees = reference_level is not None and predicted_level is not None and reference_level == predicted_level
        level_records.append({'image': row['image'], 'disease': row['disease'],
                              'reference_level': reference_level, 'predicted_level': predicted_level,
                              'level_agrees': agrees})
        counts = disease_counts.setdefault(row['disease'], {'correct': 0, 'total': 0, 'scorable': 0})
        counts['total'] += 1
        counts['correct'] += int(agrees)
        if reference_level is not None and predicted_level is not None:
            counts['scorable'] += 1
            level_confusion[level_ids[reference_level], level_ids[predicted_level]] += 1

    intersection = np.diag(tissue_confusion)
    union = tissue_confusion.sum(0) + tissue_confusion.sum(1) - intersection
    for counts in disease_counts.values():
        counts['level_agreement'] = counts['correct'] / counts['total'] if counts['total'] else None
    level_metrics = {}
    f1_values = []
    for index, level in enumerate(levels):
        tp = int(level_confusion[index, index])
        precision = tp / int(level_confusion[:, index].sum()) if level_confusion[:, index].sum() else 0.0
        recall = tp / int(level_confusion[index, :].sum()) if level_confusion[index, :].sum() else 0.0
        f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
        level_metrics[level] = {'precision': precision, 'recall': recall, 'f1': f1,
                                'support': int(level_confusion[index].sum())}
        f1_values.append(f1)
    scored = int(level_confusion.sum())
    agreement = sum(record['level_agrees'] is True for record in level_records)
    return {
        'status': 'candidate_not_approved_for_deployment',
        'severity_test_images': len(level_records),
        'segmentation_test_images': test_segmentation_images,
        'scorable_severity_test_images': scored,
        'unscorable_severity_test_images': len(level_records) - scored,
        'severity_level_order': list(levels),
        'severity_level_agreement_all_diseased_test_images': agreement / len(level_records) if level_records else None,
        'severity_level_accuracy_on_scorable_images': float(np.trace(level_confusion) / scored) if scored else None,
        'severity_macro_f1_on_scorable_images': float(np.mean(f1_values)) if scored else None,
        'severity_confusion_rows_true_columns_predicted': level_confusion.tolist(),
        'per_severity': level_metrics,
        'per_disease': disease_counts,
        'tissue_class_iou': [float(i / u) if u else None for i, u in zip(intersection, union)],
        'tissue_class_order': ['background', 'unaffected_leaf', 'affected_leaf'],
        'model_sha256': hashlib.sha256(model_path.read_bytes()).hexdigest(),
        'records': level_records,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--output', type=Path, default=Path('outputs/severity-candidate'))
    parser.add_argument('--epochs', type=int, default=30)
    parser.add_argument('--batch-size', type=int, default=8)
    parser.add_argument('--validate-only', action='store_true')
    args = parser.parse_args()
    rows = load_manifest(Path(os.path.abspath(args.manifest)))
    print(json.dumps({'reviewed_records': {s: sum(r['split'] == s for r in rows) for s in SPLITS}}))
    if args.validate_only:
        return
    if args.epochs < 1 or args.batch_size < 1:
        parser.error('epochs and batch-size must be positive')
    if args.output.exists() and any(args.output.iterdir()):
        parser.error('Use an empty candidate output folder to preserve prior runs.')
    import tensorflow as tf
    tf.keras.utils.set_random_seed(42)
    args.output.mkdir(parents=True, exist_ok=True)
    model = build_model(tf)
    model.compile(optimizer=tf.keras.optimizers.Adam(1e-3), loss='sparse_categorical_crossentropy')
    model.fit(dataset(tf, rows, 'train', args.batch_size), validation_data=dataset(tf, rows, 'validation', args.batch_size),
              epochs=args.epochs, callbacks=[tf.keras.callbacks.EarlyStopping(monitor='val_loss', patience=5, restore_best_weights=True)])
    model.save(args.output / 'severity_candidate.keras')
    model_path = args.output / 'severity_candidate.tflite'
    model_path.write_bytes(export_tflite(tf, model))
    report = evaluate_tflite(tf, model_path, rows)
    report['manifest_sha256'] = hashlib.sha256(args.manifest.read_bytes()).hexdigest()
    (args.output / 'evaluation.json').write_text(json.dumps(report, indent=2, allow_nan=False), encoding='utf-8')
    print('Candidate exported and evaluated. Review the report before integration; no deployment performed.')


if __name__ == '__main__':
    main()
