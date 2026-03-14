<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text-to-Speech service (D9.1).
 * Supports ElevenLabs API and system TTS as fallback.
 */
class TTSService
{
    /**
     * Convert text to speech audio file.
     *
     * @return array{success: bool, path: ?string, error: ?string, duration_ms: int}
     */
    public function synthesize(string $text, string $voice = 'default', string $language = 'fr'): array
    {
        $start = microtime(true);

        // Try ElevenLabs if API key is configured
        $apiKey = AppSetting::get('elevenlabs_api_key');
        if ($apiKey) {
            return $this->synthesizeElevenLabs($text, $voice, $apiKey, $start);
        }

        // Fallback: use system espeak-ng if available
        return $this->synthesizeLocal($text, $language, $start);
    }

    private function synthesizeElevenLabs(string $text, string $voice, string $apiKey, float $start): array
    {
        $voiceId = match ($voice) {
            'male' => 'pNInz6obpgDQGcFmaJgB', // Adam
            'female' => 'EXAVITQu4vr4xnSDxMaL', // Bella
            default => 'pNInz6obpgDQGcFmaJgB', // Adam as default
        };

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'xi-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                    'text' => mb_substr($text, 0, 5000), // ElevenLabs limit
                    'model_id' => 'eleven_multilingual_v2',
                ]);

            if ($response->successful()) {
                $path = storage_path('app/tts/' . uniqid() . '.mp3');
                if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
                file_put_contents($path, $response->body());

                return [
                    'success' => true,
                    'path' => $path,
                    'error' => null,
                    'duration_ms' => (int)((microtime(true) - $start) * 1000),
                    'provider' => 'elevenlabs',
                ];
            }

            return [
                'success' => false,
                'path' => null,
                'error' => "ElevenLabs API error: HTTP {$response->status()}",
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage(),
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function synthesizeLocal(string $text, string $language, float $start): array
    {
        // Check if espeak-ng is available
        $check = shell_exec('which espeak-ng 2>/dev/null');
        if (!$check) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'TTS non disponible. Configurez elevenlabs_api_key dans les parametres ou installez espeak-ng.',
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ];
        }

        $path = storage_path('app/tts/' . uniqid() . '.wav');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);

        $text = escapeshellarg(mb_substr($text, 0, 1000));
        $lang = escapeshellarg($language);
        shell_exec("espeak-ng -v {$lang} -w " . escapeshellarg($path) . " {$text} 2>/dev/null");

        if (file_exists($path) && filesize($path) > 0) {
            return [
                'success' => true,
                'path' => $path,
                'error' => null,
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
                'provider' => 'espeak',
            ];
        }

        return [
            'success' => false,
            'path' => null,
            'error' => 'Local TTS generation failed',
            'duration_ms' => (int)((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * Check if TTS is available (any provider).
     */
    public static function isAvailable(): bool
    {
        if (AppSetting::get('elevenlabs_api_key')) return true;
        return (bool) shell_exec('which espeak-ng 2>/dev/null');
    }
}
