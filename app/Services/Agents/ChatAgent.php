<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Project;
use App\Models\UserKnowledge;
use App\Services\AgentContext;
use App\Services\AgenticLoop;
use App\Services\AgentTools;
use App\Services\ContextMemory\ContextStore;
use App\Services\WhisperService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatAgent extends BaseAgent
{
    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum media size accepted before download (20 MB). */
    private const MAX_MEDIA_BYTES = 20 * 1024 * 1024;

    /** Quick commands resolved without agentic loop. */
    private const QUICK_COMMANDS = ['/aide', '/help', '/capacites', '/capabilities', '/status', '/effacer', '/resume', '/ping', '/memoire', '/stats'];

    /** Supported languages for the /langue command. */
    private const SUPPORTED_LANGUAGES = [
        'fr' => 'français', 'en' => 'anglais', 'es' => 'espagnol',
        'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais',
        'ar' => 'arabe', 'nl' => 'néerlandais', 'ru' => 'russe',
    ];

    private ?string $lastVoiceTranscript = null;

    public function name(): string
    {
        return 'chat';
    }

    public function description(): string
    {
        return 'Agent de conversation general. Repond a toutes les questions, gere les discussions libres, les images, les PDFs et les messages vocaux. Agent fallback qui prend le relais quand aucun autre agent specialise ne correspond.';
    }

    public function keywords(): array
    {
        return [
            'bonjour', 'salut', 'hello', 'hey', 'coucou', 'bonsoir', 'bonne nuit',
            'comment ca va', 'ca va', 'quoi de neuf', 'question', 'dis moi',
            'aide', 'help', 'info', 'information', 'explique', 'qu est ce que',
            'merci', 'thanks', 'ok', 'daccord', 'parfait', 'super', 'cool',
            'raconte', 'explique', 'dis-moi', 'parle-moi', 'kesako', 'c est quoi',
            'blague', 'joke', 'rigole', 'humour', 'drole', 'marrant',
            'qui es-tu', 'tu fais quoi', 'what can you do', 'capable de',
            'langue', 'langage', 'language', 'resume', 'historique',
            'pourquoi', 'comment', 'quand', 'ou', 'combien', 'lequel',
            'ping', 'memoire', 'status', 'effacer', 'traduis', 'traduit',
            'calcule', 'convertis', 'definis', 'definition', 'synonyme',
            'ecris', 'redige', 'resume', 'analyse', 'compare', 'liste',
            'stats', 'statistiques', 'oublie', 'supprimer', 'version',
        ];
    }

    public function version(): string
    {
        return '1.5.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true; // Fallback agent, always can handle
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Check prefix commands before other quick commands
        $body = trim($context->body ?? '');
        if (preg_match('/^\/langue(?:\s+(.+))?$/iu', $body, $m)) {
            return $this->handleLangueCommand($context, trim($m[1] ?? ''));
        }
        if (preg_match('/^\/oublie(?:\s+(.+))?$/iu', $body, $m)) {
            return $this->handleOublieCommand($context, trim($m[1] ?? ''));
        }

        // Quick commands bypass the agentic loop for instant responses
        $quickResult = $this->handleQuickCommand($context);
        if ($quickResult) {
            return $quickResult;
        }

        // Guard against empty body with no media
        $emptyResult = $this->buildEmptyMessageFallback($context);
        if ($emptyResult) {
            return $emptyResult;
        }

        $model = $this->resolveModel($context);
        $isOnPrem = !str_starts_with($model, 'claude-');
        $systemPrompt = $isOnPrem
            ? $this->buildCompactSystemPrompt($context)
            : $this->buildSystemPrompt($context);
        $this->lastVoiceTranscript = null;
        $claudeMessage = $this->buildMessageContent($context);

        $debug = $context->session->debug_mode ?? false;

        if ($debug) {
            $promptLen = mb_strlen($systemPrompt);
            $msgLen = is_string($claudeMessage) ? mb_strlen($claudeMessage) : strlen(json_encode($claudeMessage));
            $this->log($context, '[DEBUG CHAT] Pre-dispatch', [
                'model' => $model,
                'is_on_prem' => $isOnPrem,
                'system_prompt_chars' => $promptLen,
                'message_chars' => $msgLen,
                'tools_count' => $isOnPrem ? 0 : count(AgentTools::definitions()),
            ]);
        }

        // Run the agentic loop — the LLM decides which tools to use
        $loop = new AgenticLoop(maxIterations: $isOnPrem ? 1 : 10, debug: $debug);
        $result = $loop->run(
            userMessage: $claudeMessage,
            systemPrompt: $systemPrompt,
            model: $model,
            context: $context,
            tools: $isOnPrem ? [] : AgentTools::definitions(),
        );

        $reply = $result->reply;

        if ($debug && !$reply) {
            Log::warning("[DEBUG CHAT] AgenticLoop returned empty", [
                'model' => $model, 'iterations' => $result->iterations,
            ]);
        }

        // Fallback to simple chat if agentic loop fails (use same resolved model)
        if (!$reply) {
            $reply = $this->claude->chat($claudeMessage, $model, $systemPrompt);
        }

        if (!$reply) {
            $debugInfo = '';
            if ($debug) {
                $debugInfo = "\n\n---\n🔍 *DEBUG CHAT ERROR*\n"
                    . "Model: {$model}" . ($isOnPrem ? " (on-prem)" : " (cloud)") . "\n"
                    . "System prompt: " . mb_strlen($systemPrompt) . " chars\n"
                    . "Message: " . (is_string($claudeMessage) ? mb_strlen($claudeMessage) : strlen(json_encode($claudeMessage))) . " chars\n"
                    . "AgenticLoop: {$result->iterations} iteration(s), no reply\n"
                    . "Fallback chat: also failed\n"
                    . "Check logs: docker logs zeniclaw_app --tail 50 | grep -i error";
            }
            $fallback = "Desole, je n'ai pas pu generer une reponse. Reessaie dans quelques instants ou contacte le support." . $debugInfo;
            $this->sendText($context->from, $fallback);
            Log::warning("[chat] Both agentic loop and fallback chat returned empty reply", [
                'from' => $context->from,
                'body' => mb_substr($context->body ?? '', 0, 100),
                'model' => $model,
                'is_on_prem' => $isOnPrem,
                'prompt_len' => mb_strlen($systemPrompt),
            ]);
            return AgentResult::reply($fallback);
        }

        $this->sendText($context->from, $reply);

        $modelUsed = $result->model ?? $model;
        $this->log($context, 'Reply sent (agentic)', [
            'model' => $modelUsed,
            'routed_agent' => $context->routedAgent,
            'complexity' => $context->complexity,
            'iterations' => $result->iterations,
            'tools_used' => $result->toolsUsed,
            'reply' => mb_substr($reply, 0, 200),
        ]);

        return AgentResult::reply($reply, [
            'model' => $modelUsed,
            'complexity' => $context->complexity,
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
            'voice_transcript' => $this->lastVoiceTranscript,
        ]);
    }

    /**
     * Handle follow-up confirmation for /effacer command.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'confirm_clear_memory') {
            return null;
        }

        $this->clearPendingContext($context);
        $userReply = mb_strtolower(trim($context->body ?? ''));

        if (preg_match("/\b(oui|yes|ok|ouais|yep|affirm|correct|exact|c'est ?ca|confirme)\b/iu", $userReply)) {
            $this->memory->clear($context->agent->id, $context->from);
            $reply = "Memoire de conversation effacee. On repart de zero ! Dis-moi ce dont tu as besoin.";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Conversation memory cleared by user');
            return AgentResult::reply($reply, ['memory_cleared' => true]);
        }

        if (preg_match('/\b(non|no|nope|annuler|cancel|garde|stop)\b/iu', $userReply)) {
            $reply = "OK, je garde ta memoire de conversation intacte.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['memory_cleared' => false]);
        }

        // Ambiguous — re-ask
        $this->setPendingContext($context, 'confirm_clear_memory', [], ttlMinutes: 2, expectRawInput: true);
        $reply = "Reponds *oui* pour effacer ou *non* pour annuler.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    /**
     * Compact system prompt for on-prem models (small context, no tools).
     */
    private function buildCompactSystemPrompt(AgentContext $context): string
    {
        $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');
        $preferredLang = $this->resolvePreferredLanguage($context->from);

        $prompt = "Tu es ZeniClaw, un assistant IA. Tu tutoies, tu es direct et bienveillant. "
            . ($preferredLang ? "Reponds en {$preferredLang}. " : "Reponds dans la langue de l'utilisateur. ")
            . "Date: {$now}. Utilisateur: {$context->senderName}.\n"
            . "Format WhatsApp: *gras* _italique_ `code`. Pas de ## ni **.\n";

        // Inject only recent conversation (3 messages max for small models)
        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from, 3);
        if ($memoryContext) {
            $prompt .= "\n" . $memoryContext;
        }

        // Inject conversation memory (cross-session facts) — keep it brief
        if ($context->memoryContext) {
            $prompt .= "\n" . mb_substr($context->memoryContext, 0, 500);
        }

        return $prompt;
    }

    private function buildSystemPrompt(AgentContext $context): string
    {
        $tz = AppSetting::timezone();
        $now = now($tz)->format('Y-m-d H:i (l)');

        // Detect user's preferred language from context memory
        $preferredLang = $this->resolvePreferredLanguage($context->from);
        $langInstruction = $preferredLang
            ? "L'utilisateur a configure sa langue preferee : *{$preferredLang}*. Reponds TOUJOURS dans cette langue sauf s'il ecrit dans une autre langue."
            : "Reponds dans la meme langue que l'utilisateur (detecte automatiquement).";

        $systemPrompt =
            "Tu es ZeniClaw, un assistant WhatsApp autonome. Utilise tes outils (tools) proactivement, sans demander confirmation. "
            . "FAIS directement, ne dis jamais 'je peux faire X si tu veux'.\n\n"
            . "OUTILS: rappel→create_reminder, todo→add_todos, info perso→store_knowledge, question factuelle→web_search. "
            . "Confirme brievement apres chaque action.\n\n"
            . "MEMOIRE: utilise recall_knowledge AVANT de redemander une info. store_knowledge pour sauver les donnees importantes.\n\n"
            . "STYLE: Tutoiement, direct, decontracte, bienveillant. Max 2-3 emojis. " . $langInstruction . " "
            . $this->buildLengthDirective($context->complexity) . "\n\n"
            . "FORMAT WHATSAPP: *gras* _italique_ ~barre~ `code`. Listes: tirets ou numeros. "
            . "INTERDIT: ## titres, ** gras markdown, [texte](url).\n\n"
            . "Date ({$tz}): {$now}. Utilisateur: {$context->senderName}.";

        // Inject user context memory (preferences, profile, humor level)
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);
        if ($contextMemory) {
            $systemPrompt .= "\n\n" . $contextMemory;
            // Add humor hint based on preference
            $facts = $this->getContextMemory($context->from);
            foreach ($facts as $fact) {
                if (($fact['key'] ?? '') === 'humor_style') {
                    $systemPrompt .= "\nAdapte ton humour en fonction: {$fact['value']}.";
                    break;
                }
            }
        }

        $projectContext = $this->buildProjectContext($context);
        if ($projectContext) {
            $systemPrompt .= "\n\n" . $projectContext;
        }

        // Stored knowledge summary (per-user persistent data)
        $knowledgeSummary = $this->buildKnowledgeSummary($context);
        if ($knowledgeSummary) {
            $systemPrompt .= "\n\n" . $knowledgeSummary;
        }

        // User context (active todos, reminders)
        $userContext = $this->buildUserContext($context);
        if ($userContext) {
            $systemPrompt .= "\n\n" . $userContext;
        }

        // Build memory context with longterm summary for long conversations
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $totalEntries = count($memoryData['entries'] ?? []);
        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from);

        if ($totalEntries > 20) {
            $olderEntries = array_slice($memoryData['entries'], 0, $totalEntries - 20);
            $longtermSummary = $this->buildLongtermSummary($olderEntries);
            if ($longtermSummary) {
                $systemPrompt .= "\n\n--- Resume des conversations precedentes ---\n" . $longtermSummary . "\n--- Fin resume ---";
            }
        }

        if ($memoryContext) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }

        // Inject conversation memory (persistent cross-session facts)
        if ($context->memoryContext) {
            $systemPrompt .= "\n\n" . $context->memoryContext;
        }

        return $systemPrompt;
    }

    /**
     * Resolve preferred language from context memory. Returns null if not set.
     */
    private function resolvePreferredLanguage(string $userId): ?string
    {
        $facts = $this->getContextMemory($userId);
        foreach ($facts as $fact) {
            if (($fact['key'] ?? '') === 'preferred_language') {
                return $fact['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Build a summary of active user data (todos, reminders) so the LLM can reference them.
     */
    private function buildUserContext(AgentContext $context): string
    {
        $parts = [];
        $now = now(AppSetting::timezone());

        // Active todos summary — capped at 15, overdue highlighted, sorted pending first
        $todos = \App\Models\Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("CASE WHEN is_done = false THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->limit(15)
            ->get();

        if ($todos->isNotEmpty()) {
            $lines = ['TODOS ACTIFS:'];
            foreach ($todos->values() as $i => $todo) {
                $status = $todo->is_done ? 'FAIT' : 'A FAIRE';
                $list = $todo->list_name ? " [{$todo->list_name}]" : '';
                $overdue = '';
                if (!$todo->is_done && $todo->due_at && $todo->due_at->lt($now)) {
                    $overdue = ' [EN RETARD]';
                }
                $lines[] = "  " . ($i + 1) . ". [{$status}]{$overdue} {$todo->title}{$list}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Pending reminders summary
        $reminders = \App\Models\Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->take(10)
            ->get();

        if ($reminders->isNotEmpty()) {
            $lines = ['REMINDERS EN ATTENTE:'];
            foreach ($reminders->values() as $i => $r) {
                $at = $r->scheduled_at->setTimezone(AppSetting::timezone())->format('d/m H:i');
                $recurrence = $r->recurrence_rule ? " (recurrent: {$r->recurrence_rule})" : '';
                $late = $r->scheduled_at->lt($now) ? ' [DEPASSE]' : '';
                $lines[] = "  " . ($i + 1) . ". {$r->message} → {$at}{$recurrence}{$late}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Active project
        $activeProjectId = $context->session->active_project_id ?? null;
        if ($activeProjectId) {
            $project = Project::find($activeProjectId);
            if ($project) {
                $parts[] = "PROJET ACTIF: {$project->name} ({$project->gitlab_url})";
            }
        }

        return implode("\n\n", $parts);
    }

    private function buildLengthDirective(?string $complexity): string
    {
        return match ($complexity) {
            'complex' => "Tu peux faire des reponses longues et detaillees avec des sections, listes et formatage si necessaire. ",
            'medium' => "Tu peux faire des reponses structurees avec des listes a puces si utile. Reste clair et organise. ",
            default => "Reponds de maniere concise (2-3 phrases max sauf si on te demande plus). ",
        };
    }

    private function buildLongtermSummary(array $olderEntries): string
    {
        $topics = [];
        foreach ($olderEntries as $entry) {
            $summary = $entry['summary'] ?? '';
            if ($summary) {
                // Truncate long summaries
                $topics[] = mb_strlen($summary) > 100 ? mb_substr($summary, 0, 100) . '...' : $summary;
            } else {
                $msg = $entry['sender_message'] ?? '';
                if ($msg) {
                    $topics[] = mb_strlen($msg) > 80 ? mb_substr($msg, 0, 80) . '...' : $msg;
                }
            }
        }

        if (empty($topics)) {
            return '';
        }

        // Keep only last 30 topics to avoid prompt bloat
        $topics = array_unique(array_slice($topics, -30));
        $count = count($olderEntries);
        return "Sujets abordes dans les {$count} echanges precedents (resume) :\n- " . implode("\n- ", $topics);
    }

    private function buildMessageContent(AgentContext $context): string|array
    {
        $message = $context->body ?? '';

        if ($context->hasMedia && $context->mediaUrl) {
            $mimetype = $context->mimetype ?? '';

            if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES) || $mimetype === 'application/pdf') {
                $base64Data = $this->downloadMedia($context->mediaUrl);
                if ($base64Data) {
                    return $this->buildMediaContentBlocks($mimetype, $base64Data, $context->body);
                }
                // Download failed — inform clearly rather than silently ignoring
                $mediaLabel = $mimetype === 'application/pdf' ? 'PDF' : 'image';
                $message = ($context->body ? "{$context->body}\n\n" : '')
                    . "[Impossible de telecharger le {$mediaLabel}. "
                    . "Informe l'utilisateur et invite-le a reessayer ou a verifier sa connexion.]";
            } elseif (str_starts_with($mimetype, 'audio/')) {
                $voiceContent = $this->handleVoiceMessage($context);
                if ($voiceContent) {
                    return $voiceContent;
                }
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoye un message vocal/audio. Tu ne peux pas l'ecouter, " .
                    "dis-le poliment et propose de continuer la conversation par texte.]";
            } else {
                $mediaType = $context->media['mediaType'] ?? $context->media['type'] ?? '';
                $mediaDesc = match (true) {
                    str_starts_with($mimetype, 'video/') => 'une video',
                    $mimetype === 'image/webp' && $mediaType === 'sticker' => 'un sticker',
                    $mimetype === 'image/webp' => 'un sticker ou une image webp',
                    str_starts_with($mimetype, 'image/') => 'une image',
                    default => "un fichier de type {$mimetype}",
                };
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoye {$mediaDesc}. Tu ne peux pas le voir/ecouter, " .
                    "dis-le poliment et propose de continuer la conversation.]";
            }
        }

        return $message;
    }

    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            // Check size before downloading to avoid fetching huge files
            $headResponse = $this->waha(10)->head($mediaUrl);
            if ($headResponse->successful()) {
                $contentLength = (int) ($headResponse->header('Content-Length') ?? 0);
                if ($contentLength > 0 && $contentLength > self::MAX_MEDIA_BYTES) {
                    $sizeMb = round($contentLength / 1024 / 1024, 1);
                    Log::warning("[chat] Media too large to download: {$sizeMb} MB ({$mediaUrl})");
                    return null;
                }
            }

            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                $body = $response->body();
                if (strlen($body) > self::MAX_MEDIA_BYTES) {
                    Log::warning('[chat] Downloaded media exceeds size limit, discarding.');
                    return null;
                }
                return base64_encode($body);
            }

            Log::warning('[chat] Media download failed: HTTP ' . $response->status());
        } catch (\Exception $e) {
            Log::warning('[chat] Failed to download media: ' . $e->getMessage());
        }
        return null;
    }

    private function handleVoiceMessage(AgentContext $context): ?string
    {
        if (!$context->mediaUrl) {
            return null;
        }

        try {
            // Check size before downloading the audio file
            $headResponse = $this->waha(10)->head($context->mediaUrl);
            if ($headResponse->successful()) {
                $contentLength = (int) ($headResponse->header('Content-Length') ?? 0);
                if ($contentLength > 0 && $contentLength > self::MAX_MEDIA_BYTES) {
                    $sizeMb = round($contentLength / 1024 / 1024, 1);
                    Log::warning("[chat] Voice message too large: {$sizeMb} MB, skipping transcription.");
                    return null;
                }
            }

            $response = $this->waha(30)->get($context->mediaUrl);
            if (!$response->successful()) {
                Log::warning('[chat] Voice message download failed: HTTP ' . $response->status());
                return null;
            }

            $audioBytes = $response->body();
            if (empty($audioBytes)) {
                Log::warning('[chat] Voice message download returned empty body');
                return null;
            }

            if (strlen($audioBytes) > self::MAX_MEDIA_BYTES) {
                Log::warning('[chat] Voice message body exceeds size limit, skipping transcription.');
                return null;
            }

            $whisper = new WhisperService();
            $transcript = $whisper->transcribe($audioBytes, $context->mimetype ?? 'audio/ogg');

            if (!$transcript) {
                return null;
            }

            $this->lastVoiceTranscript = $transcript;

            $caption = $context->body ? "\n{$context->body}" : '';
            return "[Message vocal de {$context->senderName}]\nTranscription : \"{$transcript}\"{$caption}";
        } catch (\Exception $e) {
            Log::warning('[chat] Voice message handling failed: ' . $e->getMessage());
            return null;
        }
    }

    private function buildMediaContentBlocks(string $mimetype, string $base64Data, ?string $caption): array
    {
        $blocks = [];

        if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES)) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimetype,
                    'data' => $base64Data,
                ],
            ];
        } elseif ($mimetype === 'application/pdf') {
            $blocks[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $base64Data,
                ],
            ];
        }

        if ($mimetype === 'application/pdf') {
            $defaultPdfPrompt = "Analyse ce document PDF de facon structuree :\n"
                . "1. *Resume executif* : synthese en 3-5 points cles (max 3 lignes)\n"
                . "2. *Structure du document* : liste les titres/sections detectes\n"
                . "3. *Informations cles* : chiffres, dates importantes, noms, conclusions\n"
                . "4. *Type de document* : contrat, rapport, facture, CV, article... (si identifiable)\n"
                . "Termine en demandant si l'utilisateur veut approfondir une section specifique.";
            $text = $caption ? "{$caption}\n\n(Contexte supplementaire : applique aussi l'analyse structuree ci-dessus si pertinent.)" : $defaultPdfPrompt;
        } else {
            // Smart image analysis: read text + describe content + handle screenshots/documents
            $imageHint = "Analyse cette image avec attention :\n"
                . "1) Si elle contient du texte (screenshot, document, affiche, panneau), lis-le et cite-le integralement.\n"
                . "2) Decris ce que tu vois : personnes, objets, lieux, couleurs, mise en page.\n"
                . "3) Si c'est un graphique, un tableau ou du code, analyse son contenu specifiquement.\n"
                . "4) Si c'est un QR code ou un code-barres, signale-le et tente de lire le contenu encodé.\n"
                . "5) Si c'est une facture, un recu ou un formulaire, extrais les montants et informations importantes.";
            $text = $caption ? "{$caption}\n\n({$imageHint})" : $imageHint;
        }

        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }

    private function buildKnowledgeSummary(AgentContext $context): string
    {
        $allEntries = UserKnowledge::allFor($context->from);

        if ($allEntries->isEmpty()) {
            return '';
        }

        $total = $allEntries->count();
        $entries = $allEntries->take(20);
        $suffix = $total > 20 ? " ({$total} entrees au total, 20 affichees)" : '';

        $lines = ["DONNEES STOCKEES POUR CET UTILISATEUR{$suffix} (utilise recall_knowledge pour acceder aux details):"];
        foreach ($entries as $entry) {
            $label = $entry->label ?? $entry->topic_key;
            $age = $entry->updated_at->diffForHumans();
            $lines[] = "- [{$entry->topic_key}] {$label} (source: {$entry->source}, {$age})";
        }

        return implode("\n", $lines);
    }

    /**
     * Dispatch quick commands — resolves instantly without LLM call.
     */
    private function handleQuickCommand(AgentContext $context): ?AgentResult
    {
        $body = trim(mb_strtolower($context->body ?? ''));

        if (!in_array($body, self::QUICK_COMMANDS, true)) {
            return null;
        }

        return match ($body) {
            '/status'  => $this->handleStatusCommand($context),
            '/effacer' => $this->handleEffacerCommand($context),
            '/resume'  => $this->handleResumeCommand($context),
            '/ping'    => $this->handlePingCommand($context),
            '/memoire' => $this->handleMemoireCommand($context),
            '/stats'   => $this->handleStatsCommand($context),
            default    => $this->handleHelpCommand($context),
        };
    }

    /**
     * Quick command: /aide — Comprehensive help text with all supported commands.
     */
    private function handleHelpCommand(AgentContext $context): AgentResult
    {
        $senderName = $context->senderName;
        $reply = "*Bonjour {$senderName} ! Voici ce que je sais faire :*\n\n"
            . "*Messages & conversation*\n"
            . "- Discuter librement, repondre a tes questions\n"
            . "- Analyser une image ou lire un PDF\n"
            . "- Transcrire et comprendre un message vocal\n"
            . "- Traduire, resumer, rediger, expliquer\n\n"
            . "*Rappels*\n"
            . "- \"Rappelle-moi d'appeler Jean demain a 10h\"\n"
            . "- \"Rappel chaque lundi a 9h : reunion equipe\"\n"
            . "- \"Annule mon rappel du soir\"\n\n"
            . "*Todos*\n"
            . "- \"Ajoute acheter du pain a ma liste courses\"\n"
            . "- \"Montre ma todo list\"\n"
            . "- \"Marque le point 2 comme fait\"\n\n"
            . "*Projets*\n"
            . "- \"Passe sur le projet ZeniClaw\"\n"
            . "- \"Quels projets j'ai ?\"\n\n"
            . "*Musique*\n"
            . "- \"Cherche Hotel California\"\n"
            . "- \"Recommande-moi de la musique chill\"\n\n"
            . "*Memoire*\n"
            . "- Je me souviens de tes preferences et donnees entre conversations\n"
            . "- \"Souviens-toi que mon email pro est ...\"\n"
            . "- /oublie [sujet] → supprimer une entree memoire\n\n"
            . "*Commandes rapides*\n"
            . "- /aide ou /help → cette aide\n"
            . "- /status → tableau de bord (todos, rappels, memoire)\n"
            . "- /stats → statistiques d'utilisation detaillees\n"
            . "- /resume → resume de la conversation recente\n"
            . "- /memoire → voir ta memoire persistante stockee\n"
            . "- /oublie [sujet] → supprimer une entree de la memoire\n"
            . "- /langue [fr|en|es|de|it|pt|ar|nl|ru] → definir ta langue preferee\n"
            . "- /effacer → effacer l'historique de conversation\n"
            . "- /ping → tester que je reponds bien\n\n"
            . "_Tape simplement ce que tu veux faire, je comprends le langage naturel !_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /aide handled');

        return AgentResult::reply($reply, ['quick_command' => '/aide']);
    }

    /**
     * Quick command: /status — User dashboard with todos, reminders, memory and project stats.
     */
    private function handleStatusCommand(AgentContext $context): AgentResult
    {
        $now = now(AppSetting::timezone());
        $parts = ["*Ton tableau de bord ZeniClaw*\n"];

        // Todos
        $todos = \App\Models\Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();
        $todoDone = $todos->where('is_done', true)->count();
        $todoPending = $todos->where('is_done', false)->count();
        $todoOverdue = $todos->filter(fn($t) => !$t->is_done && $t->due_at && $t->due_at->lt($now))->count();

        if ($todos->isNotEmpty()) {
            $overdueStr = $todoOverdue > 0 ? " _(dont {$todoOverdue} en retard)_" : '';
            $parts[] = "*Todos :* {$todoPending} a faire{$overdueStr}, {$todoDone} termines";
        } else {
            $parts[] = "*Todos :* aucun";
        }

        // Reminders
        $reminders = \App\Models\Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get();

        if ($reminders->isNotEmpty()) {
            $nextReminder = $reminders->first();
            $at = $nextReminder->scheduled_at->setTimezone(AppSetting::timezone())->format('d/m H:i');
            $overdueReminders = $reminders->filter(fn($r) => $r->scheduled_at->lt($now))->count();
            $overdueStr = $overdueReminders > 0 ? " _({$overdueReminders} depasses)_" : '';
            $parts[] = "*Rappels :* {$reminders->count()} en attente{$overdueStr} (prochain : {$at})";
        } else {
            $parts[] = "*Rappels :* aucun en attente";
        }

        // Persistent knowledge
        $knowledge = UserKnowledge::allFor($context->from);
        if ($knowledge->isNotEmpty()) {
            $parts[] = "*Memoire persistante :* {$knowledge->count()} entrees stockees";
        } else {
            $parts[] = "*Memoire persistante :* vide";
        }

        // Conversation memory
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $memoryCount = count($memoryData['entries'] ?? []);
        $parts[] = "*Historique de conversation :* {$memoryCount} echanges";

        // Preferred language
        $lang = $this->resolvePreferredLanguage($context->from);
        $parts[] = "*Langue preferee :* " . ($lang ?? '_non definie_ (/langue fr pour configurer)');

        // Active project
        $activeProjectId = $context->session->active_project_id ?? null;
        if ($activeProjectId) {
            $project = Project::find($activeProjectId);
            $parts[] = "*Projet actif :* " . ($project ? $project->name : 'aucun');
        } else {
            $parts[] = "*Projet actif :* aucun";
        }

        $parts[] = "\n_/resume pour voir la conversation · /effacer pour reinitialiser l'historique_";

        $reply = implode("\n", $parts);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /status handled', ['memory_entries' => $memoryCount]);

        return AgentResult::reply($reply, ['quick_command' => '/status']);
    }

    /**
     * Quick command: /effacer — Clears conversation memory with user confirmation.
     */
    private function handleEffacerCommand(AgentContext $context): AgentResult
    {
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $count = count($memoryData['entries'] ?? []);

        if ($count === 0) {
            $reply = "Ta memoire de conversation est deja vide. Rien a effacer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/effacer', 'memory_cleared' => false]);
        }

        $this->setPendingContext($context, 'confirm_clear_memory', [], ttlMinutes: 2, expectRawInput: true);
        $reply = "Ta memoire de conversation contient *{$count} echanges*. "
            . "Veux-tu vraiment tout effacer ?\n\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /effacer — confirmation requested', ['entries' => $count]);

        return AgentResult::reply($reply, ['quick_command' => '/effacer']);
    }

    /**
     * Quick command: /resume — Shows a formatted summary of recent conversation exchanges.
     */
    private function handleResumeCommand(AgentContext $context): AgentResult
    {
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $entries = $memoryData['entries'] ?? [];

        if (empty($entries)) {
            $reply = "Pas encore d'historique de conversation. Dis-moi quelque chose pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/resume']);
        }

        // Show last 10 exchanges max
        $recent = array_slice($entries, -10);
        $total = count($entries);
        $shown = count($recent);

        $suffix = $total > $shown ? " _{$shown} derniers sur {$total}_" : " _{$shown} echange(s)_";
        $lines = ["*Resume de la conversation*{$suffix}\n"];

        foreach ($recent as $i => $entry) {
            $num = $i + 1;
            $userMsg = $entry['sender_message'] ?? '';
            $botMsg = $entry['bot_reply'] ?? $entry['summary'] ?? '';

            // Timestamp if available
            $ts = '';
            if (!empty($entry['created_at'])) {
                try {
                    $ts = ' _(' . \Illuminate\Support\Carbon::parse($entry['created_at'])
                        ->setTimezone(AppSetting::timezone())
                        ->format('d/m H:i') . ')_';
                } catch (\Throwable) {}
            }

            $truncatedUser = mb_strlen($userMsg) > 80 ? mb_substr($userMsg, 0, 80) . '...' : $userMsg;
            $truncatedBot  = mb_strlen($botMsg) > 100 ? mb_substr($botMsg, 0, 100) . '...' : $botMsg;

            if ($truncatedUser) {
                $lines[] = "*{$num}. Toi{$ts}:* {$truncatedUser}";
            }
            if ($truncatedBot) {
                $lines[] = "   _ZeniClaw :_ {$truncatedBot}";
            }
        }

        if ($total > 10) {
            $lines[] = "\n_({$total} echanges au total — /effacer pour reinitialiser)_";
        } else {
            $lines[] = "\n_/effacer pour reinitialiser l'historique_";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /resume handled', ['entries_shown' => $shown, 'total' => $total]);

        return AgentResult::reply($reply, ['quick_command' => '/resume', 'entries_shown' => $shown]);
    }

    /**
     * Quick command: /ping — Heartbeat test; confirms the agent is alive and responsive.
     */
    private function handlePingCommand(AgentContext $context): AgentResult
    {
        $now = now(AppSetting::timezone())->format('H:i:s');
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $memoryCount = count($memoryData['entries'] ?? []);
        $lang = $this->resolvePreferredLanguage($context->from);

        $knowledgeCount = UserKnowledge::allFor($context->from)->count();
        $reply = "*Pong !* Je suis bien la.\n\n"
            . "- Heure: *{$now}*\n"
            . "- Historique: *{$memoryCount}* echange(s)\n"
            . "- Memoire persistante: *{$knowledgeCount}* entree(s)\n"
            . "- Langue: *" . ($lang ?? 'auto') . "*\n"
            . "- Version agent: *" . $this->version() . "*\n\n"
            . "_Tout fonctionne correctement._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /ping handled');

        return AgentResult::reply($reply, ['quick_command' => '/ping', 'version' => $this->version()]);
    }

    /**
     * Quick command: /memoire — Shows all stored persistent knowledge entries for the user.
     */
    private function handleMemoireCommand(AgentContext $context): AgentResult
    {
        $allEntries = \App\Models\UserKnowledge::allFor($context->from);

        if ($allEntries->isEmpty()) {
            $reply = "*Memoire persistante*\n\nAucune donnee stockee pour l'instant.\n\n"
                . "_Dis-moi des infos importantes (email, preferences, contacts...) "
                . "et je les retiendrai pour toujours !_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/memoire', 'count' => 0]);
        }

        $total = $allEntries->count();
        $displayed = $allEntries->take(25);
        $suffix = $total > 25 ? " _(25 sur {$total} affichees)_" : " _({$total} entree(s))_";

        $lines = ["*Memoire persistante*{$suffix}\n"];

        $grouped = $displayed->groupBy('source');
        foreach ($grouped as $source => $entries) {
            $lines[] = "\n*[{$source}]*";
            foreach ($entries as $entry) {
                $label = $entry->label ?? $entry->topic_key;
                $age = $entry->updated_at->diffForHumans();
                $key = $entry->topic_key;
                // Show a short preview of the stored value if available
                $data = $entry->data ?? [];
                $valuePreview = '';
                if (!empty($data['value'])) {
                    $val = (string) $data['value'];
                    $valuePreview = ': _' . (mb_strlen($val) > 50 ? mb_substr($val, 0, 50) . '...' : $val) . '_';
                }
                $lines[] = "- *{$label}*{$valuePreview} (`{$key}`, {$age})";
            }
        }

        if ($total > 25) {
            $lines[] = "\n_Pour acceder a toutes les entrees, demande-moi de lister ma memoire._";
        }
        $lines[] = "\n_/oublie [sujet] pour supprimer une entree · /stats pour tes statistiques_";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /memoire handled', ['count' => $total]);

        return AgentResult::reply($reply, ['quick_command' => '/memoire', 'count' => $total]);
    }

    /**
     * Quick command: /langue [code] — Sets or displays the user's preferred response language.
     */
    private function handleLangueCommand(AgentContext $context, string $langCode): AgentResult
    {
        $langCode = mb_strtolower(trim($langCode));

        // No code provided — show current setting and options
        if ($langCode === '') {
            $current = $this->resolvePreferredLanguage($context->from);
            $available = implode(', ', array_map(
                fn($code, $name) => "/{$code} ({$name})",
                array_keys(self::SUPPORTED_LANGUAGES),
                self::SUPPORTED_LANGUAGES
            ));
            $currentStr = $current ? "*{$current}*" : '_non definie_';
            $reply = "*Langue preferee actuelle :* {$currentStr}\n\n"
                . "*Langues disponibles :*\n{$available}\n\n"
                . "_Exemple : `/langue en` pour passer en anglais_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/langue', 'action' => 'display']);
        }

        // Validate the language code
        if (!array_key_exists($langCode, self::SUPPORTED_LANGUAGES)) {
            $available = implode(', ', array_keys(self::SUPPORTED_LANGUAGES));
            $reply = "Code de langue non reconnu : *{$langCode}*\n\nCodes supportes : {$available}";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/langue', 'action' => 'error']);
        }

        $langName = self::SUPPORTED_LANGUAGES[$langCode];

        // Store in context memory
        $store = new ContextStore();
        $store->store($context->from, [[
            'key' => 'preferred_language',
            'category' => 'preference',
            'value' => $langName,
            'score' => 0.9,
        ]]);

        $reply = "Langue preferee configuree : *{$langName}* ({$langCode}). "
            . "Je repondrai desormais en {$langName} par defaut.";
        $this->sendText($context->from, $reply);
        $this->log($context, "Language preference set to {$langName}", ['lang' => $langCode]);

        return AgentResult::reply($reply, ['quick_command' => '/langue', 'action' => 'set', 'lang' => $langCode]);
    }

    /**
     * Contextual empty-message guard.
     * Returns a friendly nudge if the message body and media are both absent.
     */
    private function buildEmptyMessageFallback(AgentContext $context): ?AgentResult
    {
        $body = trim($context->body ?? '');
        if ($body !== '' || $context->hasMedia) {
            return null;
        }

        $hour = (int) now(AppSetting::timezone())->format('H');
        $greeting = match (true) {
            $hour >= 5 && $hour < 12  => 'Bonjour',
            $hour >= 12 && $hour < 18 => 'Coucou',
            $hour >= 18 && $hour < 22 => 'Bonsoir',
            default                    => 'Salut',
        };

        // Vary the nudge to avoid repetitive messages
        $nudges = [
            "{$greeting} ! Tu m'as envoye un message vide. Ecris-moi quelque chose, envoie une image, un PDF ou un vocal — je suis la !",
            "{$greeting} {$context->senderName} ! Message vide recu. Tu peux m'ecrire, m'envoyer une image ou un vocal.",
            "{$greeting} ! Il me semble que ton message est arrive vide. Reessaie ou tape /aide pour voir ce que je sais faire.",
        ];

        // Pick based on memory count to vary across sessions
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $idx = count($memoryData['entries'] ?? []) % count($nudges);
        $reply = $nudges[$idx];

        $this->sendText($context->from, $reply);
        $this->log($context, 'Empty message received — nudge sent');

        return AgentResult::reply($reply, ['empty_message' => true]);
    }

    /**
     * Quick command: /stats — Detailed usage statistics for the user.
     */
    private function handleStatsCommand(AgentContext $context): AgentResult
    {
        $now = now(AppSetting::timezone());
        $parts = ["*Statistiques d'utilisation ZeniClaw*\n"];

        // Conversation memory stats
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $entries = $memoryData['entries'] ?? [];
        $totalMessages = count($entries);

        if ($totalMessages > 0) {
            // First and last message dates
            $firstEntry = $entries[0];
            $lastEntry = $entries[array_key_last($entries)];
            $firstDate = !empty($firstEntry['created_at'])
                ? \Illuminate\Support\Carbon::parse($firstEntry['created_at'])->setTimezone(AppSetting::timezone())->format('d/m/Y')
                : 'inconnu';
            $lastDate = !empty($lastEntry['created_at'])
                ? \Illuminate\Support\Carbon::parse($lastEntry['created_at'])->setTimezone(AppSetting::timezone())->format('d/m/Y H:i')
                : 'inconnu';

            $parts[] = "*Historique de conversation :*\n"
                . "- Total echanges: *{$totalMessages}*\n"
                . "- Depuis le: *{$firstDate}*\n"
                . "- Dernier message: *{$lastDate}*";

            // Message length stats
            $lengths = array_filter(array_map(fn ($e) => mb_strlen($e['sender_message'] ?? ''), $entries));
            if (!empty($lengths)) {
                $avgLen = (int) (array_sum($lengths) / count($lengths));
                $maxLen = max($lengths);
                $parts[] = "- Longueur moyenne des messages: *{$avgLen}* caracteres (max: *{$maxLen}*)";
            }
        } else {
            $parts[] = "*Historique de conversation :* aucun echange enregistre";
        }

        // Todos stats
        $todos = \App\Models\Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        if ($todos->isNotEmpty()) {
            $todoDone    = $todos->where('is_done', true)->count();
            $todoPending = $todos->where('is_done', false)->count();
            $todoOverdue = $todos->filter(fn ($t) => !$t->is_done && $t->due_at && $t->due_at->lt($now))->count();
            $completionRate = $todos->count() > 0 ? round(($todoDone / $todos->count()) * 100) : 0;
            $overdueStr = $todoOverdue > 0 ? ", *{$todoOverdue}* en retard" : '';
            $parts[] = "*Todos :*\n"
                . "- Total: *{$todos->count()}* (fait: *{$todoDone}*, a faire: *{$todoPending}*{$overdueStr})\n"
                . "- Taux de completion: *{$completionRate}%*";
        } else {
            $parts[] = "*Todos :* aucun";
        }

        // Reminders stats
        $reminders = \App\Models\Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        if ($reminders->isNotEmpty()) {
            $pending   = $reminders->where('status', 'pending')->count();
            $sent      = $reminders->where('status', 'sent')->count();
            $cancelled = $reminders->where('status', 'cancelled')->count();
            $recurrent = $reminders->whereNotNull('recurrence_rule')->count();
            $parts[] = "*Rappels :*\n"
                . "- En attente: *{$pending}*, envoyes: *{$sent}*, annules: *{$cancelled}*\n"
                . "- Recurrents: *{$recurrent}*";
        } else {
            $parts[] = "*Rappels :* aucun cree";
        }

        // Knowledge stats
        $knowledge = UserKnowledge::allFor($context->from);
        if ($knowledge->isNotEmpty()) {
            $bySource = $knowledge->groupBy('source');
            $sourceLines = $bySource->map(fn ($items, $src) => "  - {$src}: *{$items->count()}*")->implode("\n");
            $parts[] = "*Memoire persistante :* *{$knowledge->count()}* entree(s)\n{$sourceLines}";
        } else {
            $parts[] = "*Memoire persistante :* vide";
        }

        // Language
        $lang = $this->resolvePreferredLanguage($context->from);
        $parts[] = "*Langue preferee :* " . ($lang ?? '_non definie_');

        $parts[] = "\n_/status pour le tableau de bord · /memoire pour les details_";

        $reply = implode("\n\n", $parts);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /stats handled', ['total_messages' => $totalMessages]);

        return AgentResult::reply($reply, ['quick_command' => '/stats', 'total_messages' => $totalMessages]);
    }

    /**
     * Prefix command: /oublie [sujet] — Deletes a specific knowledge entry from persistent memory.
     */
    private function handleOublieCommand(AgentContext $context, string $subject): AgentResult
    {
        // No subject provided — show usage instructions
        if ($subject === '') {
            $reply = "*Commande /oublie*\n\n"
                . "Usage: `/oublie [sujet]`\n\n"
                . "Exemples:\n"
                . "- `/oublie email` → supprime la cle 'email'\n"
                . "- `/oublie telephone` → supprime la cle 'telephone'\n\n"
                . "_Utilise /memoire pour voir les cles disponibles._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/oublie', 'action' => 'usage']);
        }

        // Search for matching entries (topic_key or label)
        $matches = UserKnowledge::search($context->from, $subject);

        if ($matches->isEmpty()) {
            // Try exact topic_key match as fallback
            $exact = UserKnowledge::recall($context->from, $subject);
            if ($exact) {
                $matches = collect([$exact]);
            }
        }

        if ($matches->isEmpty()) {
            $reply = "Aucune entree trouvee dans ta memoire pour *{$subject}*.\n\n"
                . "_Utilise /memoire pour voir toutes tes entrees._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['quick_command' => '/oublie', 'action' => 'not_found', 'subject' => $subject]);
        }

        // Delete all matching entries
        $deleted = [];
        foreach ($matches as $entry) {
            $deleted[] = $entry->label ?? $entry->topic_key;
            $entry->delete();
        }

        $deletedList = implode(', ', array_map(fn ($d) => "*{$d}*", $deleted));
        $count = count($deleted);
        $reply = ($count === 1)
            ? "Entree {$deletedList} supprimee de ta memoire persistante."
            : "{$count} entrees supprimees : {$deletedList}.";

        $this->sendText($context->from, $reply);
        $this->log($context, "Knowledge entries deleted via /oublie", [
            'subject' => $subject,
            'deleted' => $deleted,
            'count'   => $count,
        ]);

        return AgentResult::reply($reply, ['quick_command' => '/oublie', 'action' => 'deleted', 'count' => $count, 'deleted' => $deleted]);
    }

    private function buildProjectContext(AgentContext $context): ?string
    {
        $phone = $context->from;
        $agentId = $context->agent->id;
        $activeProjectId = $context->session->active_project_id;
        $cacheKey = "project_context:{$agentId}:{$phone}";

        return Cache::remember($cacheKey, 300, function () use ($phone, $activeProjectId) {
            $ownedProjects = Project::where('requester_phone', $phone)
                ->whereIn('status', ['approved', 'in_progress', 'completed'])
                ->orderByDesc('created_at')
                ->get();

            $allowedProjects = Project::whereNotNull('allowed_phones')
                ->whereIn('status', ['approved', 'in_progress', 'completed'])
                ->where('requester_phone', '!=', $phone)
                ->get()
                ->filter(fn($p) => is_array($p->allowed_phones) && in_array($phone, $p->allowed_phones));

            $allProjects = $ownedProjects->merge($allowedProjects)->unique('id');

            if ($allProjects->isEmpty()) {
                return "PROJETS: Aucun projet n'est configure pour cet utilisateur. "
                    . "S'il demande de modifier du code ou un projet, dis-lui d'envoyer l'URL GitLab du repo.";
            }

            $lines = ["PROJETS CONFIGURES POUR CET UTILISATEUR:"];
            foreach ($allProjects as $project) {
                $status = match ($project->status) {
                    'approved' => 'pret',
                    'in_progress' => 'en cours',
                    'completed' => 'termine',
                    default => $project->status,
                };

                $line = "- {$project->name} (GitLab: {$project->gitlab_url}, statut: {$status})";
                $lines[] = $line;
            }

            if ($activeProjectId) {
                $activeProject = $allProjects->firstWhere('id', $activeProjectId);
                if ($activeProject) {
                    $lines[] = "\nPROJET ACTIF ACTUELLEMENT: {$activeProject->name} ({$activeProject->gitlab_url})";
                }
            }

            return implode("\n", $lines);
        });
    }
}
