<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReminderAgent extends BaseAgent
{
    public function name(): string
    {
        return 'reminder';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'reminder';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $now = now('Europe/Paris')->format('Y-m-d H:i');
        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"",
            'claude-haiku-4-5-20251001',
            "Extrais les informations d'un rappel a partir du message.\n"
            . "Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:\n"
            . "{\"message\": \"le texte du rappel\", \"scheduled_at\": \"YYYY-MM-DD HH:MM\", \"recurrence\": \"weekly:thursday:09:00\" | null}\n"
            . "- 'message' = reformule le rappel de maniere claire et courte (ex: 'Appeler Jean')\n"
            . "- 'scheduled_at' = la date/heure de la PROCHAINE occurrence au format YYYY-MM-DD HH:MM\n"
            . "- 'recurrence' = regle de recurrence si le rappel est periodique, sinon null\n"
            . "  Formats: \"daily:HH:MM\", \"weekly:DAYNAME:HH:MM\" (monday,tuesday,...,sunday), \"monthly:DAY:HH:MM\"\n"
            . "  Exemples: \"chaque lundi a 8h\" → \"weekly:monday:08:00\", \"tous les jours a 7h\" → \"daily:07:00\"\n"
            . "- Si l'heure est mentionnee sans date, utilise la date du jour\n"
            . "- Si 'demain' est mentionne, utilise la date de demain\n"
            . "- Si 'dans X minutes/heures', calcule a partir de la date actuelle\n"
            . "Reponds UNIQUEMENT avec le JSON."
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['message']) || empty($parsed['scheduled_at'])) {
            $reply = "J'ai pas bien compris ton rappel. Essaie un truc comme:\n"
                . "\"Rappelle-moi d'appeler Jean demain a 10h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_parse_failed']);
        }

        try {
            $scheduledAt = Carbon::parse($parsed['scheduled_at'], 'Europe/Paris')->utc();
        } catch (\Exception $e) {
            $reply = "J'ai pas reussi a comprendre la date/heure. Precise un peu plus ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_date_failed']);
        }

        $reminder = Reminder::create([
            'agent_id' => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'message' => $parsed['message'],
            'channel' => 'whatsapp',
            'scheduled_at' => $scheduledAt,
            'recurrence_rule' => $parsed['recurrence'] ?? null,
            'status' => 'pending',
        ]);

        $parisTime = $scheduledAt->copy()->setTimezone('Europe/Paris');
        $recurrenceText = '';
        if (!empty($parsed['recurrence'])) {
            $recurrenceText = "\nRecurrence : {$parsed['recurrence']}";
        }
        $reply = "Rappel cree !\n"
            . "{$parsed['message']}\n"
            . "Le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}"
            . $recurrenceText;

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder created', [
            'reminder_id' => $reminder->id,
            'message' => $parsed['message'],
            'scheduled_at' => $scheduledAt->toISOString(),
        ]);

        return AgentResult::reply($reply, ['reminder_id' => $reminder->id]);
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) return null;

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        Log::info("Reminder parse - cleaned: {$clean}");

        return json_decode($clean, true);
    }
}
