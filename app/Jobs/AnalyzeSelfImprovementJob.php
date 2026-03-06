<?php

namespace App\Jobs;

use App\Models\SelfImprovement;
use App\Services\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyzeSelfImprovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $agentId,
        private string $from,
        private string $body,
        private string $response,
        private string $routedAgent,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        try {
            $claude = new AnthropicClient();

            $prompt = "Agent: {$this->routedAgent}\n"
                . "Message utilisateur: {$this->body}\n"
                . "Reponse de ZeniClaw: {$this->response}";

            $system = "Tu es un expert en amelioration de systemes IA conversationnels.\n"
                . "Analyse cet echange entre un utilisateur et ZeniClaw (un agent IA WhatsApp multi-agents: chat, dev, reminder, project, analysis, todo).\n\n"
                . "Questions:\n"
                . "1. La reponse etait-elle optimale ? (qualite, pertinence, ton)\n"
                . "2. Y a-t-il un probleme SYSTEME (code/architecture/prompt) — pas un cas isole ?\n"
                . "3. L'utilisateur a-t-il demande quelque chose que ZeniClaw ne peut PAS faire actuellement ?\n\n"
                . "CAS IMPORTANTS A DETECTER:\n"
                . "- Si l'utilisateur demande une fonctionnalite que ZeniClaw ne supporte pas encore (ex: generer un audio, envoyer un email, "
                . "chercher sur le web, generer une image, etc.), propose un plan pour l'ajouter.\n"
                . "- Le plan doit decrire: creer un nouveau sub-agent OU ameliorer un agent existant.\n"
                . "- Structure du projet: app/Services/Agents/ contient les agents (ChatAgent, DevAgent, TodoAgent, ReminderAgent, etc.). "
                . "Chaque agent herite de BaseAgent et implemente handle(). Le RouterAgent dans RouterAgent.php route les messages.\n\n"
                . "Si tu identifies une amelioration systeme concrete et actionnable:\n"
                . "Reponds UNIQUEMENT avec du JSON valide:\n"
                . "{\"improve\": true, \"new_capability\": true/false, \"title\": \"titre court\", \"analysis\": \"explication\", \"plan\": \"etapes\"}\n\n"
                . "IMPORTANT sur new_capability:\n"
                . "- true = l'utilisateur demande quelque chose de COMPLETEMENT NOUVEAU que ZeniClaw ne peut pas faire du tout (ex: generer une image, envoyer un email)\n"
                . "- false = l'agent a ESSAYE de repondre mais a mal fait (bug, erreur API, mauvaise analyse, info incomplete). C'est une amelioration technique, pas une nouvelle fonctionnalite.\n"
                . "- La plupart des cas sont false. N'utilise true QUE si c'est vraiment une capacite totalement absente.\n\n"
                . "Si la reponse etait correcte et aucune amelioration n'est necessaire:\n"
                . "{\"improve\": false}\n\n"
                . "IMPORTANT: Reponds UNIQUEMENT avec du JSON valide, rien d'autre.";

            $result = $claude->chat($prompt, 'claude-haiku-4-5-20251001', $system);

            if (!$result) {
                Log::warning('AnalyzeSelfImprovementJob: empty Claude response');
                return;
            }

            // Extract JSON from response (handle markdown code blocks)
            $jsonStr = $result;
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $result, $matches)) {
                $jsonStr = $matches[1];
            }

            $data = json_decode(trim($jsonStr), true);

            if (!$data || !is_array($data)) {
                Log::warning('AnalyzeSelfImprovementJob: invalid JSON', ['raw' => $result]);
                return;
            }

            if (empty($data['improve'])) {
                return;
            }

            // Check if the agent actually tried to handle the request (vs completely failing)
            // Only notify for genuinely NEW capabilities, not for poor execution of existing ones
            $isNewCapability = $data['new_capability'] ?? false;

            $improvement = SelfImprovement::create([
                'agent_id' => $this->agentId,
                'trigger_message' => $this->body,
                'agent_response' => $this->response,
                'routed_agent' => $this->routedAgent,
                'analysis' => $data,
                'improvement_title' => $data['title'] ?? 'Amelioration sans titre',
                'development_plan' => $data['plan'] ?? '',
                'status' => 'pending',
            ]);

            Log::info('SelfImprovement created', ['title' => $data['title'] ?? '', 'new_capability' => $isNewCapability]);

            // Only notify user for genuinely new capabilities they requested
            // Do NOT notify when the agent tried but did a poor job — that's a bug fix, not a feature request
            if ($isNewCapability) {
                $this->notifyUser(
                    "Hey, bonne idee ! Je ne sais pas encore faire ca, mais ta suggestion a ete transmise a l'admin pour validation. "
                    . "Si c'est approuve, je serai bientot capable de le faire !"
                );
            }
        } catch (\Exception $e) {
            Log::error('AnalyzeSelfImprovementJob failed: ' . $e->getMessage());
        }
    }

    private function notifyUser(string $text): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $this->from,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Exception $e) {
            Log::warning('Failed to notify user about improvement: ' . $e->getMessage());
        }
    }
}
