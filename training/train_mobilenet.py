#!/usr/bin/env python3
"""Train and run a MobileNetV2 rice-leaf image classifier."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import random
import shutil
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError


PROJECT_ROOT = Path(__file__).absolute().parents[1]
DEFAULT_DATA_ROOT = PROJECT_ROOT
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "storage" / "app" / "models"
IMAGE_SIZE = 224
SEED = 42
SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}
CLASSES = [
    {"key": "blb", "name": "Bacterial Leaf Blight", "folder": "Bacterial Leaf Blight"},
    {"key": "brown_spot", "name": "Brown Spot", "folder": "Brown Spot"},
    {"key": "healthy", "name": "Healthy Rice Leaf", "folder": "Healthy Rice Leaf"},
    {"key": "sheath_blight", "name": "Sheath Blight", "folder": "Sheath Blight"},
    {"key": "tungro", "name": "Tungro", "folder": "Tungro"},
    {"key": "not_rice_leaf", "name": "Not a Rice Leaf", "folder": "Not Rice Leaf"},
]


def image_digest(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as image_file:
        for chunk in iter(lambda: image_file.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def collect_images(data_root: Path) -> tuple[list[dict[str, Any]], dict[str, int], list[dict[str, str]]]:
    by_digest: dict[str, list[dict[str, Any]]] = defaultdict(list)
    invalid: list[str] = []
    excluded_mislabels: list[dict[str, str]] = []

    for class_index, class_info in enumerate(CLASSES):
        class_dir = data_root / class_info["folder"]
        if not class_dir.is_dir():
            raise FileNotFoundError(f"Missing class folder: {class_dir}")

        for path in sorted(class_dir.rglob("*")):
            if not path.is_file() or path.suffix.lower() not in SUPPORTED_EXTENSIONS:
                continue

            # The source Brown Spot directory also contains files explicitly
            # named Leaf_Blast. Since Leaf Blast is not a disease output class,
            # training those examples as Brown Spot teaches the wrong label.
            # Keep them available to the binary rice-leaf validator, but omit
            # them from this disease classifier until a dedicated Blast class
            # and independently confirmed labels are supplied.
            relative_label_path = " ".join(path.relative_to(class_dir).with_suffix("").parts)
            normalized_label_path = relative_label_path.casefold().replace("_", " ").replace("-", " ")
            if class_info["key"] == "brown_spot" and "blast" in normalized_label_path.split():
                excluded_mislabels.append({
                    "path": str(path.absolute()),
                    "assigned_folder": class_info["key"],
                    "reason": "filename_or_folder_identifies_leaf_blast_but_no_blast_output_class_exists",
                })
                continue

            try:
                with Image.open(path) as image:
                    image.verify()
                by_digest[image_digest(path)].append({
                    "path": path.absolute(),
                    "label": class_index,
                    "class_key": class_info["key"],
                })
            except (OSError, UnidentifiedImageError, ValueError):
                invalid.append(str(path))

    records: list[dict[str, Any]] = []
    ambiguous_hashes = 0
    for digest, copies in by_digest.items():
        labels = {copy["label"] for copy in copies}
        if len(labels) != 1:
            ambiguous_hashes += 1
            continue
        # Keep one copy per exact image so duplicates cannot leak across splits.
        records.append({**copies[0], "sha256": digest})

    counts = {class_info["key"]: 0 for class_info in CLASSES}
    for record in records:
        counts[record["class_key"]] += 1

    if invalid:
        print(f"Skipped {len(invalid)} unreadable image(s).", file=sys.stderr)
    if ambiguous_hashes:
        print(
            f"Skipped {ambiguous_hashes} exact image(s) labeled as more than one class.",
            file=sys.stderr,
        )
    return records, counts, excluded_mislabels


def stratified_split(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    rng = random.Random(SEED)
    split_records: list[dict[str, Any]] = []

    for class_index, class_info in enumerate(CLASSES):
        class_records = [row for row in records if row["label"] == class_index]
        if len(class_records) < 20:
            raise ValueError(
                f"Class {class_info['name']} has only {len(class_records)} usable images; "
                "at least 20 are required to create reliable splits."
            )
        rng.shuffle(class_records)
        validation_count = max(1, round(len(class_records) * 0.15))
        test_count = max(1, round(len(class_records) * 0.15))
        train_count = len(class_records) - validation_count - test_count
        if train_count < 1:
            raise ValueError(f"Not enough images to split class {class_info['name']}.")

        for index, record in enumerate(class_records):
            split = "train" if index < train_count else (
                "validation" if index < train_count + validation_count else "test"
            )
            split_records.append({**record, "split": split})

    return split_records


def save_manifest(output_dir: Path, split_records: list[dict[str, Any]]) -> None:
    manifest_path = output_dir / "training_manifest.csv"
    with manifest_path.open("w", newline="", encoding="utf-8") as manifest_file:
        writer = csv.DictWriter(manifest_file, fieldnames=["path", "class_key", "label", "split", "sha256"])
        writer.writeheader()
        for row in split_records:
            writer.writerow({**row, "path": str(row["path"])})

    labels = [{"index": i, "key": row["key"], "name": row["name"]} for i, row in enumerate(CLASSES)]
    (output_dir / "class_labels.json").write_text(
        json.dumps(labels, indent=2) + "\n", encoding="utf-8"
    )


def make_dataset(tf: Any, records: list[dict[str, Any]], training: bool, batch_size: int):
    paths = [str(row["path"]) for row in records]
    labels = [row["label"] for row in records]
    dataset = tf.data.Dataset.from_tensor_slices((paths, labels))

    def load_image(path, label):
        def decode_pillow(path_bytes):
            image_path = Path(os.fsdecode(path_bytes))
            with Image.open(image_path) as source:
                image = ImageOps.exif_transpose(source).convert("RGB").resize(
                    (IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR
                )
                return np.asarray(image, dtype=np.uint8)

        image = tf.numpy_function(decode_pillow, [path], Tout=tf.uint8)
        # Match the deployed Pillow RGB + EXIF + bilinear preprocessing exactly.
        # Previously training resized with TensorFlow antialiasing, while the
        # server and validation threshold calibration used Pillow bilinear.
        image.set_shape([IMAGE_SIZE, IMAGE_SIZE, 3])
        image = tf.cast(image, tf.float32)
        if training:
            image = tf.image.random_flip_left_right(image, seed=SEED)
            image = tf.image.rot90(image, k=tf.random.uniform([], 0, 4, dtype=tf.int32))
            image = tf.image.random_contrast(image, lower=0.9, upper=1.1)
            image = tf.clip_by_value(image, 0.0, 255.0)
        return image, label

    dataset = dataset.map(load_image, num_parallel_calls=tf.data.AUTOTUNE)
    if training:
        dataset = dataset.shuffle(len(records), seed=SEED, reshuffle_each_iteration=True)
    return dataset.batch(batch_size).prefetch(tf.data.AUTOTUNE)


def build_model(tf: Any):
    base_model = tf.keras.applications.MobileNetV2(
        input_shape=(IMAGE_SIZE, IMAGE_SIZE, 3),
        include_top=False,
        weights="imagenet",
    )
    base_model.trainable = False

    inputs = tf.keras.Input(shape=(IMAGE_SIZE, IMAGE_SIZE, 3), name="rice_leaf_rgb_0_255")
    # Keeping preprocessing inside the model makes Python and TFLite inputs consistent.
    x = tf.keras.layers.Rescaling(1.0 / 127.5, offset=-1, name="mobilenetv2_preprocess")(inputs)
    x = base_model(x, training=False)
    x = tf.keras.layers.GlobalAveragePooling2D()(x)
    x = tf.keras.layers.Dropout(0.25)(x)
    outputs = tf.keras.layers.Dense(len(CLASSES), activation="softmax", name="rice_disease_probabilities")(x)
    return tf.keras.Model(inputs, outputs), base_model


def compute_class_weights(train_records: list[dict[str, Any]]) -> dict[int, float]:
    counts = {index: sum(row["label"] == index for row in train_records) for index in range(len(CLASSES))}
    total = sum(counts.values())
    return {index: total / (len(CLASSES) * count) for index, count in counts.items()}


def evaluate_and_save(tf: Any, model: Any, test_ds: Any, test_records: list[dict[str, Any]], output_dir: Path) -> dict[str, Any]:
    loss, accuracy = model.evaluate(test_ds, verbose=2)
    probabilities = model.predict(test_ds, verbose=0)
    truth = np.asarray([row["label"] for row in test_records], dtype=np.int32)
    predicted = np.argmax(probabilities, axis=1)
    confusion = np.zeros((len(CLASSES), len(CLASSES)), dtype=int)
    for actual, guess in zip(truth, predicted):
        confusion[actual, guess] += 1

    per_class = {}
    for index, class_info in enumerate(CLASSES):
        total = int(confusion[index].sum())
        per_class[class_info["key"]] = {
            "test_images": total,
            "accuracy": round(float(confusion[index, index] / total), 4) if total else None,
        }

    metrics = {
        "test_loss": float(loss),
        "test_accuracy": float(accuracy),
        "test_image_count": int(len(test_records)),
        "confusion_matrix": confusion.tolist(),
        "class_order": [row["key"] for row in CLASSES],
        "per_class": per_class,
    }
    (output_dir / "evaluation.json").write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    return metrics


def train(args: argparse.Namespace) -> None:
    args.output_dir.mkdir(parents=True, exist_ok=True)
    if os.name == "nt":
        # TensorFlow's checkpoint writer can fail to rename its temporary
        # files from the restricted Windows system temp directory. Keep its
        # intermediate files beside the selected model outputs instead.
        training_temp_dir = str(args.output_dir.absolute())
        for name in ("TMPDIR", "TEMP", "TMP"):
            os.environ[name] = training_temp_dir

    try:
        import tensorflow as tf
    except ImportError as error:
        raise SystemExit(
            "TensorFlow is required. Install the packages from requirements-training.txt first."
        ) from error

    random.seed(SEED)
    np.random.seed(SEED)
    tf.random.set_seed(SEED)

    records, counts, excluded_mislabels = collect_images(args.data_root.absolute())
    split_records = stratified_split(records)
    save_manifest(args.output_dir, split_records)
    exclusions_path = args.output_dir / "dataset_exclusions.csv"
    with exclusions_path.open("w", newline="", encoding="utf-8") as exclusions_file:
        writer = csv.DictWriter(exclusions_file, fieldnames=["path", "assigned_folder", "reason"])
        writer.writeheader()
        writer.writerows(excluded_mislabels)
    print("Usable images by class:")
    for class_info in CLASSES:
        print(f"  {class_info['name']}: {counts[class_info['key']]}")
    print(f"Wrote stratified split manifest to {args.output_dir / 'training_manifest.csv'}")
    if excluded_mislabels:
        print(
            f"Excluded {len(excluded_mislabels)} likely mislabeled Leaf Blast image(s) from Brown Spot; "
            f"see {exclusions_path}."
        )

    train_records = [row for row in split_records if row["split"] == "train"]
    validation_records = [row for row in split_records if row["split"] == "validation"]
    test_records = [row for row in split_records if row["split"] == "test"]
    train_ds = make_dataset(tf, train_records, training=True, batch_size=args.batch_size)
    validation_ds = make_dataset(tf, validation_records, training=False, batch_size=args.batch_size)
    test_ds = make_dataset(tf, test_records, training=False, batch_size=args.batch_size)

    model, base_model = build_model(tf)
    candidate_checkpoint_path = args.output_dir / "best_mobilenetv2_candidate.keras"
    candidate_checkpoint_path.unlink(missing_ok=True)
    # Keras formats checkpoint paths even when no replacement fields are used.
    # Escape braces so valid Windows temp paths containing {GUID} work.
    checkpoint_filepath = str(candidate_checkpoint_path).replace("{", "{{").replace("}", "}}")
    callbacks = [
        tf.keras.callbacks.EarlyStopping(monitor="val_loss", patience=3, restore_best_weights=True),
        tf.keras.callbacks.ReduceLROnPlateau(monitor="val_loss", factor=0.2, patience=2, min_lr=1e-6),
        tf.keras.callbacks.ModelCheckpoint(
            filepath=checkpoint_filepath,
            monitor="val_loss",
            save_best_only=True,
        ),
    ]

    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=1e-3),
        loss="sparse_categorical_crossentropy",
        metrics=["accuracy"],
    )
    model.fit(
        train_ds,
        validation_data=validation_ds,
        epochs=args.head_epochs,
        class_weight=compute_class_weights(train_records),
        callbacks=callbacks,
    )

    # Fine-tune only the tail of the pretrained backbone; keep BatchNorm stable.
    base_model.trainable = True
    for layer in base_model.layers[:-30]:
        layer.trainable = False
    for layer in base_model.layers:
        if isinstance(layer, tf.keras.layers.BatchNormalization):
            layer.trainable = False

    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=1e-5),
        loss="sparse_categorical_crossentropy",
        metrics=["accuracy"],
    )
    model.fit(
        train_ds,
        validation_data=validation_ds,
        epochs=args.fine_tune_epochs,
        class_weight=compute_class_weights(train_records),
        callbacks=callbacks,
    )

    model = tf.keras.models.load_model(candidate_checkpoint_path)
    final_model_path = args.output_dir / "mobilenetv2_rice_leaf.keras"
    model.save(final_model_path)

    metrics = evaluate_and_save(tf, model, test_ds, test_records, args.output_dir)
    # TensorFlow 2.20 bundles Keras 3, where from_keras_model() relies on a
    # removed private Keras API. Export through SavedModel using the fixed
    # single-image tensor contract expected by server inference.
    saved_model_dir = args.output_dir / "mobilenetv2_tflite_export"
    if saved_model_dir.exists():
        shutil.rmtree(saved_model_dir)
    model.export(str(saved_model_dir), verbose=False)
    converter = tf.lite.TFLiteConverter.from_saved_model(str(saved_model_dir))
    tflite_model = converter.convert()
    tflite_path = args.output_dir / "mobilenetv2_rice_leaf.tflite"
    tflite_path.write_bytes(tflite_model)
    shutil.copy2(candidate_checkpoint_path, args.output_dir / "best_mobilenetv2.keras")
    candidate_checkpoint_path.unlink()

    if args.output_dir.absolute() == DEFAULT_OUTPUT_DIR.absolute():
        app_model_dir = PROJECT_ROOT / "oryzatix_app" / "assets" / "models"
        app_model_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(tflite_path, app_model_dir / tflite_path.name)
        shutil.copy2(args.output_dir / "class_labels.json", app_model_dir / "class_labels.json")
        print(f"Synced TensorFlow Lite model and labels to {app_model_dir}")

    print(f"Keras model: {final_model_path}")
    print(f"TensorFlow Lite model: {tflite_path}")
    print(f"Test accuracy: {metrics['test_accuracy']:.2%}")
    print("Next: run `python training/train_mobilenet.py predict PATH_TO_IMAGE` to try a photo.")


def predict(args: argparse.Namespace) -> None:
    try:
        import tensorflow as tf
    except ImportError as error:
        raise SystemExit("TensorFlow is required. Install requirements-training.txt first.") from error

    if not args.model.is_file():
        raise FileNotFoundError(f"Model file not found: {args.model}")
    if not args.labels.is_file():
        raise FileNotFoundError(f"Class label file not found: {args.labels}")
    labels = json.loads(args.labels.read_text(encoding="utf-8"))
    with Image.open(args.image) as source:
        image = ImageOps.exif_transpose(source).convert("RGB").resize(
            (IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR
        )
        pixels = np.asarray(image, dtype=np.float32)[None, ...]

    if args.model.suffix.lower() == ".tflite":
        interpreter = tf.lite.Interpreter(model_path=str(args.model))
        interpreter.allocate_tensors()
        input_detail = interpreter.get_input_details()[0]
        output_detail = interpreter.get_output_details()[0]
        interpreter.set_tensor(input_detail["index"], pixels.astype(input_detail["dtype"]))
        interpreter.invoke()
        probabilities = interpreter.get_tensor(output_detail["index"])[0]
    else:
        model = tf.keras.models.load_model(args.model)
        probabilities = model.predict(pixels, verbose=0)[0]

    best_index = int(np.argmax(probabilities))
    confidence = float(probabilities[best_index])
    result = {
        "class": labels[best_index]["key"],
        "name": labels[best_index]["name"],
        "confidence": confidence,
        "needs_review": confidence < args.threshold,
        "probabilities": {
            labels[index]["key"]: float(score)
            for index, score in enumerate(probabilities)
        },
    }
    print(json.dumps(result, indent=2))


def main() -> None:
    parser = argparse.ArgumentParser(description="Train and run MobileNetV2 for rice-leaf classes.")
    commands = parser.add_subparsers(dest="command", required=True)

    train_parser = commands.add_parser("train", help="Train from the labeled class folders and export Keras/TFLite models.")
    train_parser.add_argument("--data-root", type=Path, default=DEFAULT_DATA_ROOT)
    train_parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    train_parser.add_argument("--batch-size", type=int, default=32)
    train_parser.add_argument("--head-epochs", type=int, default=8)
    train_parser.add_argument("--fine-tune-epochs", type=int, default=5)
    train_parser.set_defaults(run=train)

    predict_parser = commands.add_parser("predict", help="Classify a single image with a trained Keras or TFLite model.")
    predict_parser.add_argument("image", type=Path)
    predict_parser.add_argument("--model", type=Path, default=DEFAULT_OUTPUT_DIR / "mobilenetv2_rice_leaf.tflite")
    predict_parser.add_argument("--labels", type=Path, default=DEFAULT_OUTPUT_DIR / "class_labels.json")
    predict_parser.add_argument("--threshold", type=float, default=0.55)
    predict_parser.set_defaults(run=predict)

    args = parser.parse_args()
    if args.command == "predict" and not 0 <= args.threshold <= 1:
        parser.error("--threshold must be between 0 and 1")
    args.run(args)


if __name__ == "__main__":
    main()
