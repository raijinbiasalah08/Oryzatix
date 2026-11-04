import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from check_scanner import check_assets


class ScannerAssetChecks(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        model = b'example-model-bytes'
        (self.directory / 'mobilenetv2_rice_leaf.tflite').write_bytes(model)
        (self.directory / 'class_labels.json').write_text(json.dumps([
            {'index': 0, 'key': 'healthy'}, {'index': 1, 'key': 'not_rice_leaf'}]))
        self.thresholds = {'disease': {'model_sha256': hashlib.sha256(model).hexdigest(),
                                      'thresholds': {'healthy': 0.5}},
                           'rice_leaf': {'operating_point_available': True}}

    def check(self):
        (self.directory / 'scan_validation_thresholds.json').write_text(json.dumps(self.thresholds))
        return check_assets(self.directory)

    def test_matching_pair_is_accepted(self):
        self.assertEqual(len(self.check()), 2)

    def test_old_calibration_is_rejected(self):
        self.thresholds['disease']['model_sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'model/calibration mismatch'):
            self.check()

    def test_missing_hash_is_rejected(self):
        del self.thresholds['disease']['model_sha256']
        with self.assertRaisesRegex(ValueError, 'model/calibration mismatch'):
            self.check()

    def test_wrong_classes_are_rejected(self):
        self.thresholds['disease']['thresholds'] = {'blast': 0.5}
        with self.assertRaisesRegex(ValueError, 'classes do not match'):
            self.check()


if __name__ == '__main__':
    unittest.main()
