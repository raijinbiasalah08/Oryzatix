# Train and run the Oryzatix scan models

## Scanner orientation upgrade — October 31, 2026

The server now averages the disease model's probabilities over the original image and its 90°, 180°, and 270° rotations. Each view contains the entire leaf image. The interpreter is reused across views. The rice/non-rice and focus gates still run before disease classification. This costs four disease-model invocations per scan instead of one.

The four-view policy was selected on the existing validation split, with class thresholds calibrated there to at least 95% empirical precision and at least 20 accepted examples. The existing frozen test split was then evaluated once. On its 1,074 images, classification accuracy rose from 94.79% to 96.09%; on the 599 rice images alone, it rose from 90.65% to 92.99%. Leaf Blast recall rose from 75.64% to 84.62% (78 images), and Brown Spot recall rose from 89.02% to 90.24% (164 images).

Thresholded disease acceptance increased from 486 to 533 test images, with 472 versus 514 correct accepted results. Precision among accepted results decreased from 97.12% to 96.44%. These are classifier measurements before the rice/focus gates and reference-match fallback, not end-to-end field accuracy. Existing limitations around limited scene negatives, synthetic blur, and dataset representativeness still apply. Scores are model probabilities, not calibrated guarantees of correctness.

The reproducible report is in `training/reports/scanner-orientations-2026-10-31/evaluation.json`. Model and manifest SHA-256 identifiers record the exact inputs. Reproduce calibration with:

```powershell
.\.venv-training\Scripts\python.exe training/calibrate_scan_orientations.py --manifest outputs/brown-spot-retraining-2026-10-23/candidate-01/training_manifest.csv --output outputs/scanner-orientation-candidate
```

The command writes candidate thresholds without deploying them. Only use four-view thresholds with the same disease model; server inference rejects a mismatched model hash. After retraining, recalibrate before replacing model assets. Legacy threshold files without `orientation_views` retain single-view behavior.

Oryzatix uses two separate MobileNetV2 models during server-side scans: a binary rice-leaf versus non-rice-image validator, followed by the existing disease classifier. The disease classifier is not treated as a non-rice rejection model. The scan pipeline also applies an image-focus check and rejects malformed probabilities or predictions below validation-derived class thresholds.

The disease training workflow trains a seven-class rice-leaf classifier, evaluates it on a held-out test split, and exports Keras and TensorFlow Lite models. It uses exact SHA-256 deduplication, excludes identical images assigned to different labels, and writes a deterministic 70/15/15 class-stratified manifest. Offline images inside an `Augmented` subfolder are excluded before splitting to prevent near-duplicate leakage; online augmentation is applied to training batches instead. Files under the Brown Spot folder whose filename or subfolder explicitly identifies Leaf Blast are excluded from Brown Spot and listed with excluded augmented images in `dataset_exclusions.csv`; Leaf Blast images belong in the dedicated `leaf_blast` folder. It does not move or rewrite source images.

The rice-leaf folders are Bacterial Leaf Blight, Brown Spot, Healthy Rice Leaf, Sheath Blight, Tungro, and `leaf_blast`. `Not Rice Leaf` is the negative class and may contain any number of nested folders, such as `people`, `objects`, and `other_plants`.

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

The validator trainer includes one person photo and the available bookstore scene as negative training examples; it reserves the second person photo for validation. It calibrates its rice/non-rice threshold, blur cutoff, and disease-class confidence cutoffs from validation data and actual exported TFLite outputs. `--calibrate-only` recalculates those reports from the current model artifacts without retraining. A class with no validated threshold is not auto-diagnosed.

Training writes models, labels, split manifests, and reports under `storage/app/models`. The binary validator and calibration JSON are copied into `oryzatix_app/assets/models`, which is also used by the server pipeline. Review `scan_validation_thresholds.json` and the test report after each training run.

Current workspace limitation: the training split contains 1 person image and 1 bookstore scene; the validation split has only the other person image. The object-image result is therefore a training-fit check, not an independent object test. The non-rice-leaf test split contains PlantVillage apple leaves captured under controlled conditions. The blur check has been calibrated with synthetic blur, because there is no independently labeled real blurry-photo set. These results do not establish reliable rejection of every possible plant, object, person, or photo condition. Add varied, labeled field negatives and real blurry examples, retrain, and evaluate them on a separately held-out scene test set before relying on broad field rejection. The source folders are imbalanced, and this workflow does not create a separate lesion-area severity model.
