"""Train a separate experimental segmenter from machine-generated draft masks.

All labels are color-rule pseudo-labels, never reviewed annotations. Metrics
measure agreement with those rules and cannot establish disease severity accuracy.
This command never updates scanner assets or changes reviewed manifests.
"""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path

os.environ.setdefault('TF_CPP_MIN_LOG_LEVEL', '2')
import numpy as np
from PIL import Image, ImageOps, ImageFilter, ImageDraw

SIZE = 224
LEVELS = ('mild', 'moderate', 'severe')


def draft_mask(image):
    hsv = np.asarray(image.convert('HSV'), dtype=np.float32) / 255.0
    hue, saturation, value = hsv[..., 0], hsv[..., 1], hsv[..., 2]
    foreground = (saturation > .12) & (value > .12) & (hue < .53)
    binary = Image.fromarray(foreground.astype(np.uint8) * 255)
    binary = binary.filter(ImageFilter.MaxFilter(5)).filter(ImageFilter.MinFilter(5))
    foreground = np.asarray(binary.filter(ImageFilter.MedianFilter(3))) > 0
    green = (hue >= .16) & (hue <= .48) & (saturation >= .18) & (value >= .20)
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    mask[foreground] = 1
    mask[foreground & ~green] = 2
    return mask


def category(mask):
    leaf = np.count_nonzero(mask > 0)
    affected = np.count_nonzero(mask == 2)
    if leaf < SIZE * SIZE * .01 or affected == 0:
        return None
    ratio = affected / leaf
    return 'mild' if ratio <= .25 else 'moderate' if ratio <= .60 else 'severe'


