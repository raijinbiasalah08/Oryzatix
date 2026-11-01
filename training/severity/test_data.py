"""Contract checks with synthetic fixtures, not model accuracy measurements."""
import json
import csv
import hashlib
from pathlib import Path
import tempfile
import unittest

import numpy as np
from PIL import Image

from train import area_percent, band, load_manifest
from prepare_batch import prepare


class SeverityDataTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.rows = []
        for i, split in enumerate(('train', 'validation', 'test')):
            Image.new('RGB', (4, 4), (i * 50, 100, 0)).save(self.root / f'{i}.png')
            mask = np.zeros((4, 4), dtype=np.uint8)
            mask[1:3, 1:3] = 1
            mask[1, 1] = 2
            Image.fromarray(mask).save(self.root / f'{i}-mask.png')
            self.rows.append(dict(image=f'{i}.png', mask=f'{i}-mask.png', group_id=f'plant-{i}',
                                  disease='blast', split=split, reviewed=True, reviewer='fixture', group_confirmed=True))

    def load(self):
        path = self.root / 'manifest.json'
        path.write_text(json.dumps(self.rows), encoding='utf-8')
        return load_manifest(path)

    def test_reviewed_dataset(self):
        self.assertEqual(len(self.load()), 3)

    def test_unreviewed_rejected(self):
        self.rows[0]['reviewed'] = False
        with self.assertRaisesRegex(ValueError, 'reviewer approval'):
            self.load()

    def test_group_leakage_rejected(self):
        self.rows[1]['group_id'] = self.rows[0]['group_id']
        with self.assertRaisesRegex(ValueError, 'crosses'):
            self.load()

    def test_unconfirmed_group_rejected(self):
        self.rows[0]['group_confirmed'] = False
        with self.assertRaisesRegex(ValueError, 'confirm plant/capture'):
            self.load()

    def test_duplicate_rejected(self):
        self.rows[1]['image'] = self.rows[0]['image']
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            self.load()

    def test_invalid_mask_rejected(self):
        Image.new('L', (4, 4), 255).save(self.root / '0-mask.png')
        with self.assertRaisesRegex(ValueError, 'class IDs'):
            self.load()

    def test_background_excluded_from_denominator(self):
        self.assertEqual(area_percent(np.array([[0, 0, 1, 2]])), 50)
        self.assertIsNone(area_percent(np.zeros((2, 2))))
        self.assertEqual(band(25), 'mild')
        self.assertEqual(band(25.01), 'moderate')
        self.assertEqual(band(60), 'moderate')
        self.assertEqual(band(60.01), 'severe')

    def test_batch_preserves_splits_and_requires_real_review(self):
        source = self.root / 'disease.csv'
        with source.open('w', newline='', encoding='utf-8') as stream:
            writer = csv.DictWriter(stream, fieldnames=['path', 'class_key', 'split', 'sha256'])
            writer.writeheader()
            for row in self.rows:
                image = self.root / row['image']
                writer.writerow({'path': str(image), 'class_key': 'blast', 'split': row['split'],
                                 'sha256': hashlib.sha256(image.read_bytes()).hexdigest()})
        output = self.root / 'batch'
        report = prepare(source, output)
        self.assertEqual(report['total_images'], 3)
        self.assertEqual(list((output / 'masks').iterdir()), [])
        records = json.loads((output / 'manifest.json').read_text())
        self.assertEqual({r['split'] for r in records}, {'train', 'validation', 'test'})
        self.assertTrue(all(not r['reviewed'] and not r['group_confirmed'] for r in records))
        with self.assertRaisesRegex(ValueError, 'reviewer approval'):
            load_manifest(output / 'manifest.json')
        with self.assertRaisesRegex(ValueError, 'Output must be empty'):
            prepare(source, output)


if __name__ == '__main__':
    unittest.main()
