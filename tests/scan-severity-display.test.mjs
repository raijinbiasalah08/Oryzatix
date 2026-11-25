import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { getScanSeverity, getScanTreatments, scanSeverityLabel } from '../resources/js/utils/diseases.js';

const saved = {
    disease_key: 'blast', severity: 'severe',
    treatments: { chemical: [{ name: 'Previously saved recommendation' }], organic: [] },
};

test('unknown provenance never supplies a level or a saved treatment', () => {
    for (const status of [undefined, 'unassessed', 'unknown']) {
        const scan = { ...saved, severity_status: status, affected_percentage: 90 };
        const severity = getScanSeverity(scan);
        assert.equal(scanSeverityLabel(severity, 'english'), 'Not assessed');
        assert.deepEqual(getScanTreatments(scan, severity), { chemical: [], organic: [] });
    }
});

test('an experimental estimate is labeled in both languages and never selects a treatment', () => {
    const scan = { ...saved, severity_status: 'model_estimated', severity: 'mild', affected_percentage: 90 };
    const severity = getScanSeverity(scan);
    assert.equal(scanSeverityLabel(severity, 'english'), 'Mild · Experimental AI estimate');
    assert.match(scanSeverityLabel(severity, 'tagalog'), /Eksperimental/);
    assert.deepEqual(getScanTreatments(scan, severity), { chemical: [], organic: [] });
});

test('recorded user and expert assessments retain their origin and matching guidance', () => {
    const user = { ...saved, severity_source: 'user_assessed', affected_percentage: 5 };
    assert.deepEqual(getScanSeverity(user), { level: 'severe', source: 'user_assessed' });
    assert.deepEqual(getScanTreatments(user, getScanSeverity(user)), user.treatments);
    const expert = { disease_key: 'blb', severity_status: 'expert_validated', affected_percentage: 45 };
    assert.deepEqual(getScanSeverity(expert), { level: 'moderate', source: 'expert_validated' });
    assert.equal(getScanSeverity({ ...expert, affected_percentage: ' ' }).level, 'unassessed');
});

test('legacy healthy labels are recognized without classifying an unhealthy label as healthy', () => {
    assert.equal(getScanSeverity({ disease: 'Healthy Rice Leaf' }).level, 'healthy');
    assert.equal(getScanSeverity({ disease: 'Not Healthy Leaf', severity: 'healthy' }).level, 'unassessed');
    assert.equal(getScanSeverity({ ...saved, severity: 'healthy' }).level, 'unassessed');
});

test('the dashboard labels experimental estimates and withholds cached stage-specific treatment', () => {
    const readout = {};
    const treatmentList = {};
    const page = vm.createContext({
        window: { addEventListener() {} },
        document: {
            addEventListener() {},
            querySelector: () => null,
            getElementById: id => ({ resultSeverityReadout: readout, resultTreatmentItemsList: treatmentList })[id],
        },
    });
    const script = readFileSync(new URL('../public/js/rice-detector.js', import.meta.url), 'utf8');
    vm.runInContext(script, page);
    const scan = { ...saved, severity_status: 'model_estimated' };
    page.updateSeverityReadout(scan, 'english');
    assert.equal(readout.textContent, 'Experimental estimate · Severe');
    assert.equal(page.scanSeverityLabel(scan), 'Severe (experimental estimate)');
    page.renderResultTreatments(scan, 'english');
    assert.match(treatmentList.textContent, /confirm the disease and severity/);
    assert.equal(treatmentList.innerHTML, undefined);
    assert.equal(page.canUseSeverityForGuidance({ ...saved, severity_source: 'user_assessed' }), true);
    assert.equal(page.scanSeverityLabel(saved), 'Not assessed');
});
