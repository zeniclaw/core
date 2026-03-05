<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceTranscriber
{
    private const MIME_EXTENSIONS = [
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/mp4' => 'm4a',
        'audio/webm' => 'webm',
        'audio/amr' => 'amr',
        'audio/aac' => 'aac',
    ];

    /**
     * Transcribe audio bytes to text.
     *
     * Returns ['text' => string, 'confidence' => float, 'language' => string] or null on failure.
     */
    public function transcribe(string $audioBytes, string $mimetype): ?array
    {
        $provider = config('voice_command.provider', 'whisper');

        return match ($provider) {
            'deepgram' => $this->transcribeWithDeepgram($audioBytes, $mimetype),
            default => $this->transcribeWithWhisper($audioBytes, $mimetype),
        };
    }

    private function transcribeWithWhisper(string $audioBytes, string $mimetype): ?array
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) {
            Log::warning('[voice_transcriber] No OpenAI API key configured');
            return null;
        }

        $baseMime = explode(';', $mimetype)[0];
        $extension = self::MIME_EXTENSIONS[trim($baseMime)] ?? 'ogg';
        $language = config('voice_command.default_language', 'fr');

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->attach('file', $audioBytes, "audio.{$extension}")
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'response_format' => 'verbose_json',
                    'language' => $language,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $text = trim($data['text'] ?? '');

                if (!$text) {
                    Log::warning('[voice_transcriber] Whisper returned empty text');
                    return null;
                }

                // Whisper verbose_json includes segments with avg_logprob
                $confidence = $this->calculateWhisperConfidence($data);

                Log::info('[voice_transcriber] Whisper transcription OK', [
                    'length' => mb_strlen($text),
                    'language' => $data['language'] ?? $language,
                    'confidence' => $confidence,
                ]);

                return [
                    'text' => $text,
                    'confidence' => $confidence,
                    'language' => $data['language'] ?? $language,
                ];
            }

            Log::warning('[voice_transcriber] Whisper API error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::warning('[voice_transcriber] Whisper exception: ' . $e->getMessage());
        }

        return null;
    }

    private function transcribeWithDeepgram(string $audioBytes, string $mimetype): ?array
    {
        $apiKey = AppSetting::get('deepgram_api_key');
        if (!$apiKey) {
            Log::warning('[voice_transcriber] No Deepgram API key configured');
            return null;
        }

        $baseMime = explode(';', $mimetype)[0];
        $language = config('voice_command.default_language', 'fr');

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->withHeaders(['Content-Type' => trim($baseMime)])
                ->withBody($audioBytes, trim($baseMime))
                ->post("https://api.deepgram.com/v1/listen?language={$language}&model=nova-2&smart_format=true");

            if ($response->successful()) {
                $data = $response->json();
                $alternative = $data['results']['channels'][0]['alternatives'][0] ?? null;

                if (!$alternative || empty($alternative['transcript'])) {
                    Log::warning('[voice_transcriber] Deepgram returned empty transcript');
                    return null;
                }

                $text = trim($alternative['transcript']);
                $confidence = $alternative['confidence'] ?? 0.0;
                $detectedLang = $data['results']['channels'][0]['detected_language'] ?? $language;

                Log::info('[voice_transcriber] Deepgram transcription OK', [
                    'length' => mb_strlen($text),
                    'language' => $detectedLang,
                    'confidence' => $confidence,
                ]);

                return [
                    'text' => $text,
                    'confidence' => $confidence,
                    'language' => $detectedLang,
                ];
            }

            Log::warning('[voice_transcriber] Deepgram API error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);
        } catch (\Exception $e) {
            Log::warning('[voice_transcriber] Deepgram exception: ' . $e->getMessage());
        }

        return null;
    }

    private function calculateWhisperConfidence(array $data): float
    {
        $segments = $data['segments'] ?? [];
        if (empty($segments)) {
            return 0.9; // Default if no segments
        }

        $totalLogProb = 0;
        $count = 0;
        foreach ($segments as $segment) {
            if (isset($segment['avg_logprob'])) {
                $totalLogProb += $segment['avg_logprob'];
                $count++;
            }
        }

        if ($count === 0) {
            return 0.9;
        }

        // Convert avg_logprob to a 0-1 confidence score
        // avg_logprob typically ranges from -1.0 (low) to 0.0 (high)
        $avgLogProb = $totalLogProb / $count;
        return max(0.0, min(1.0, 1.0 + $avgLogProb));
    }
}
