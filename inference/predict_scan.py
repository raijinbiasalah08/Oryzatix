#!/usr/bin/env python3
"""Run the Oryzatix quality gate, rice-leaf validator, and disease model for one image."""

from __future__ import annotations

import json
import hashlib
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps
try:
    from ai_edge_litert.interpreter import Interpreter
except ImportError:  # Local training environments may have TensorFlow but not LiteRT.
    import tensorflow as tf

    Interpreter = tf.lite.Interpreter


IMAGE_SIZE = 224


def load_rgb(image_path: Path) -> Image.Image:
    with Image.open(image_path) as source:
        return ImageOps.exif_transpose(source).convert("RGB")


def laplacian_variance(image: Image.Image) -> float:
    gray = np.asarray(
        image.convert("L").resize((IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR),
        dtype=np.float32,
    )
    center = gray[1:-1, 1:-1]
    laplacian = (
        gray[:-2, 1:-1] + gray[2:, 1:-1] + gray[1:-1, :-2]
        + gray[1:-1, 2:] - 4.0 * center
    )
    return float(np.var(laplacian))


def load_model(model_path: Path):
    interpreter = Interpreter(model_path=str(model_path), num_threads=2)
    interpreter.allocate_tensors()
    inputs = interpreter.get_input_details()
    outputs = interpreter.get_output_details()
    if len(inputs) != 1 or len(outputs) != 1:
        raise ValueError(f"Unexpected tensor count for {model_path.name}.")
    input_detail = inputs[0]
    output_detail = outputs[0]
    if tuple(input_detail["shape"]) != (1, IMAGE_SIZE, IMAGE_SIZE, 3):
        raise ValueError(f"Unexpected input shape for {model_path.name}.")
    if input_detail["dtype"] != np.float32 or output_detail["dtype"] != np.float32:
        raise ValueError(f"Unexpected tensor dtype for {model_path.name}.")
    return interpreter, input_detail, output_detail


def invoke_model(model, pixels: np.ndarray, expected_shape: tuple[int, ...]) -> np.ndarray:
    interpreter, input_detail, output_detail = model
    interpreter.set_tensor(input_detail["index"], np.ascontiguousarray(pixels, dtype=np.float32))
    interpreter.invoke()
    result = np.asarray(interpreter.get_tensor(output_detail["index"]), dtype=np.float32)
    if result.shape != expected_shape or not np.isfinite(result).all():
        raise ValueError("Model returned invalid output shape or values.")
    return result


def run_model(model_path: Path, pixels: np.ndarray, expected_shape: tuple[int, ...]) -> np.ndarray:
    return invoke_model(load_model(model_path), pixels, expected_shape)


def disease_views(model, pixels: np.ndarray, class_count: int, rotations: int = 1) -> np.ndarray:
    """Average identical full-image views; never crop away disease symptoms."""
    if rotations not in (1, 4):
        raise ValueError("Supported orientation counts are 1 and 4.")
    views = [invoke_model(model, np.rot90(pixels, k, axes=(1, 2)), (1, class_count))[0]
             for k in range(rotations)]
    views = np.asarray(views)
    if (not np.isfinite(views).all() or np.any(views < 0) or np.any(views > 1)
            or not np.allclose(views.sum(axis=1), 1.0, rtol=0.0, atol=0.02)):
        raise ValueError("Disease model returned invalid probabilities for an orientation.")
    return views


def make_result(
    status: str,
    reason: str,
    rice_probability: float | None,
    rice_threshold: float,
    focus_score: float,
    blur_threshold: float,
    diagnosis: dict[str, object] | None = None,
) -> dict[str, object]:
    return {
        "schema_version": 1,
        "status": status,
        "reason": reason,
        "rice_leaf_validated": status in {"diagnosed", "uncertain_prediction"},
        "validation": {
            "classifier": "rice_leaf_validator_v1",
            "rice_leaf": status in {"diagnosed", "uncertain_prediction"},
            "rice_leaf_probability": rice_probability,
            "rice_leaf_threshold": rice_threshold,
        },
        "quality": {
            "status": "passed" if focus_score >= blur_threshold else "blurry",
            "laplacian_variance": focus_score,
            "minimum_laplacian_variance": blur_threshold,
        },
        "diagnosis": diagnosis,
    }


def main() -> int:
    if len(sys.argv) != 6:
        raise ValueError("Expected validator model, disease model, labels, thresholds, and image paths.")

    validator_path, disease_path, labels_path, thresholds_path, image_path = map(Path, sys.argv[1:])
    labels = json.loads(labels_path.read_text(encoding="utf-8"))
    labels.sort(key=lambda row: int(row["index"]))
    thresholds = json.loads(thresholds_path.read_text(encoding="utf-8"))
    calibrated_hash = thresholds['disease'].get('model_sha256')
    if calibrated_hash and hashlib.sha256(disease_path.read_bytes()).hexdigest() != calibrated_hash:
        raise ValueError("Disease thresholds belong to a different model; recalibration is required.")
    rice_gate = thresholds["rice_leaf"]
    blur_gate = thresholds["blur"]
    disease_gate = thresholds["disease"]["thresholds"]
    rice_threshold = float(rice_gate["threshold"])
    blur_threshold = float(blur_gate["threshold"])
    if not rice_gate.get("operating_point_available"):
        raise ValueError("Rice-leaf validator has no accepting operating point from validation data.")
    if not (0.0 <= rice_threshold <= 1.0) or not np.isfinite(blur_threshold) or blur_threshold < 0:
        raise ValueError("Calibrated validation thresholds are invalid.")
    for value in disease_gate.values():
        if value is not None and not 0.0 <= float(value) <= 1.0:
            raise ValueError("Calibrated disease threshold is invalid.")

    image = load_rgb(image_path)
    focus_score = laplacian_variance(image)
    if not np.isfinite(focus_score):
        raise ValueError("Image quality metric is not finite.")
    if focus_score < blur_threshold:
        print(json.dumps(make_result(
            "blurry_image", "image_quality_below_validation_threshold", None,
            rice_threshold, focus_score, blur_threshold,
        )))
        return 0

    resized = image.resize((IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR)
    pixels = np.asarray(resized, dtype=np.float32)[None, ...]
    validator_probability = float(run_model(validator_path, pixels, (1, 1))[0, 0])
    if not 0.0 <= validator_probability <= 1.0:
        raise ValueError("Rice-leaf validator returned a probability outside [0, 1].")
    if validator_probability < rice_threshold:
        print(json.dumps(make_result(
            "not_rice_leaf", "rice_leaf_probability_below_validation_threshold",
            validator_probability, rice_threshold, focus_score, blur_threshold,
        )))
        return 0

    rotations = int(thresholds["disease"].get("orientation_views", 1))
    views = disease_views(load_model(disease_path), pixels, len(labels), rotations)
    probabilities = np.mean(views, axis=0)
    if np.any(probabilities < 0.0) or np.any(probabilities > 1.0):
        raise ValueError("Disease model probabilities fall outside [0, 1].")
    probability_sum = float(np.sum(probabilities))
    if not np.isclose(probability_sum, 1.0, rtol=0.0, atol=0.02):
        raise ValueError("Disease model probabilities do not sum to one.")

    best_index = int(np.argmax(probabilities))
    label = labels[best_index]
    disease_key = label.get("key")
    confidence = float(probabilities[best_index])
    thresholds_by_class = disease_gate
    disease_threshold = thresholds_by_class.get(disease_key)

    if disease_key == "not_rice_leaf":
        result = make_result(
            "uncertain_prediction", "classifiers_disagree", validator_probability,
            rice_threshold, focus_score, blur_threshold,
            {"disease_key": disease_key, "confidence": confidence, "minimum_confidence": None},
        )
    elif disease_key not in thresholds_by_class or disease_threshold is None:
        result = make_result(
            "uncertain_prediction", "no_validated_threshold_for_predicted_class", validator_probability,
            rice_threshold, focus_score, blur_threshold,
            {"disease_key": disease_key, "confidence": confidence, "minimum_confidence": None},
        )
    elif confidence < float(disease_threshold):
        result = make_result(
            "uncertain_prediction", "disease_confidence_below_validation_threshold", validator_probability,
            rice_threshold, focus_score, blur_threshold,
            {"disease_key": disease_key, "confidence": confidence, "minimum_confidence": float(disease_threshold)},
        )
    else:
        result = make_result(
            "diagnosed", "validated_prediction", validator_probability,
            rice_threshold, focus_score, blur_threshold,
            {"disease_key": disease_key, "name": label.get("name"), "confidence": confidence, "minimum_confidence": float(disease_threshold)},
        )
    result['diagnosis']['orientation_views'] = rotations
    result['diagnosis']['orientation_agreement'] = float(np.mean(np.argmax(views, axis=1) == best_index))
    result['diagnosis']['score_description'] = 'Mean model probability across full-image orientations'
    print(json.dumps(result, allow_nan=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"Scan inference error: {error}", file=sys.stderr)
        raise SystemExit(1)
