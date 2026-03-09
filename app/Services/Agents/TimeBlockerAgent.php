<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Services\AgentContext;
use App\Services\TimeBlockOptimizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TimeBlockerAgent extends BaseAgent
{
    private TimeBlockOptimizer $optimizer;

    public function __construct()
    {
        parent::__construct();
        $this->optimizer = new TimeBlockOptimizer();
    }

    public function name(): string
    {
        return 'time_blocker';
    }

    public function description(): string
    {
        return 'Optimisation intelligente de la journee par blocs de temps. Analyse taches et projets, propose des blocs focus, pauses et reunions avec justifications IA.';
    }

    public function keywords(): array
    {
        return [
            'bloque ma journee', 'organise mon temps', 'optimise mon agenda',
            'comment je gere mon temps', 'time blocking', 'blocs de temps',
            'planifie ma journee', 'organise ma journee', 'emploi du temps',
            'schedule', 'planning optimal', 'bloc focus', 'deep work',
            'creneaux', 'optimiser planning', 'gestion du temps',
            'journee productive', 'organiser demain', 'plan de journee',
            'time management', 'focus blocks', 'pause', 'energie',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Gather user tasks and projects via TodoAgent + ProjectAgent data
        $taskData = $this->optimizer->gatherUserData($context);

        // Detect specific sub-commands
        if (preg_match('/\b(appliquer?|accepter?|valider?|apply)\s+(le\s+)?(bloc|block|planning)/iu', $lower)) {
            return $this->applyBlocks($context, $body);
        }

        if (preg_match('/\b(modifier?|decaler?|ajuster?|shift|move)\s+(le\s+)?(bloc|block)/iu', $lower)) {
            return $this->modifyBlock($context, $body, $taskData);
        }

        if (preg_match('/\b(demain|tomorrow)\b/iu', $lower)) {
            return $this->generatePlan($context, $body, $taskData, 'tomorrow');
        }

        // Default: generate optimized day plan
        return $this->generatePlan($context, $body, $taskData, 'today');
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        if ($type === 'awaiting_plan_action') {
            // User is responding to a generated plan
            if (preg_match('/\b(appliquer?|accepter?|valider?|ok|go|oui|yes|apply)\b/iu', $lower)) {
                $this->clearPendingContext($context);
                return $this->applyBlocks($context, $body);
            }

            if (preg_match('/\b(modifier?|decaler?|ajuster?|changer?|shift|move)\b/iu', $lower)) {
                $this->clearPendingContext($context);
                $taskData = $this->optimizer->gatherUserData($context);
                return $this->modifyBlock($context, $body, $taskData);
            }

            if (preg_match('/\b(non|annuler?|cancel|stop)\b/iu', $lower)) {
                $this->clearPendingContext($context);
                Redis::del("time_plan:{$context->from}");
                $reply = "Planning annule. N'hesite pas a me redemander quand tu veux organiser ta journee !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }

            // Unrecognized response - re-prompt
            $reply = "Que souhaites-tu faire ?\n\n"
                . "1. *Appliquer* - Activer les rappels pour chaque bloc\n"
                . "2. *Modifier* - Ajuster le planning\n"
                . "3. *Annuler* - Abandonner ce planning";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        return null;
    }

    private function generatePlan(AgentContext $context, string $query, array $taskData, string $day): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';

        $now = now()->setTimezone($timezone);
        $dayLabel = $day === 'tomorrow' ? $now->copy()->addDay()->format('l d/m/Y') : $now->format('l d/m/Y');
        $currentHour = $day === 'today' ? $now->format('H:i') : '08:00';

        // Use TimeBlockOptimizer to analyze urgency and energy patterns
        $scoredTasks = $this->optimizer->analyzeTaskUrgency($taskData['todos'] ?? []);
        $energyDips = $this->optimizer->estimateEnergyDips();
        $breakSuggestions = $this->optimizer->suggestBreakTiming($currentHour);

        $taskSummary = $this->formatTaskSummary($taskData);
        $energySummary = $this->formatEnergySummary($energyDips);
        $urgencySummary = $this->formatUrgencySummary($scoredTasks);

        $systemPrompt = <<<PROMPT
Tu es un expert en productivite et gestion du temps. Tu crees des plannings optimises par blocs de temps.

DONNEES UTILISATEUR:
{$taskSummary}

ANALYSE D'URGENCE (scores calcules):
{$urgencySummary}

COURBE D'ENERGIE JOURNALIERE:
{$energySummary}

REGLES:
1. Analyse les taches/projets de l'utilisateur et propose un planning par blocs de temps pour {$dayLabel}
2. Commence a partir de {$currentHour}
3. Structure les blocs ainsi:
   - Blocs focus (1h30-2h) pour les taches importantes/complexes
   - Pauses courtes (15-30min) entre les blocs
   - Blocs admin/emails (30min-1h) pour les taches legeres
   - Pause dejeuner (1h)
4. Priorise selon:
   - Urgence (deadlines proches) - utilise les scores d'urgence fournis
   - Importance (impact projet)
   - Energie: taches complexes le matin (pic 08h-11h), taches legeres apres-midi (creux 14h-15h)
5. Pour chaque bloc, indique:
   - Heure debut - fin
   - Emoji de type (focus, pause, reunion, admin)
   - Tache(s) a realiser
   - Justification courte
6. Integre les creux d'energie dans la planification
7. Formate pour WhatsApp avec emojis et formatage riche:
   - Utilise *gras* pour les titres et heures
   - Utilise _italique_ pour les justifications
   - Emojis: focus=🎯, pause=☕, reunion=📞, admin=📧, dejeuner=🍽️, sport=💪
8. Termine par:
   a) Resume: nombre de blocs focus, temps total productif, taches couvertes
   b) Actions interactives claires
9. Langue: {$lang}

FORMAT OBLIGATOIRE:
📅 *Planning {$dayLabel}*
━━━━━━━━━━━━━━━━━━━━

🎯 *08:00 - 10:00* | *Focus: [Tache]*
   _→ Justification basee sur urgence/energie_

☕ *10:00 - 10:15* | *Pause*
   _→ Recuperation avant prochain bloc_

📧 *10:15 - 11:00* | *Admin: Emails & messages*
   _→ Traiter les urgences_

...

━━━━━━━━━━━━━━━━━━━━
📊 *Resume:* X blocs focus | Xh productives | X taches planifiees

⚡ *Courbe d'energie:*
🌅 Matin: pic d'energie → taches complexes
😴 Apres-midi: creux → taches legeres
💨 Fin de journee: second souffle

━━━━━━━━━━━━━━━━━━━━
💡 *Actions:*
✅ Tape *"appliquer planning"* pour activer les rappels
✏️ Tape *"modifier bloc [detail]"* pour ajuster
🔄 Tape *"decaler bloc [heure]"* pour decaler
❌ Tape *"annuler"* pour abandonner

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Desole, je n'ai pas pu generer ton planning optimise. Reessaie en me decrivant tes taches du jour !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Store the generated plan in Redis for potential apply later
        $this->cachePlan($context->from, $response, $day);

        // Set pending context so follow-up messages are routed back here
        $this->setPendingContext($context, 'awaiting_plan_action', ['day' => $day], 30);

        $this->sendText($context->from, $response);
        $this->log($context, 'Time block plan generated', [
            'day' => $day,
            'tasks_count' => count($taskData['todos'] ?? []),
            'reminders_count' => count($taskData['reminders'] ?? []),
            'projects_count' => count($taskData['projects'] ?? []),
        ]);

        return AgentResult::reply($response, ['action' => 'generate_plan', 'day' => $day]);
    }

    private function applyBlocks(AgentContext $context, string $query): AgentResult
    {
        $cachedPlan = $this->getCachedPlan($context->from);

        if (!$cachedPlan) {
            $reply = "Aucun planning en attente.\n\n"
                . "Demande-moi d'abord d'organiser ta journee :\n"
                . "💡 _\"Organise ma journee\"_\n"
                . "💡 _\"Bloque ma journee\"_\n"
                . "💡 _\"Planifie demain\"_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Extract time blocks from the cached plan using Claude
        $extractPrompt = <<<PROMPT
Extrais les blocs de temps du planning suivant. Retourne un JSON array avec chaque bloc:
[{"start": "HH:MM", "end": "HH:MM", "type": "focus|pause|reunion|admin|dejeuner|sport", "label": "description courte"}]

Planning:
{$cachedPlan}

Reponds UNIQUEMENT avec le JSON array, pas de texte autour.
PROMPT;

        $blocksJson = $this->claude->chat($extractPrompt, 'claude-haiku-4-5-20251001', 'Tu extrais des donnees structurees. Reponds uniquement en JSON valide.');

        // Clean JSON response
        $blocksJson = $this->extractJson($blocksJson);
        $blocks = json_decode($blocksJson ?? '[]', true);

        if (empty($blocks) || !is_array($blocks)) {
            $reply = "Je n'ai pas pu extraire les blocs du planning. Regenere ton planning et reessaie.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Validate block format
        $validBlocks = $this->validateBlocks($blocks);
        if (empty($validBlocks)) {
            $reply = "Les blocs extraits ne sont pas valides. Regenere ton planning.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Store blocks in Redis with expiration (end of day)
        $prefs = $this->getUserPrefs($context);
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';
        $endOfDay = now()->setTimezone($timezone)->endOfDay();
        $ttl = max($endOfDay->diffInSeconds(now()), 60);

        $redisKey = "time_blocks:{$context->from}";
        Redis::setex($redisKey, $ttl, json_encode($validBlocks));

        // Create reminders for block transitions (sync with ReminderAgent)
        $createdReminders = $this->createBlockReminders($context, $validBlocks, $timezone);

        $blockCount = count($validBlocks);
        $reply = "✅ *Planning applique !*\n\n"
            . "📋 {$blockCount} blocs programmes\n"
            . "🔔 {$createdReminders} rappels crees\n\n"
            . "Tu recevras une notification au debut de chaque bloc focus, reunion et admin.\n\n"
            . "💡 _Tape \"modifier bloc [detail]\" pour ajuster a tout moment_\n\n"
            . "Bonne journee productive ! 💪";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Time blocks applied', ['blocks' => $blockCount, 'reminders' => $createdReminders]);

        // Clear cached plan
        Redis::del("time_plan:{$context->from}");

        return AgentResult::reply($reply, ['action' => 'apply_blocks', 'blocks' => $blockCount, 'reminders' => $createdReminders]);
    }

    private function modifyBlock(AgentContext $context, string $query, array $taskData): AgentResult
    {
        $cachedPlan = $this->getCachedPlan($context->from);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $planContext = $cachedPlan ? "\nPLANNING ACTUEL:\n{$cachedPlan}" : "\nAucun planning en cours.";

        $systemPrompt = <<<PROMPT
Tu es un expert en gestion du temps. L'utilisateur veut modifier son planning actuel.
{$planContext}

REGLES:
1. Comprends la modification demandee (decaler, allonger, raccourcir, supprimer, ajouter un bloc)
2. Propose le planning modifie complet avec le meme format WhatsApp riche
3. Explique les changements apportes
4. Garde le format avec emojis: 🎯 focus, ☕ pause, 📞 reunion, 📧 admin, 🍽️ dejeuner, 💪 sport
5. Utilise *gras* et _italique_ pour le formatage WhatsApp
6. Termine par les memes actions interactives:
   ✅ "appliquer planning" | ✏️ "modifier bloc" | ❌ "annuler"
7. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Desole, je n'ai pas pu modifier le planning. Reessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Update cached plan
        $this->cachePlan($context->from, $response, 'today');

        // Keep pending context for follow-up
        $this->setPendingContext($context, 'awaiting_plan_action', ['day' => 'today'], 30);

        $this->sendText($context->from, $response);
        $this->log($context, 'Time block modified', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'modify_block']);
    }

    private function createBlockReminders(AgentContext $context, array $blocks, string $timezone): int
    {
        $createdReminders = 0;

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if (!in_array($type, ['focus', 'reunion', 'admin'])) {
                continue;
            }

            try {
                $startTime = now()->setTimezone($timezone)->setTimeFromTimeString($block['start']);
                if (!$startTime->isFuture()) {
                    continue;
                }

                $emoji = match ($type) {
                    'focus' => '🎯',
                    'reunion' => '📞',
                    'admin' => '📧',
                    default => '⏰',
                };

                Reminder::create([
                    'requester_phone' => $context->from,
                    'agent_id' => $context->agent->id,
                    'message' => "{$emoji} Bloc: {$block['label']} ({$block['start']} - {$block['end']})",
                    'scheduled_at' => $startTime->setTimezone('UTC'),
                    'status' => 'pending',
                ]);
                $createdReminders++;
            } catch (\Throwable $e) {
                Log::warning("TimeBlocker: failed to create reminder for block", [
                    'block' => $block,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $createdReminders;
    }

    private function validateBlocks(array $blocks): array
    {
        $valid = [];
        $allowedTypes = ['focus', 'pause', 'reunion', 'admin', 'dejeuner', 'sport'];

        foreach ($blocks as $block) {
            if (!is_array($block)) continue;

            $start = $block['start'] ?? null;
            $end = $block['end'] ?? null;
            $type = $block['type'] ?? null;
            $label = $block['label'] ?? null;

            // Validate time format HH:MM
            if (!$start || !$end || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                continue;
            }

            // Validate type
            if (!$type || !in_array($type, $allowedTypes)) {
                continue;
            }

            // Validate label
            if (!$label || !is_string($label) || mb_strlen($label) > 255) {
                continue;
            }

            $valid[] = [
                'start' => $start,
                'end' => $end,
                'type' => $type,
                'label' => mb_substr($label, 0, 255),
            ];
        }

        return $valid;
    }

    private function extractJson(?string $text): ?string
    {
        if (!$text) return null;
        $clean = trim($text);

        // Strip markdown code blocks
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            return trim($m[1]);
        }

        // Find JSON array
        if (preg_match('/(\[.*\])/s', $clean, $m)) {
            return $m[1];
        }

        return $clean;
    }

    private function formatTaskSummary(array $taskData): string
    {
        $parts = [];

        if (!empty($taskData['todos'])) {
            $lines = ['TACHES A FAIRE:'];
            foreach ($taskData['todos'] as $todo) {
                $priority = $todo['priority'] ?? 'normal';
                $lines[] = "  - [{$priority}] {$todo['title']}";
            }
            $parts[] = implode("\n", $lines);
        }

        if (!empty($taskData['reminders'])) {
            $lines = ['RAPPELS DU JOUR:'];
            foreach ($taskData['reminders'] as $reminder) {
                $lines[] = "  - {$reminder['time']} : {$reminder['message']}";
            }
            $parts[] = implode("\n", $lines);
        }

        if (!empty($taskData['projects'])) {
            $lines = ['PROJETS ACTIFS:'];
            foreach ($taskData['projects'] as $project) {
                $active = ($project['is_active'] ?? false) ? ' ← ACTIF' : '';
                $lines[] = "  - {$project['name']} ({$project['status']}){$active}";
            }
            $parts[] = implode("\n", $lines);
        }

        if (empty($parts)) {
            return "Aucune tache ou projet detecte. L'utilisateur doit decrire ses taches dans son message.";
        }

        return implode("\n\n", $parts);
    }

    private function formatEnergySummary(array $energyDips): string
    {
        $lines = [];
        foreach ($energyDips as $dip) {
            $lines[] = "{$dip['emoji']} {$dip['time']} ({$dip['level']}): {$dip['advice']}";
        }
        return implode("\n", $lines);
    }

    private function formatUrgencySummary(array $scoredTasks): string
    {
        if (empty($scoredTasks)) {
            return "Aucune tache scoree.";
        }

        $lines = [];
        foreach ($scoredTasks as $task) {
            $score = $task['urgency_score'] ?? 50;
            $indicator = $score >= 80 ? '🔴' : ($score >= 60 ? '🟡' : '🟢');
            $lines[] = "{$indicator} Score {$score}/100: {$task['title']}";
        }
        return implode("\n", $lines);
    }

    private function cachePlan(string $userId, string $plan, string $day): void
    {
        try {
            Redis::setex("time_plan:{$userId}", 14400, json_encode([
                'plan' => $plan,
                'day' => $day,
                'created_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning("TimeBlocker: failed to cache plan", ['error' => $e->getMessage()]);
        }
    }

    private function getCachedPlan(string $userId): ?string
    {
        try {
            $data = Redis::get("time_plan:{$userId}");
            if ($data) {
                $decoded = json_decode($data, true);
                return $decoded['plan'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning("TimeBlocker: failed to get cached plan", ['error' => $e->getMessage()]);
        }
        return null;
    }
}
