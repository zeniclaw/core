<?php

namespace App\Services\Agents;

use App\Models\AgentLog;
use App\Models\AppSetting;
use App\Services\AgentContext;
use App\Services\VoiceTranscriber;
use Illuminate\Support\Facades\Log;

class VoiceCommandAgent extends BaseAgent
{
    // Video mimetypes that contain audio and can be transcribed
    private const TRANSCRIBABLE_VIDEO_TYPES = [
        'video/mp4', 'video/webm', 'video/ogg', 'video/3gpp', 'video/mpeg',
        'video/quicktime',
    ];

    // Max audio size in bytes (25MB — Whisper API limit)
    private const MAX_AUDIO_BYTES = 25 * 1024 * 1024;

    // Minimum meaningful characters in a transcript (below = silence/noise)
    private const MIN_TRANSCRIPT_CHARS = 3;

    // Max download attempts before giving up
    private const MAX_DOWNLOAD_ATTEMPTS = 3;

    // Chars threshold above which an AI summary is generated
    private const LONG_TRANSCRIPT_THRESHOLD = 600;

    // Average speaking rate in words per minute (French/English average)
    private const WORDS_PER_MINUTE = 130;

    // Duration threshold (seconds) above which duration is shown in reply
    private const DURATION_DISPLAY_THRESHOLD = 15;

    // Confidence thresholds for emoji indicators
    private const CONFIDENCE_HIGH = 0.85;
    private const CONFIDENCE_MED  = 0.70;

    // Max transcriptions shown in historique
    private const HISTORIQUE_LIMIT = 5;

    // Known Whisper hallucination patterns (common false positives on silent/noisy audio)
    private const WHISPER_HALLUCINATIONS = [
        "sous-titres réalisés par la communauté d'amara",
        "sous-titres réalisés par",
        'sous-titres francophones',
        "merci d'avoir regardé",
        "merci d'avoir vu cette vidéo",
        'thank you for watching',
        'transcribed by',
        'amara.org',
        "sous titres réalisés",
        'sous-titres par',
        'caption by',
        'subtitled by',
        'captioned by',
        // Musical/noise hallucinations
        '[music]',
        '[musique]',
        '[applaudissements]',
        '[applause]',
        '[rires]',
        '[laughter]',
        '[bruit de fond]',
        '[background noise]',
        '[inaudible]',
        '[silence]',
        '[blank_audio]',
        '[noise]',
        '[ambient sound]',
        '(silence)',
        '(ambient sound)',
        '(background noise)',
    ];

    // Hallucination patterns detected by single special chars (checked separately)
    private const WHISPER_HALLUCINATION_CHARS = ['♪', '♫', '🎵', '🎶'];

    // Language code to WhatsApp-friendly flag emoji
    private const LANGUAGE_FLAGS = [
        'fr' => '🇫🇷', 'en' => '🇬🇧', 'es' => '🇪🇸', 'de' => '🇩🇪',
        'it' => '🇮🇹', 'pt' => '🇵🇹', 'ar' => '🇸🇦', 'zh' => '🇨🇳',
        'ja' => '🇯🇵', 'ko' => '🇰🇷', 'ru' => '🇷🇺', 'nl' => '🇳🇱',
        'tr' => '🇹🇷', 'pl' => '🇵🇱', 'sv' => '🇸🇪', 'he' => '🇮🇱',
        'vi' => '🇻🇳', 'uk' => '🇺🇦', 'cs' => '🇨🇿', 'ro' => '🇷🇴',
        'hu' => '🇭🇺', 'da' => '🇩🇰', 'fi' => '🇫🇮', 'el' => '🇬🇷',
        'hi' => '🇮🇳', 'id' => '🇮🇩', 'th' => '🇹🇭', 'ms' => '🇲🇾',
    ];

