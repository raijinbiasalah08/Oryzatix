"""Experimental severity category; no affected-area value is returned."""
import hashlib
import numpy as np

VERSION = 'severity_experimental_v1'
MODEL_HASH = '4d6bfd0e5d5b1120d25b4e46f31284e5c94992ffdd5e212c3c14decdc0eead3c'
SUPPORTED = {'blb', 'blast', 'brown_spot', 'sheath_blight', 'tungro'}


def category(probabilities):
    if probabilities.shape != (1, 224, 224, 3) or not np.isfinite(probabilities).all():
        return None
    if np.any(probabilities < 0) or np.any(probabilities > 1) or not np.allclose(probabilities.sum(-1), 1, atol=.02, rtol=0):
        return None
    mask = probabilities.argmax(-1)
    leaf = np.count_nonzero(mask > 0)
    affected = np.count_nonzero(mask == 2)
    if leaf < 224 * 224 * .01 or not affected:
        return None
    ratio = affected / leaf
    return 'mild' if ratio <= .25 else 'moderate' if ratio <= .60 else 'severe'


def estimate(model_directory, pixels, disease_key, runner):
    if disease_key not in SUPPORTED:
        return None
    path = model_directory / (VERSION + '.tflite')
    if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != MODEL_HASH:
        return None
    level = category(runner(path, pixels, (1, 224, 224, 3)))
    return {'level': level, 'source': 'model_estimated', 'version': VERSION} if level else None
