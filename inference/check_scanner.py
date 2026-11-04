"""Refuse deployment when the scanner model and its calibration disagree."""
import argparse
import hashlib
import json
from pathlib import Path


def check_assets(directory):
    directory = Path(directory)
    model = directory / 'mobilenetv2_rice_leaf.tflite'
    thresholds = json.loads((directory / 'scan_validation_thresholds.json').read_text(encoding='utf-8'))
    expected = thresholds['disease'].get('model_sha256')
    actual = hashlib.sha256(model.read_bytes()).hexdigest()
    if not expected or expected.lower() != actual:
        raise ValueError('Scanner model/calibration mismatch: deploy the calibration produced for this model.')
    labels = json.loads((directory / 'class_labels.json').read_text(encoding='utf-8'))
    if sorted(row['index'] for row in labels) != list(range(len(labels))):
        raise ValueError('Scanner label indexes are invalid.')
    if set(thresholds['disease']['thresholds']) != {row['key'] for row in labels if row['key'] != 'not_rice_leaf'}:
        raise ValueError('Scanner calibration classes do not match the labels.')
    if not thresholds['rice_leaf'].get('operating_point_available'):
        raise ValueError('Rice-leaf validation is not calibrated.')
    return labels


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--load-models', action='store_true')
    args = parser.parse_args()
    directory = Path(__file__).absolute().parents[1] / 'oryzatix_app/assets/models'
    labels = check_assets(directory)
    if args.load_models:
        import numpy as np
        from predict_scan import run_model
        pixels = np.zeros((1, 224, 224, 3), dtype=np.float32)
        for name, shape in [('rice_leaf_validator.tflite', (1, 1)),
                            ('mobilenetv2_rice_leaf.tflite', (1, len(labels))),
                            ('severity_experimental_v1.tflite', (1, 224, 224, 3))]:
            run_model(directory / name, pixels, shape)
    print('Scanner model and calibration checks passed.' +
          (' Runtime inference checks passed.' if args.load_models else ''))
