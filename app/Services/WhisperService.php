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
        'audio/webm' => 'webm',
        'audio/flac' => 'flac',
        'audio/x-wav' => 'wav',
    ];

    /**
     * Transcribe audio using the best available provider.
     * Priority: local whisper.cpp → OpenAI Whisper API
     */
    public function transcribe(string $audioBytes, string $mimetype): ?string
    {
        // Try local whisper.cpp first (free, fast, private)
        $localResult = $this->transcribeLocal($audioBytes, $mimetype);
        if ($localResult !== null) {
            return $localResult;
        }

        // Fallback to OpenAI Whisper API
        return $this->transcribeOpenAI($audioBytes, $mimetype);
    }

    /**
     * Transcribe using local whisper.cpp binary.
     * Expects whisper.cpp binary at /usr/local/bin/whisper-cpp or WHISPER_CPP_PATH env.
     */
    private function transcribeLocal(string $audioBytes, string $mimetype): ?string
    {
        $whisperBin = env('WHISPER_CPP_PATH', '/usr/local/bin/whisper-cpp');
        $modelPath = env('WHISPER_CPP_MODEL', '/models/ggml-base.bin');

        if (!file_exists($whisperBin) || !file_exists($modelPath)) {
            return null; // whisper.cpp not installed — fall through
        }

        $baseMime = explode(';', $mimetype)[0];
        $extension = self::MIME_EXTENSIONS[trim($baseMime)] ?? 'ogg';
        $tmpInput = tempnam(sys_get_temp_dir(), 'whisper_in_') . ".{$extension}";
        $tmpWav = tempnam(sys_get_temp_dir(), 'whisper_wav_') . '.wav';

        try {
            file_put_contents($tmpInput, $audioBytes);

            // Convert to 16kHz mono WAV (whisper.cpp requirement)
            $ffmpegCmd = sprintf(
                'ffmpeg -i %s -ar 16000 -ac 1 -f wav %s -y 2>/dev/null',
                escapeshellarg($tmpInput),
                escapeshellarg($tmpWav)
            );

            exec($ffmpegCmd, $output, $exitCode);
            if ($exitCode !== 0 || !file_exists($tmpWav)) {
                Log::debug('[whisper-local] ffmpeg conversion failed');
                return null;
            }

            // Run whisper.cpp
            $cmd = sprintf(
                '%s -m %s -f %s -nt -l auto 2>/dev/null',
                escapeshellarg($whisperBin),
                escapeshellarg($modelPath),
                escapeshellarg($tmpWav)
            );

            $text = null;
            exec($cmd, $lines, $exitCode);

            if ($exitCode === 0 && !empty($lines)) {
                $text = trim(implode(' ', $lines));
                // whisper.cpp sometimes outputs [BLANK_AUDIO] for silence
                if ($text === '[BLANK_AUDIO]' || $text === '') {
                    $text = null;
                }
            }

            if ($text) {
                Log::info('[whisper-local] Transcription successful', ['length' => mb_strlen($text)]);
            }

            return $text;
        } catch (\Exception $e) {
            Log::warning('[whisper-local] Failed: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpInput);
            @unlink($tmpWav);
        }
    }

    /**
     * Transcribe using OpenAI Whisper API.
     */
    private function transcribeOpenAI(string $audioBytes, string $mimetype): ?string
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) {
            Log::warning('[whisper] No OpenAI API key configured');
            return null;
        }

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
                Log::info('[whisper-openai] Transcription successful', ['length' => mb_strlen($text)]);
                return $text;
            }

            Log::warning('[whisper-openai] API error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::warning('[whisper-openai] Transcription failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Check if any transcription provider is available.
     */
    public static function isAvailable(): bool
    {
        $whisperBin = env('WHISPER_CPP_PATH', '/usr/local/bin/whisper-cpp');
        $modelPath = env('WHISPER_CPP_MODEL', '/models/ggml-base.bin');

        if (file_exists($whisperBin) && file_exists($modelPath)) {
            return true;
        }

        return (bool) AppSetting::get('openai_api_key');
    }
}