    // Language code to human-readable name (in French)
    private const LANGUAGE_NAMES = [
        'fr' => 'Français', 'en' => 'Anglais', 'es' => 'Espagnol', 'de' => 'Allemand',
        'it' => 'Italien', 'pt' => 'Portugais', 'ar' => 'Arabe', 'zh' => 'Chinois',
        'ja' => 'Japonais', 'ko' => 'Coréen', 'ru' => 'Russe', 'nl' => 'Néerlandais',
        'tr' => 'Turc', 'pl' => 'Polonais', 'sv' => 'Suédois', 'he' => 'Hébreu',
        'vi' => 'Vietnamien', 'uk' => 'Ukrainien', 'cs' => 'Tchèque', 'ro' => 'Roumain',
        'hu' => 'Hongrois', 'da' => 'Danois', 'fi' => 'Finnois', 'el' => 'Grec',
        'hi' => 'Hindi', 'id' => 'Indonésien', 'th' => 'Thaï', 'ms' => 'Malais',
    ];

    public function name(): string
    {
        return 'voice_command';
    }

    public function description(): string
    {
        return 'Agent interne de traitement des messages vocaux et videos. Telecharge et transcrit les messages audio/video via Whisper ou Deepgram, avec gestion de la confiance de transcription, detection du silence/bruit/hallucinations Whisper, confirmation interactive en cas de doute, correction manuelle de transcript, estimation de duree, indicateur de langue par drapeau et resume automatique des longs messages. Supporte ogg, mp3, wav, mp4, webm, amr, mov et plus. Commandes texte : "vocal aide" (aide), "vocal stats" (statistiques), "vocal historique" (5 dernières transcriptions), "vocal langue [code]" (définir la langue préférée).';
    }

    public function keywords(): array
    {
        return ['vocal', 'voice', 'audio', 'transcrire', 'transcription', 'message vocal', 'note vocale', 'aide vocal', 'stats vocal', 'historique vocal', 'vocal langue'];
    }

