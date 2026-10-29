<?php

use App\Services\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['services.google.client_id' => 'test-client']);
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    if (is_file($bundledConfig)) {
        $options['config'] = $bundledConfig;
    }
    $this->key = openssl_pkey_new($options);
    $publicKey = openssl_pkey_get_details($this->key)['key'];
    Http::preventStrayRequests();
    Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::response(['test-key' => $publicKey])]);
    $this->claims = [
        'iss' => 'https://accounts.google.com', 'aud' => 'test-client',
        'sub' => 'google-user', 'email' => 'farmer@gmail.com', 'email_verified' => true,
        'iat' => time() - 10, 'exp' => time() + 600,
    ];
});

it('verifies a signed Google identity and caches the certificates', function () {
    $token = JWT::encode($this->claims, $this->key, 'RS256', 'test-key');
    $verifier = new GoogleIdTokenVerifier;
    expect($verifier->verify($token)['sub'])->toBe('google-user');
    $verifier->verify($token);
    Http::assertSentCount(1);
});

it('rejects invalid identity claims', function ($claim, $value) {
    $this->claims[$claim] = $value;
    $token = JWT::encode($this->claims, $this->key, 'RS256', 'test-key');
    expect(fn () => (new GoogleIdTokenVerifier)->verify($token))->toThrow(UnexpectedValueException::class);
})->with([
    ['aud', 'another-app'], ['iss', 'https://attacker.example'], ['exp', 1],
    ['email_verified', false], ['sub', ''],
]);

it('rejects a forged signature', function () {
    $token = JWT::encode($this->claims, str_repeat('x', 32), 'HS256', 'test-key');
    expect(fn () => (new GoogleIdTokenVerifier)->verify($token))->toThrow(UnexpectedValueException::class);
});
