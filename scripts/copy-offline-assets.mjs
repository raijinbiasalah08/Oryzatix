import { copyFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const modelSource = path.join(root, 'oryzatix_app', 'assets', 'models');
const modelTarget = path.join(root, 'public', 'models');
const runtimeSource = path.join(root, 'node_modules', '@litertjs', 'core', 'wasm');
const runtimeTarget = path.join(root, 'public', 'litert');

await mkdir(modelTarget, { recursive: true });
await mkdir(runtimeTarget, { recursive: true });

for (const name of [
    'class_labels.json',
    'mobilenetv2_rice_leaf.tflite',
    'rice_leaf_validator.tflite',
    'scan_validation_thresholds.json',
]) {
    await copyFile(path.join(modelSource, name), path.join(modelTarget, name));
}

for (const name of ['litert_wasm_internal.js', 'litert_wasm_internal.wasm']) {
    await copyFile(path.join(runtimeSource, name), path.join(runtimeTarget, name));
}

console.log('Copied the offline rice scanner models and LiteRT WASM runtime into public assets.');
