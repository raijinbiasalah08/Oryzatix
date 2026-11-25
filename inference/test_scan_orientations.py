"""Regression checks for full-image orientation inference."""
import io
import json
import sys
import unittest
from contextlib import ExitStack, redirect_stdout
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

import numpy as np
import predict_scan


class OrientationTests(unittest.TestCase):
    def test_views_preserve_pixels_and_average_is_rotation_invariant(self):
        pixels = np.arange(48, dtype=np.float32).reshape(1, 4, 4, 3)

        def predict(model, view, shape):
            self.assertEqual(sorted(view.flatten()), sorted(pixels.flatten()))
            score = float(view[0, 0, 0, 0]) / 100
            return np.array([[score, 1 - score]], dtype=np.float32)

        with patch.object(predict_scan, 'invoke_model', side_effect=predict):
            first = predict_scan.disease_views(None, pixels, 2, 4).mean(axis=0)
            rotated = predict_scan.disease_views(None, np.rot90(pixels, axes=(1, 2)), 2, 4).mean(axis=0)
            np.testing.assert_allclose(first, rotated)

    def test_invalid_view_cannot_be_hidden_by_averaging(self):
        with patch.object(predict_scan, 'invoke_model', return_value=np.array([[1.2, -.2]])):
            with self.assertRaises(ValueError):
                predict_scan.disease_views(None, np.zeros((1, 4, 4, 3)), 2, 4)

    def test_acceptance_requires_both_votes_and_confidence(self):
        with TemporaryDirectory() as folder:
            root = Path(folder)
            labels = root / 'labels.json'
            labels.write_text(json.dumps([{'index': 0, 'key': 'healthy', 'name': 'Healthy'},
                                          {'index': 1, 'key': 'blast', 'name': 'Leaf Blast'}]))
            image = root / 'sample.png'
            predict_scan.Image.new('RGB', (224, 224)).save(image)
            thresholds = root / 'thresholds.json'
            argv = ['predict_scan', 'gate', 'model', str(labels), str(thresholds), str(image)]
            cases = [
                ([.99, .99, .49, .49], 3, .6, 'orientation_disagreement'),
                ([.99, .99, .49, .49], 0, .6, 'validated_prediction'),
                ([.8, .8, .8, .49], 3, .8, 'disease_confidence_below_validation_threshold'),
                ([.8, .8, .8, .49], 3, .6, 'validated_prediction'),
            ]
            for scores, votes, cutoff, reason in cases:
                with self.subTest(reason=reason, votes=votes), ExitStack() as stack:
                    thresholds.write_text(json.dumps({
                        'rice_leaf': {'threshold': .5, 'operating_point_available': True},
                        'blur': {'threshold': 0},
                        'disease': {'thresholds': {'healthy': cutoff}, 'orientation_views': 4,
                                    'minimum_orientation_votes': votes},
                    }))
                    views = np.array([[p, 1 - p] for p in scores])
                    stack.enter_context(patch.object(sys, 'argv', argv))
                    stack.enter_context(patch.object(predict_scan, 'run_model', return_value=np.ones((1, 1))))
                    stack.enter_context(patch.object(predict_scan, 'load_model'))
                    stack.enter_context(patch.object(predict_scan, 'disease_views', return_value=views))
                    output = stack.enter_context(redirect_stdout(io.StringIO()))
                    self.assertEqual(predict_scan.main(), 0)
                    response = json.loads(output.getvalue())
                    self.assertEqual(response['reason'], reason)
                    self.assertEqual(response['status'], 'diagnosed' if reason == 'validated_prediction' else 'uncertain_prediction')

    def test_legacy_one_view_and_invalid_configuration(self):
        with patch.object(predict_scan, 'invoke_model', return_value=np.array([[.8, .2]])) as invoke:
            result = predict_scan.disease_views(None, np.zeros((1, 4, 4, 3)), 2)
            np.testing.assert_allclose(result, [[.8, .2]])
            self.assertEqual(invoke.call_count, 1)
            with self.assertRaises(ValueError):
                predict_scan.disease_views(None, np.zeros((1, 4, 4, 3)), 2, 3)


if __name__ == '__main__':
    unittest.main()
