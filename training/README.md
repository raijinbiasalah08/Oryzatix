# Train and run MobileNetV2

This workflow trains a five-class rice-leaf classifier from the image folders at the project root, evaluates it on a held-out test split, and exports both Keras and TensorFlow Lite models. It uses exact SHA-256 deduplication, excludes identical images assigned to different labels, and writes a deterministic 70/15/15 class-stratified manifest. It does not move or rewrite source images.

The included folders are Bacterial Leaf Blight, Brown Spot, Healthy Rice Leaf, Sheath Blight, and Tungro. Leaf Blast is omitted because there is no corresponding populated image folder.

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

The model expects a 224×224 RGB image with pixel values in the 0–255 range; the model itself performs MobileNetV2 preprocessing. The `confidence` field is the winning class probability, not an estimate of lesion severity. Predictions below 0.55 are marked `needs_review`.

Training writes model files, labels, a split manifest, and test metrics under `storage/app/models`. Review per-class test results, especially for Sheath Blight, before using the model for farm decisions. The source folders are imbalanced, and this workflow does not create a separate lesion-area severity model.
