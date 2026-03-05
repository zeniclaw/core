<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;
use App\Services\AgenticLoop;
use App\Services\AgentTools;
use App\Services\WhisperService;
use Illuminate\Support\Facades\Cache;

class ChatAgent extends BaseAgent
{
    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private ?string $lastVoiceTranscript = null;

    public function name(): string
    {
        return 'chat';
    }

    public function canHandle(AgentContext $context): bool
    {
        return true; // Fallback agent, always can handle
    }

    public function handle(AgentContext $context): AgentResult
    {
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

        // Fallback to simple chat if agentic loop fails
        if (!$reply) {
            $reply = $this->claude->chat(
                is_array($claudeMessage) ? $claudeMessage : $claudeMessage,
                'claude-haiku-4-5-20251001',
                $systemPrompt
            );
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
        ]);
    }

    private function buildSystemPrompt(AgentContext $context): string
    {
        $now = now('Europe/Paris')->format('Y-m-d H:i (l)');

        $systemPrompt =
            "Tu es ZeniClaw, un assistant WhatsApp autonome et intelligent. "
            . "Tu as acces a des outils (tools) que tu peux utiliser librement pour aider l'utilisateur. "
            . "Tu n'as PAS besoin de demander permission — utilise les outils quand c'est pertinent. "
            . "Par exemple, si l'utilisateur dit 'rappelle-moi d'appeler Jean demain a 10h', "
            . "utilise directement l'outil create_reminder sans demander confirmation. "
            . "Si l'utilisateur parle de sa todo list, utilise les outils todo directement. "
            . "Tu es proactif : si tu peux resoudre le probleme avec un outil, fais-le. "
            . "\n\n"
            . "STYLE: Tu parles comme un ami ou un collegue decontracte. "
            . "Tu tutoies, tu es direct, drole et bienveillant. "
            . "Tu utilises un langage naturel et detendu (pas trop formel). "
            . "Tu peux utiliser des emojis avec moderation. "
            . "Reponds dans la meme langue que l'utilisateur. "
            . $this->buildLengthDirective($context->complexity)
            . "\n\nDate et heure actuelles (Paris): {$now}"
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
                $at = $r->scheduled_at->setTimezone('Europe/Paris')->format('d/m H:i');
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
            } elseif (str_starts_with($mimetype, 'audio/')) {
                $voiceContent = $this->handleVoiceMessage($context);
                if ($voiceContent) {
                    return $voiceContent;
                }
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoye un message vocal/audio. Tu ne peux pas l'ecouter, " .
                    "dis-le poliment et propose de continuer la conversation.]";
            } else {
                $mediaDesc = match (true) {
                    str_starts_with($mimetype, 'video/') => 'une video',
                    $mimetype === 'image/webp' && str_contains($context->mediaUrl, 'sticker') => 'un sticker',
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
            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to download media: ' . $e->getMessage());
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
                return null;
            }

            $audioBytes = $response->body();
            $whisper = new WhisperService();
            $transcript = $whisper->transcribe($audioBytes, $context->mimetype ?? 'audio/ogg');

            if (!$transcript) {
                return null;
            }

            $this->lastVoiceTranscript = $transcript;

            $caption = $context->body ? "\n{$context->body}" : '';
            return "[Message vocal de {$context->senderName}]\nTranscription : \"{$transcript}\"{$caption}";
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[chat] Voice message handling failed: ' . $e->getMessage());
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

        $text = $caption ?: 'Decris ce que tu vois.';
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
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
