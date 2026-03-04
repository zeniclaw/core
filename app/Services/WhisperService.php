<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhisperService
{
    private const MIME_EXTENSIONS = [
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/mp4' => 'm4a',
    ];

    public function transcribe(string $audioBytes, string $mimetype): ?string
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) {
            Log::warning('[whisper] No OpenAI API key configured');
            return null;
        }

        // Extract base mimetype (strip codecs parameter)
        $baseMime = explode(';', $mimetype)[0];
        $extension = self::MIME_EXTENSIONS[trim($baseMime)] ?? 'ogg';

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->attach('file', $audioBytes, "audio.{$extension}")
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'response_format' => 'text',
                ]);

            if ($response->successful()) {
                $text = trim($response->body());
                Log::info('[whisper] Transcription successful', ['length' => mb_strlen($text)]);
                return $text;
            }

            Log::warning('[whisper] API error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::warning('[whisper] Transcription failed: ' . $e->getMessage());
        }

        return null;
    }
}
