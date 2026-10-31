"""Compare full-image orientation averaging using an existing, frozen split.

Choose the policy on validation only, then report the untouched test split.
Writes candidate thresholds; does not deploy them automatically.
"""
import argparse
import csv
import hashlib
import json
import sys
from pathlib import Path

import numpy as np

ROOT = Path(__file__).absolute().parents[1]
sys.path.insert(0, str(ROOT / 'inference'))
from predict_scan import IMAGE_SIZE, Image, load_rgb, load_model, disease_views


def calibrate(probs, truth, keys):
    predicted = probs.argmax(axis=1)
    thresholds = {}
    for i, key in enumerate(keys):
        if key == 'not_rice_leaf':
            continue
        thresholds[key] = None
        for cutoff in sorted(set(float(x) for x in probs[predicted == i, i])):
            accepted = (predicted == i) & (probs[:, i] >= cutoff)
            if accepted.sum() >= 20 and (truth[accepted] == i).mean() >= .95:
                thresholds[key] = cutoff
                break
    return thresholds


def metrics(probs, truth, keys, thresholds):
    predicted = probs.argmax(axis=1)
    accepted = np.array([thresholds.get(keys[i]) is not None and float(row[i]) >= thresholds[keys[i]]
                         for row, i in zip(probs, predicted)])
    per_class = {}
    for i, key in enumerate(keys):
        selected = predicted == i
        actual = truth == i
        per_class[key] = {'images': int(actual.sum()), 'recall': float((predicted[actual] == i).mean()),
                          'precision': float((truth[selected] == i).mean()) if selected.any() else None}
    rice = np.array([keys[i] != 'not_rice_leaf' for i in truth])
    return {'images': len(truth), 'accuracy': float((predicted == truth).mean()),
            'rice_disease_accuracy': float((predicted[rice] == truth[rice]).mean()),
            'macro_recall': float(np.mean([v['recall'] for v in per_class.values()])),
            'accepted': int(accepted.sum()), 'correct_accepted': int((accepted & (predicted == truth)).sum()),
            'accepted_precision': float((predicted[accepted] == truth[accepted]).mean()) if accepted.any() else None,
            'per_class': per_class}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--assets', type=Path, default=ROOT / 'oryzatix_app/assets/models')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    labels = sorted(json.loads((args.assets / 'class_labels.json').read_text()), key=lambda x: x['index'])
    keys = [x['key'] for x in labels]
    thresholds = json.loads((args.assets / 'scan_validation_thresholds.json').read_text())
    model_path = args.assets / 'mobilenetv2_rice_leaf.tflite'
    model = load_model(model_path)
    with args.manifest.open(newline='', encoding='utf-8') as f:
        manifest = list(csv.DictReader(f))
    split_by_digest = {}
    for row in manifest:
        previous = split_by_digest.setdefault(row['sha256'], row['split'])
        if previous != row['split']:
            raise ValueError('Identical image crosses training, validation or test splits.')
    rows = [r for r in manifest if r['split'] in ('validation', 'test')]
    report = {'model_sha256': hashlib.sha256(model_path.read_bytes()).hexdigest(),
              'manifest_sha256': hashlib.sha256(args.manifest.read_bytes()).hexdigest(),
              'selection_split': 'validation', 'scope': 'Disease classifier only; existing quality and rice gate remain unchanged.'}
    selected = 1
    for split in ('validation', 'test'):
        subset = [r for r in rows if r['split'] == split]
        results = []
        for n, row in enumerate(subset):
            if hashlib.sha256(Path(row['path']).read_bytes()).hexdigest() != row['sha256']:
                raise ValueError('Image contents changed since the model split was created.')
            if keys[int(row['label'])] != row['class_key']:
                raise ValueError('Manifest labels do not match model.')
            image = load_rgb(Path(row['path'])).resize((IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR)
            results.append(disease_views(model, np.asarray(image, dtype=np.float32)[None], len(keys), 4))
            if (n + 1) % 100 == 0:
                print(f'{split}: {n + 1}/{len(subset)}', flush=True)
        results = np.asarray(results)
        truth = np.asarray([int(r['label']) for r in subset])
        np.savez_compressed(args.output / f'{split}_predictions.npz', views=results, truth=truth)
        baseline = results[:, 0]
        candidate = results.mean(axis=1)
        if split == 'validation':
            candidate_thresholds = calibrate(candidate, truth, keys)
        before = metrics(baseline, truth, keys, thresholds['disease']['thresholds'])
        after = metrics(candidate, truth, keys, candidate_thresholds)
        report[split] = {'single_view': before, 'four_views': after}
        if split == 'validation':
            selected = 4 if (after['macro_recall'] > before['macro_recall'] and
                             after['correct_accepted'] >= before['correct_accepted'] and
                             after['accepted_precision'] >= .95) else 1
            print(f'Validation selected {selected} view(s)', flush=True)
    report['selected_orientation_views'] = selected
    (args.output / 'evaluation.json').write_text(json.dumps(report, indent=2) + '\n')
    if selected == 4:
        thresholds['disease'].update({'orientation_views': 4, 'thresholds': candidate_thresholds,
            'threshold_policy': 'Four full-image orientations averaged; minimum 20 validation accepts per class at 95% empirical precision.',
            'model_sha256': report['model_sha256']})
        thresholds['disease'].pop('per_class_validation', None)
        (args.output / 'scan_validation_thresholds.json').write_text(json.dumps(thresholds, indent=2) + '\n')
    print(json.dumps(report, indent=2), flush=True)


if __name__ == '__main__':
    main()
