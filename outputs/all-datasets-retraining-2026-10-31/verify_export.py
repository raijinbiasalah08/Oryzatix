"""Export frozen weights and verify one held-out image per output class."""
import csv
import json
import os
from pathlib import Path
import sys
import numpy as np
from PIL import Image, ImageOps

RUN = Path(__file__).absolute().parent
ROOT = RUN.parent.parent
OUT = RUN / 'models'
sys.path.insert(0, str(ROOT / 'training'))
for key in ('TMP', 'TEMP', 'TMPDIR'):
    os.environ[key] = str(OUT)
import tensorflow as tf
from train_rice_leaf_validator import export_validator_tflite
tf.config.threading.set_intra_op_parallelism_threads(4)
tf.config.threading.set_inter_op_parallelism_threads(2)
model = tf.keras.models.load_model(OUT / 'mobilenetv2_rice_leaf.keras')
path = OUT / 'mobilenetv2_rice_leaf.tflite'
export_validator_tflite(tf, model, path)
interpreter = tf.lite.Interpreter(model_path=str(path), num_threads=4)
interpreter.allocate_tensors()
inp = interpreter.get_input_details()[0]
out = interpreter.get_output_details()[0]
with (OUT / 'training_manifest.csv').open(newline='', encoding='utf-8') as stream:
    rows = list(csv.DictReader(stream))
seen = set()
checks = []
for row in rows:
    if row['split'] != 'test' or row['class_key'] in seen:
        continue
    seen.add(row['class_key'])
    with Image.open(row['path']) as source:
        pixels = np.asarray(ImageOps.exif_transpose(source).convert('RGB').resize((224,224), Image.Resampling.BILINEAR), dtype=np.float32)[None]
    expected = np.asarray(model(pixels, training=False))
    interpreter.set_tensor(inp['index'], pixels)
    interpreter.invoke()
    actual = interpreter.get_tensor(out['index'])
    assert actual.shape == (1,7) and np.isfinite(actual).all()
    assert np.allclose(actual, expected, atol=1e-4, rtol=1e-4), (row['class_key'], actual, expected)
    checks.append({'class_key': row['class_key'], 'maximum_absolute_error': float(np.max(np.abs(actual-expected)))})
assert len(checks) == 7
report = {'tflite_bytes': path.stat().st_size, 'keras_tflite_parity': checks, 'passed': True}
(RUN / 'export_verification.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2), flush=True)