    public function version(): string
    {
        return '1.6.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        // Handle audio/video media messages
        $mimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        if ($context->hasMedia && $this->isTranscribableMimetype($mimetype)) {
            return true;
        }

        // Handle text commands: "vocal aide", "vocal stats", "vocal historique", "vocal langue [code]"
        $body = trim($context->body ?? '');
        return (bool) preg_match(
            '/^(vocal|voice)\s+(aide|help|stats|statistiques|historique|history|langue(\s+\w+)?|language(\s+\w+)?)$/iu',
            $body
        );
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Route text commands before media handling
        if (!$context->hasMedia) {
            $body = trim($context->body ?? '');
            if (preg_match('/^(vocal|voice)\s+(aide|help)$/iu', $body)) {
                return $this->handleHelp($context);
            }
            if (preg_match('/^(vocal|voice)\s+(stats|statistiques)$/iu', $body)) {
                return $this->handleStats($context);
            }
            if (preg_match('/^(vocal|voice)\s+(historique|history)$/iu', $body)) {
                return $this->handleHistorique($context);
            }
            if (preg_match('/^(vocal|voice)\s+(langue|language)(\s+(\w+))?$/iu', $body, $matches)) {
                $langCode = trim($matches[4] ?? '');
                return $this->handleLanguage($context, $langCode);
            }
        }

        $mimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        $isVideo = $this->isVideoMimetype($mimetype);

        $this->log($context, 'Processing voice/video command', [
            'mimetype' => $mimetype,
            'hasMedia' => $context->hasMedia,
            'is_video' => $isVideo,
        ]);

        // Send processing indicator immediately so the user knows we received the message
        $this->sendProcessingIndicator($context, $isVideo);

        // Download audio from WAHA (with retry on transient failures)
        $audioBytes = $this->downloadAudioWithRetry($context);
        if (!$audioBytes) {
            $this->log($context, 'Failed to download audio', [
                'mediaUrl' => $context->mediaUrl,
                'mediaFromArray' => $context->media['url'] ?? null,
            ], 'warn');
            $reply = $isVideo
                ? "Je n'ai pas pu telecharger ta video. Verifie ta connexion et reessaie, ou ecris-moi directement en texte."
                : "Je n'ai pas pu telecharger ton message vocal. Verifie ta connexion et reessaie, ou ecris-moi en texte.";
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

        // Apply user's preferred transcription language if set
        $this->applyLanguagePreference($context);

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
        $wordCount = str_word_count($transcript);
        $durationSec = $this->estimateDurationSeconds($wordCount);

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

        // Detect known Whisper hallucinations (false positives on silent/noisy audio)
        if ($this->isWhisperHallucination($transcript)) {
            $this->log($context, 'Whisper hallucination detected — rejecting transcript', [
                'transcript' => mb_substr($transcript, 0, 200),
                'size_kb' => $sizeKb,
            ], 'warn');
            $reply = "Je n'ai pas detecte de parole claire dans ton message (bruit de fond probable). Reessaie ou ecris-moi directement en texte.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->log($context, 'Transcription successful', [
            'transcript' => mb_substr($transcript, 0, 200),
            'confidence' => $confidence,
            'language' => $language,
            'is_video' => $isVideo,
            'size_kb' => $sizeKb,
            'word_count' => $wordCount,
            'duration_sec' => $durationSec,
            'from' => $context->from,
        ]);

        // If confidence is too low, store transcript and ask for confirmation
        if ($confidence < $minConfidence) {
            return $this->handleLowConfidence($context, $transcript, $confidence, $language);
        }

        // Build language note with flag emoji for non-default-language transcriptions
        $languageNote = $this->buildLanguageNote($language);

        // Show estimated duration for messages long enough to be meaningful
        $durationNote = $this->buildDurationNote($durationSec, $wordCount);

        // For long transcripts, generate an AI summary appended to the reply
        $summaryNote = $this->maybeSummarizeTranscript($context, $transcript, $language);

        // Return transcript — orchestrator will re-route to the appropriate agent
        return AgentResult::reply($transcript . $languageNote . $durationNote . $summaryNote, [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'language' => $language,
            'source' => 'voice',
            'word_count' => $wordCount,
            'duration_sec' => $durationSec,
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

        $rawReply = trim($context->body ?? '');
        $userReply = mb_strtolower($rawReply);
        $storedTranscript = $pendingContext['data']['transcript'] ?? null;

        if (!$storedTranscript) {
            return null;
        }

        // User wants to manually correct the transcript
        if (preg_match('/^corrig(?:er|é|e)\s*:\s*(.+)$/isu', $rawReply, $matches)) {
            $correctedText = trim($matches[1]);
            if (mb_strlen($correctedText) >= self::MIN_TRANSCRIPT_CHARS) {
                $language = $pendingContext['data']['language'] ?? 'fr';
                $this->log($context, 'Transcript manually corrected by user', [
                    'original' => mb_substr($storedTranscript, 0, 200),
                    'corrected' => mb_substr($correctedText, 0, 200),
                ]);
                return AgentResult::reply($correctedText, [
                    'transcript' => $correctedText,
                    'confidence' => 1.0,
                    'language' => $language,
                    'source' => 'voice',
                    'user_corrected' => true,
                    'user_confirmed' => true,
                ]);
            }
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
        $reply = "Reponds *oui* pour valider, *non* pour annuler, ou *corriger: [ton texte]* pour corriger la transcription :\n\n\"{$preview}\"";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    /**
     * Show help text explaining supported formats and text commands.
     * Uses low_confidence flag to prevent orchestrator from re-routing the help text.
     */
    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "🎤 *Agent Vocal — Aide*\n\n"
            . "*Formats audio supportes :*\n"
            . "ogg, mp3, wav, m4a, webm, amr, flac\n\n"
            . "*Formats video supportes :*\n"
            . "mp4, webm, mov, 3gpp\n\n"
            . "*Limite :* 25 MB\n\n"
            . "*Fonctionnalites :*\n"
            . "• Transcription automatique de tes vocaux\n"
            . "• Detection de la langue + drapeau\n"
            . "• Duree estimee pour les longs messages\n"
            . "• Resume auto des longs messages (>600 car.)\n"
            . "• Confirmation si transcription incertaine\n"
            . "• Correction : reponds *corriger: [ton texte]*\n\n"
            . "*Commandes texte :*\n"
            . "• `vocal aide` — afficher cette aide\n"
            . "• `vocal stats` — tes statistiques de transcription\n"
            . "• `vocal historique` — tes 5 dernières transcriptions\n"
            . "• `vocal langue [code]` — definir la langue (ex: `vocal langue en`)";

        $this->log($context, 'Help command requested');
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
    }

    /**
     * Show transcription stats for this user from AgentLog.
     * Includes language breakdown for the last 30 days.
     */
    private function handleStats(AgentContext $context): AgentResult
    {
        $agentId = $context->agent->id;
        $pattern = '%[voice_command] Transcription successful%';

        $total = AgentLog::where('agent_id', $agentId)
            ->where('message', 'like', $pattern)
            ->count();

        $weekly = AgentLog::where('agent_id', $agentId)
            ->where('message', 'like', $pattern)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $today = AgentLog::where('agent_id', $agentId)
            ->where('message', 'like', $pattern)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        // Language breakdown from last 30 days
        $langBreakdown = AgentLog::where('agent_id', $agentId)
            ->where('message', 'like', $pattern)
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy(fn($log) => ($log->context['language'] ?? 'fr'))
            ->map->count()
            ->sortDesc()
            ->take(3);

        $this->log($context, 'Stats command requested', [
            'total' => $total,
            'weekly' => $weekly,
            'today' => $today,
        ]);

        $reply = "🎤 *Statistiques vocales*\n\n"
            . "• Aujourd'hui : *{$today}* transcription(s)\n"
            . "• Cette semaine : *{$weekly}* transcription(s)\n"
            . "• Total : *{$total}* transcription(s)\n";

        if ($langBreakdown->isNotEmpty()) {
            $reply .= "\n*Langues (30 derniers jours) :*\n";
            foreach ($langBreakdown as $lang => $count) {
                $flag = self::LANGUAGE_FLAGS[$lang] ?? '🌐';
                $name = self::LANGUAGE_NAMES[$lang] ?? mb_strtoupper($lang);
                $reply .= "• {$flag} {$name} : {$count}\n";
            }
        }

        $reply .= "\n_Tape `vocal aide` pour voir les commandes disponibles._";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
    }

    /**
     * Show the last N transcriptions for this user from AgentLog.
     * Filters by user phone (context->from) stored in the log's context JSON.
     */
    private function handleHistorique(AgentContext $context): AgentResult
    {
        $agentId = $context->agent->id;
        $from = $context->from;

        $logs = AgentLog::where('agent_id', $agentId)
            ->where('message', 'like', '%[voice_command] Transcription successful%')
            ->whereRaw("context->>'from' = ?", [$from])
            ->orderByDesc('created_at')
            ->limit(self::HISTORIQUE_LIMIT)
            ->get();

        if ($logs->isEmpty()) {
            $reply = "🎤 *Historique vocal*\n\nAucune transcription trouvee pour ton compte.\n\n_Envoie un message vocal pour commencer !_";
        } else {
            $lines = ["🎤 *Historique vocal* _(5 dernières)_\n"];
            foreach ($logs as $i => $log) {
                $ctx = $log->context ?? [];
                $preview = mb_substr($ctx['transcript'] ?? '—', 0, 70);
                if (mb_strlen($ctx['transcript'] ?? '') > 70) {
                    $preview .= '...';
                }
                $lang = $ctx['language'] ?? 'fr';
                $wordCount = $ctx['word_count'] ?? 0;
                $flag = self::LANGUAGE_FLAGS[$lang] ?? '🌐';
                $date = $log->created_at ? $log->created_at->format('d/m H:i') : '—';
                $num = $i + 1;
                $wordInfo = $wordCount ? " ({$wordCount} mots)" : '';
                $lines[] = "*{$num}.* [{$date}] {$flag}\n_{$preview}_{$wordInfo}";
            }
            $reply = implode("\n\n", $lines);
        }

        $this->log($context, 'Historique command requested');
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
    }

    /**
     * View or set the preferred transcription language for this user.
     * Stored per-user via AppSetting with key "voice_lang_pref_{from}".
     * Pass empty $langCode to view the current preference.
     * Pass "auto" to reset to system default.
     */
    private function handleLanguage(AgentContext $context, string $langCode): AgentResult
    {
        $langCode = mb_strtolower(trim($langCode));
        $prefKey = "voice_lang_pref_{$context->from}";

        // No code provided — show current preference
        if ($langCode === '') {
            $current = AppSetting::get($prefKey) ?? config('voice_command.default_language', 'fr');
            $flag = self::LANGUAGE_FLAGS[$current] ?? '🌐';
            $name = self::LANGUAGE_NAMES[$current] ?? mb_strtoupper($current);
            $supported = implode(', ', array_keys(self::LANGUAGE_FLAGS));
            $reply = "🌐 *Langue de transcription*\n\n"
                . "Langue actuelle : {$flag} *{$name}*\n\n"
                . "_Commandes :_\n"
                . "• `vocal langue fr` — definir en Français\n"
                . "• `vocal langue en` — definir en Anglais\n"
                . "• `vocal langue auto` — reinitialiser (detecte automatiquement)\n\n"
                . "_Codes supportes : {$supported}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
        }

        // "auto" → reset to system default
        if ($langCode === 'auto') {
            $default = config('voice_command.default_language', 'fr');
            AppSetting::set($prefKey, $default);
            $flag = self::LANGUAGE_FLAGS[$default] ?? '🌐';
            $name = self::LANGUAGE_NAMES[$default] ?? $default;
            $reply = "🌐 Langue reinitialise : {$flag} *{$name}* (detecte automatiquement).";
            $this->log($context, 'Language preference reset to default', ['lang' => $default]);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
        }

        // Validate language code
        if (!array_key_exists($langCode, self::LANGUAGE_FLAGS)) {
            $supported = implode(', ', array_keys(self::LANGUAGE_FLAGS));
            $reply = "🌐 Code de langue non reconnu : `{$langCode}`.\n\n_Codes supportes : {$supported}_\n\nOu tape `vocal langue auto` pour la detection automatique.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
        }

        // Save preference
        AppSetting::set($prefKey, $langCode);
        $flag = self::LANGUAGE_FLAGS[$langCode];
        $name = self::LANGUAGE_NAMES[$langCode];
        $reply = "✅ Langue de transcription definie : {$flag} *{$name}*.\n\n_Tes prochains vocaux seront transcrits en {$name}._";
        $this->log($context, 'Language preference set', ['lang' => $langCode]);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['low_confidence' => true, 'text_command' => true]);
    }

    /**
     * Handle low-confidence transcription: store in pending context and ask user to confirm.
     * Uses emoji indicator for confidence level: 🟢 high, 🟡 medium, 🔴 low.
     */
    private function handleLowConfidence(AgentContext $context, string $transcript, float $confidence, string $language): AgentResult
    {
        $confidencePct = round($confidence * 100);
        $confidenceEmoji = $confidence >= self::CONFIDENCE_HIGH ? '🟢' : ($confidence >= self::CONFIDENCE_MED ? '🟡' : '🔴');
        $languageNote = $this->buildLanguageNote($language);
        $langSuffix = $languageNote ? " {$languageNote}" : '';

        $this->setPendingContext($context, 'low_confidence_confirm', [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'language' => $language,
        ], ttlMinutes: 5, expectRawInput: true);

        $preview = mb_substr($transcript, 0, 200) . (mb_strlen($transcript) > 200 ? '...' : '');
        $reply = "J'ai transcrit ton vocal ({$confidenceEmoji} confiance : *{$confidencePct}%*){$langSuffix} :\n\n"
            . "_{$preview}_\n\n"
            . "C'est bien ca ? Reponds *oui* pour valider, *non* pour annuler, ou *corriger: [ton texte]* pour corriger.";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'low_confidence' => true,
        ]);
    }

