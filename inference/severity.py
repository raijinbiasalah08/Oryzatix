"""Experimental severity category; no affected-area value is returned."""
import hashlib
import numpy as np

VERSION = 'severity_experimental_v1'
MODEL_HASH = '4d6bfd0e5d5b1120d25b4e46f31284e5c94992ffdd5e212c3c14decdc0eead3c'
SUPPORTED = {'blb', 'blast', 'brown_spot', 'sheath_blight', 'tungro'}


def _level(fraction):
    return 'mild' if fraction <= .25 else 'moderate' if fraction <= .60 else 'severe'


def category(probabilities):
    if not isinstance(probabilities, np.ndarray) or probabilities.shape != (1, 224, 224, 3):
        return None
    if probabilities.dtype.kind not in 'fiu' or not np.isfinite(probabilities).all():
        return None
    # Match the browser's numeric precision when adding probabilities near a tie.
    probabilities = probabilities.astype(np.float64, copy=False)
    if np.any(probabilities < 0) or np.any(probabilities > 1) or not np.allclose(probabilities.sum(-1), 1, atol=.02, rtol=0):
        return None
    background, unaffected, symptomatic = np.moveaxis(probabilities, -1, 0)
    affected = np.count_nonzero(symptomatic > background + unaffected)
    leaf = affected + np.count_nonzero(unaffected > background + symptomatic)
    if leaf < 224 * 224 * .01 or not affected:
        return None
    # Bound the fraction by assigning pixels without a majority to either tissue class.
    ambiguous = background.size - np.count_nonzero(background > unaffected + symptomatic) - leaf
    possible_leaf = leaf + ambiguous
    low = _level(affected / possible_leaf)
    high = _level((affected + ambiguous) / possible_leaf)
    return low if low == high else None


def estimate(model_directory, pixels, disease_key, runner):
    if disease_key not in SUPPORTED:
        return None
    path = model_directory / (VERSION + '.tflite')
    if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != MODEL_HASH:
        return None
    level = category(runner(path, pixels, (1, 224, 224, 3)))
    return {'level': level, 'source': 'model_estimated', 'version': VERSION} if level else None
