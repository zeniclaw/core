<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\VoiceTranscriber;
use Illuminate\Support\Facades\Log;

class VoiceCommandAgent extends BaseAgent
{
    // Video mimetypes that contain audio and can be transcribed
    private const TRANSCRIBABLE_VIDEO_TYPES = [
        'video/mp4', 'video/webm', 'video/ogg', 'video/3gpp', 'video/mpeg',
    ];

    // Max audio size in bytes (25MB — Whisper API limit)
    private const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    // Minimum meaningful characters in a transcript (below = silence/noise)
    private const MIN_TRANSCRIPT_CHARS = 3;

    // Max download attempts before giving up
    private const MAX_DOWNLOAD_ATTEMPTS = 2;

    // Language code to WhatsApp-friendly flag emoji
    private const LANGUAGE_FLAGS = [
        'fr' => '🇫🇷', 'en' => '🇬🇧', 'es' => '🇪🇸', 'de' => '🇩🇪',
        'it' => '🇮🇹', 'pt' => '🇵🇹', 'ar' => '🇸🇦', 'zh' => '🇨🇳',
        'ja' => '🇯🇵', 'ko' => '🇰🇷', 'ru' => '🇷🇺', 'nl' => '🇳🇱',
        'tr' => '🇹🇷', 'pl' => '🇵🇱', 'sv' => '🇸🇪',
    ];

    public function name(): string
    {
        return 'voice_command';
    }

    public function description(): string
    {
        return 'Agent interne de traitement des messages vocaux et videos. Telecharge et transcrit les messages audio/video via Whisper ou Deepgram, avec gestion de la confiance de transcription, detection du silence/bruit, confirmation interactive en cas de doute et indicateur de langue par drapeau. Supporte ogg, mp3, wav, mp4, webm, amr et plus.';
    }

    public function keywords(): array
    {
        return ['vocal', 'voice', 'audio', 'transcrire', 'transcription', 'message vocal', 'note vocale'];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $mimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        return $context->hasMedia && $this->isTranscribableMimetype($mimetype);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $mimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        $isVideo = $this->isVideoMimetype($mimetype);

        $this->log($context, 'Processing voice/video command', [
            'mimetype' => $mimetype,
            'hasMedia' => $context->hasMedia,
            'is_video' => $isVideo,
        ]);

        // Download audio from WAHA (with retry on transient failures)
        $audioBytes = $this->downloadAudioWithRetry($context);
        if (!$audioBytes) {
            $this->log($context, 'Failed to download audio', [
                'mediaUrl' => $context->mediaUrl,
                'mediaFromArray' => $context->media['url'] ?? null,
            ], 'warn');
            $reply = $isVideo
                ? "Je n'ai pas pu telecharger ta video. Peux-tu reessayer ou m'ecrire directement en texte ?"
                : "Je n'ai pas pu telecharger ton message vocal. Peux-tu reessayer ou m'ecrire en texte ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Check audio size (Whisper limit: 25MB)
        $audioSize = strlen($audioBytes);
        if ($audioSize > self::MAX_AUDIO_BYTES) {
            $sizeMb = round($audioSize / 1024 / 1024, 1);
            $this->log($context, 'Audio too large for transcription', ['size_mb' => $sizeMb], 'warn');
            $reply = "Ton message est trop long ({$sizeMb} MB). La limite est de 25 MB. Envoie un message plus court ou ecris-moi directement en texte.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Transcribe audio — for videos, use audio/mp4 as effective mimetype
        $transcriber = new VoiceTranscriber();
        $effectiveMimetype = $isVideo ? 'audio/mp4' : ($mimetype ?? 'audio/ogg');
        $result = $transcriber->transcribe($audioBytes, $effectiveMimetype);

        if (!$result || empty($result['text'])) {
            $this->log($context, 'Transcription failed', ['mimetype' => $effectiveMimetype], 'warn');
            $reply = $isVideo
                ? "Je n'ai pas reussi a extraire l'audio de ta video. Peux-tu reessayer ou m'ecrire en texte ?"
                : "Je n'ai pas reussi a transcrire ton message vocal. Peux-tu reessayer ou m'ecrire en texte ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $transcript = trim($result['text']);
        $confidence = $result['confidence'] ?? 1.0;
        $language = $result['language'] ?? config('voice_command.default_language', 'fr');
        $minConfidence = config('voice_command.min_confidence', 0.8);
        $sizeKb = round($audioSize / 1024, 1);

        // Detect silence or background noise (transcript too short to be meaningful)
        if ($this->isSilenceOrNoise($transcript)) {
            $this->log($context, 'Transcript too short — likely silence or noise', [
                'transcript' => $transcript,
                'size_kb' => $sizeKb,
            ], 'warn');
            $reply = "Je n'ai pas detecte de parole dans ton message. C'est peut-etre du silence ou du bruit de fond ? Reessaie ou ecris-moi directement en texte.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->log($context, 'Transcription successful', [
            'transcript' => mb_substr($transcript, 0, 200),
            'confidence' => $confidence,
            'language' => $language,
            'is_video' => $isVideo,
            'size_kb' => $sizeKb,
        ]);

        // If confidence is too low, store transcript and ask for confirmation
        if ($confidence < $minConfidence) {
            return $this->handleLowConfidence($context, $transcript, $confidence, $language);
        }

        // Build language note with flag emoji for non-default-language transcriptions
        $languageNote = $this->buildLanguageNote($language);

        // Return transcript — orchestrator will re-route to the appropriate agent
        return AgentResult::reply($transcript . $languageNote, [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'language' => $language,
            'source' => 'voice',
        ]);
    }

    /**
     * Handle follow-up messages when a low-confidence transcript is pending confirmation.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'low_confidence_confirm') {
            return null;
        }

        $this->clearPendingContext($context);

        $userReply = mb_strtolower(trim($context->body ?? ''));
        $storedTranscript = $pendingContext['data']['transcript'] ?? null;

        if (!$storedTranscript) {
            return null;
        }

        // User confirmed the transcript
        if (preg_match("/\b(oui|yes|ok|ouais|yep|affirm|correct|exact|c'est ?ca|c'est bien)\b/iu", $userReply)) {
            $language = $pendingContext['data']['language'] ?? 'fr';
            $confidence = $pendingContext['data']['confidence'] ?? 0.0;

            $this->log($context, 'Low-confidence transcript confirmed by user', [
                'transcript' => mb_substr($storedTranscript, 0, 200),
            ]);

            // Handoff with the confirmed transcript — orchestrator re-routes
            return AgentResult::reply($storedTranscript, [
                'transcript' => $storedTranscript,
                'confidence' => $confidence,
                'language' => $language,
                'source' => 'voice',
                'user_confirmed' => true,
            ]);
        }

        // User denied / wants to cancel
        if (preg_match('/\b(non|no|nope|annuler|cancel|incorrect|faux|wrong|pas ca)\b/iu', $userReply)) {
            $reply = "Transcription annulee. Reessaie le message vocal ou ecris-moi directement en texte.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Ambiguous reply — restore pending context and re-ask
        $this->setPendingContext($context, 'low_confidence_confirm', [
            'transcript' => $storedTranscript,
            'confidence' => $pendingContext['data']['confidence'] ?? 0.0,
            'language' => $pendingContext['data']['language'] ?? 'fr',
        ], ttlMinutes: 3, expectRawInput: true);

        $preview = mb_substr($storedTranscript, 0, 120) . (mb_strlen($storedTranscript) > 120 ? '...' : '');
        $reply = "Reponds *oui* pour valider ou *non* pour annuler la transcription :\n\n\"{$preview}\"";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    /**
     * Handle low-confidence transcription: store in pending context and ask user to confirm.
     * Formatting: bold for confidence %, italic for transcript preview.
     */
    private function handleLowConfidence(AgentContext $context, string $transcript, float $confidence, string $language): AgentResult
    {
        $confidencePct = round($confidence * 100);
        $languageNote = $this->buildLanguageNote($language);
        $langSuffix = $languageNote ? " {$languageNote}" : '';

        $this->setPendingContext($context, 'low_confidence_confirm', [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'language' => $language,
        ], ttlMinutes: 5, expectRawInput: true);

        $preview = mb_substr($transcript, 0, 200) . (mb_strlen($transcript) > 200 ? '...' : '');
        $reply = "J'ai transcrit ton vocal (confiance : *{$confidencePct}%*){$langSuffix} :\n\n"
            . "_{$preview}_\n\n"
            . "C'est bien ca ? Reponds *oui* pour valider ou *non* pour annuler.";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'low_confidence' => true,
        ]);
    }

