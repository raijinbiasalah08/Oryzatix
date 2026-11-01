"""Resume this interrupted run from its saved head checkpoint and frozen manifest."""
import csv
import json
import os
from pathlib import Path
import sys

RUN = Path(__file__).resolve().parent
ROOT = RUN.parent.parent
OUT = RUN / 'models'
sys.path.insert(0, str(ROOT / 'training'))
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'
for key in ('TMP', 'TEMP', 'TMPDIR'):
    os.environ[key] = str(OUT)
import numpy as np
import tensorflow as tf
import train_mobilenet as trainer

tf.config.threading.set_intra_op_parallelism_threads(4)
tf.config.threading.set_inter_op_parallelism_threads(2)
tf.keras.utils.set_random_seed(trainer.SEED)
with (OUT / 'training_manifest.csv').open(newline='', encoding='utf-8') as stream:
    rows = list(csv.DictReader(stream))
for row in rows:
    row['label'] = int(row['label'])
    if trainer.image_digest(Path(row['path'])) != row['sha256']:
        raise ValueError('Source image changed: ' + row['path'])
train_rows = [r for r in rows if r['split'] == 'train']
val_rows = [r for r in rows if r['split'] == 'validation']
test_rows = [r for r in rows if r['split'] == 'test']
train_ds = trainer.make_dataset(tf, train_rows, True, 16)
val_ds = trainer.make_dataset(tf, val_rows, False, 16)
test_ds = trainer.make_dataset(tf, test_rows, False, 16)
checkpoint = OUT / 'best_mobilenetv2_candidate.keras'
model = tf.keras.models.load_model(checkpoint)
baseline = float(model.evaluate(val_ds, verbose=2)[0])
print('Resuming best head checkpoint; validation loss:', baseline, flush=True)
base = next(layer for layer in model.layers if isinstance(layer, tf.keras.Model))
base.trainable = True
for layer in base.layers[:-30]:
    layer.trainable = False
for layer in base.layers:
    if isinstance(layer, tf.keras.layers.BatchNormalization):
        layer.trainable = False
model.compile(optimizer=tf.keras.optimizers.Adam(1e-5),
              loss='sparse_categorical_crossentropy', metrics=['accuracy'])
model.fit(train_ds, validation_data=val_ds, epochs=5, verbose=2,
          class_weight=trainer.compute_class_weights(train_rows), callbacks=[
              tf.keras.callbacks.EarlyStopping(monitor='val_loss', patience=3, restore_best_weights=True),
              tf.keras.callbacks.ReduceLROnPlateau(monitor='val_loss', factor=.2, patience=2, min_lr=1e-6),
              tf.keras.callbacks.ModelCheckpoint(str(checkpoint), monitor='val_loss',
                  save_best_only=True, initial_value_threshold=baseline),
          ])
model = tf.keras.models.load_model(checkpoint)
model.save(OUT / 'mobilenetv2_rice_leaf.keras')
metrics = trainer.evaluate_and_save(tf, model, test_ds, test_rows, OUT)

@tf.function(input_signature=[tf.TensorSpec([1, 224, 224, 3], tf.float32, name='rice_leaf_rgb_0_255')])
def serving(images):
    return {'rice_disease_probabilities': model(images, training=False)}

from tensorflow.python.framework.convert_to_constants import convert_variables_to_constants_v2
frozen = convert_variables_to_constants_v2(serving.get_concrete_function())
converter = tf.lite.TFLiteConverter.from_concrete_functions([frozen])
(OUT / 'mobilenetv2_rice_leaf.tflite').write_bytes(converter.convert())
print(json.dumps(metrics, indent=2), flush=True)
print('Resumed classifier training and export complete.', flush=True)