    /**
     * Send a brief processing indicator to the user before the download+transcription pipeline.
     * Gives immediate feedback so the user knows their voice message was received.
     */
    private function sendProcessingIndicator(AgentContext $context, bool $isVideo): void
    {
        $msg = $isVideo ? "\u{23F3} Je traite ta video..." : "\u{23F3} Je traite ton vocal...";
        $this->sendText($context->from, $msg);
    }

    /**
     * Apply the user's preferred transcription language if one is stored in AppSetting.
     * Overrides the config value at runtime for this request only.
     */
    private function applyLanguagePreference(AgentContext $context): void
    {
        $prefLang = AppSetting::get("voice_lang_pref_{$context->from}");
        if ($prefLang && array_key_exists($prefLang, self::LANGUAGE_FLAGS)) {
            config(['voice_command.default_language' => $prefLang]);
        }
    }

    /**
     * For transcripts above LONG_TRANSCRIPT_THRESHOLD chars, use Claude to generate a brief summary.
     * The summary is generated in the detected language when possible, defaulting to French.
     * Returns formatted summary note (with leading newlines) or empty string on failure/short transcript.
     */
    private function maybeSummarizeTranscript(AgentContext $context, string $transcript, string $language = 'fr'): string
    {
        if (mb_strlen($transcript) < self::LONG_TRANSCRIPT_THRESHOLD) {
            return '';
        }

        $model = $this->resolveModel($context);
        $langName = self::LANGUAGE_NAMES[$language] ?? 'français';
        $systemPrompt = "Tu es un assistant qui resume des transcriptions vocales. "
            . "Genere un resume TRES court (1-2 phrases maximum) en {$langName} de la transcription fournie. "
            . "Sois concis et direct. Ne commence pas par \"La transcription dit\" ou similaire. "
            . "Reponds uniquement avec le resume, rien d'autre.";

        try {
            $summary = $this->claude->chat($transcript, $model, $systemPrompt);
            if (!$summary) {
                return '';
            }
            $summary = trim($summary);
            return "\n\n\u{1F4DD} *Resume :* _{$summary}_";
        } catch (\Exception $e) {
            $this->log($context, 'Summary generation failed: ' . $e->getMessage(), [], 'warn');
            return '';
        }
    }

