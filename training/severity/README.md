# Automatic affected-area training

Status: training preparation only. No severity model has been trained or deployed.
The existing MobileNet disease model outputs disease probabilities, not affected area.

## Collect annotations

Open `annotate.html` in a browser. Select one or several photos and use the current-photo
selector or Previous/Next buttons. Load a saved mask for the current photo to resume
annotation or review it as an overlay. Load an image, paint the entire visible rice leaf
with **Leaf**, then mark symptomatic regions with **Affected**. Use **Background**
to correct strokes. Inspect the whole mask before exporting. The tool exports a PNG
mask; it does not decide which regions are diseased and does not provide expert review.

Mask classes are grayscale integer values: 0 = background, 1 = unaffected leaf,
2 = affected leaf. Affected pixels are part of the leaf denominator. Masks must use
the same dimensions and orientation as the EXIF-corrected source image. Keep the
original photos and exported masks together and get an agricultural reviewer to
check them. Do not use AI-generated percentages or classifier confidence as labels.

Start with clear individual leaves across all supported diseases, healthy leaves,
different severities, lighting, and backgrounds. Whole-field or overlapping-plant
images, including the example shown in the app, require a defined annotation
protocol; a single-leaf model should not claim whole-field severity. Sheath symptoms
also require a separate agreed definition of the target plant tissue.

Create a JSON manifest following `manifest.example.json`. Paths are relative to the
manifest. `reviewed` must be true only after actual review; fill in `reviewer`.
Group photos of the same plant/capture session and all derived copies under one
`group_id` and set `group_confirmed=true` only after confirming the grouping. Keep each group in exactly one of train, validation, or test. Stratify
those groups by disease and severity, and reserve independent field images for test.
The validator rejects duplicate images and groups crossing splits.

To prepare a reproducible starting batch from an existing disease manifest:

```powershell
.\.venv-training\Scripts\python.exe training/severity/prepare_batch.py --manifest outputs/all-datasets-retraining-2026-10-31/models/training_manifest.csv --output outputs/severity-annotation-pilot-2026-11-01
```

The pilot targets 360 images: 60 per rice class (40 train, 10 validation, 10 test).
It copies original images with neutral filenames, preserves existing disease splits,
and writes a summary and unreviewed manifest. It creates no masks and no predicted
severity labels. Review image suitability and revise capture groups/splits before
training; separate photos from the same plant may already cross the disease splits.
Existing disease test images cannot establish independent end-to-end field accuracy.
Export masks into the batch's `masks` folder using the generated filenames.

## Train and evaluate

Use the project's training environment (TensorFlow, NumPy, Pillow):

```powershell
.\.venv-training\Scripts\python.exe training/severity/train.py --manifest path/to/manifest.json --validate-only
.\.venv-training\Scripts\python.exe training/severity/train.py --manifest path/to/manifest.json --output outputs/severity-candidate --epochs 30
```

The pipeline uses a frozen pretrained MobileNetV2 encoder with a segmentation
decoder. It predicts background, unaffected leaf, and affected leaf for each pixel.
ImageNet encoder weights download on the first training run. Model inputs are RGB
float32 in 0–255, resized to 224×224; preprocessing is inside the model. Mask resize
uses nearest-neighbor interpolation to preserve class IDs.

Affected area = 100 × affected pixels / (unaffected leaf + affected pixels).
No predicted leaf means no severity result. The app's display bands are Mild ≤25%,
Moderate >25–60%, Severe >60%; these are project bands, not a claim of a validated
disease-specific agronomic grading standard. Healthy classification is a separate
decision; a zero-area estimate alone does not establish health.

Outputs are a candidate `.keras` model, `.tflite` model, and a held-out TFLite test
report with class IoU, area error, severity-band agreement, and failed-leaf counts.
Check small-lesion performance, background errors, per-disease coverage, and external
field performance before approving deployment. Low-resolution masks can miss small
lesions. Pixel accuracy alone is not a sufficient acceptance measure. Split assignment
and reviewer identity are supplied by the dataset author, not independently verified
by the script. An exported model is not automatically a validated model.

## Deployment still required

After adequate reviewed data and satisfactory evaluation, integrate the approved
model into server inference and browser/native offline inference. Store its predicted
area, model version, and `model_estimated` source with each scan. The UI must identify
the output as a model estimate, not `expert_validated`. Cache the exported TFLite
asset for offline use and measure performance on target phones. This preparation
does not alter existing models, production scans, or manually saved assessments.

Architecture reference: https://www.tensorflow.org/tutorials/images/segmentation
