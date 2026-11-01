# All-dataset retraining — October 31, 2026

Status: completed November 1, 2026. Both models trained, exported and calibrated. Candidate artifacts only; the app's active models have not been replaced.

## Final results

The disease classifier scored 95.36% with one view and 95.79% with four rotations on 1,165 held-out test images. Four views were selected using validation data. Rice-class-only accuracy with four views was 93.04%. Among 625 accepted disease predictions, 602 were correct (96.32% precision); these classifier results precede the rice/focus gates and do not measure the complete scan pipeline.

| Class | Test images | Four-view recall |
|---|---:|---:|
| Bacterial Leaf Blight | 39 | 97.44% |
| Brown Spot | 164 | 89.63% |
| Healthy Rice Leaf | 159 | 96.23% |
| Sheath Blight | 131 | 98.47% |
| Tungro | 119 | 94.96% |
| Leaf Blast | 78 | 79.49% |
| Not Rice Leaf | 475 | 99.79% |

The separately retrained binary validator used 4,613 unique rice images and 3,167 negatives. It accepted all 692 rice images and rejected all 475 other-plant images in its 1,167-image test split. There is no independent object-scene test set, and only one held-out person image. Controlled other-plant images do not establish rejection performance for arbitrary field scenes.

The final matched bundle is in `models/`: `mobilenetv2_rice_leaf.keras`, `mobilenetv2_rice_leaf.tflite`, `rice_leaf_validator.keras`, `rice_leaf_validator.tflite`, `class_labels.json`, and `scan_validation_thresholds.json`. Both split manifests and exclusions are retained there. The final thresholds select four views and identify the exact disease model by SHA-256. Original single-view thresholds are preserved in `single-view-thresholds.json`.

The first disease TFLite export produced NaNs; explicit variable freezing corrected it. `export_verification.json` records finite predictions and Keras/TFLite parity on one held-out image from each of the seven classes. The full orientation test also verified the corrected TFLite model. Binary calibration and evaluation ran against the exported binary TFLite model. The reusable validator exporter now checks finite outputs and Keras parity before writing exports.

Detailed evaluations: `models/evaluation.json` (single-view classifier), `orientations/evaluation.json` (both scanning policies), and `models/scan_validation_thresholds.json` (validator, focus and confidence calibration). No direct improvement claim over the previously deployed model is made because the dataset and test split changed.

## Dataset

The user requested retraining after adding Sheath Blight images and selected the policy of using valid unique images with separate validation/test splits. Source images are not modified. Offline augmented copies and explicit Leaf Blast filenames inside Brown Spot are excluded by the existing trainer. Exact duplicates are deduplicated and conflicting labels are excluded.

| Class | Training | Validation | Test | Total unique images |
|---|---:|---:|---:|---:|
| Bacterial Leaf Blight | 184 | 39 | 39 | 262 |
| Brown Spot | 764 | 164 | 164 | 1,092 |
| Healthy Rice Leaf | 740 | 159 | 159 | 1,058 |
| Sheath Blight | 613 | 131 | 131 | 875 |
| Tungro | 554 | 119 | 119 | 792 |
| Leaf Blast | 365 | 78 | 78 | 521 |
| Not Rice Leaf | 2,217 | 475 | 475 | 3,167 |
| Total | 5,437 | 1,165 | 1,165 | 7,767 |

Sheath Blight increased from 267 to 875 usable unique images (+608). The source folder contains 879 files. The classifier excludes 716 offline augmented files and 24 explicit Leaf Blast filenames within Brown Spot. One exact image hash assigned to multiple classes is also excluded.

The classifier starts from ImageNet MobileNetV2 weights. The initial run completed five head-training epochs and became unresponsive at epoch six. Its best checkpoint (validation loss 0.1685576, accuracy 94.16%) was preserved. Training resumed from this checkpoint for up to five fine-tuning epochs using `resume_training.py`, which verifies every source hash against the original manifest before training. The best validation-loss checkpoint across the preserved head and fine-tuning stages is selected.

The image pipeline was corrected to shuffle file paths before decoding instead of buffering thousands of decoded float32 images. Decoding parallelism is limited to two and prefetch to one batch. Recovery uses batch size 16 and four TensorFlow compute threads. The separate validator pipeline also bounds decoding/prefetch memory.

Splits use the existing deterministic 70/15/15 procedure. These are internal image splits, not independent farm-level field validation; exact-hash deduplication does not establish independence of similar images from the same plant.

Artifacts and logs are saved in this directory. Runtime model assets are not automatically replaced because a separate output directory is used.

## Reproduce

```powershell
.\.venv-training\Scripts\python.exe -u training/train_mobilenet.py train --output-dir outputs/all-datasets-retraining-2026-10-31/models
.\.venv-training\Scripts\python.exe -u training/train_rice_leaf_validator.py --output-dir outputs/all-datasets-retraining-2026-10-31/models
.\.venv-training\Scripts\python.exe -u training/calibrate_scan_orientations.py --manifest outputs/all-datasets-retraining-2026-10-31/models/training_manifest.csv --assets outputs/all-datasets-retraining-2026-10-31/models --output outputs/all-datasets-retraining-2026-10-31/orientations
```

These commands describe the workflow; use a new output directory for a new run. The actual interrupted classifier run was recovered with `resume_training.py` and its export repaired/verified with `verify_export.py`, as documented above.
