"""Regression checks for full-image orientation inference."""
import unittest
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

    def test_legacy_one_view_and_invalid_configuration(self):
        with patch.object(predict_scan, 'invoke_model', return_value=np.array([[.8, .2]])) as invoke:
            result = predict_scan.disease_views(None, np.zeros((1, 4, 4, 3)), 2)
            np.testing.assert_allclose(result, [[.8, .2]])
            self.assertEqual(invoke.call_count, 1)
            with self.assertRaises(ValueError):
                predict_scan.disease_views(None, np.zeros((1, 4, 4, 3)), 2, 3)


if __name__ == '__main__':
    unittest.main()
