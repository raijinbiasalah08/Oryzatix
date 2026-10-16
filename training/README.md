# Train and run the Oryzatix scan models

Oryzatix uses two separate MobileNetV2 models during server-side scans: a binary rice-leaf versus non-rice-image validator, followed by the existing disease classifier. The disease classifier is not treated as a non-rice rejection model. The scan pipeline also applies an image-focus check and rejects malformed probabilities or predictions below validation-derived class thresholds.

The disease training workflow trains a six-class rice-leaf classifier, evaluates it on a held-out test split, and exports Keras and TensorFlow Lite models. It uses exact SHA-256 deduplication, excludes identical images assigned to different labels, and writes a deterministic 70/15/15 class-stratified manifest. Files under the Brown Spot folder whose filename or subfolder explicitly identifies Leaf Blast are excluded from disease training and listed in `dataset_exclusions.csv`; no Leaf Blast disease output class currently exists. It does not move or rewrite source images.

The rice-leaf folders are Bacterial Leaf Blight, Brown Spot, Healthy Rice Leaf, Sheath Blight, and Tungro. `Not Rice Leaf` is the negative class and may contain any number of nested folders, such as `people`, `objects`, and `other_plants`. Leaf Blast is omitted because there is no corresponding populated image folder.

Negative images must clearly show something other than a rice leaf. The local `Not Rice Leaf/other_plants` folder can contain the PlantVillage apple-leaf subset; it is licensed CC BY 4.0 and should be attributed to Derya Birant. The subset was collected under controlled conditions, so add field photos of non-rice plants before relying on this class for field use. Keep people, objects, and plant examples varied, and avoid placing rice leaves in the negative folder.

## Windows setup

Use 64-bit Python 3.9 or newer. TensorFlow 2.20 supports the system Python 3.9 currently available on this machine; TensorFlow 2.21 no longer supports Python 3.9. The package installs CPU support on native Windows. Create and activate a project-local virtual environment, then install the optional training dependencies:

```powershell
py -3.9 -m venv .venv-training
.\.venv-training\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -r requirements-training.txt
```

Train the disease classifier and export its evaluation report and TFLite model:

```powershell
python training\train_mobilenet.py train
```

Classify an image with the resulting TFLite model:

```powershell
python training\train_mobilenet.py predict "D:\path\to\rice-leaf.jpg"
```

The model expects a 224×224 RGB image with pixel values in the 0–255 range; the model itself performs MobileNetV2 preprocessing. The `confidence` field is the winning class probability, not an estimate of lesion severity. Predictions below 0.55 are marked `needs_review`. A confident `not_rice_leaf` prediction is returned as an unsupported-image result and is not saved as a disease scan.

Train the separate rice-leaf validator after adding or changing either the rice or negative-image folders:

```powershell
python training\train_rice_leaf_validator.py
```

The validator trainer includes one person photo and the available bookstore scene as negative training examples; it reserves the second person photo for validation. It calibrates its rice/non-rice threshold, blur cutoff, and disease-class confidence cutoffs from validation data and actual exported TFLite outputs. `--calibrate-only` recalculates those reports from the current model artifacts without retraining. A class with no validated threshold is not auto-diagnosed. Leaf Blast is not a disease-model output class; existing exact-reference matches remain available after the image passes the rice-leaf and focus checks.

Training writes models, labels, split manifests, and reports under `storage/app/models`. The binary validator and calibration JSON are copied into `oryzatix_app/assets/models`, which is also used by the server pipeline. Review `scan_validation_thresholds.json` and the test report after each training run.

Current workspace limitation: the training split contains 1 person image and 1 bookstore scene; the validation split has only the other person image. The object-image result is therefore a training-fit check, not an independent object test. The non-rice-leaf test split contains PlantVillage apple leaves captured under controlled conditions. The blur check has been calibrated with synthetic blur, because there is no independently labeled real blurry-photo set. These results do not establish reliable rejection of every possible plant, object, person, or photo condition. Add varied, labeled field negatives and real blurry examples, retrain, and evaluate them on a separately held-out scene test set before relying on broad field rejection. The source folders are imbalanced, and this workflow does not create a separate lesion-area severity model.
