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

        $apiKey = trim((string) config('services.azure_speech.key'));
        $region = strtolower(trim((string) config('services.azure_speech.region')));
        if ($apiKey === '' || !preg_match('/^[a-z0-9-]+$/', $region)) {
            return response()->json([
                'success' => false,
                'message' => 'The online narrator is not configured.',
            ], 503);
        }

        $language = $data['language'] === 'tagalog' ? 'tagalog' : 'english';
        $locale = $language === 'tagalog' ? 'fil-PH' : 'en-PH';
        $voice = trim((string) config(
            $language === 'tagalog' ? 'services.azure_speech.voice_tagalog' : 'services.azure_speech.voice_english'
        ));
        if ($voice === '') {
            return response()->json([
                'success' => false,
                'message' => 'The online narrator is not configured.',
            ], 503);
        }

        $escapedLocale = htmlspecialchars($locale, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $escapedVoice = htmlspecialchars($voice, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $escapedText = htmlspecialchars(trim($data['text']), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $spokenText = preg_replace('/([!?;])\s+/u', '$1<break time="180ms"/> ', $escapedText) ?? $escapedText;
        $ssml = "<speak version=\"1.0\" xml:lang=\"{$escapedLocale}\"><voice xml:lang=\"{$escapedLocale}\" name=\"{$escapedVoice}\"><prosody rate=\"-2%\">{$spokenText}</prosody></voice></speak>";

        try {
            $response = Http::withHeaders([
                'Ocp-Apim-Subscription-Key' => $apiKey,
                'Content-Type' => 'application/ssml+xml',
                'X-Microsoft-OutputFormat' => 'audio-24khz-160kbitrate-mono-mp3',
                'User-Agent' => 'Oryzatix',
            ])
                ->accept('audio/mpeg')
                ->connectTimeout(10)
                ->timeout(45)
                ->withBody($ssml, 'application/ssml+xml')
                ->post("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
        } catch (ConnectionException $exception) {
            Log::warning('Azure Speech narration request could not connect.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The online narrator is temporarily unavailable.',
            ], 502);
        }

        if (!$response->successful() || $response->body() === '') {
            Log::warning('Azure Speech narration request failed.', [
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
