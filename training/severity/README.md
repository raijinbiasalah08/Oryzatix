# Segmentation-based automatic severity level

The selected approach segments background, unaffected leaf, and symptomatic leaf tissue with a MobileNetV2 model. The app should convert the internal mask to Mild, Moderate, or Severe and display only that category. The internal project bands are Mild up to 25%, Moderate over 25% through 60%, and Severe over 60%; no affected-area number is returned by the training evaluation report or intended scanner result.

## Annotate the pilot masks

The pilot contains 360 balanced images: 60 diseased images per supported disease plus 60 healthy images. The mask folder currently has no annotations, so supervised training with reviewed masks cannot start yet.

1. Open `outputs/severity-annotation-pilot-2026-11-01/annotate.html` in a browser.
2. Select photos from that batch's `images` folder. For each photo, paint all visible rice leaf as **Leaf**, then paint visibly symptomatic tissue as **Affected**. Leave background black. Use individual leaves; exclude scenes where leaf boundaries or symptoms cannot be judged consistently.
3. Export each mask and move it to that batch's `masks` folder, preserving the exported filename. The filename must match the `mask` path in `manifest.json`.
4. Have an agricultural reviewer check each mask against the original. In `manifest.json`, set `reviewed` to true and enter the reviewer's name only after approval. Confirm plant/capture groups and keep each group in one of train, validation, or test.
5. Run the manifest preflight before training:

```powershell
.\.venv-training\Scripts\python.exe training\severity\train.py --manifest outputs\severity-annotation-pilot-2026-11-01\manifest.json --validate-only
```

6. After preflight succeeds, train and evaluate a candidate:

```powershell
.\.venv-training\Scripts\python.exe training\severity\train.py --manifest outputs\severity-annotation-pilot-2026-11-01\manifest.json --output outputs\severity-segmentation-candidate --epochs 30
```

The output includes a `.tflite` segmentation candidate and held-out severity-level agreement, per-disease agreement, a level confusion matrix, and tissue-mask IoU. It does not include affected-area percentages. The trainer labels every output as a candidate and does not deploy it.

## Accuracy and deployment

The 360-image pilot is only a starting point. Correct its disease labels and group assignments, and evaluate on independent field images before claiming accuracy. A segmentation candidate must also be checked for missed small lesions, background mistakes, and performance on each disease. The app should only run severity classification after the disease detector finds a supported disease; healthy or unscorable images should not be labeled Mild.

Once an approved candidate exists, integrate its TFLite output in online and offline scanner paths, map the mask to a category internally, and store/display only that category. Until that model is trained and validated, the scanner must keep severity unassessed rather than substitute disease confidence or color heuristics.

`annotate_levels.html` and `train_level_classifier.py` are the alternate direct-category workflow. They are not the selected path for this project.

## Experimental training without reviewed masks

`train_experimental_masks.py` can train a separate MobileNetV2 segmentation prototype from automatic color-rule masks. These are pseudo-labels, never expert annotations. The script preserves existing splits, verifies source image hashes and decoded uniqueness, logs unusable masks, and sets `reviewed=false` for all generated masks. Frozen MobileNetV2 features feed a small trainable decoder; the exported TFLite model accepts RGB 0–255 images at 224×224.

```powershell
.\.venv-training\Scripts\python.exe training\severity\train_experimental_masks.py --manifest outputs\severity-level-labeling\manifest.json --data-root . --output outputs\severity-experimental-new-run --epochs 12
```

Outputs include the draft masks, a preview, a draft manifest, cached features, Keras/TFLite models, and a report. The report explicitly sets actual severity accuracy to null: its test references come from the same rules and are not independent ground truth. Color rules can mistake soil, shadows, pale lesions, and non-disease discoloration for symptoms or background. Capture groups also remain unconfirmed. Review real masks and collect an independent evaluation before using this candidate in the scanner. This script never publishes a model or changes production assets.
