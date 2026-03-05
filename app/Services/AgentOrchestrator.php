<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\Project;
use App\Services\Agents\AgentInterface;
use App\Services\Agents\AgentResult;
use App\Services\Agents\AnalysisAgent;
use App\Services\Agents\ChatAgent;
use App\Services\Agents\DevAgent;
use App\Services\Agents\ProjectAgent;
use App\Services\Agents\ReminderAgent;
use App\Services\Agents\RouterAgent;
use App\Services\Agents\TodoAgent;
use App\Services\Agents\MusicAgent;
use App\Services\Agents\MoodCheckAgent;
use App\Services\Agents\FinanceAgent;
use App\Services\Agents\SmartMeetingAgent;
use App\Services\Agents\HangmanGameAgent;
use App\Services\Agents\FlashcardAgent;
use App\Services\Agents\SmartContextAgent;
use App\Services\Agents\VoiceCommandAgent;
use App\Services\Agents\CodeReviewAgent;
use App\Services\Agents\ScreenshotAgent;
use App\Services\Agents\ContentSummarizerAgent;
use App\Services\Agents\EventReminderAgent;
use App\Services\Agents\HabitAgent;
use App\Jobs\AnalyzeSelfImprovementJob;
use Illuminate\Support\Facades\Log;

/**
 * AgentOrchestrator v2 — Agentic architecture
 *
 * The ChatAgent now has access to all tools (reminders, todos, projects, music)
 * via the AgenticLoop. Most messages go through ChatAgent which autonomously
 * decides which tools to use. Only DevAgent (code tasks) bypasses the agentic
 * loop since it needs SubAgent execution.
 */

class AgentOrchestrator
{
    private RouterAgent $router;
    private ConversationMemoryService $memory;
    private array $agents = [];
    private int $maxHandoffs = 3;

    public function __construct()
    {
        $this->router = new RouterAgent();
        $this->memory = new ConversationMemoryService();

        $this->registerAgents();
    }

    private function registerAgents(): void
    {
        $agentClasses = [
            new ChatAgent(),
            new DevAgent(),
            new ReminderAgent(),
            new ProjectAgent(),
            new AnalysisAgent(),
            new TodoAgent(),
            new MusicAgent(),
            new MoodCheckAgent(),
            new FinanceAgent(),
            new SmartMeetingAgent(),
            new HangmanGameAgent(),
            new FlashcardAgent(),
            new SmartContextAgent(),
            new VoiceCommandAgent(),
            new CodeReviewAgent(),
            new ScreenshotAgent(),
            new ContentSummarizerAgent(),
            new EventReminderAgent(),
            new HabitAgent(),
        ];

        foreach ($agentClasses as $agent) {
            $this->agents[$agent->name()] = $agent;
        }
    }

