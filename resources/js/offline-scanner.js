import { loadAndCompile, loadLiteRt, Tensor } from '@litertjs/core';

const IMAGE_SIZE = 224;
const SUPPORTED_DISEASES = new Set([
    'blast',
    'blb',
    'brown_spot',
    'healthy',
    'sheath_blight',
    'tungro',
]);

let scannerModelsPromise;

function assetUrl(base, file) {
    return new URL(file, base).toString();
}

async function readJson(url) {
    const response = await fetch(url, { cache: 'force-cache' });
    if (!response.ok) throw new Error(`Offline scanner asset unavailable: ${response.status}`);
    return response.json();
}

async function readBytes(url) {
    const response = await fetch(url, { cache: 'force-cache' });
    if (!response.ok) throw new Error(`Offline scanner model unavailable: ${response.status}`);
    return new Uint8Array(await response.arrayBuffer());
}

async function sha256(bytes) {
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), value => value.toString(16).padStart(2, '0')).join('');
}

function loadScannerModels() {
    if (!scannerModelsPromise) {
        scannerModelsPromise = (async () => {
            const assets = window.ORYZATIX_OFFLINE_ASSETS;
            if (!assets?.models || !assets?.runtime) {
                throw new Error('The offline scanner asset paths are missing.');
            }

            await loadLiteRt(assets.runtime);

            const [labels, thresholds, validatorBytes, diseaseBytes] = await Promise.all([
                readJson(assetUrl(assets.models, 'class_labels.json')),
                readJson(assetUrl(assets.models, 'scan_validation_thresholds.json')),
                readBytes(assetUrl(assets.models, 'rice_leaf_validator.tflite')),
                readBytes(assetUrl(assets.models, 'mobilenetv2_rice_leaf.tflite')),
            ]);

            if (!Array.isArray(labels) || labels.length !== 7) {
                throw new Error('The offline scanner class labels are invalid.');
            }
            labels.sort((a, b) => a.index - b.index);
            if (
                labels.some((label, index) => label.index !== index || !label.key || !label.name)) {
                throw new Error('The offline scanner class labels are invalid.');
            }
            if (thresholds?.rice_leaf?.operating_point_available !== true ||
                !Number.isFinite(thresholds?.rice_leaf?.threshold) ||
                !Number.isFinite(thresholds?.blur?.threshold) ||
                !thresholds?.disease?.thresholds) {
                throw new Error('The offline scanner calibration file is invalid.');
            }
            const expectedHash = thresholds.disease.model_sha256;
            if (expectedHash && await sha256(diseaseBytes) !== expectedHash.toLowerCase()) {
                throw new Error('The offline scanner calibration does not match the disease model.');
            }

            const compileOptions = { accelerator: 'wasm', cpuOptions: { numThreads: 2 } };
            const [validator, diseaseModel] = await Promise.all([
                loadAndCompile(validatorBytes, compileOptions),
                loadAndCompile(diseaseBytes, compileOptions),
            ]);

            const validatorInput = validator.getInputDetails()[0];
            const validatorOutput = validator.getOutputDetails()[0];
            const diseaseInput = diseaseModel.getInputDetails()[0];
            const diseaseOutput = diseaseModel.getOutputDetails()[0];
            const supportedInput = shape =>
                (shape.length === 4 && shape[0] === 1 && shape[1] === IMAGE_SIZE &&
                    shape[2] === IMAGE_SIZE && shape[3] === 3) ||
                (shape.length === 4 && shape[0] === 1 && shape[1] === 3 &&
                    shape[2] === IMAGE_SIZE && shape[3] === IMAGE_SIZE);

            if (!validatorInput || !validatorOutput || !diseaseInput || !diseaseOutput ||
                validatorInput.dtype !== 'float32' || validatorOutput.dtype !== 'float32' ||
                diseaseInput.dtype !== 'float32' || diseaseOutput.dtype !== 'float32' ||
                !supportedInput(Array.from(validatorInput.shape)) ||
                !supportedInput(Array.from(diseaseInput.shape)) ||
                Array.from(validatorOutput.shape).join(',') !== '1,1' ||
                Array.from(diseaseOutput.shape).join(',') !== `1,${labels.length}`) {
                validator.delete();
                diseaseModel.delete();
                throw new Error('The offline scanner models have unsupported tensor shapes.');
            }

            return { validator, diseaseModel, labels, thresholds, validatorShape: Array.from(validatorInput.shape), diseaseShape: Array.from(diseaseInput.shape) };
        })().catch(error => {
            scannerModelsPromise = null;
            throw error;
        });
    }
    return scannerModelsPromise;
}

async function decodeToPixels(file) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (_) {
        bitmap = await createImageBitmap(file);
    }

    try {
        const canvas = document.createElement('canvas');
        canvas.width = IMAGE_SIZE;
        canvas.height = IMAGE_SIZE;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) throw new Error('This browser cannot prepare the scanner image.');
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'low';
        context.drawImage(bitmap, 0, 0, IMAGE_SIZE, IMAGE_SIZE);
        return context.getImageData(0, 0, IMAGE_SIZE, IMAGE_SIZE).data;
    } finally {
        bitmap.close();
    }
}

