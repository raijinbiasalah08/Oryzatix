#!/usr/bin/env python3
"""Train a separate rice-leaf gate and calibrate scan thresholds from validation data."""

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
from PIL import Image, ImageFilter, ImageOps, UnidentifiedImageError


PROJECT_ROOT = Path(__file__).absolute().parents[1]
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "storage" / "app" / "models"
ASSET_DIR = PROJECT_ROOT / "oryzatix_app" / "assets" / "models"
IMAGE_SIZE = 224
SEED = 29
EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}
RICE_CLASSES = {
    "blb": "Bacterial Leaf Blight",
    "brown_spot": "Brown Spot",
    "healthy": "Healthy Rice Leaf",
    "sheath_blight": "Sheath Blight",
    "tungro": "Tungro",
}


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def collect_records(data_root: Path) -> tuple[list[dict[str, Any]], dict[str, int]]:
    """Collect rice positives and non-rice negatives; ignore offline augmented rice copies."""
    by_hash: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for key, folder in RICE_CLASSES.items():
        class_dir = data_root / folder
        if not class_dir.is_dir():
            raise FileNotFoundError(f"Missing rice class folder: {class_dir}")
        for path in sorted(class_dir.rglob("*")):
            if not path.is_file() or path.suffix.lower() not in EXTENSIONS:
                continue
            if any(part.lower() == "augmented" for part in path.relative_to(class_dir).parts):
                continue
            try:
                with Image.open(path) as image:
                    image.verify()
                by_hash[digest(path)].append({
                    "path": path.absolute(), "class_key": key,
                    "binary_label": 1, "category": "rice_leaf",
                })
            except (OSError, UnidentifiedImageError, ValueError):
                continue

    negative_dir = data_root / "Not Rice Leaf"
    if not negative_dir.is_dir():
        raise FileNotFoundError(f"Missing negative image folder: {negative_dir}")
    for path in sorted(negative_dir.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in EXTENSIONS:
            continue
        relative = path.relative_to(negative_dir)
        first = relative.parts[0].lower() if relative.parts else "other_plants"
        category = first if first in {"people", "objects"} else "other_plant"
        try:
            with Image.open(path) as image:
                image.verify()
            by_hash[digest(path)].append({
                "path": path.absolute(), "class_key": "not_rice_leaf",
                "binary_label": 0, "category": category,
            })
        except (OSError, UnidentifiedImageError, ValueError):
            continue

    records: list[dict[str, Any]] = []
    ambiguous = 0
    for copies in by_hash.values():
        labels = {item["binary_label"] for item in copies}
        if len(labels) != 1:
            ambiguous += 1
            continue
        records.append(copies[0])

    counts = {
        "rice_leaf": sum(row["binary_label"] == 1 for row in records),
        "not_rice_leaf": sum(row["binary_label"] == 0 for row in records),
        "people": sum(row["category"] == "people" for row in records),
        "objects": sum(row["category"] == "objects" for row in records),
        "other_plant": sum(row["category"] == "other_plant" for row in records),
        "ambiguous_exact_duplicates_excluded": ambiguous,
    }
    return records, counts


def split_records(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Split rice/other plants and include available people/object negatives in training."""
    randomizer = random.Random(SEED)
    result: list[dict[str, Any]] = []
    rice_keys = list(RICE_CLASSES)

    for class_index, key in enumerate(rice_keys):
        rows = [row for row in records if row["class_key"] == key]
        if len(rows) < 20:
            raise ValueError(f"Need at least 20 usable rice-leaf images for {key}; found {len(rows)}.")
        random.Random(SEED + class_index).shuffle(rows)
        validation_count = max(1, round(len(rows) * 0.15))
        test_count = max(1, round(len(rows) * 0.15))
        train_end = len(rows) - validation_count - test_count
        for index, row in enumerate(rows):
            split = "train" if index < train_end else (
                "validation" if index < train_end + validation_count else "test"
            )
            result.append({**row, "split": split})

    negatives = [row for row in records if row["binary_label"] == 0]
    ordinary = [row for row in negatives if row["category"] == "other_plant"]
    randomizer.shuffle(ordinary)
    validation_count = max(1, round(len(ordinary) * 0.15))
    test_count = max(1, round(len(ordinary) * 0.15))
    train_end = len(ordinary) - validation_count - test_count
    for index, row in enumerate(ordinary):
        split = "train" if index < train_end else (
            "validation" if index < train_end + validation_count else "test"
        )
        result.append({**row, "split": split})

    # The workspace has only two people photos and one object scene. Put one
    # person and the object scene in training; reserve the other person for
    # validation. Do not claim an independent object-scene test result with only
    # one available object image.
    for category in ("people", "objects"):
        rows = sorted(
            (row for row in negatives if row["category"] == category),
            key=lambda row: str(row["path"]).lower(),
        )
        if category == "people":
            if rows:
                result.append({**rows[0], "split": "train"})
            if len(rows) > 1:
                result.append({**rows[1], "split": "validation"})
            for row in rows[2:]:
                result.append({**row, "split": "train"})
        else:
            result.extend({**row, "split": "train"} for row in rows)

    return result


def save_manifest(output_dir: Path, rows: list[dict[str, Any]]) -> None:
    with (output_dir / "rice_leaf_validator_manifest.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=["path", "class_key", "binary_label", "category", "split"])
        writer.writeheader()
        for row in rows:
            writer.writerow({**row, "path": str(row["path"])})


def make_dataset(tf: Any, rows: list[dict[str, Any]], training: bool, batch_size: int):
    paths = [str(row["path"]) for row in rows]
    labels = [row["binary_label"] for row in rows]
    dataset = tf.data.Dataset.from_tensor_slices((paths, labels))
    if training:
        # Shuffle the small path/label tensors before decoding images so the
        # shuffle buffer does not retain gigabytes of 224px image tensors.
        dataset = dataset.shuffle(len(rows), seed=SEED, reshuffle_each_iteration=True)

    def load_image(path, label):
        def decode(path_bytes):
            with Image.open(Path(path_bytes.numpy().decode("utf-8"))) as source:
                return np.asarray(ImageOps.exif_transpose(source).convert("RGB"), dtype=np.uint8)

        image = tf.py_function(decode, [path], Tout=tf.uint8)
        image.set_shape([None, None, 3])
        image = tf.image.resize(image, [IMAGE_SIZE, IMAGE_SIZE], antialias=True)
        image = tf.cast(image, tf.float32)
        if training:
            image = tf.image.random_flip_left_right(image, seed=SEED)
            image = tf.image.rot90(image, k=tf.random.uniform([], 0, 4, dtype=tf.int32))
            image = tf.image.random_contrast(image, 0.9, 1.1)
            image = tf.clip_by_value(image, 0.0, 255.0)
        return image, tf.cast(label, tf.float32)

    dataset = dataset.map(load_image, num_parallel_calls=tf.data.AUTOTUNE)
    return dataset.batch(batch_size).prefetch(tf.data.AUTOTUNE)


def laplacian_variance(image: Image.Image) -> float:
    gray = np.asarray(
        ImageOps.exif_transpose(image).convert("L").resize(
            (IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR
        ), dtype=np.float32,
    )
    center = gray[1:-1, 1:-1]
    laplacian = (
        gray[:-2, 1:-1] + gray[2:, 1:-1] + gray[1:-1, :-2]
        + gray[1:-1, 2:] - 4.0 * center
    )
    return float(np.var(laplacian))


def calibrate_blur_threshold(rows: list[dict[str, Any]]) -> tuple[float, dict[str, Any]]:
    sharp_scores: list[float] = []
    synthetic_blur_scores: list[float] = []
    for row in rows:
        if row["split"] != "validation":
            continue
        with Image.open(row["path"]) as source:
            image = ImageOps.exif_transpose(source).convert("RGB").resize(
                (IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR
            )
            sharp_scores.append(laplacian_variance(image))
            synthetic_blur_scores.append(laplacian_variance(image.filter(ImageFilter.GaussianBlur(radius=2.0))))
    if not sharp_scores or not synthetic_blur_scores:
        raise ValueError("Could not calibrate blur scoring because validation images are missing.")

    sharp_floor = float(np.quantile(sharp_scores, 0.05))
    blur_ceiling = float(np.quantile(synthetic_blur_scores, 0.95))
    if blur_ceiling < sharp_floor:
        threshold = (blur_ceiling + sharp_floor) / 2.0
        method = "midpoint between 95th percentile synthetic blur and 5th percentile sharp validation score"
    else:
        # In overlapping distributions, choose the threshold with the best
        # balanced accuracy and retain the overlap metrics in the report.
        candidates = sorted(set(sharp_scores + synthetic_blur_scores))
        best_score = -1.0
        threshold = sharp_floor
        for candidate in candidates:
            sharp_recall = sum(score >= candidate for score in sharp_scores) / len(sharp_scores)
            blur_recall = sum(score < candidate for score in synthetic_blur_scores) / len(synthetic_blur_scores)
            score = (sharp_recall + blur_recall) / 2.0
            if score > best_score:
                best_score, threshold = score, candidate
        method = "maximum balanced accuracy on sharp and synthetic-blur validation samples (overlap present)"

    sharp_recall = sum(score >= threshold for score in sharp_scores) / len(sharp_scores)
    blur_recall = sum(score < threshold for score in synthetic_blur_scores) / len(synthetic_blur_scores)
    return threshold, {
        "method": "variance of 4-neighbor Laplacian on 224x224 grayscale input",
        "threshold": threshold,
        "threshold_source": method,
        "validation_sharp_images": len(sharp_scores),
        "synthetic_blur_radius_at_model_input": 2.0,
        "synthetic_blurred_images": len(synthetic_blur_scores),
        "sharp_acceptance_rate": sharp_recall,
        "synthetic_blur_rejection_rate": blur_recall,
        "sharp_score_5th_percentile": sharp_floor,
        "synthetic_blur_score_95th_percentile": blur_ceiling,
    }


def tflite_predictions(tf: Any, model_path: Path, paths: list[str], output_width: int, batch_size: int = 32) -> np.ndarray:
    interpreter = tf.lite.Interpreter(model_path=str(model_path), num_threads=2)
    interpreter.allocate_tensors()
    input_detail = interpreter.get_input_details()[0]
    output_detail = interpreter.get_output_details()[0]
    if input_detail["dtype"] != np.float32 or output_detail["dtype"] != np.float32:
        raise ValueError(f"Unexpected TFLite dtype in {model_path.name}.")
    predictions = []
    for start in range(0, len(paths), batch_size):
        images = []
        for path in paths[start:start + batch_size]:
            with Image.open(path) as source:
                image = ImageOps.exif_transpose(source).convert("RGB").resize(
                    (IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR
                )
                images.append(np.asarray(image, dtype=np.float32))
        tensor = np.asarray(images, dtype=np.float32)
        if tuple(input_detail["shape"]) != tuple(tensor.shape):
            interpreter.resize_tensor_input(input_detail["index"], tensor.shape, strict=False)
            interpreter.allocate_tensors()
            input_detail = interpreter.get_input_details()[0]
            output_detail = interpreter.get_output_details()[0]
        interpreter.set_tensor(input_detail["index"], tensor)
        interpreter.invoke()
        batch_result = np.asarray(interpreter.get_tensor(output_detail["index"]), dtype=np.float32)
        if batch_result.shape != (len(images), output_width) or not np.isfinite(batch_result).all():
            raise ValueError(f"TFLite output shape or values are invalid for {model_path.name}.")
        predictions.append(batch_result)
    return np.concatenate(predictions, axis=0) if predictions else np.empty((0, output_width), dtype=np.float32)


def calibrate_rice_threshold(tf: Any, model_path: Path, rows: list[dict[str, Any]], batch_size: int) -> tuple[float, dict[str, Any]]:
    validation_rows = [row for row in rows if row["split"] == "validation"]
    scores = tflite_predictions(
        tf, model_path, [str(row["path"]) for row in validation_rows], 1, batch_size
    ).reshape(-1)
    labels = np.asarray([row["binary_label"] for row in validation_rows], dtype=np.int32)
    positives = scores[labels == 1]
    negatives = scores[labels == 0]
    if not len(positives) or not len(negatives):
        raise ValueError("Validation split must contain both rice and non-rice examples.")

    max_negative = float(np.max(negatives))
    min_positive = float(np.min(positives))
    has_validation_gap = max_negative < min_positive
    # Probabilities are float32 in the exported model. Advance to the next
    # representable float32 so equality at the validation maximum is rejected
    # consistently by NumPy, PHP, and LiteRT comparisons.
    next_float32 = float(np.nextafter(np.float32(max_negative), np.float32(np.inf)))
    threshold = (
        (max_negative + min_positive) / 2.0
        if has_validation_gap
        else (next_float32 if max_negative < 1.0 else 1.0)
    )
    accepted = scores >= threshold
    true_positive = int(np.sum(accepted & (labels == 1)))
    false_positive = int(np.sum(accepted & (labels == 0)))
    false_negative = int(np.sum((~accepted) & (labels == 1)))
    true_negative = int(np.sum((~accepted) & (labels == 0)))
    metrics = {
        "threshold": threshold,
        "threshold_policy": "midpoint between highest non-rice and lowest rice validation scores when separated; otherwise smallest threshold above highest non-rice validation score",
        "validation_rice_images": int(np.sum(labels == 1)),
        "validation_non_rice_images": int(np.sum(labels == 0)),
        "validation_true_positive": true_positive,
        "validation_false_positive": false_positive,
        "validation_false_negative": false_negative,
        "validation_true_negative": true_negative,
        "validation_rice_recall": true_positive / int(np.sum(labels == 1)),
        "validation_non_rice_specificity": true_negative / int(np.sum(labels == 0)),
        "max_non_rice_validation_score": max_negative,
        "min_rice_validation_score": min_positive,
        "validation_score_gap": min_positive - max_negative,
        "separable_validation_scores": has_validation_gap,
        "operating_point_available": bool(max_negative < 1.0 and threshold <= 1.0 and np.any(positives >= threshold)),
    }
    return threshold, metrics


def calibrate_disease_thresholds(tf: Any, output_dir: Path, target_precision: float = 0.95) -> dict[str, Any]:
    """Calibrate the existing disease model on its held-out validation manifest."""
    model_path = output_dir / "mobilenetv2_rice_leaf.tflite"
    labels_path = output_dir / "class_labels.json"
    manifest_path = output_dir / "training_manifest.csv"
    if not all(path.is_file() for path in (model_path, labels_path, manifest_path)):
        raise FileNotFoundError("Existing disease Keras model, labels, and validation manifest are required.")

    labels = json.loads(labels_path.read_text(encoding="utf-8"))
    labels.sort(key=lambda item: int(item["index"]))
    class_keys = [item["key"] for item in labels]
    with manifest_path.open(newline="", encoding="utf-8") as stream:
        validation_rows = [row for row in csv.DictReader(stream) if row["split"] == "validation"]

    probabilities = tflite_predictions(
        tf, model_path, [row["path"] for row in validation_rows], len(class_keys), 32
    )
    if probabilities.shape != (len(validation_rows), len(class_keys)) or not np.isfinite(probabilities).all():
        raise ValueError("Existing disease model produced invalid validation probabilities.")

    truth = np.asarray([int(row["label"]) for row in validation_rows], dtype=np.int32)
    predicted = np.argmax(probabilities, axis=1)
    thresholds: dict[str, float | None] = {}
    per_class: dict[str, Any] = {}
    for class_index, key in enumerate(class_keys):
        if key == "not_rice_leaf":
            continue
        selected = predicted == class_index
        scores = probabilities[:, class_index]
        best_threshold = None
        best_accepted = 0
        for candidate in sorted(set(float(value) for value in scores[selected])):
            accepted = selected & (scores >= candidate)
            accepted_count = int(np.sum(accepted))
            if accepted_count == 0:
                continue
            precision = float(np.sum(accepted & (truth == class_index)) / accepted_count)
            if precision >= target_precision and accepted_count >= best_accepted:
                best_threshold = candidate
                best_accepted = accepted_count
        thresholds[key] = best_threshold
        if best_threshold is None:
            accepted = np.zeros(len(truth), dtype=bool)
        else:
            accepted = selected & (scores >= best_threshold)
        accepted_count = int(np.sum(accepted))
        true_count = int(np.sum(truth == class_index))
        correct_count = int(np.sum(accepted & (truth == class_index)))
        per_class[key] = {
            "validation_true_images": true_count,
            "accepted_predictions": accepted_count,
            "correct_accepted_predictions": correct_count,
            "precision": correct_count / accepted_count if accepted_count else None,
            "recall_at_threshold": correct_count / true_count if true_count else None,
            "threshold": best_threshold,
        }

    if "blast" not in thresholds:
        thresholds["blast"] = None
        per_class["blast"] = {
            "validation_true_images": 0,
            "accepted_predictions": 0,
            "correct_accepted_predictions": 0,
            "precision": None,
            "recall_at_threshold": None,
            "threshold": None,
            "note": "Leaf Blast is not an output class of the current MobileNet model; exact reference matches remain supported.",
        }
    return {
        "target_precision": target_precision,
        "threshold_policy": "lowest predicted-class threshold reaching the target precision on the disease model validation split; maximum validation coverage",
        "thresholds": thresholds,
        "per_class_validation": per_class,
        "validation_image_count": len(validation_rows),
    }


def make_model(tf: Any):
    base = tf.keras.applications.MobileNetV2(
        input_shape=(IMAGE_SIZE, IMAGE_SIZE, 3), include_top=False, weights="imagenet"
    )
    base.trainable = False
    inputs = tf.keras.Input(shape=(IMAGE_SIZE, IMAGE_SIZE, 3), name="rice_scan_rgb_0_255")
    scaled = tf.keras.layers.Rescaling(1.0 / 127.5, offset=-1.0, name="mobilenetv2_preprocess")(inputs)
    features = base(scaled, training=False)
    features = tf.keras.layers.GlobalAveragePooling2D()(features)
    features = tf.keras.layers.Dropout(0.25)(features)
    output = tf.keras.layers.Dense(1, activation="sigmoid", name="rice_leaf_probability")(features)
    return tf.keras.Model(inputs, output), base


def export_validator_tflite(tf: Any, model: Any, path: Path) -> None:
    """Export a fixed single-image TFLite signature without Keras SavedModel I/O."""
    @tf.function(input_signature=[
        tf.TensorSpec([1, IMAGE_SIZE, IMAGE_SIZE, 3], tf.float32, name="rice_scan_rgb_0_255")
    ])
    def serving_fn(images):
        return {"rice_leaf_probability": model(images, training=False)}

    converter = tf.lite.TFLiteConverter.from_concrete_functions(
        [serving_fn.get_concrete_function()], model
    )
    path.write_bytes(converter.convert())


def summarize_predictions(tf: Any, model_path: Path, rows: list[dict[str, Any]], batch_size: int, threshold: float) -> dict[str, Any]:
    test_rows = [row for row in rows if row["split"] == "test"]
    scores = tflite_predictions(tf, model_path, [str(row["path"]) for row in test_rows], 1, batch_size).reshape(-1)
    labels = np.asarray([row["binary_label"] for row in test_rows], dtype=np.int32)
    guesses = scores >= threshold
    overall = {
        "test_images": len(test_rows),
        "true_rice_images": int(np.sum(labels == 1)),
        "true_non_rice_images": int(np.sum(labels == 0)),
        "rice_recall": float(np.sum(guesses & (labels == 1)) / max(1, np.sum(labels == 1))),
        "non_rice_specificity": float(np.sum((~guesses) & (labels == 0)) / max(1, np.sum(labels == 0))),
        "non_rice_false_accepts": int(np.sum(guesses & (labels == 0))),
    }
    categories = {}
    for category in sorted({row["category"] for row in test_rows}):
        indices = [index for index, row in enumerate(test_rows) if row["category"] == category]
        if not indices:
            continue
        category_labels = labels[indices]
        category_guesses = guesses[indices]
        categories[category] = {
            "images": len(indices),
            "true_rice": int(np.sum(category_labels == 1)),
            "accepted_as_rice": int(np.sum(category_guesses)),
            "rejected_or_inconclusive": int(np.sum(~category_guesses)),
            "false_rice_accepts": int(np.sum(category_guesses & (category_labels == 0))),
        }
    return {"overall": overall, "by_category": categories}


def finalize_validation(tf: Any, output_dir: Path, split_rows: list[dict[str, Any]], counts: dict[str, int], batch_size: int) -> dict[str, Any]:
    validator_path = output_dir / "rice_leaf_validator.tflite"
    if not validator_path.is_file():
        raise FileNotFoundError(f"Missing exported rice-leaf validator: {validator_path}")
    rice_threshold, rice_metrics = calibrate_rice_threshold(tf, validator_path, split_rows, batch_size)
    _, blur_metrics = calibrate_blur_threshold(split_rows)
    disease_metrics = calibrate_disease_thresholds(tf, output_dir)
    test_metrics = summarize_predictions(tf, validator_path, split_rows, batch_size, rice_threshold)
    challenge_rows = [row for row in split_rows if row["split"] == "validation" and row["category"] in {"people", "objects"}]
    challenge_scores = tflite_predictions(tf, validator_path, [str(row["path"]) for row in challenge_rows], 1, batch_size).reshape(-1)
    challenge_metrics = {
        "images": len(challenge_rows),
        "rejected_or_inconclusive": int(np.sum(challenge_scores < rice_threshold)),
        "false_rice_accepts": int(np.sum(challenge_scores >= rice_threshold)),
        "by_category": {
            category: {
                "images": sum(row["category"] == category for row in challenge_rows),
                "false_rice_accepts": int(sum(score >= rice_threshold for row, score in zip(challenge_rows, challenge_scores) if row["category"] == category)),
            }
            for category in ("people", "objects")
        },
        "note": "Only the held-out person image is an independent scene validation example. The object scene and other person image are training examples; no independent object-scene test data is available.",
    }
    hard_negative_train_rows = [row for row in split_rows if row["split"] == "train" and row["category"] in {"people", "objects"}]
    hard_negative_train_scores = tflite_predictions(
        tf, validator_path, [str(row["path"]) for row in hard_negative_train_rows], 1, batch_size
    ).reshape(-1) if hard_negative_train_rows else np.asarray([], dtype=np.float32)
    hard_negative_training_fit = {
        "images": len(hard_negative_train_rows),
        "rejected_by_trained_model": int(np.sum(hard_negative_train_scores < rice_threshold)),
        "false_rice_accepts": int(np.sum(hard_negative_train_scores >= rice_threshold)),
        "by_category": {
            category: {
                "images": sum(row["category"] == category for row in hard_negative_train_rows),
                "false_rice_accepts": int(sum(score >= rice_threshold for row, score in zip(hard_negative_train_rows, hard_negative_train_scores) if row["category"] == category)),
            }
            for category in ("people", "objects")
        },
        "note": "Training-fit diagnostic only; these examples are not an independent evaluation set.",
    }
    thresholds = {
        "schema_version": 1,
        "validator": "MobileNetV2 binary rice-leaf versus non-rice-image classifier",
        "rice_leaf": {**rice_metrics, "calibration_split": "validation"},
        "blur": {**blur_metrics, "calibration_split": "validation", "synthetic_test_only": "Gaussian blur radius 2.0 applied after 224px resize; no real blurry-photo set is present."},
        "disease": disease_metrics,
        "binary_test": test_metrics,
        "hard_negative_validation": challenge_metrics,
        "hard_negative_training_fit": hard_negative_training_fit,
        "data_counts": counts,
        "split_counts": {
            split: {
                "rice_leaf": sum(row["binary_label"] == 1 and row["split"] == split for row in split_rows),
                "not_rice_leaf": sum(row["binary_label"] == 0 and row["split"] == split for row in split_rows),
            }
            for split in ("train", "validation", "test")
        },
    }
    threshold_path = output_dir / "scan_validation_thresholds.json"
    threshold_path.write_text(json.dumps(thresholds, indent=2) + "\n", encoding="utf-8")
    if output_dir.absolute() == DEFAULT_OUTPUT_DIR.absolute():
        ASSET_DIR.mkdir(parents=True, exist_ok=True)
        shutil.copy2(validator_path, ASSET_DIR / validator_path.name)
        shutil.copy2(threshold_path, ASSET_DIR / threshold_path.name)
    print("Rice-leaf validation metrics:", json.dumps(rice_metrics, sort_keys=True))
    print("Blur validation metrics:", json.dumps(blur_metrics, sort_keys=True))
    print("Binary held-out test:", json.dumps(test_metrics, sort_keys=True))
    print("Hard-negative validation challenge:", json.dumps(challenge_metrics, sort_keys=True))
    print("Hard-negative training-fit diagnostic:", json.dumps(hard_negative_training_fit, sort_keys=True))
    print("Disease validation thresholds:", json.dumps(disease_metrics, sort_keys=True))
    print(f"Threshold report: {threshold_path}")
    return thresholds


def train(args: argparse.Namespace) -> None:
    args.output_dir.mkdir(parents=True, exist_ok=True)
    if os.name == "nt":
        # TensorFlow checkpoint temp files need a writable location beside the
        # model outputs on restricted Windows workspaces.
        training_temp_dir = str(args.output_dir.absolute())
        for name in ("TMPDIR", "TEMP", "TMP"):
            os.environ[name] = training_temp_dir

    try:
        import tensorflow as tf
    except ImportError as error:
        raise SystemExit("TensorFlow is required. Install training/requirements-training.txt first.") from error

    random.seed(SEED)
    np.random.seed(SEED)
    tf.random.set_seed(SEED)
    rows, counts = collect_records(args.data_root.absolute())
    split_rows = split_records(rows)
    save_manifest(args.output_dir, split_rows)
    for split in ("train", "validation", "test"):
        group = [row for row in split_rows if row["split"] == split]
        print(f"{split}: rice={sum(row['binary_label'] == 1 for row in group)}, non-rice={sum(row['binary_label'] == 0 for row in group)}")
        for category in ("other_plant", "people", "objects"):
            count = sum(row["category"] == category and row["split"] == split for row in split_rows)
            if count:
                print(f"  {category}: {count}")
    print("Usable images:", json.dumps(counts, sort_keys=True))

    train_rows = [row for row in split_rows if row["split"] == "train"]
    validation_rows = [row for row in split_rows if row["split"] == "validation"]
    train_ds = make_dataset(tf, train_rows, training=True, batch_size=args.batch_size)
    validation_ds = make_dataset(tf, validation_rows, training=False, batch_size=args.batch_size)
    positive_count = sum(row["binary_label"] == 1 for row in train_rows)
    negative_count = len(train_rows) - positive_count
    class_weights = {
        0: len(train_rows) / (2.0 * negative_count),
        1: len(train_rows) / (2.0 * positive_count),
    }

    model, base = make_model(tf)
    checkpoint = args.output_dir / "best_rice_leaf_validator_candidate.keras"
    checkpoint.unlink(missing_ok=True)
    callbacks = [
        tf.keras.callbacks.EarlyStopping(monitor="val_loss", patience=3, restore_best_weights=True),
        tf.keras.callbacks.ReduceLROnPlateau(monitor="val_loss", factor=0.2, patience=2, min_lr=1e-6),
        tf.keras.callbacks.ModelCheckpoint(str(checkpoint), monitor="val_loss", save_best_only=True),
    ]
    model.compile(optimizer=tf.keras.optimizers.Adam(1e-3), loss="binary_crossentropy", metrics=["accuracy"])
    model.fit(train_ds, validation_data=validation_ds, epochs=args.head_epochs, class_weight=class_weights, callbacks=callbacks)

    if args.fine_tune_epochs > 0:
        base.trainable = True
        for layer in base.layers[:-30]:
            layer.trainable = False
        for layer in base.layers:
            if isinstance(layer, tf.keras.layers.BatchNormalization):
                layer.trainable = False
        model.compile(optimizer=tf.keras.optimizers.Adam(1e-5), loss="binary_crossentropy", metrics=["accuracy"])
        model.fit(train_ds, validation_data=validation_ds, epochs=args.fine_tune_epochs, class_weight=class_weights, callbacks=callbacks)

    model = tf.keras.models.load_model(checkpoint)
    keras_path = args.output_dir / "rice_leaf_validator.keras"
    model.save(keras_path)
    tflite_path = args.output_dir / "rice_leaf_validator.tflite"
    # Direct Keras conversion uses a removed private API in TF 2.20/Keras 3,
    # while writing a SavedModel can fail on restricted Windows temp mounts.
    export_validator_tflite(tf, model, tflite_path)

    # Calibrate every cutoff against the exported TFLite models, not the Keras
    # training model. Production outputs are copied by finalize_validation().
    finalize_validation(tf, args.output_dir, split_rows, counts, args.batch_size)
    print(f"TFLite validator: {tflite_path}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Train the separate Oryzatix rice-leaf image validator.")
    parser.add_argument("--data-root", type=Path, default=PROJECT_ROOT)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    parser.add_argument("--batch-size", type=int, default=32)
    parser.add_argument("--head-epochs", type=int, default=6)
    parser.add_argument("--fine-tune-epochs", type=int, default=2)
    parser.add_argument("--calibrate-only", action="store_true", help="Recompute validation cutoffs and evaluation reports without retraining the validator.")
    args = parser.parse_args()
    if args.batch_size < 1 or args.head_epochs < 1 or args.fine_tune_epochs < 0:
        parser.error("Batch size and head epochs must be positive; fine-tune epochs cannot be negative.")
    if args.calibrate_only:
        try:
            import tensorflow as tf
        except ImportError as error:
            raise SystemExit("TensorFlow is required. Install training/requirements-training.txt first.") from error
        rows, counts = collect_records(args.data_root.absolute())
        split_rows = split_records(rows)
        save_manifest(args.output_dir, split_rows)
        finalize_validation(tf, args.output_dir, split_rows, counts, args.batch_size)
    else:
        train(args)


if __name__ == "__main__":
    main()
