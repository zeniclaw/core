<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Project;
use App\Models\UserKnowledge;
use App\Services\AgentContext;
use App\Services\AgenticLoop;
use App\Services\AgentTools;
use App\Services\WhisperService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatAgent extends BaseAgent
{
    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum media size accepted before download (20 MB). */
    private const MAX_MEDIA_BYTES = 20 * 1024 * 1024;

    /** Quick commands resolved without agentic loop. */
    private const QUICK_COMMANDS = ['/aide', '/help', '/capacites', '/capabilities', '/status', '/effacer'];

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
            'bonjour', 'salut', 'hello', 'hey', 'coucou', 'bonsoir',
            'comment ca va', 'ca va', 'quoi de neuf', 'question',
            'aide', 'help', 'info', 'information',
            'merci', 'thanks', 'ok', 'daccord',
            'raconte', 'explique', 'dis-moi', 'parle-moi',
            'blague', 'joke', 'rigole', 'humour',
            'qui es-tu', 'tu fais quoi', 'what can you do',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true; // Fallback agent, always can handle
    }

    public function handle(AgentContext $context): AgentResult
    {
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
        $systemPrompt = $this->buildSystemPrompt($context);
        $this->lastVoiceTranscript = null;
        $claudeMessage = $this->buildMessageContent($context);

        $debug = file_exists(storage_path('app/orchestrator_debug'));

        // Run the agentic loop — the LLM decides which tools to use
        $loop = new AgenticLoop(maxIterations: 10, debug: $debug);
        $result = $loop->run(
            userMessage: $claudeMessage,
            systemPrompt: $systemPrompt,
            model: $model,
            context: $context,
            tools: AgentTools::definitions(),
        );

        $reply = $result->reply;

        // Fallback to simple chat if agentic loop fails (use same resolved model)
        if (!$reply) {
            $reply = $this->claude->chat($claudeMessage, $model, $systemPrompt);
        }

        if (!$reply) {
            $fallback = 'Desole, je n\'ai pas pu generer de reponse. Reessaie !';
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }

        $this->sendText($context->from, $reply);

        $modelUsed = $result->model ?? $model;
        $this->log($context, 'Reply sent (agentic)', [
            'model' => $modelUsed,
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

    private function buildSystemPrompt(AgentContext $context): string
    {
        $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

        $systemPrompt =
            "Tu es ZeniClaw, un assistant WhatsApp autonome et intelligent. "
            . "Tu as acces a des outils (tools) que tu peux utiliser librement pour aider l'utilisateur. "
            . "Tu n'as PAS besoin de demander permission — utilise les outils quand c'est pertinent. "
            . "Par exemple, si l'utilisateur dit 'rappelle-moi d'appeler Jean demain a 10h', "
            . "utilise directement l'outil create_reminder sans demander confirmation. "
            . "Si l'utilisateur parle de sa todo list, utilise les outils todo directement. "
            . "Si l'utilisateur partage une info importante (email, numero, preference, donnee cle), "
            . "stocke-la avec store_knowledge pour ne pas l'oublier. "
            . "Tu es proactif : si tu peux resoudre le probleme avec un outil, fais-le. "
            . "\n\n"
            . "MEMOIRE PERSISTANTE (CRITIQUE):\n"
            . "- AVANT de demander une info a l'utilisateur ou de faire un appel API, utilise TOUJOURS recall_knowledge ou list_knowledge pour verifier si tu n'as pas deja cette info.\n"
            . "- Quand tu obtiens des donnees importantes (listes de clients, resultats API, infos financieres, etc.), utilise store_knowledge pour les sauvegarder.\n"
            . "- Les donnees sont stockees PAR UTILISATEUR et persistent entre les conversations.\n"
            . "- Ne redemande JAMAIS une info que tu as deja stockee.\n"
            . "\n\n"
            . "STYLE: Tu parles comme un ami ou un collegue decontracte. "
            . "Tu tutoies, tu es direct, drole et bienveillant. "
            . "Tu utilises un langage naturel et detendu (pas trop formel). "
            . "Tu peux utiliser des emojis avec moderation. "
            . "Reponds dans la meme langue que l'utilisateur. "
            . $this->buildLengthDirective($context->complexity)
            . "\n\nFORMATAGE WHATSAPP: "
            . "Utilise *texte* pour le gras, _texte_ pour l'italique, ~texte~ pour le barre. "
            . "Pour les listes, utilise des tirets (-) ou des numeros (1. 2. 3.). "
            . "Evite le Markdown classique (##, **, etc.) — utilise exclusivement le formatage WhatsApp natif. "
            . "Pour les separateurs visuels, utilise ---. "
            . "Si tu affiches du code, encadre-le avec des backticks (`) ou triple backticks (```)."
            . "\n\nDate et heure actuelles (" . AppSetting::timezone() . "): {$now}"
            . "\nLe message vient de {$context->senderName}.";

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

        return $systemPrompt;
    }

    /**
     * Build a summary of active user data (todos, reminders) so the LLM can reference them.
     */
    private function buildUserContext(AgentContext $context): string
    {
        $parts = [];

        // Active todos summary
        $todos = \App\Models\Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('id')
            ->get();

        if ($todos->isNotEmpty()) {
            $lines = ['TODOS ACTIFS:'];
            foreach ($todos->values() as $i => $todo) {
                $status = $todo->is_done ? 'FAIT' : 'A FAIRE';
                $list = $todo->list_name ? " [{$todo->list_name}]" : '';
                $lines[] = "  " . ($i + 1) . ". [{$status}] {$todo->title}{$list}";
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
                $lines[] = "  " . ($i + 1) . ". {$r->message} → {$at}{$recurrence}";
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
                $topics[] = $summary;
            } else {
                $msg = mb_substr($entry['sender_message'] ?? '', 0, 80);
                if ($msg) {
                    $topics[] = $msg;
                }
            }
        }

        if (empty($topics)) {
            return '';
        }

        $topics = array_slice($topics, -30);
        return "Sujets abordes precedemment (" . count($olderEntries) . " echanges) :\n- " . implode("\n- ", $topics);
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
                    . "Informe l'utilisateur et invite-le a reessayer.]";
            } elseif (str_starts_with($mimetype, 'audio/')) {
                $voiceContent = $this->handleVoiceMessage($context);
                if ($voiceContent) {
                    return $voiceContent;
                }
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoye un message vocal/audio. Tu ne peux pas l'ecouter, " .
                    "dis-le poliment et propose de continuer la conversation.]";
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
            $text = $caption
                ? $caption
                : "Analyse ce document PDF. Commence par un resume concis (3-5 points cles), "
                . "puis demande a l'utilisateur s'il veut plus de details sur une section specifique.";
        } else {
            // Smart image analysis: read text + describe content + handle screenshots/documents
            $imageHint = "Analyse cette image avec attention : "
                . "1) Si elle contient du texte (screenshot, document, photo d'affiche), lis-le integralement et cite-le. "
                . "2) Decris ce que tu vois : personnes, objets, lieux, couleurs, mise en page. "
                . "3) Si c'est un graphique, code ou tableau, analyse son contenu specifiquement.";
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
            . "- Transcrire et comprendre un message vocal\n\n"
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
            . "- Je me souviens de tes preferences et donnees entre conversations\n\n"
            . "*Commandes rapides*\n"
            . "- /aide ou /help → cette aide\n"
            . "- /status → ton tableau de bord (todos, rappels, memoire)\n"
            . "- /effacer → effacer ta memoire de conversation\n\n"
            . "_Tape simplement ce que tu veux faire, je comprends le langage naturel !_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /aide handled');

        return AgentResult::reply($reply, ['quick_command' => '/aide']);
    }

    /**
     * New Feature 1: /status — User dashboard with todos, reminders, memory and project stats.
     */
    private function handleStatusCommand(AgentContext $context): AgentResult
    {
        $parts = ["*Ton tableau de bord ZeniClaw*\n"];

        // Todos
        $todos = \App\Models\Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();
        $todoDone = $todos->where('is_done', true)->count();
        $todoPending = $todos->where('is_done', false)->count();

        if ($todos->isNotEmpty()) {
            $parts[] = "*Todos :* {$todoPending} a faire, {$todoDone} termines";
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
            $parts[] = "*Rappels :* {$reminders->count()} en attente (prochain : {$at})";
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

        // Active project
        $activeProjectId = $context->session->active_project_id ?? null;
        if ($activeProjectId) {
            $project = Project::find($activeProjectId);
            if ($project) {
                $parts[] = "*Projet actif :* {$project->name}";
            } else {
                $parts[] = "*Projet actif :* aucun";
            }
        } else {
            $parts[] = "*Projet actif :* aucun";
        }

        $parts[] = "\n_/effacer pour reinitialiser l'historique de conversation_";

        $reply = implode("\n", $parts);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quick command /status handled', ['memory_entries' => $memoryCount]);

        return AgentResult::reply($reply, ['quick_command' => '/status']);
    }

    /**
     * New Feature 2: /effacer — Clears conversation memory with user confirmation.
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

        $reply = "{$greeting} ! Tu m'as envoye un message vide. "
            . "Ecris-moi quelque chose, envoie une image, un PDF ou un vocal — je suis la !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Empty message received — nudge sent');

        return AgentResult::reply($reply, ['empty_message' => true]);
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
