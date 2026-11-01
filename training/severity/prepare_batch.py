"""Prepare an unannotated severity segmentation pilot from a disease-training manifest."""
import argparse
from collections import Counter
import csv
import hashlib
import json
from pathlib import Path
import shutil

from PIL import Image, ImageOps

CLASSES = ('blb', 'brown_spot', 'healthy', 'sheath_blight', 'tungro', 'blast')
QUOTAS = {'train': 40, 'validation': 10, 'test': 10}


def prepare(manifest, output, resume_incomplete=False):
    if output.exists() and any(output.iterdir()):
        safe_incomplete = (resume_incomplete and
                           all(p.name in ('images', 'masks') and p.is_dir() for p in output.iterdir()) and
                           (not (output / 'masks').exists() or not any((output / 'masks').iterdir())))
        if not safe_incomplete:
            raise ValueError('Output must be empty; existing annotations will not be overwritten.')
    with manifest.open(encoding='utf-8-sig', newline='') as stream:
        source = list(csv.DictReader(stream))
    candidates = sorted(source, key=lambda row: hashlib.sha256(('severity-pilot-42:' + row['sha256']).encode()).hexdigest())
    counts, seen, records, exclusions = Counter(), set(), [], []
    output.mkdir(parents=True, exist_ok=True)
    (output / 'images').mkdir(exist_ok=True)
    (output / 'masks').mkdir(exist_ok=True)
    for row in candidates:
        disease, split = row['class_key'], row['split']
        if disease not in CLASSES or split not in QUOTAS or counts[disease, split] >= QUOTAS[split]:
            continue
        path = Path(row['path'])
        if not path.is_absolute():
            path = manifest.parent / path
        try:
            if any(part.lower() == 'augmented' for part in path.parts):
                raise ValueError('Augmented directory')
            if hashlib.sha256(path.read_bytes()).hexdigest() != row['sha256']:
                raise ValueError('Source image changed since disease training')
            with Image.open(path) as photo:
                rgb = ImageOps.exif_transpose(photo).convert('RGB')
                if rgb.width * rgb.height > 16000000:
                    raise ValueError('Exceeds annotation tool size limit')
                digest = hashlib.sha256(str(rgb.size).encode() + rgb.tobytes()).hexdigest()
            if digest in seen:
                raise ValueError('Duplicate decoded image')
        except (OSError, ValueError) as error:
            exclusions.append({'source': str(path), 'reason': str(error)})
            continue
        seen.add(digest)
        identifier = f'leaf-{len(records) + 1:04d}'
        image_name = identifier + path.suffix.lower()
        target = output / 'images' / image_name
        if target.exists():
            if hashlib.sha256(target.read_bytes()).hexdigest() != row['sha256']:
                raise ValueError('Incomplete batch image differs from source; refusing to overwrite it.')
        else:
            shutil.copy2(path, target)
        records.append({'image': 'images/' + image_name, 'mask': 'masks/' + identifier + '-mask.png',
                        'group_id': 'UNCONFIRMED-' + identifier, 'group_confirmed': False,
                        'disease': disease, 'split': split, 'reviewed': False, 'reviewer': '',
                        'source_image': str(path), 'source_sha256': row['sha256'], 'decoded_sha256': digest})
        counts[disease, split] += 1
    expected = {Path(row['image']).name for row in records}
    if {p.name for p in (output / 'images').iterdir()} != expected:
        raise ValueError('Unexpected images in output folder; inspect before continuing.')
    summary = {'status': 'unannotated_pilot_not_training_ready', 'total_images': len(records),
               'reviewed_masks': 0, 'source_manifest': str(manifest.absolute()),
               'source_manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest(),
               'counts': {key: {split: counts[key, split] for split in QUOTAS} for key in CLASSES},
               'target_per_class': QUOTAS, 'exclusions': exclusions,
               'limitations': ['Source disease splits are preserved, but plant/capture groups still need confirmation.',
                               'Existing disease test images are not an independent end-to-end field holdout.',
                               'Selection is balanced by disease, not severity: severity annotations do not exist yet.',
                               'Image suitability and disease labels require reviewer confirmation.']}
    (output / 'manifest.json').write_text(json.dumps(records, indent=2), encoding='utf-8')
    (output / 'batch-summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    shutil.copy2(Path(__file__).with_name('annotate.html'), output / 'annotate.html')
    (output / 'START-HERE.txt').write_text(
        '1. Open annotate.html in a browser. Choose photos from the images folder (multiple selection supported).\n'
        '2. Exclude field scenes/overlapping leaves or unsuitable tissue until a reviewer defines how to annotate them.\n'
        '3. Paint the whole leaf with Leaf, then symptoms with Affected; use Background for corrections.\n'
        '4. Export each mask PNG and move it from Downloads to this batch\'s masks folder, preserving its filename.\n'
        '5. A reviewer checks each original image/mask; only then set reviewed=true and reviewer in manifest.json.\n'
        '6. Confirm same-plant/capture groups, replace UNCONFIRMED group IDs, set group_confirmed=true, and keep each group in one split.\n'
        '7. Run training/severity/train.py --manifest <this folder>/manifest.json --validate-only before training.\n'
        'No masks or severity labels were generated by the preparation script. This pilot does not establish accuracy.\n', encoding='utf-8')
    return summary


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--resume-incomplete', action='store_true', help='Resume image copies only; refuses existing masks or manifest.')
    args = parser.parse_args()
    result = prepare(args.manifest, args.output, args.resume_incomplete)
    print(json.dumps({'total_images': result['total_images'], 'counts': result['counts'], 'reviewed_masks': 0}, indent=2))