    /**
     * Download audio from WAHA with retry on transient failures.
     */
    private function downloadAudioWithRetry(AgentContext $context): ?string
    {
        $mediaUrl = $context->mediaUrl ?? ($context->media['url'] ?? null);
        if (!$mediaUrl) {
            Log::warning('[voice_command] No media URL in context', [
                'from' => $context->from,
                'hasMedia' => $context->hasMedia,
            ]);
            return null;
        }

        for ($attempt = 1; $attempt <= self::MAX_DOWNLOAD_ATTEMPTS; $attempt++) {
            try {
                $response = $this->waha(30)->get($mediaUrl);
                if ($response->successful()) {
                    $body = $response->body();
                    if (!empty($body)) {
                        return $body;
                    }
                    Log::warning('[voice_command] Download returned empty body', [
                        'url' => $mediaUrl,
                        'attempt' => $attempt,
                    ]);
                } else {
                    Log::warning('[voice_command] Download failed', [
                        'status' => $response->status(),
                        'url' => $mediaUrl,
                        'attempt' => $attempt,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('[voice_command] Download exception: ' . $e->getMessage(), [
                    'url' => $mediaUrl,
                    'attempt' => $attempt,
                ]);
            }

            if ($attempt < self::MAX_DOWNLOAD_ATTEMPTS) {
                sleep(2);
            }
        }

        return null;
    }

    /**
     * Returns true if the transcript appears to be silence or noise (too short to be meaningful).
     * Strips all punctuation and whitespace before checking length.
     */
    private function isSilenceOrNoise(string $transcript): bool
    {
        $stripped = preg_replace('/[\s\p{P}]+/u', '', $transcript);
        return mb_strlen($stripped ?? '') < self::MIN_TRANSCRIPT_CHARS;
    }

    /**
     * Build a language note with flag emoji for non-default languages.
     * Returns empty string for the default language.
     */
    private function buildLanguageNote(string $language): string
    {
        $defaultLang = config('voice_command.default_language', 'fr');
        if (!$language || $language === $defaultLang) {
            return '';
        }

        $flag = self::LANGUAGE_FLAGS[$language] ?? '';
        $flagStr = $flag ? "{$flag} " : '';
        return "\n_{$flagStr}Langue detectee : {$language}_";
    }

    /**
     * Returns true if the mimetype can be transcribed (audio/* or supported video types).
     */
    private function isTranscribableMimetype(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }

        $baseMime = trim(explode(';', $mimetype)[0]);
        return str_starts_with($baseMime, 'audio/')
            || in_array($baseMime, self::TRANSCRIBABLE_VIDEO_TYPES);
    }

    private function isVideoMimetype(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }

        $baseMime = trim(explode(';', $mimetype)[0]);
        return in_array($baseMime, self::TRANSCRIBABLE_VIDEO_TYPES);
    }
}