function focusScore(rgba) {
    const gray = new Float32Array(IMAGE_SIZE * IMAGE_SIZE);
    for (let pixel = 0, offset = 0; pixel < gray.length; pixel++, offset += 4) {
        gray[pixel] = Math.round((299 * rgba[offset] + 587 * rgba[offset + 1] + 114 * rgba[offset + 2]) / 1000);
    }

    let sum = 0;
    let sumSquares = 0;
    let count = 0;
    for (let y = 1; y < IMAGE_SIZE - 1; y++) {
        for (let x = 1; x < IMAGE_SIZE - 1; x++) {
            const index = y * IMAGE_SIZE + x;
            const laplacian = gray[index - IMAGE_SIZE] + gray[index + IMAGE_SIZE] +
                gray[index - 1] + gray[index + 1] - 4 * gray[index];
            sum += laplacian;
            sumSquares += laplacian * laplacian;
            count++;
        }
    }
    const mean = sum / count;
    return Math.max(0, sumSquares / count - mean * mean);
}

function inputTensor(rgba, modelShape, rotation) {
    const isNhwc = modelShape[3] === 3;
    const values = new Float32Array(IMAGE_SIZE * IMAGE_SIZE * 3);
    const planeSize = IMAGE_SIZE * IMAGE_SIZE;

    for (let y = 0; y < IMAGE_SIZE; y++) {
        for (let x = 0; x < IMAGE_SIZE; x++) {
            let sourceX = x;
            let sourceY = y;
            if (rotation === 1) {
                sourceX = y;
                sourceY = IMAGE_SIZE - 1 - x;
            } else if (rotation === 2) {
                sourceX = IMAGE_SIZE - 1 - x;
                sourceY = IMAGE_SIZE - 1 - y;
            } else if (rotation === 3) {
                sourceX = IMAGE_SIZE - 1 - y;
                sourceY = x;
            }

            const sourceOffset = (sourceY * IMAGE_SIZE + sourceX) * 4;
            const targetPixel = y * IMAGE_SIZE + x;
            for (let channel = 0; channel < 3; channel++) {
                const targetOffset = isNhwc ? targetPixel * 3 + channel : channel * planeSize + targetPixel;
                values[targetOffset] = rgba[sourceOffset + channel];
            }
        }
    }

    return new Tensor(values, modelShape);
}

async function invoke(model, rgba, shape, rotation = 0) {
    const input = inputTensor(rgba, shape, rotation);
    try {
        const outputs = await model.run(input);
        if (!Array.isArray(outputs) || outputs.length !== 1) {
            throw new Error('The offline scanner model returned an unexpected output.');
        }
        try {
            return Array.from(await outputs[0].data());
        } finally {
            outputs.forEach(output => output.delete());
        }
    } finally {
        input.delete();
    }
}

async function classify(file) {
    if (!file || file.size > 10 * 1024 * 1024) {
        throw new Error('Choose a supported image under 10 MB.');
    }

    const [models, rgba] = await Promise.all([loadScannerModels(), decodeToPixels(file)]);
    const blurThreshold = Number(models.thresholds.blur.threshold);
    const qualityScore = focusScore(rgba);
    if (!Number.isFinite(qualityScore) || qualityScore < blurThreshold) {
        return { status: 'blurry_image', reason: 'image_quality_below_validation_threshold', qualityScore, blurThreshold };
    }

    const validatorOutput = await invoke(models.validator, rgba, models.validatorShape);
    const riceProbability = validatorOutput[0];
    if (!Number.isFinite(riceProbability) || riceProbability < 0 || riceProbability > 1) {
        throw new Error('The offline rice-leaf validator returned an invalid score.');
    }
    const riceThreshold = Number(models.thresholds.rice_leaf.threshold);
    if (riceProbability < riceThreshold) {
        return {
            status: 'not_rice_leaf',
            reason: 'rice_leaf_probability_below_validation_threshold',
            riceProbability,
            riceThreshold,
            qualityScore,
            blurThreshold,
        };
    }

    const viewScores = [];
    for (let rotation = 0; rotation < 4; rotation++) {
        const scores = await invoke(models.diseaseModel, rgba, models.diseaseShape, rotation);
        const sum = scores.reduce((total, value) => total + value, 0);
        if (scores.length !== models.labels.length ||
            scores.some(value => !Number.isFinite(value) || value < 0 || value > 1) ||
            Math.abs(sum - 1) > 0.02) {
            throw new Error('The offline disease model returned invalid class scores.');
        }
        viewScores.push(scores);
    }

    const averaged = models.labels.map((_, index) =>
        viewScores.reduce((total, scores) => total + scores[index], 0) / viewScores.length);
    let bestIndex = 0;
    for (let index = 1; index < averaged.length; index++) {
        if (averaged[index] > averaged[bestIndex]) bestIndex = index;
    }
    const label = models.labels[bestIndex];
    const confidence = averaged[bestIndex];
    const minimumConfidence = models.thresholds.disease.thresholds[label.key];
    const orientationAgreement = viewScores.filter(scores =>
        scores.indexOf(Math.max(...scores)) === bestIndex).length / viewScores.length;

    let status = 'diagnosed';
    let reason = 'validated_prediction';
    if (label.key === 'not_rice_leaf') {
        status = 'uncertain_prediction';
        reason = 'classifiers_disagree';
    } else if (!SUPPORTED_DISEASES.has(label.key) || !Number.isFinite(minimumConfidence)) {
        status = 'uncertain_prediction';
        reason = 'no_validated_threshold_for_predicted_class';
    } else if (confidence < minimumConfidence) {
        status = 'uncertain_prediction';
        reason = 'disease_confidence_below_validation_threshold';
    }

    return {
        status,
        reason,
        riceProbability,
        riceThreshold,
        qualityScore,
        blurThreshold,
        diseaseKey: label.key,
        diseaseName: label.name,
        confidence,
        minimumConfidence: Number.isFinite(minimumConfidence) ? minimumConfidence : null,
        orientationAgreement,
    };
}

window.OryzatixOfflineScanner = Object.freeze({ classify });