def dump(path, value):
    path.write_text(json.dumps(value, indent=2, allow_nan=False), encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--data-root', type=Path, default=Path('.'))
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--epochs', type=int, default=12)
    args = parser.parse_args()
    root = Path(os.path.abspath(args.data_root))
    output = Path(os.path.abspath(args.output))
    if output.exists() and any(output.iterdir()):
        parser.error('Use an empty output directory; previous runs are preserved.')
    if args.epochs < 1:
        parser.error('epochs must be positive')
    source = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
    output.mkdir(parents=True, exist_ok=True)
    (output / 'draft_masks').mkdir()
    images = np.lib.format.open_memmap(str(output / 'images.npy'), mode='w+', dtype='uint8', shape=(len(source), SIZE, SIZE, 3))
    masks = np.lib.format.open_memmap(str(output / 'masks.npy'), mode='w+', dtype='uint8', shape=(len(source), 56, 56))
    rows, exclusions, seen = [], [], set()
    preview = Image.new('RGB', (SIZE * 6, (SIZE + 26) * 4), 'white')
    draw = ImageDraw.Draw(preview)
    for number, row in enumerate(source):
        path = Path(os.path.abspath(root / row['image']))
        if os.path.commonpath((str(root), str(path))) != str(root):
            raise ValueError('Image outside data root')
        if row['split'] not in ('train', 'validation', 'test'):
            raise ValueError('Invalid source split')
        if hashlib.sha256(path.read_bytes()).hexdigest() != row['source_sha256']:
            raise ValueError('Source image changed: ' + str(path))
        with Image.open(path) as opened:
            rgb = ImageOps.exif_transpose(opened).convert('RGB')
            digest = hashlib.sha256(str(rgb.size).encode() + rgb.tobytes()).hexdigest()
            if digest in seen:
                raise ValueError('Duplicate decoded image: ' + str(path))
            seen.add(digest)
            image = rgb.resize((SIZE, SIZE), Image.Resampling.BILINEAR)
        mask = draft_mask(image)
        if np.count_nonzero(mask) < SIZE * SIZE * .01:
            exclusions.append({'image': row['image'], 'reason': 'draft rule found insufficient foreground'})
            continue
        index = len(rows)
        images[index] = np.asarray(image)
        masks[index] = np.asarray(Image.fromarray(mask).resize((56, 56), Image.Resampling.NEAREST))
        mask_path = 'draft_masks/%05d.png' % index
        Image.fromarray(mask).save(output / mask_path)
        rows.append({**row, 'mask': mask_path, 'cache_index': index, 'reviewed': False,
                     'reviewer': '', 'annotation_source': 'unvalidated_color_rules_v1',
                     'draft_level': category(mask)})
        if index < 12:
            x, y = (index % 3) * SIZE * 2, (index // 3) * (SIZE + 26)
            palette = np.array([[30, 30, 30], [30, 200, 80], [240, 50, 50]], dtype=np.uint8)
            preview.paste(image, (x, y)); preview.paste(Image.fromarray(palette[mask]), (x + SIZE, y))
            draw.text((x + 4, y + SIZE + 4), row['disease'] + ' / DRAFT ' + str(category(mask)), fill='black')
        if (number + 1) % 200 == 0:
            print('Prepared %d/%d source images' % (number + 1, len(source)), flush=True)
    images.flush(); masks.flush()
    preview.save(output / 'draft_mask_preview.jpg')
    dump(output / 'draft_manifest.json', rows)
    dump(output / 'exclusions.json', exclusions)
    counts = {split: dict(Counter(row['disease'] for row in rows if row['split'] == split)) for split in ('train', 'validation', 'test')}
    draft_levels = {split: dict(Counter(str(row['draft_level']) for row in rows if row['split'] == split)) for split in counts}
    report = {'status': 'experimental_unvalidated_not_for_deployment', 'reviewed_masks': 0,
              'source_records': len(source), 'usable_draft_masks': len(rows), 'exclusions': len(exclusions),
              'counts': counts, 'draft_level_counts': draft_levels,
              'limitations': ['Reference masks are generated by color rules and can mistake soil, shadows, pale lesions, and non-disease discoloration.',
                             'Metrics measure imitation of those rules, not true severity accuracy.',
                             'Plant/capture groups are unconfirmed. Existing splits are preserved.',
                             'No independent reviewed severity evaluation or deployment has occurred.']}
    dump(output / 'report.json', report)
    import tensorflow as tf
    tf.config.threading.set_intra_op_parallelism_threads(2)
    tf.config.threading.set_inter_op_parallelism_threads(2)
    tf.keras.utils.set_random_seed(42)
    print('TensorFlow', tf.__version__, 'devices', tf.config.list_physical_devices(), flush=True)
    base = tf.keras.applications.MobileNetV2(input_shape=(SIZE, SIZE, 3), include_top=False, weights='imagenet')
    encoder = tf.keras.Model(base.input, base.get_layer('block_6_expand_relu').output)
    encoder.trainable = False
    shape = tuple(encoder.output_shape[1:])
    features = np.lib.format.open_memmap(str(output / 'features.npy'), mode='w+', dtype='float16', shape=(len(rows),) + shape)
    for start in range(0, len(rows), 16):
        batch = np.asarray(images[start:min(start + 16, len(rows))], dtype=np.float32) / 127.5 - 1
        features[start:start + len(batch)] = encoder(batch, training=False).numpy().astype(np.float16)
        if start % 256 == 0:
            print('Encoded %d/%d images' % (min(start + 16, len(rows)), len(rows)), flush=True)
    features.flush()
    pixel_counts = np.zeros(3, dtype=np.int64)
    for row in rows:
        if row['split'] == 'train':
            pixel_counts += np.bincount(masks[row['cache_index']].ravel(), minlength=3)
    if np.any(pixel_counts == 0):
        raise ValueError('Draft training masks do not cover all three tissue classes')
    weights = np.clip(pixel_counts.sum() / (3 * pixel_counts), .25, 4).astype(np.float32)
    def dataset(split):
        selected = [row['cache_index'] for row in rows if row['split'] == split]
        def generate():
            for index in selected:
                truth = np.asarray(masks[index], dtype=np.int32)
                yield np.asarray(features[index], dtype=np.float32), truth, weights[truth]
        ds = tf.data.Dataset.from_generator(generate, output_signature=(tf.TensorSpec(shape, tf.float32), tf.TensorSpec((56, 56), tf.int32), tf.TensorSpec((56, 56), tf.float32)))
        ds = ds.apply(tf.data.experimental.assert_cardinality(len(selected)))
        if split == 'train':
            ds = ds.shuffle(128, seed=42)
        return ds.batch(16).prefetch(1)
    inputs = tf.keras.Input(shape)
    x = tf.keras.layers.Conv2D(32, 1, activation='relu')(inputs)
    x = tf.keras.layers.SeparableConv2D(32, 3, padding='same', activation='relu')(x)
    x = tf.keras.layers.UpSampling2D(interpolation='bilinear')(x)
    x = tf.keras.layers.SeparableConv2D(16, 3, padding='same', activation='relu')(x)
    prediction = tf.keras.layers.Conv2D(3, 1, activation='softmax')(x)
    decoder = tf.keras.Model(inputs, prediction)
    decoder.compile(optimizer=tf.keras.optimizers.Adam(1e-3), loss='sparse_categorical_crossentropy')
    class Progress(tf.keras.callbacks.Callback):
        def on_epoch_end(self, epoch, logs=None):
            print('EPOCH %d %s' % (epoch + 1, json.dumps(logs)), flush=True)
            decoder.save(output / 'latest_decoder.keras')
    history = decoder.fit(dataset('train'), validation_data=dataset('validation'), epochs=args.epochs, verbose=0,
                          callbacks=[Progress(), tf.keras.callbacks.EarlyStopping(monitor='val_loss', patience=3, restore_best_weights=True)])
    rgb = tf.keras.Input((SIZE, SIZE, 3), name='rgb_0_255')
    result = decoder(encoder(tf.keras.layers.Rescaling(1 / 127.5, offset=-1)(rgb), training=False))
    result = tf.keras.layers.UpSampling2D(size=4, interpolation='bilinear', name='draft_tissue_probabilities')(result)
    model = tf.keras.Model(rgb, result)
    model.save(output / 'experimental_segmentation.keras')
    from train import export_tflite
    model_path = output / 'experimental_segmentation.tflite'
    model_path.write_bytes(export_tflite(tf, model))
    interpreter = tf.lite.Interpreter(model_path=str(model_path), num_threads=2)
    interpreter.allocate_tensors()
    inp, out = interpreter.get_input_details()[0], interpreter.get_output_details()[0]
    confusion = np.zeros((3, 3), dtype=np.int64)
    records = []
    for row in rows:
        if row['split'] != 'test':
            continue
        index = row['cache_index']
        interpreter.set_tensor(inp['index'], np.asarray(images[index:index+1], dtype=np.float32))
        interpreter.invoke()
        probabilities = interpreter.get_tensor(out['index'])[0]
        if probabilities.shape != (SIZE, SIZE, 3) or not np.isfinite(probabilities).all():
            raise ValueError('Invalid TFLite export output')
        predicted = probabilities.argmax(axis=-1)
        reference = np.asarray(Image.open(output / row['mask']))
        confusion += np.bincount((reference * 3 + predicted).ravel(), minlength=9).reshape(3, 3)
        records.append({'image': row['image'], 'disease': row['disease'], 'draft_reference_level': category(reference), 'predicted_level': category(predicted)})
    union = confusion.sum(0) + confusion.sum(1) - np.diag(confusion)
    report.update({'epochs_completed': len(history.history['loss']), 'history': history.history,
                   'draft_mask_iou': [float(a / b) if b else None for a, b in zip(np.diag(confusion), union)],
                   'tissue_order': ['background', 'unaffected_leaf', 'affected_leaf'],
                   'test_records': records, 'test_images': len(records),
                   'model_sha256': hashlib.sha256(model_path.read_bytes()).hexdigest(),
                   'actual_severity_accuracy': None})
    dump(output / 'report.json', report)
    print('FINISHED: ' + str(output), flush=True)
    print(json.dumps({key: report[key] for key in ('status', 'usable_draft_masks', 'epochs_completed', 'test_images', 'actual_severity_accuracy')}), flush=True)


if __name__ == '__main__':
    main()
