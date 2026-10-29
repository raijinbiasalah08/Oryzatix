<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class GoogleIdTokenVerifier
{
    public function verify(string $token): array
    {
        $clientId = config('services.google.client_id');
        if (!is_string($clientId) || $clientId === '') {
            throw new UnexpectedValueException('Google sign-in is not configured.');
        }

        $certificates = Cache::remember('google_signin_certificates', 300, function () {
            return Http::timeout(10)->get('https://www.googleapis.com/oauth2/v1/certs')
                ->throw()->json();
        });

        $keys = [];
        foreach ($certificates as $id => $certificate) {
            $keys[$id] = new Key($certificate, 'RS256');
        }

        // The library verifies the signature, algorithm and token timestamps.
        $claims = (array) JWT::decode($token, $keys);
        if (($claims['aud'] ?? null) !== $clientId
            || !in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || !isset($claims['exp']) || $claims['exp'] <= time()
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ($claims['email_verified'] ?? false) !== true
            || !is_string($claims['email'] ?? null)) {
            throw new UnexpectedValueException('Invalid Google identity.');
        }

        return $claims;
    }
}
