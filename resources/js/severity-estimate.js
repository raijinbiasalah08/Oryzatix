export const SEVERITY_VERSION = 'severity_experimental_v1';
export const SEVERITY_HASH = '4d6bfd0e5d5b1120d25b4e46f31284e5c94992ffdd5e212c3c14decdc0eead3c';

function levelForFraction(fraction) {
    return fraction <= 0.25 ? 'mild' : fraction <= 0.60 ? 'moderate' : 'severe';
}

// Only the category leaves this function. Pixel ratios are not user-facing measurements.
export function severityFromProbabilities(values, channelFirst = false) {
    const pixels = 224 * 224;
    if (values?.length !== pixels * 3) return null;
    let leaf = 0;
    let affected = 0;
    let ambiguous = 0;
    for (let pixel = 0; pixel < pixels; pixel++) {
        const offset = channelFirst ? pixel : pixel * 3;
        const background = values[offset];
        const unaffected = values[channelFirst ? pixels + pixel : offset + 1];
        const symptomatic = values[channelFirst ? 2 * pixels + pixel : offset + 2];
        if (!Number.isFinite(background) || background < 0 || background > 1 ||
            !Number.isFinite(unaffected) || unaffected < 0 || unaffected > 1 ||
            !Number.isFinite(symptomatic) || symptomatic < 0 || symptomatic > 1 ||
            Math.abs(background + unaffected + symptomatic - 1) > 0.02) return null;
        if (symptomatic > background + unaffected) {
            affected++;
            leaf++;
        } else if (unaffected > background + symptomatic) {
            leaf++;
        } else if (background <= symptomatic + unaffected) {
            ambiguous++;
        }
    }
    if (leaf < pixels * 0.01 || affected === 0) return null;
    // A pixel with no majority could be background, healthy tissue, or affected tissue.
    // Report a level only when assigning all such pixels either way gives the same level.
    const possibleLeaf = leaf + ambiguous;
    const low = levelForFraction(affected / possibleLeaf);
    const high = levelForFraction((affected + ambiguous) / possibleLeaf);
    return low === high ? low : null;
}
