'use strict';

// The current Vercel bootstrap does not provide the legacy task-root variable.
process.env.LAMBDA_TASK_ROOT ||= __dirname;

function normalizeBody(body, base64 = false) {
    if (body == null) return undefined;
    if (Buffer.isBuffer(body)) return body;
    if (Array.isArray(body)) {
        if (!body.every(byte => Number.isInteger(byte) && byte >= 0 && byte <= 255)) {
            throw new TypeError('Request body contains invalid bytes');
        }
        return Buffer.from(body);
    }
    if (typeof body === 'string') return Buffer.from(body, base64 ? 'base64' : 'utf8');
    throw new TypeError('Unsupported request body type');
}

function normalizeRequest(event) {
    let request = event;
    if (event.Action === 'Invoke') {
        const nested = JSON.parse(normalizeBody(event.body).toString('utf8'));
        request = { ...nested, httpMethod: nested.method,
            isBase64Encoded: nested.encoding === 'base64' };
        if (nested.encoding != null && nested.encoding !== 'base64' && nested.encoding !== 'utf8') {
            throw new TypeError('Unsupported request encoding');
        }
    }
    const headers = request.headers || {};
    const lower = Object.fromEntries(Object.entries(headers).map(([key, value]) => [key.toLowerCase(), value]));
    const { Action, ...rest } = request;
    return { ...rest, headers, host: request.host || lower['x-forwarded-host'] || lower.host,
        body: normalizeBody(request.body, request.isBase64Encoded === true) };
}

exports.normalizeRequest = normalizeRequest;
exports.launcher = async (event, context) => {
    // Lazy require also makes request conversion independently testable.
    const original = require('./launcher-original.js');
    return original.launcher(normalizeRequest(event), context);
};
