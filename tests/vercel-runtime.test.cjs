const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizeRequest } = require('../scripts/vercel-launcher.cjs');

test('GET retains query and resolves case-insensitive host without a body', () => {
    const source = { httpMethod: 'GET', path: '/?page=2', headers: { Host: 'example.test' }, body: null };
    const result = normalizeRequest(source);
    assert.equal(result.path, '/?page=2');
    assert.equal(result.host, 'example.test');
    assert.equal(result.body, undefined);
    assert.equal(source.body, null);
});
test('POST byte arrays, UTF-8 JSON and base64 retain exact bytes', () => {
    for (const bytes of [Buffer.from('{"name":"José"}'), Buffer.from([0, 255, 13, 10, 128])]) {
        for (const body of [bytes, [...bytes], bytes.toString('base64')]) {
            assert.deepEqual(normalizeRequest({ body, isBase64Encoded: typeof body === 'string' }).body, bytes);
        }
    }
    assert.deepEqual(normalizeRequest({ body: 'name=José' }).body, Buffer.from('name=José'));
});
test('multipart body is unchanged and forwarded host takes precedence', () => {
    const bytes = Buffer.from('--boundary\r\nContent-Disposition: form-data; name="image"\r\n\r\nabc\r\n--boundary--\r\n');
    const result = normalizeRequest({ body: [...bytes], headers: { Host: 'internal', 'X-Forwarded-Host': 'public.test' } });
    assert.deepEqual(result.body, bytes);
    assert.equal(result.host, 'public.test');
});
test('legacy Invoke envelopes remain supported', () => {
    const result = normalizeRequest({ Action: 'Invoke', body: JSON.stringify({ method: 'POST', path: '/login', encoding: 'base64', body: Buffer.from('email=test').toString('base64') }) });
    assert.equal(result.httpMethod, 'POST');
    assert.equal(result.Action, undefined);
    assert.deepEqual(result.body, Buffer.from('email=test'));
});
test('invalid byte arrays and objects fail rather than corrupt uploads', () => {
    for (const body of [[256], [-1], [1.5], ['1'], {}]) assert.throws(() => normalizeRequest({ body }), TypeError);
});
