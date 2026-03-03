<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\AgentSession;
use App\Jobs\RunSubAgentJob;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SubAgent;
use App\Services\AnthropicClient;
use App\Services\ConversationMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';
    private string $sessionName = 'default';

    private function waha(int $timeout = 10)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    /**
     * Ensure a WAHA session exists and is started.
     * Handles all states: missing, STOPPED, FAILED, SCAN_QR_CODE, WORKING.
     */
    private function ensureSession(): string
    {
        $status = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");

        if (!$status->successful()) {
            // Session doesn't exist → create it
            $this->waha()->post("{$this->wahaBase}/api/sessions/start", [
                'name' => $this->sessionName,
            ]);
            return 'STARTING';
        }

        $sessionStatus = $status->json()['status'] ?? '';

        if ($sessionStatus === 'WORKING' || $sessionStatus === 'SCAN_QR_CODE') {
            return $sessionStatus;
        }

        if ($sessionStatus === 'STOPPED') {
            // Restart — preserves auth data, no new QR needed
            $this->waha()->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/start");
            return 'STARTING';
        }

        // FAILED or unknown — auth is broken, delete and recreate
        $this->waha()->delete("{$this->wahaBase}/api/sessions/{$this->sessionName}");
        usleep(500_000);
        $this->waha()->post("{$this->wahaBase}/api/sessions/start", [
            'name' => $this->sessionName,
        ]);
        return 'STARTING';
    }

    public function startWhatsapp(Request $request): JsonResponse
    {
        try {
            $result = $this->ensureSession();
            return response()->json(['status' => $result]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function getQr(): JsonResponse
    {
        try {
            $status = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");

            if ($status->successful()) {
                $data = $status->json();
                $sessionStatus = $data['status'] ?? '';

                if ($sessionStatus === 'WORKING') {
                    return response()->json([
                        'connected' => true,
                        'phone' => $data['me']['pushname'] ?? $data['me']['id'] ?? 'Connected',
                    ]);
                }

                if ($sessionStatus === 'SCAN_QR_CODE') {
                    $response = $this->waha()->get("{$this->wahaBase}/api/{$this->sessionName}/auth/qr", ['format' => 'image']);
                    if ($response->successful()) {
                        $base64 = base64_encode($response->body());
                        return response()->json(['qr' => "data:image/png;base64,{$base64}"]);
                    }
                }

                // STARTING, FAILED, etc. — tell frontend to keep waiting
                return response()->json(['status' => $sessionStatus, 'waiting' => true]);
            }

            return response()->json(['status' => 'NO_SESSION', 'waiting' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'WAHA unavailable'], 503);
        }
    }

    public function statusWhatsapp(): JsonResponse
    {
        try {
            $response = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'connected' => ($data['status'] ?? '') === 'WORKING',
                    'status' => $data['status'] ?? 'STOPPED',
                    'phone' => $data['me']['pushname'] ?? null,
                ]);
            }
            return response()->json(['connected' => false, 'status' => 'STOPPED']);
        } catch (\Exception $e) {
            return response()->json(['connected' => false, 'status' => 'ERROR']);
        }
    }

    public function stopWhatsapp(): JsonResponse
    {
        try {
            $this->waha()->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/stop");
            return response()->json(['status' => 'stopped']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    /**
     * Detect if a message contains a GitLab URL and extract it.
     * Returns ['url' => '...', 'description' => '...'] or null.
     */
    private function detectGitlabUrl(string $body): ?array
    {
        if (preg_match('#(https?://gitlab\.[^\s]+)#i', $body, $matches)) {
            $url = rtrim($matches[1], '.,;:!?)');
            $description = trim(str_replace($matches[0], '', $body));
            if (!$description) {
                $description = 'Modification demandee (pas de description supplementaire)';
            }
            return ['url' => $url, 'description' => $description];
        }
        return null;
    }

    /**
     * Use Claude Haiku to classify if a message is a task request or casual chat.
     * TASK = any actionable request (code, files, documents, modifications, etc.)
     * CHAT = conversation, question, greeting, small talk
     */
    private function isTaskRequest(string $body): bool
    {
        $claude = new AnthropicClient();
        $response = $claude->chat(
            "Message: \"{$body}\"\n\nReponds UNIQUEMENT par TASK ou CHAT.",
            'claude-haiku-4-5-20251001',
            "Tu classes des messages WhatsApp. Reponds un seul mot: TASK ou CHAT.\n"
            . "TASK = le message demande une action concrete: modifier du code, creer un fichier, "
            . "generer un document (xlsx, pdf...), deployer, corriger un bug, ajouter une fonctionnalite, "
            . "faire une modification sur un site/app, ou toute autre tache executable.\n"
            . "CHAT = conversation normale, question d'info, salutation, remerciement, blague, opinion."
        );

        return str_contains(strtoupper(trim($response ?? '')), 'TASK');
    }

    /**
     * Find an approved project whose name is mentioned in the message.
     * Checks both projects owned by the user and projects with allowed_phones.
     */
    private function findProjectByNameInMessage(string $body, string $phone): ?Project
    {
        // When a user explicitly mentions a project name, search ALL approved projects.
        // The name mention is intentional — no need to restrict by phone/allowed_phones.
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        // Try exact name match in message
        foreach ($projects as $project) {
            if (mb_stripos($body, $project->name) !== false) {
                return $project;
            }
        }

        // Try matching against the repo slug from gitlab_url
        foreach ($projects as $project) {
            $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
            $slug = str_replace('.git', '', $slug);
            if ($slug && mb_stripos($body, $slug) !== false) {
                return $project;
            }
        }

        return null;
    }

    /**
     * Find an approved project where this phone is in allowed_phones.
     */
    private function findProjectByAllowedPhone(string $phone): ?Project
    {
        return Project::whereNotNull('allowed_phones')
            ->whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get()
            ->first(function ($project) use ($phone) {
                return is_array($project->allowed_phones) && in_array($phone, $project->allowed_phones);
            });
    }

    /**
     * Find the last active project for a given phone number.
     */
    private function findLastProjectForUser(string $phone): ?Project
    {
        return Project::where('requester_phone', $phone)
            ->whereNotIn('status', ['rejected'])
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Notify admin via WhatsApp about a new project request.
     */
    private function notifyAdminNewProject(Project $project): void
    {
        try {
            $adminPhone = AppSetting::get('admin_whatsapp_phone');
            if (!$adminPhone) {
                Log::warning('No admin WhatsApp phone configured');
                return;
            }

            $message = "Nouvelle demande de projet !\n\n"
                . "De: {$project->requester_name}\n"
                . "Repo: {$project->gitlab_url}\n"
                . "Demande: " . substr($project->request_description, 0, 200) . "\n\n"
                . "Connecte-toi au dashboard pour approuver ou rejeter.";

            $this->waha(10)->post("{$this->wahaBase}/api/sendText", [
                'chatId' => $adminPhone,
                'text' => $message,
                'session' => $this->sessionName,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to notify admin: " . $e->getMessage());
        }
    }

    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Download media from WAHA and return base64-encoded data.
     */
    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Exception $e) {
            Log::warning('Failed to download media: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Build Claude content blocks for a media message.
     */
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

        // Add caption or default description
        $text = $caption ?: 'Décris ce que tu vois.';
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }

    public function whatsappWebhook(Request $request, Agent $agent): JsonResponse
    {
        try {
            $payload = $request->input('payload', []);
            $body = $payload['body'] ?? null;
            $from = $payload['from'] ?? null;
            $fromMe = $payload['fromMe'] ?? false;
            $hasMedia = $payload['hasMedia'] ?? false;
            $media = $payload['media'] ?? null;
            $mediaUrl = $media['url'] ?? null;
            $mimetype = $media['mimetype'] ?? null;

            // WAHA returns localhost URLs — rewrite to internal Docker hostname
            if ($mediaUrl) {
                $mediaUrl = str_replace('http://localhost:3000', $this->wahaBase, $mediaUrl);
            }

            // Log incoming message
            AgentLog::create([
                'agent_id' => $agent->id,
                'level' => 'info',
                'message' => 'WhatsApp message received',
                'context' => $request->all(),
            ]);

            // Skip: sent by us, system messages, or no content at all
            if ($fromMe || !$from || (!$body && !$hasMedia)) {
                return response()->json(['ok' => true]);
            }

            // Create or update AgentSession
            $sessionKey = AgentSession::keyFor($agent->id, 'whatsapp', $from);
            $session = AgentSession::updateOrCreate(
                ['session_key' => $sessionKey],
                [
                    'agent_id' => $agent->id,
                    'channel' => 'whatsapp',
                    'peer_id' => $from,
                    'last_message_at' => now(),
                ]
            );
            $session->increment('message_count');

            // Get sender name
            $senderName = $payload['_data']['pushName'] ?? 'ami';

            // ── Project detection ─────────────────────────────────────────
            if ($body) {
                $gitlabData = $this->detectGitlabUrl($body);
                $isTaskReq = !$gitlabData && $this->isTaskRequest($body);

                if ($gitlabData || $isTaskReq) {
                    $gitlabUrl = null;
                    $repoName = null;
                    $description = $body;
                    $isNewRepo = false;

                    if ($gitlabData) {
                        $gitlabUrl = $gitlabData['url'];
                        $description = $gitlabData['description'];
                        $repoName = basename(parse_url($gitlabUrl, PHP_URL_PATH) ?? 'repo');
                        $repoName = str_replace('.git', '', $repoName);
                        // Check if this specific repo was already approved
                        $existingApproved = Project::where('requester_phone', $from)
                            ->where('gitlab_url', $gitlabUrl)
                            ->whereIn('status', ['approved', 'in_progress', 'completed'])
                            ->first();
                        $isNewRepo = !$existingApproved;
                    } else {
                        // Dev request without GitLab URL
                        // Priority: name match in message > allowed_phones > findLastProjectForUser()
                        $namedProject = $this->findProjectByNameInMessage($body, $from);
                        $lastProject = $namedProject
                            ?? $this->findProjectByAllowedPhone($from)
                            ?? $this->findLastProjectForUser($from);

                        if ($lastProject) {
                            $gitlabUrl = $lastProject->gitlab_url;
                            $repoName = $lastProject->name;
                            // Last project was already approved → no need to re-approve
                            $isNewRepo = !in_array($lastProject->status, ['approved', 'in_progress', 'completed']);
                        } else {
                            // No previous project → ask for GitLab URL
                            $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                                'chatId' => $from,
                                'text' => "On dirait une demande de modif !\n"
                                    . "Envoie-moi l'URL GitLab du repo sur lequel tu veux que je travaille.",
                                'session' => $this->sessionName,
                            ]);
                            return response()->json(['ok' => true, 'action' => 'asked_for_repo']);
                        }
                    }

                    if ($isNewRepo) {
                        // New repo never approved → needs admin approval
                        $project = Project::create([
                            'name' => $repoName,
                            'gitlab_url' => $gitlabUrl,
                            'request_description' => $description,
                            'requester_phone' => $from,
                            'requester_name' => $senderName,
                            'agent_id' => $agent->id,
                            'status' => 'pending',
                        ]);

                        $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                            'chatId' => $from,
                            'text' => "[{$repoName}] J'ai recu ta demande.\n"
                                . "Un admin doit d'abord approuver avant que je commence.\n"
                                . "Je te tiens au courant !",
                            'session' => $this->sessionName,
                        ]);

                        $this->notifyAdminNewProject($project);
                    } else {
                        // Repo already approved → launch directly
                        $project = Project::create([
                            'name' => $repoName,
                            'gitlab_url' => $gitlabUrl,
                            'request_description' => $description,
                            'requester_phone' => $from,
                            'requester_name' => $senderName,
                            'agent_id' => $agent->id,
                            'status' => 'approved',
                            'approved_at' => now(),
                        ]);

                        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);
                        $subAgent = SubAgent::create([
                            'project_id' => $project->id,
                            'status' => 'queued',
                            'task_description' => $description,
                            'timeout_minutes' => $defaultTimeout,
                        ]);

                        RunSubAgentJob::dispatch($subAgent);

                        $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                            'chatId' => $from,
                            'text' => "[{$repoName}] C'est parti ! Je bosse dessus.\n"
                                . "Je te tiens au courant de l'avancement.",
                            'session' => $this->sessionName,
                        ]);
                    }

                    AgentLog::create([
                        'agent_id' => $agent->id,
                        'level' => 'info',
                        'message' => 'Project request created from WhatsApp',
                        'context' => [
                            'project_id' => $project->id,
                            'gitlab_url' => $gitlabUrl,
                            'detection' => $gitlabData ? 'gitlab_url' : 'claude_classification',
                            'auto_approved' => !$isNewRepo,
                        ],
                    ]);

                    return response()->json(['ok' => true, 'project_id' => $project->id]);
                }
            }

            // Load conversation memory
            $memory = new ConversationMemoryService();
            $memoryContext = $memory->formatForPrompt($agent->id, $from);

            // Build system prompt with memory
            $systemPrompt =
                "Tu es ZeniClaw, un assistant WhatsApp cool et sympa. " .
                "Tu parles comme un ami ou un collègue décontracté. " .
                "Tu tutoies, tu es direct, drôle et bienveillant. " .
                "Tu utilises un langage naturel et détendu (pas trop formel). " .
                "Tu peux utiliser des emojis avec modération. " .
                "Réponds de manière concise (2-3 phrases max sauf si on te demande plus). " .
                "Le message vient de {$senderName}.";

            if ($memoryContext) {
                $systemPrompt .= "\n\n" . $memoryContext;
            }

            // Build message for Claude (text or multimodal)
            $claudeMessage = $body ?? '';
            $bodyForMemory = $body ?? '';

            if ($hasMedia && $mediaUrl) {
                if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES) || $mimetype === 'application/pdf') {
                    // Download and encode media for Claude
                    $base64Data = $this->downloadMedia($mediaUrl);
                    if ($base64Data) {
                        $claudeMessage = $this->buildMediaContentBlocks($mimetype, $base64Data, $body);
                        if (!$bodyForMemory) {
                            $bodyForMemory = in_array($mimetype, self::SUPPORTED_IMAGE_TYPES)
                                ? '[Image envoyée]'
                                : '[PDF envoyé]';
                        }
                    }
                } else {
                    // Unsupported media type — send descriptive text to Claude
                    $mediaDesc = match (true) {
                        str_starts_with($mimetype ?? '', 'audio/') => 'un message vocal/audio',
                        str_starts_with($mimetype ?? '', 'video/') => 'une vidéo',
                        ($mimetype ?? '') === 'image/webp' && str_contains($mediaUrl ?? '', 'sticker') => 'un sticker',
                        default => "un fichier de type {$mimetype}",
                    };
                    $claudeMessage = ($body ? "{$body}\n\n" : '') .
                        "[{$senderName} a envoyé {$mediaDesc}. Tu ne peux pas le voir/écouter, " .
                        "dis-le poliment et propose de continuer la conversation.]";
                    if (!$bodyForMemory) {
                        $bodyForMemory = "[Media: {$mimetype}]";
                    }
                }
            }

            // Generate reply with Claude
            $claude = new AnthropicClient();
            $reply = $claude->chat($claudeMessage, 'claude-haiku-4-5-20251001', $systemPrompt);

            if ($reply) {
                // Send reply via WAHA
                $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $from,
                    'text' => $reply,
                    'session' => $this->sessionName,
                ]);

                AgentLog::create([
                    'agent_id' => $agent->id,
                    'level' => 'info',
                    'message' => 'WhatsApp reply sent',
                    'context' => ['to' => $from, 'reply' => $reply],
                ]);

                // Generate summary with Haiku and append to memory
                $summary = $claude->chat(
                    "Résume cet échange en 1 phrase courte (max 20 mots).\n" .
                    "Message de {$senderName}: {$bodyForMemory}\n" .
                    "Réponse de ZeniClaw: {$reply}",
                    'claude-haiku-4-5-20251001',
                    'Tu es un assistant qui résume des échanges. Réponds uniquement avec le résumé, rien d\'autre.'
                );

                $memory->append($agent->id, $from, $senderName, $bodyForMemory, $reply, $summary ?? '');
            }

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
