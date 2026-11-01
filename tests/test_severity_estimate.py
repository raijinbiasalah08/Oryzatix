"""Category and fail-closed checks; these do not measure agronomic accuracy."""
import os
import sys
import unittest
from pathlib import Path
import numpy as np

sys.path.insert(0, os.path.abspath('inference'))
from severity import category, estimate


def pixels(affected, leaf=1000):
    mask = np.zeros(224 * 224, dtype=np.uint8)
    mask[:leaf] = 1
    mask[:affected] = 2
    return np.eye(3, dtype=np.float32)[mask].reshape(1, 224, 224, 3)


class SeverityEstimateTests(unittest.TestCase):
    def test_category_boundaries(self):
        for affected, expected in ((250, 'mild'), (251, 'moderate'), (600, 'moderate'), (601, 'severe')):
            self.assertEqual(category(pixels(affected)), expected)

    def test_invalid_and_unscorable(self):
        for data in (pixels(0), pixels(1, 10), np.zeros((1, 3)), pixels(250) * np.nan, pixels(250) * 2):
            self.assertIsNone(category(data))

    def test_healthy_never_invokes_severity(self):
        self.assertIsNone(estimate(Path('.'), pixels(0), 'healthy', lambda *args: self.fail('Must not run')))

    def test_result_contains_only_category_and_provenance(self):
        result = estimate(Path('oryzatix_app/assets/models'), pixels(250), 'blast', lambda *args: pixels(250))
        self.assertEqual(result, {'level': 'mild', 'source': 'model_estimated', 'version': 'severity_experimental_v1'})


if __name__ == '__main__':
    unittest.main()
