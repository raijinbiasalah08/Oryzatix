# Oryzatix training candidate — 2026-10-16

Status: trained and evaluated locally. After evaluation, the candidate TFLite disease classifier, rice-leaf validator, and their matching calibrated thresholds were promoted into the Flutter/Laravel runtime asset folder for version-controlled deployment. The metrics below describe internal image splits, not guaranteed field accuracy.

## Disease classifier

- Architecture/workflow: six-class MobileNetV2, with disease classes plus `not_rice_leaf`.
- Usable images: 6,629 after image validation and exact-hash duplicate handling; one exact image with conflicting class labels was excluded.
- Held-out test: 995 images. This candidate and the current model use the exact same test-image hashes and labels.
- Candidate accuracy: 97.69% (current model: 95.48%), an increase of 2.21 percentage points on that test set.

| Class | Test images | Current accuracy | Candidate accuracy |
|---|---:|---:|---:|
| BLB | 147 | 94.56% | 96.60% |
| Brown Spot | 55 | 92.73% | 98.18% |
| Healthy | 159 | 85.53% | 94.97% |
| Sheath Blight | 40 | 100.00% | 100.00% |
| Tungro | 119 | 91.60% | 92.44% |
| Not Rice Leaf | 475 | 100.00% | 100.00% |

The disease model does not include Leaf Blast. Its 100% non-rice test score comes from the `other_plant` test subset, primarily controlled PlantVillage apple-leaf images. This is not evidence of rejection performance on all people, objects, or field scenes. The random image split also does not group near-duplicate or augmented siblings, so the score may be optimistic for new farms or camera conditions.

## Rice-leaf validator

- Training data: 2,747 rice-leaf images and 3,167 negatives (3,164 other-plant images, 2 people images, 1 object image).
- Held-out test: 887 images (412 rice leaves and 475 other plants); 100% rice recall and 100% other-plant specificity at the calibrated threshold.
- The test split contains no people or object scenes. The validation challenge has one people photo and no object photos; the object scene was used in training. These results do not establish broad people/object rejection.
- Rice-leaf acceptance threshold: 0.5044519, calibrated on the validation split.
- Blur check: 100% rejection of synthetic Gaussian blur at the tested setting and 98.99% acceptance of sharp validation images. No independently labeled real-blurry-photo set exists.

## Not trained

No severity/affected-area model was trained. The project does not have independent expert measurements of symptomatic area. The earlier provisional visual estimates must not be used as ground truth or to claim severity accuracy.

## Files

- `mobilenetv2_rice_leaf.keras` and `mobilenetv2_rice_leaf.tflite`: disease-classifier candidate.
- `rice_leaf_validator.keras` and `rice_leaf_validator.tflite`: binary rice-leaf gate candidate.
- `evaluation.json`: disease test metrics and confusion matrix.
- `scan_validation_thresholds.json`: validator results and calibrated thresholds.
- `training_manifest.csv` and `rice_leaf_validator_manifest.csv`: deterministic data splits.

## Training-script fix

`training/train_rice_leaf_validator.py` now uses absolute paths for restricted Windows workspaces, exports a fixed `[1, 224, 224, 3]` TFLite signature through concrete functions to work with TensorFlow 2.20/Keras 3, places TensorFlow temporary files beside candidate outputs, and only copies models into Flutter assets when training the production model directory. Candidate runs no longer overwrite app assets.