    /**
     * Build a duration note for messages longer than DURATION_DISPLAY_THRESHOLD seconds.
     * Returns empty string for short messages.
     */
    private function buildDurationNote(int $durationSec, int $wordCount): string
    {
        if ($durationSec < self::DURATION_DISPLAY_THRESHOLD) {
            return '';
        }

        $durationMin = intdiv($durationSec, 60);
        $durationSecRem = $durationSec % 60;
        $durationStr = $durationMin > 0
            ? "{$durationMin}m{$durationSecRem}s"
            : "{$durationSec}s";

        return "\n_⏱ Duree estimee : {$durationStr} — {$wordCount} mots_";
    }

    /**
     * Returns true if the transcript matches a known Whisper hallucination pattern.
     * These are common false positives generated when audio is silent or too noisy.
     */
    private function isWhisperHallucination(string $transcript): bool
    {
        $lower = mb_strtolower(trim($transcript));

        foreach (self::WHISPER_HALLUCINATIONS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) {
                return true;
            }
        }

        // Check if transcript consists almost entirely of hallucination chars
        $strippedOfHallucinationChars = $transcript;
        foreach (self::WHISPER_HALLUCINATION_CHARS as $char) {
            $strippedOfHallucinationChars = str_replace($char, '', $strippedOfHallucinationChars);
        }
        if ($this->isSilenceOrNoise($strippedOfHallucinationChars)) {
            return true;
        }

        return false;
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
     * Build a language note with flag emoji and full language name for non-default languages.
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
        $langName = self::LANGUAGE_NAMES[$language] ?? mb_strtoupper($language);
        return "\n_{$flagStr}Langue detectee : {$langName}_";
    }

    /**
     * Estimate the spoken duration in seconds from word count.
     * Uses an average speaking rate of 130 words per minute.
     */
    private function estimateDurationSeconds(int $wordCount): int
    {
        if ($wordCount === 0) {
            return 0;
        }
        return (int) round($wordCount / self::WORDS_PER_MINUTE * 60);
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
