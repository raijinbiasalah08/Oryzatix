export const SEVERITY_VERSION = 'severity_experimental_v1';
export const SEVERITY_HASH = '4d6bfd0e5d5b1120d25b4e46f31284e5c94992ffdd5e212c3c14decdc0eead3c';

// Only the category leaves this function. Pixel ratios are not user-facing measurements.
export function severityFromProbabilities(values, channelFirst = false) {
    const pixels = 224 * 224;
    if (values.length !== pixels * 3) return null;
    let leaf = 0;
    let affected = 0;
    for (let pixel = 0; pixel < pixels; pixel++) {
        const offset = channelFirst ? pixel : pixel * 3;
        const background = values[offset];
        const unaffected = values[channelFirst ? pixels + pixel : offset + 1];
        const symptomatic = values[channelFirst ? 2 * pixels + pixel : offset + 2];
        if (!Number.isFinite(background) || background < 0 || background > 1 ||
            !Number.isFinite(unaffected) || unaffected < 0 || unaffected > 1 ||
            !Number.isFinite(symptomatic) || symptomatic < 0 || symptomatic > 1 ||
            Math.abs(background + unaffected + symptomatic - 1) > 0.02) return null;
        const tissue = background >= unaffected && background >= symptomatic ? 0 :
            unaffected >= symptomatic ? 1 : 2;
        if (tissue > 0) leaf++;
        if (tissue === 2) affected++;
    }
    if (leaf < pixels * 0.01 || affected === 0) return null;
    const fraction = affected / leaf;
    return fraction <= 0.25 ? 'mild' : fraction <= 0.60 ? 'moderate' : 'severe';
}
