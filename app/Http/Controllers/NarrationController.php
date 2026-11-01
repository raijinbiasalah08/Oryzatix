<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NarrationController extends Controller
{
    public function synthesize(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1800'],
            'language' => ['required', 'in:english,tagalog'],
        ]);

        $apiKey = trim((string) config('services.elevenlabs.api_key'));
        $voiceId = trim((string) config('services.elevenlabs.voice_id'));
        if ($apiKey === '' || $voiceId === '') {
            return response()->json([
                'success' => false,
                'message' => 'The online narrator is not configured.',
            ], 503);
        }

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $apiKey,
            ])
                ->accept('audio/mpeg')
                ->connectTimeout(10)
                ->timeout(45)
                ->post('https://api.elevenlabs.io/v1/text-to-dialogue?output_format=mp3_44100_128', [
                    'inputs' => [[
                        'text' => trim($data['text']),
                        'voice_id' => $voiceId,
                    ]],
                    'model_id' => 'eleven_v4',
                    'language_code' => $data['language'] === 'tagalog' ? 'tl' : 'en',
                    'apply_text_normalization' => 'auto',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('ElevenLabs narration request could not connect.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The online narrator is temporarily unavailable.',
            ], 502);
        }

        if (!$response->successful() || $response->body() === '') {
            Log::warning('ElevenLabs narration request failed.', [
                'status' => $response->status(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The online narrator could not generate audio.',
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type', 'audio/mpeg'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
