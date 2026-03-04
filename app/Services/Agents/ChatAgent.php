<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;
use App\Services\WhisperService;

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
        $bodyForMemory = $context->body ?? '';

        // Handle media description for memory
        if ($this->lastVoiceTranscript) {
            $bodyForMemory = '[Vocal: "' . mb_substr($this->lastVoiceTranscript, 0, 200) . '"]';
        } elseif (!$bodyForMemory && $context->hasMedia) {
            $mimetype = $context->mimetype ?? '';
            if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES)) {
                $bodyForMemory = '[Image envoyée]';
            } elseif ($mimetype === 'application/pdf') {
                $bodyForMemory = '[PDF envoyé]';
            } else {
                $bodyForMemory = '[Media envoyé]';
            }
        }

        $reply = $this->claude->chat($claudeMessage, $model, $systemPrompt);

        if (!$reply) {
            return AgentResult::reply('Désolé, je n\'ai pas pu générer de réponse. Réessaie !');
        }

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reply sent', [
            'model' => $model,
            'complexity' => $context->complexity,
            'reply' => mb_substr($reply, 0, 200),
        ]);

        return AgentResult::reply($reply, ['model' => $model, 'complexity' => $context->complexity]);
    }

    private function buildSystemPrompt(AgentContext $context): string
    {
        $systemPrompt =
            "Tu es ZeniClaw, un assistant WhatsApp cool et sympa. " .
            "Tu parles comme un ami ou un collègue décontracté. " .
            "Tu tutoies, tu es direct, drôle et bienveillant. " .
            "Tu utilises un langage naturel et détendu (pas trop formel). " .
            "Tu peux utiliser des emojis avec modération. " .
            "Réponds de manière concise (2-3 phrases max sauf si on te demande plus). " .
            "Le message vient de {$context->senderName}.";

        $projectContext = $this->buildProjectContext($context);
        if ($projectContext) {
            $systemPrompt .= "\n\n" . $projectContext;
        }

        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from);
        if ($memoryContext) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }

        return $systemPrompt;
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
                // Fallback if transcription failed
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoyé un message vocal/audio. Tu ne peux pas l'écouter, " .
                    "dis-le poliment et propose de continuer la conversation.]";
            } else {
                // Unsupported media type
                $mediaDesc = match (true) {
                    str_starts_with($mimetype, 'video/') => 'une vidéo',
                    $mimetype === 'image/webp' && str_contains($context->mediaUrl, 'sticker') => 'un sticker',
                    default => "un fichier de type {$mimetype}",
                };
                $message = ($context->body ? "{$context->body}\n\n" : '') .
                    "[{$context->senderName} a envoyé {$mediaDesc}. Tu ne peux pas le voir/écouter, " .
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
                \Illuminate\Support\Facades\Log::warning('[chat] Failed to download voice message');
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

        $text = $caption ?: 'Décris ce que tu vois.';
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }

    private function buildProjectContext(AgentContext $context): ?string
    {
        $phone = $context->from;
        $session = $context->session;

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

        $lines = ["PROJETS CONFIGURES POUR CET UTILISATEUR (donnees reelles de la base de donnees):"];
        foreach ($allProjects as $project) {
            $status = match ($project->status) {
                'approved' => 'pret',
                'in_progress' => 'en cours',
                'completed' => 'termine',
                default => $project->status,
            };

            $lastTask = $project->subAgents()->orderByDesc('created_at')->first();
            $line = "- {$project->name} (GitLab: {$project->gitlab_url}, statut: {$status})";
            if ($lastTask) {
                $taskStatus = match ($lastTask->status) {
                    'queued' => 'en attente',
                    'running' => 'en cours',
                    'completed' => 'termine',
                    'failed' => 'echoue',
                    'killed' => 'arrete',
                    default => $lastTask->status,
                };
                $line .= " — derniere tache: \"{$lastTask->task_description}\" ({$taskStatus})";
            }
            $lines[] = $line;
        }

        if ($session->active_project_id) {
            $activeProject = $allProjects->firstWhere('id', $session->active_project_id);
            if ($activeProject) {
                $lines[] = "\nPROJET ACTIF ACTUELLEMENT: {$activeProject->name} ({$activeProject->gitlab_url})";
            }
        }

        $lines[] = "Quand l'utilisateur pose une question sur ses projets, utilise UNIQUEMENT ces informations reelles.";

        return implode("\n", $lines);
    }
}
