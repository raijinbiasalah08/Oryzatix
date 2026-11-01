import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { severityFromProbabilities, SEVERITY_HASH } from '../resources/js/severity-estimate.js';

function pixels(affected, channelFirst = false, leaf = 1000) {
    const size = 224 * 224;
    const values = new Float32Array(size * 3);
    for (let i = 0; i < size; i++) {
        const tissue = i < affected ? 2 : i < leaf ? 1 : 0;
        values[channelFirst ? tissue * size + i : i * 3 + tissue] = 1;
    }
    return values;
}
test('severity boundaries exclude background in both tensor layouts', () => {
    for (const channelFirst of [false, true]) {
        for (const [affected, expected] of [[250, 'mild'], [251, 'moderate'], [600, 'moderate'], [601, 'severe']]) {
            assert.equal(severityFromProbabilities(pixels(affected, channelFirst), channelFirst), expected);
        }
    }
});
test('empty, non-symptomatic, insufficient, malformed masks stay unassessed', () => {
    assert.equal(severityFromProbabilities(pixels(0)), null);
    assert.equal(severityFromProbabilities(pixels(1, false, 10)), null);
    assert.equal(severityFromProbabilities(new Float32Array(0)), null);
    const invalid = pixels(250);
    invalid[0] = NaN;
    assert.equal(severityFromProbabilities(invalid), null);
    invalid[0] = 2;
    assert.equal(severityFromProbabilities(invalid), null);
});
test('packaged model matches the pinned browser/server artifact', async () => {
    const model = await readFile(new URL('../oryzatix_app/assets/models/severity_experimental_v1.tflite', import.meta.url));
    assert.equal(createHash('sha256').update(model).digest('hex'), SEVERITY_HASH);
    const metadata = JSON.parse(await readFile(new URL('../oryzatix_app/assets/models/severity_experimental_v1.json', import.meta.url)));
    assert.equal(metadata.status, 'experimental_unvalidated');
    assert.equal(metadata.actual_severity_accuracy, null);
    assert.equal(metadata.model_sha256, SEVERITY_HASH);
});
