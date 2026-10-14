#!/usr/bin/env python3
"""Run the bundled MobileNetV2 TFLite model for one uploaded leaf image."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps
from ai_edge_litert.interpreter import Interpreter


IMAGE_SIZE = 224


def main() -> int:
    if len(sys.argv) != 4:
        raise ValueError("Expected model path, labels path, and image path.")

    model_path, labels_path, image_path = map(Path, sys.argv[1:])
    labels = json.loads(labels_path.read_text(encoding="utf-8"))
    labels.sort(key=lambda label: int(label["index"]))

    with Image.open(image_path) as source:
        image = ImageOps.exif_transpose(source).convert("RGB")
        image = image.resize((IMAGE_SIZE, IMAGE_SIZE), Image.Resampling.BILINEAR)
        pixels = np.asarray(image, dtype=np.float32)[None, ...]

    interpreter = Interpreter(model_path=str(model_path), num_threads=2)
    interpreter.allocate_tensors()
    input_detail = interpreter.get_input_details()[0]
    output_detail = interpreter.get_output_details()[0]

    if tuple(input_detail["shape"]) != (1, IMAGE_SIZE, IMAGE_SIZE, 3):
        raise ValueError("The bundled model has an unexpected input shape.")
    if input_detail["dtype"] != np.float32:
        raise ValueError("The bundled model must accept float32 RGB pixels.")

    interpreter.set_tensor(input_detail["index"], pixels)
    interpreter.invoke()
    probabilities = np.asarray(interpreter.get_tensor(output_detail["index"])[0])

    if probabilities.shape != (len(labels),) or not np.isfinite(probabilities).all():
        raise ValueError("The model returned invalid class probabilities.")

    best_index = int(np.argmax(probabilities))
    print(json.dumps({
        "disease_key": labels[best_index]["key"],
        "confidence": float(probabilities[best_index]),
    }))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"MobileNet inference error: {error}", file=sys.stderr)
        raise SystemExit(1)