    public function process(AgentContext $context): AgentResult
    {
        try {
            $debug = file_exists(storage_path('app/orchestrator_debug'));

            // 1. Handle pending stateful flows
            $pendingResult = $this->handlePendingStates($context, $debug);
            if ($pendingResult) {
                return $pendingResult;
            }

            // 2. Route the message
            $routing = $this->router->route($context);

            if ($debug) {
                $this->sendDebug($context,
                    "[DEBUG ROUTER]\n"
                    . "Message: " . mb_substr($context->body ?? '', 0, 80) . "\n"
                    . "Agent: {$routing['agent']}\n"
                    . "Model: {$routing['model']}\n"
                    . "Complexity: {$routing['complexity']}\n"
                    . "Autonomy: {$routing['autonomy']}\n"
                    . "Reasoning: {$routing['reasoning']}"
                );
            }

            // Log routing decision
            AgentLog::create([
                'agent_id' => $context->agent->id,
                'level' => 'info',
                'message' => 'Router decision',
                'context' => [
                    'from' => $context->from,
                    'body' => mb_substr($context->body ?? '', 0, 100),
                    'routing' => $routing,
                ],
            ]);

            // Enrich context with routing info
            $routedContext = $context->withRouting(
                $routing['agent'],
                $routing['model'],
                $routing['complexity'],
                $routing['reasoning'],
                $routing['autonomy'] ?? 'confirm'
            );

            // 3. Dispatch to agent — agentic architecture
            // VoiceCommandAgent transcribes audio, then we re-route the text.
            // DevAgent handles code tasks via SubAgent.
            // Everything else goes through ChatAgent's agentic loop (with tools).
            $dispatchAgent = $routing['agent'];

            if ($dispatchAgent === 'voice_command') {
                // Voice: transcribe first, then re-route the transcribed text
                $voiceAgent = $this->agents['voice_command'];
                $voiceResult = $voiceAgent->handle($routedContext);

                if ($debug) {
                    $this->sendDebug($context, "[DEBUG VOICE] Transcription: " . mb_substr($voiceResult->reply ?? '', 0, 100));
                }

                // If transcription succeeded and confidence is good, re-route the text
                $isLowConfidence = $voiceResult->metadata['low_confidence'] ?? false;
                if ($voiceResult->action === 'reply' && $voiceResult->reply && !$isLowConfidence) {
                    $transcript = $voiceResult->reply;

                    // Create a new context with the transcribed text replacing the body
                    $textContext = new AgentContext(
                        agent: $context->agent,
                        session: $context->session,
                        from: $context->from,
                        senderName: $context->senderName,
                        body: $transcript,
                        hasMedia: false,
                        mediaUrl: null,
                        mimetype: null,
                        media: null,
                    );

                    // Re-route the transcribed text
                    $newRouting = $this->router->route($textContext);

                    if ($debug) {
                        $this->sendDebug($context, "[DEBUG VOICE RE-ROUTE] → {$newRouting['agent']} (from transcript)");
                    }

                    $routedContext = $textContext->withRouting(
                        $newRouting['agent'],
                        $newRouting['model'],
                        $newRouting['complexity'],
                        'voice_command → ' . $newRouting['reasoning'],
                        $newRouting['autonomy'] ?? 'confirm'
                    );

                    $dispatchAgent = $newRouting['agent'];
                    if (!in_array($dispatchAgent, ['dev'])) {
                        $dispatchAgent = 'chat';
                    }
                } else {
                    // Low confidence or failed — voice agent already replied to user
                    return $voiceResult;
                }
            } elseif (!in_array($dispatchAgent, ['dev'])) {
                $dispatchAgent = 'chat';
            }

            if ($debug) {
                $this->sendDebug($context, "[DEBUG DISPATCH] → {$dispatchAgent} agent" . ($dispatchAgent !== ($routing['agent'] ?? '') ? " (agentic, routed from {$routing['agent']})" : ''));
            }

            $result = $this->dispatch($routedContext, $dispatchAgent);

            // 4. Save memory for reply actions
            if ($result->action === 'reply' && $result->reply) {
                $this->saveMemory($context, $result->reply);

                // 5. Dispatch self-improvement analysis in background
                if ($context->body) {
                    AnalyzeSelfImprovementJob::dispatch(
                        $context->agent->id,
                        $context->from,
                        $context->body,
                        $result->reply,
                        $routing['agent']
                    )->onQueue('low');
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('AgentOrchestrator error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return AgentResult::reply('Désolé, j\'ai eu un souci technique. Réessaie dans un instant.');
        }
    }

    private function handlePendingStates(AgentContext $context, bool $debug = false): ?AgentResult
    {
        if (!$context->body) return null;

        // Pending project switch confirmation
        if ($context->session->pending_switch_project_id) {
            $isNew = $this->isNewIntent($context->body);

            if ($debug) {
                $this->sendDebug($context,
                    "[DEBUG PENDING] pending_switch_project_id={$context->session->pending_switch_project_id}\n"
                    . "isNewIntent=" . ($isNew ? 'TRUE' : 'FALSE') . "\n"
                    . "Action: " . ($isNew ? 'CLEAR pending, route normally' : 'handle as switch confirmation')
                );
            }

            if ($isNew) {
                $context->session->update(['pending_switch_project_id' => null]);
                return null;
            }

            $projectAgent = $this->agents['project'] ?? null;
            if ($projectAgent) {
                return $projectAgent->handle($context);
            }
        }

        // Task awaiting validation
        $awaitingProject = Project::where('status', 'awaiting_validation')
            ->where('requester_phone', $context->from)
            ->orderByDesc('created_at')
            ->first();

        if ($awaitingProject) {
            $isNew = $this->isNewIntent($context->body);

            if ($debug) {
                $this->sendDebug($context,
                    "[DEBUG PENDING] awaiting_validation project={$awaitingProject->name} (id={$awaitingProject->id})\n"
                    . "isNewIntent=" . ($isNew ? 'TRUE' : 'FALSE') . "\n"
                    . "Action: " . ($isNew ? 'CANCEL awaiting, route normally' : 'handle as task validation')
                );
            }

            if ($isNew) {
                $awaitingProject->update(['status' => 'rejected']);

                AgentLog::create([
                    'agent_id' => $context->agent->id,
                    'level' => 'info',
                    'message' => 'Awaiting validation auto-cancelled — new intent detected',
                    'context' => [
                        'project_id' => $awaitingProject->id,
                        'new_message' => mb_substr($context->body, 0, 100),
                    ],
                ]);

                return null;
            }

            $devAgent = $this->agents['dev'] ?? null;
            if ($devAgent) {
                return $devAgent->handle($context);
            }
        }

        if ($debug && !$context->session->pending_switch_project_id && !$awaitingProject) {
            $this->sendDebug($context, "[DEBUG PENDING] No pending states found → routing normally");
        }

        return null;
    }

    /**
     * Detect if a message is a new intent rather than a response to a pending state.
     * Short confirmations (oui, non, ok, go, annule) are NOT new intents.
     * Longer messages or messages mentioning projects/tasks are new intents.
     */
    private function isNewIntent(string $body): bool
    {
        $clean = mb_strtolower(trim($body));

        // Short confirmation/rejection words → NOT a new intent
        $confirmWords = ['oui', 'non', 'ok', 'go', 'yes', 'no', 'annule', 'stop',
            'c\'est bon', 'parfait', 'lance', 'envoie', 'top', 'nickel', 'yep',
            'ouais', 'nope', 'cancel', 'let\'s go'];

        foreach ($confirmWords as $word) {
            if ($clean === $word) return false;
        }

        // Very short messages (< 15 chars) are likely confirmations
        if (mb_strlen($clean) < 15) return false;

        // Use Haiku to detect if this is a new topic
        $claude = new AnthropicClient();
        $response = $claude->chat(
            "Message: \"{$body}\"",
            'claude-haiku-4-5-20251001',
            "L'utilisateur etait en train de confirmer/modifier une tache precedente.\n"
            . "Determine si ce nouveau message est:\n"
            . "- CONTINUATION = une reponse a la tache en cours (confirmation, modification, precision, annulation)\n"
            . "- NEW_INTENT = un sujet completement different (changer de projet, nouvelle demande, autre question)\n\n"
            . "Indices de NEW_INTENT: mentionne un autre projet, demande de switcher, nouvelle tache sans rapport, "
            . "mots comme 'je veux bosser sur', 'on passe sur', 'switch', 'autre projet', 'plutot'\n\n"
            . "Reponds UNIQUEMENT par CONTINUATION ou NEW_INTENT."
        );

        $result = strtoupper(trim($response ?? ''));

        Log::info('isNewIntent check', [
            'body' => mb_substr($body, 0, 100),
            'haiku_response' => $response,
            'result' => str_contains($result, 'NEW_INTENT') ? 'NEW_INTENT' : 'CONTINUATION',
        ]);

        return str_contains($result, 'NEW_INTENT');
    }

    private function dispatch(AgentContext $context, string $agentName, int $depth = 0): AgentResult
    {
        if ($depth >= $this->maxHandoffs) {
            Log::warning('AgentOrchestrator: max handoff depth reached', ['agent' => $agentName]);
            $agentName = 'chat'; // Fallback to chat
        }

        $agent = $this->agents[$agentName] ?? $this->agents['chat'];
        $result = $agent->handle($context);

        // Handle handoff
        if ($result->action === 'handoff' && $result->handoffTo) {
            AgentLog::create([
                'agent_id' => $context->agent->id,
                'level' => 'info',
                'message' => "Handoff: {$agentName} → {$result->handoffTo}",
                'context' => $result->metadata,
            ]);

            return $this->dispatch($context, $result->handoffTo, $depth + 1);
        }

        return $result;
    }

    private function sendDebug(AgentContext $context, string $text): void
    {
        try {
            \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $context->from,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Exception $e) {
            Log::warning('Debug message failed: ' . $e->getMessage());
        }
    }

    private function saveMemory(AgentContext $context, string $reply): void
    {
        $bodyForMemory = $context->body ?? '';

        if (!$bodyForMemory && $context->hasMedia) {
            $bodyForMemory = $context->mimetype === 'application/pdf'
                ? '[PDF envoyé]'
                : '[Image envoyée]';
        }

        // Generate summary
        $summary = $this->memory->formatForPrompt($context->agent->id, $context->from)
            ? (new AnthropicClient())->chat(
                "Résume cet échange en 1 phrase courte (max 20 mots).\n"
                . "Message de {$context->senderName}: {$bodyForMemory}\n"
                . "Réponse de ZeniClaw: {$reply}",
                'claude-haiku-4-5-20251001',
                'Tu es un assistant qui résume des échanges. Réponds uniquement avec le résumé, rien d\'autre.'
            )
            : '';

        $this->memory->append(
            $context->agent->id,
            $context->from,
            $context->senderName,
            $bodyForMemory,
            $reply,
            $summary ?? ''
        );
    }
}
