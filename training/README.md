# Train and run MobileNetV2

This workflow trains a six-class rice-leaf classifier from the image folders at the project root, evaluates it on a held-out test split, and exports both Keras and TensorFlow Lite models. It uses exact SHA-256 deduplication, excludes identical images assigned to different labels, and writes a deterministic 70/15/15 class-stratified manifest. It does not move or rewrite source images.

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

Train the classifier and export its evaluation report and TFLite model:

```powershell
python training\train_mobilenet.py train
```

Classify an image with the resulting TFLite model:

```powershell
python training\train_mobilenet.py predict "D:\path\to\rice-leaf.jpg"
```

The model expects a 224×224 RGB image with pixel values in the 0–255 range; the model itself performs MobileNetV2 preprocessing. The `confidence` field is the winning class probability, not an estimate of lesion severity. Predictions below 0.55 are marked `needs_review`. A confident `not_rice_leaf` prediction is returned as an unsupported-image result and is not saved as a disease scan.

Training writes model files, labels, a split manifest, and test metrics under `storage/app/models`. When the default output directory is used, the TensorFlow Lite model and labels are also copied into `oryzatix_app/assets/models` for the mobile app and server inference. Review the per-class test results, especially the `not_rice_leaf` recall, before using the model for farm decisions. The source folders are imbalanced, and this workflow does not create a separate lesion-area severity model.
