<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Services\AgentContext;
use App\Services\ModelResolver;
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
        return 'Optimisation intelligente de la journee par blocs de temps. Analyse taches et projets, propose des blocs focus, pauses et reunions avec justifications IA. Affiche le planning actif, suggere le prochain focus, planifie la semaine.';
    }

    public function keywords(): array
    {
        return [
            // Génération de planning
            'bloque ma journee', 'organise mon temps', 'optimise mon agenda',
            'comment je gere mon temps', 'time blocking', 'blocs de temps',
            'planifie ma journee', 'organise ma journee', 'emploi du temps',
            'schedule', 'planning optimal', 'bloc focus', 'deep work',
            'creneaux', 'optimiser planning', 'gestion du temps',
            'journee productive', 'organiser demain', 'plan de journee',
            'time management', 'focus blocks', 'energie',
            // Consulter le planning actif
            'voir mon planning', 'mon planning', 'consulter planning',
            'afficher planning', 'voir agenda', 'mon agenda', 'agenda du jour',
            'quel est mon planning', 'montre mon planning', 'show planning',
            // Focus immédiat
            'que faire maintenant', 'quoi faire maintenant', 'sur quoi travailler',
            'aide moi a focus', 'aide moi a me concentrer', 'focus maintenant',
            'prochaine tache', 'bloc actuel', 'next focus', 'que faire',
            // Planning hebdomadaire
            'planifie ma semaine', 'organise ma semaine', 'plan de la semaine',
            'planning semaine', 'weekly planning', 'preview semaine',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Apply/accept plan
        if (preg_match('/\b(appliquer?|accepter?|valider?|apply)\s+(le\s+)?(bloc|block|planning)/iu', $lower)) {
            return $this->applyBlocks($context, $body);
        }

        // Modify block
        if (preg_match('/\b(modifier?|decaler?|ajuster?|changer?|shift|move)\s+(le\s+)?(bloc|block)/iu', $lower)) {
            $taskData = $this->optimizer->gatherUserData($context);
            return $this->modifyBlock($context, $body, $taskData);
        }

        // Show current plan
        if (preg_match('/\b(voir|afficher?|montre|consulter?|show)\s+(mon\s+)?(planning|agenda|blocs?)/iu', $lower)
            || preg_match('/\b(mon\s+planning|mon\s+agenda|agenda\s+du\s+jour|quel\s+est\s+mon\s+planning)\b/iu', $lower)
        ) {
            return $this->showCurrentPlan($context);
        }

        // Quick focus — what to do right now
        if (preg_match('/\b(que\s+faire|quoi\s+faire|sur\s+quoi\s+travailler|next\s+focus|focus\s+maintenant|prochaine\s+tache|bloc\s+actuel)\b/iu', $lower)
            || preg_match('/\b(aide.{0,5}(focus|concentr))/iu', $lower)
        ) {
            return $this->quickFocusNow($context);
        }

        // Weekly planning
        if (preg_match('/\b(semaine|weekly|week)\b/iu', $lower)) {
            $taskData = $this->optimizer->gatherUserData($context);
            return $this->generateWeeklyPreview($context, $body, $taskData);
        }

        // Tomorrow
        if (preg_match('/\b(demain|tomorrow)\b/iu', $lower)) {
            $taskData = $this->optimizer->gatherUserData($context);
            return $this->generatePlan($context, $body, $taskData, 'tomorrow');
        }

        // Default: generate optimized day plan for today
        $taskData = $this->optimizer->gatherUserData($context);
        return $this->generatePlan($context, $body, $taskData, 'today');
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);
        $day = $pendingContext['data']['day'] ?? 'today';

        if ($type === 'awaiting_plan_action') {
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
            $reply = "Que souhaites-tu faire avec ce planning ?\n\n"
                . "✅ Tape *\"appliquer planning\"* pour activer les rappels\n"
                . "✏️ Tape *\"modifier bloc [detail]\"* pour ajuster\n"
                . "❌ Tape *\"annuler\"* pour abandonner";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Core actions
    // ─────────────────────────────────────────────────────────────────────────

    private function generatePlan(AgentContext $context, string $query, array $taskData, string $day): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';

        $now = now()->setTimezone($timezone);
        $dayLabel = $day === 'tomorrow'
            ? $now->copy()->addDay()->format('l d/m/Y')
            : $now->format('l d/m/Y');
        $currentHour = $day === 'today' ? $now->format('H:i') : '08:00';

        $scoredTasks = $this->optimizer->analyzeTaskUrgency($taskData['todos'] ?? []);
        $energyDips = $this->optimizer->estimateEnergyDips();
        $breakSuggestions = $this->optimizer->suggestBreakTiming($currentHour);

        $taskSummary = $this->formatTaskSummary($taskData);
        $energySummary = $this->formatEnergySummary($energyDips);
        $urgencySummary = $this->formatUrgencySummary($scoredTasks);

        $systemPrompt = <<<PROMPT
Tu es un expert en productivite et gestion du temps. Tu crees des plannings optimises par blocs de temps adaptes a WhatsApp.

DONNEES UTILISATEUR:
{$taskSummary}

ANALYSE D'URGENCE (scores 0-100 calcules automatiquement):
{$urgencySummary}

COURBE D'ENERGIE JOURNALIERE (rythme circadien):
{$energySummary}

REGLES DE CONSTRUCTION DU PLANNING:
1. Planifie pour {$dayLabel}, en commencant a partir de {$currentHour}
2. Structure les blocs:
   - 🎯 Blocs focus (1h30-2h) pour les taches importantes/complexes → le matin en priorite
   - ☕ Pauses courtes (15-30min) obligatoires entre les blocs focus
   - 📧 Blocs admin/emails (30-45min) pour taches legeres → apres-midi creux 13h-15h
   - 📞 Blocs reunion → apres le premier bloc focus du matin ou en milieu d'apres-midi
   - 🍽️ Pause dejeuner (1h) vers 12h-13h
3. Priorites:
   - Score urgence ≥80 🔴 = obligatoire en matin pic (08h-11h)
   - Score 60-79 🟡 = planifie en matinee ou debut d'apres-midi
   - Score <60 🟢 = apres-midi ou fin de journee
4. Evite de planifier plus de 5 blocs focus par journee (risque d'epuisement)
5. Pour chaque bloc, inclus:
   - Heure debut - fin en *gras*
   - Emoji du type
   - Titre de la tache
   - Une justification courte en _italique_ (1 ligne max)
6. Formate pour WhatsApp:
   - *gras* pour titres et heures
   - _italique_ pour justifications
   - Lignes bien espacees (lisibilite mobile)

FORMAT OBLIGATOIRE DE REPONSE:
📅 *Planning {$dayLabel}*
━━━━━━━━━━━━━━━━━━━━

🎯 *08:00 - 10:00* | *Focus: [Tache la plus urgente]*
   _→ Score urgence 85/100 — a traiter en pic d'energie matinal_

☕ *10:00 - 10:15* | *Pause*
   _→ Marche courte ou etirement avant le prochain bloc_

📧 *10:15 - 11:00* | *Admin: Emails & messages*
   _→ Traiter les urgences administratives_

...continuer selon les taches disponibles...

━━━━━━━━━━━━━━━━━━━━
📊 *Resume:* X blocs focus | Xh productives | X taches planifiees

⚡ *Courbe d'energie du jour:*
🌅 Matin 08h-11h: pic → taches complexes prioritaires
😴 13h-15h: creux → taches legeres, emails, reunions
💨 16h-18h: second souffle → revues, collaboration

━━━━━━━━━━━━━━━━━━━━
💡 *Actions disponibles:*
✅ *"appliquer planning"* → activer les rappels pour chaque bloc
✏️ *"modifier bloc [detail]"* → ajuster le planning
🔄 *"decaler bloc [heure]"* → decaler un bloc specifique
❌ *"annuler"* → abandonner ce planning

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Desole, je n'ai pas pu generer ton planning optimise.\n\n"
                . "💡 Decris tes taches du jour dans ton message, ex :\n"
                . "_\"Planifie ma journee : finir rapport, call client 14h, revue code\"_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->cachePlan($context->from, $response, $day);
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
                . "Genere d'abord un planning :\n"
                . "💡 _\"Organise ma journee\"_\n"
                . "💡 _\"Bloque ma journee\"_\n"
                . "💡 _\"Planifie demain\"_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $extractPrompt = <<<PROMPT
Extrais TOUS les blocs de temps du planning suivant.
Retourne un JSON array strict avec chaque bloc :
[{"start": "HH:MM", "end": "HH:MM", "type": "focus|pause|reunion|admin|dejeuner|sport", "label": "description courte max 100 caracteres"}]

Regles :
- "start" et "end" DOIVENT etre au format HH:MM (ex: "08:00", "14:30")
- "type" doit etre l'un de : focus, pause, reunion, admin, dejeuner, sport
- "label" : titre court de la tache ou du bloc (sans emojis)
- Inclus TOUS les blocs du planning, y compris pauses et dejeuner

Planning a analyser :
{$cachedPlan}

Reponds UNIQUEMENT avec le JSON array, aucun texte autour, aucun commentaire.
PROMPT;

        $blocksJson = $this->claude->chat($extractPrompt, ModelResolver::fast(), 'Tu extrais des donnees structurees de plannings. Reponds uniquement en JSON valide, sans texte supplementaire.');

        $blocksJson = $this->extractJson($blocksJson);
        $blocks = json_decode($blocksJson ?? '[]', true);

        if (empty($blocks) || !is_array($blocks)) {
            $reply = "Je n'ai pas pu extraire les blocs du planning.\n\n"
                . "Regenere ton planning et reessaie avec *\"appliquer planning\"*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $validBlocks = $this->validateBlocks($blocks);
        if (empty($validBlocks)) {
            $reply = "Les blocs extraits ne sont pas valides (format incorrect).\n\n"
                . "Regenere ton planning avec _\"planifie ma journee\"_.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Store blocks in Redis until end of day
        $prefs = $this->getUserPrefs($context);
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';
        $endOfDay = now()->setTimezone($timezone)->endOfDay();
        $ttl = max((int) now()->diffInSeconds($endOfDay), 60);

        $redisKey = "time_blocks:{$context->from}";
        Redis::setex($redisKey, $ttl, json_encode($validBlocks));

        $createdReminders = $this->createBlockReminders($context, $validBlocks, $timezone);

        // Clear cached plan
        Redis::del("time_plan:{$context->from}");

        $blockCount = count($validBlocks);
        $focusCount = count(array_filter($validBlocks, fn($b) => $b['type'] === 'focus'));

        $reply = "✅ *Planning applique !*\n\n"
            . "📋 *{$blockCount} blocs* programmes\n"
            . "🎯 *{$focusCount} blocs focus*\n"
            . "🔔 *{$createdReminders} rappels* crees\n\n"
            . "Tu recevras une notification au debut de chaque bloc.\n\n"
            . "💡 _Tape *\"voir mon planning\"* pour consulter les blocs actifs_\n"
            . "💡 _Tape *\"que faire maintenant\"* pour connaitre ton focus actuel_\n\n"
            . "Bonne journee productive ! 💪";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Time blocks applied', ['blocks' => $blockCount, 'reminders' => $createdReminders]);

        return AgentResult::reply($reply, ['action' => 'apply_blocks', 'blocks' => $blockCount, 'reminders' => $createdReminders]);
    }

    private function modifyBlock(AgentContext $context, string $query, array $taskData): AgentResult
    {
        $cachedPlan = $this->getCachedPlan($context->from);
        $cachedDay = $this->getCachedPlanDay($context->from);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';

        $now = now()->setTimezone($timezone);
        $dayLabel = $cachedDay === 'tomorrow'
            ? $now->copy()->addDay()->format('l d/m/Y')
            : $now->format('l d/m/Y');

        $planContext = $cachedPlan
            ? "\nPLANNING ACTUEL ({$dayLabel}):\n{$cachedPlan}"
            : "\nAucun planning en cours. Propose un nouveau planning optimise.";

        $systemPrompt = <<<PROMPT
Tu es un expert en gestion du temps. L'utilisateur veut modifier son planning.
{$planContext}

REGLES:
1. Comprends la modification demandee : decaler, allonger, raccourcir, supprimer ou ajouter un bloc
2. Propose le planning COMPLET modifie avec le meme format WhatsApp
3. Explique brievement les changements apportes (1-2 lignes)
4. Respecte les emojis de type : 🎯 focus, ☕ pause, 📞 reunion, 📧 admin, 🍽️ dejeuner, 💪 sport
5. Utilise *gras* pour les heures et _italique_ pour les justifications
6. Termine toujours par les actions interactives :
   ✅ *"appliquer planning"* | ✏️ *"modifier bloc [detail]"* | ❌ *"annuler"*
7. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Desole, je n'ai pas pu modifier le planning. Reessaie en decrivant la modification souhaitee !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Preserve the original day when updating cached plan
        $this->cachePlan($context->from, $response, $cachedDay ?? 'today');
        $this->setPendingContext($context, 'awaiting_plan_action', ['day' => $cachedDay ?? 'today'], 30);

        $this->sendText($context->from, $response);
        $this->log($context, 'Time block modified', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'modify_block']);
    }

    /**
     * NEW: Show the currently active/cached time block plan.
     * Highlights the current block if blocks are active in Redis.
     */
    private function showCurrentPlan(AgentContext $context): AgentResult
    {
        $prefs = $this->getUserPrefs($context);
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';
        $now = now()->setTimezone($timezone);
        $currentTime = $now->format('H:i');

        // Check for active blocks stored after apply
        $activeBlocksRaw = Redis::get("time_blocks:{$context->from}");
        $cachedPlan = $this->getCachedPlan($context->from);

        if (!$activeBlocksRaw && !$cachedPlan) {
            $reply = "Aucun planning actif pour le moment.\n\n"
                . "Genere un planning avec :\n"
                . "💡 _\"Organise ma journee\"_\n"
                . "💡 _\"Planifie demain\"_\n"
                . "💡 _\"Planifie ma semaine\"_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($activeBlocksRaw) {
            $blocks = json_decode($activeBlocksRaw, true) ?? [];
            $currentBlock = null;
            $nextBlock = null;

            foreach ($blocks as $block) {
                $blockStart = $this->timeToMinutes($block['start'] ?? '00:00');
                $blockEnd = $this->timeToMinutes($block['end'] ?? '23:59');
                $nowMinutes = $this->timeToMinutes($currentTime);

                if ($nowMinutes >= $blockStart && $nowMinutes < $blockEnd) {
                    $currentBlock = $block;
                } elseif ($nowMinutes < $blockStart && !$nextBlock) {
                    $nextBlock = $block;
                }
            }

            $typeEmojis = [
                'focus' => '🎯', 'pause' => '☕', 'reunion' => '📞',
                'admin' => '📧', 'dejeuner' => '🍽️', 'sport' => '💪',
            ];

            $reply = "📅 *Ton planning actif — {$now->format('l d/m/Y')}*\n";
            $reply .= "🕐 Il est *{$currentTime}*\n";
            $reply .= "━━━━━━━━━━━━━━━━━━━━\n\n";

            if ($currentBlock) {
                $emoji = $typeEmojis[$currentBlock['type']] ?? '⏰';
                $reply .= "▶️ *MAINTENANT* : {$emoji} {$currentBlock['label']}\n";
                $reply .= "   _{$currentBlock['start']} - {$currentBlock['end']}_\n\n";
            } else {
                $reply .= "⏸️ _Aucun bloc actif en ce moment_\n\n";
            }

            if ($nextBlock) {
                $emoji = $typeEmojis[$nextBlock['type']] ?? '⏰';
                $reply .= "⏭️ *PROCHAIN* : {$emoji} {$nextBlock['label']}\n";
                $reply .= "   _{$nextBlock['start']} - {$nextBlock['end']}_\n\n";
            }

            $reply .= "━━━━━━━━━━━━━━━━━━━━\n";
            $reply .= "*Tous les blocs :*\n";
            foreach ($blocks as $block) {
                $emoji = $typeEmojis[$block['type']] ?? '⏰';
                $nowMinutes = $this->timeToMinutes($currentTime);
                $blockEnd = $this->timeToMinutes($block['end'] ?? '23:59');
                $done = $nowMinutes >= $blockEnd;
                $prefix = $done ? '✓' : '○';
                $reply .= "{$prefix} {$emoji} *{$block['start']}-{$block['end']}* {$block['label']}\n";
            }

            $reply .= "\n💡 _Tape *\"que faire maintenant\"* pour un focus immédiat_";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Plan shown', ['has_active_blocks' => true, 'block_count' => count($blocks)]);
            return AgentResult::reply($reply, ['action' => 'show_plan']);
        }

        // No applied blocks, but a cached (unapplied) plan exists
        $reply = "📋 *Planning en attente (non applique)*\n\n"
            . $cachedPlan
            . "\n\n━━━━━━━━━━━━━━━━━━━━\n"
            . "✅ Tape *\"appliquer planning\"* pour activer les rappels\n"
            . "✏️ Tape *\"modifier bloc [detail]\"* pour ajuster";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'show_plan']);
    }

    /**
     * NEW: Instant suggestion — what to focus on right now based on active blocks and tasks.
     */
    private function quickFocusNow(AgentContext $context): AgentResult
    {
        $prefs = $this->getUserPrefs($context);
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';
        $lang = $prefs['language'] ?? 'fr';
        $now = now()->setTimezone($timezone);
        $currentTime = $now->format('H:i');
        $hour = (int) $now->format('H');

        // Check active blocks first
        $activeBlocksRaw = Redis::get("time_blocks:{$context->from}");
        if ($activeBlocksRaw) {
            $blocks = json_decode($activeBlocksRaw, true) ?? [];
            $nowMinutes = $this->timeToMinutes($currentTime);

            $typeEmojis = [
                'focus' => '🎯', 'pause' => '☕', 'reunion' => '📞',
                'admin' => '📧', 'dejeuner' => '🍽️', 'sport' => '💪',
            ];

            foreach ($blocks as $block) {
                $blockStart = $this->timeToMinutes($block['start'] ?? '00:00');
                $blockEnd = $this->timeToMinutes($block['end'] ?? '23:59');
                if ($nowMinutes >= $blockStart && $nowMinutes < $blockEnd) {
                    $emoji = $typeEmojis[$block['type']] ?? '⏰';
                    $remaining = $blockEnd - $nowMinutes;
                    $reply = "▶️ *Focus actuel ({$currentTime})*\n\n"
                        . "{$emoji} *{$block['label']}*\n"
                        . "🕐 Bloc : {$block['start']} - {$block['end']}\n"
                        . "⏱️ Temps restant : ~{$remaining} min\n\n"
                        . "_Reste concentre jusqu'a {$block['end']} !_ 💪";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'quick_focus']);
                }
            }
        }

        // No active blocks — use AI with tasks + energy context
        $taskData = $this->optimizer->gatherUserData($context);
        $scoredTasks = $this->optimizer->analyzeTaskUrgency($taskData['todos'] ?? []);
        $taskSummary = $this->formatTaskSummary($taskData);
        $urgencySummary = $this->formatUrgencySummary($scoredTasks);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);

        // Determine current energy level
        $energyLevel = match (true) {
            $hour >= 8 && $hour < 11 => 'PIC D\'ENERGIE (taches complexes recommandees)',
            $hour >= 11 && $hour < 12 => 'ENERGIE HAUTE (termine les taches en cours)',
            $hour >= 12 && $hour < 14 => 'CREUX DIGESTIF (taches legeres, emails)',
            $hour >= 14 && $hour < 16 => 'REPRISE PROGRESSIVE (reunions, admin)',
            $hour >= 16 && $hour < 18 => 'SECOND SOUFFLE (collaboration, revues)',
            default => 'ENERGIE EN BAISSE (taches legeres)',
        };

        $systemPrompt = <<<PROMPT
Tu es un coach de productivite. L'utilisateur te demande sur quoi se concentrer MAINTENANT.

HEURE ACTUELLE: {$currentTime}
NIVEAU D'ENERGIE: {$energyLevel}

{$taskSummary}

ANALYSE D'URGENCE:
{$urgencySummary}

REGLES:
1. Recommande UNE seule tache prioritaire pour maintenant, en 3-4 lignes max
2. Justifie en 1 phrase pourquoi cette tache maintenant (urgence + energie)
3. Suggere une duree de session (ex: "Lance un bloc focus de 90 min")
4. Indique l'heure de fin suggeree
5. Ajoute un conseil de concentration en 1 ligne
6. Format WhatsApp compact avec emojis
7. Langue: {$lang}

FORMAT:
🎯 *Focus recommande : [Tache]*
⏱️ *Duree* : Xh (jusqu'a HH:MM)
💡 *Pourquoi* : [justification courte]
⚡ *Conseil* : [astuce de concentration]

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($context->body ?? '', $model, $systemPrompt);

        if (!$response) {
            $reply = "Il est {$currentTime} — niveau d'energie : {$energyLevel}.\n\n"
                . "Tap *\"planifie ma journee\"* pour que je t'aide a organiser tes prochaines heures !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Quick focus suggestion', ['hour' => $currentTime]);

        return AgentResult::reply($response, ['action' => 'quick_focus']);
    }

    /**
     * NEW: Generate a weekly planning preview (Monday to Friday overview).
     */
    private function generateWeeklyPreview(AgentContext $context, string $query, array $taskData): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';
        $timezone = $prefs['timezone'] ?? 'Europe/Paris';

        $now = now()->setTimezone($timezone);
        $startOfWeek = $now->copy()->startOfWeek(); // Monday
        $endOfWeek = $now->copy()->endOfWeek();     // Sunday

        $weekLabel = $startOfWeek->format('d/m') . ' - ' . $endOfWeek->format('d/m/Y');
        $scoredTasks = $this->optimizer->analyzeTaskUrgency($taskData['todos'] ?? []);
        $taskSummary = $this->formatTaskSummary($taskData);
        $urgencySummary = $this->formatUrgencySummary($scoredTasks);

        $systemPrompt = <<<PROMPT
Tu es un expert en planification hebdomadaire. Tu crees une vue d'ensemble de la semaine par distribution intelligente des taches.

SEMAINE: {$weekLabel}
JOUR ACTUEL: {$now->format('l d/m/Y')}

{$taskSummary}

ANALYSE D'URGENCE:
{$urgencySummary}

REGLES:
1. Distribue les taches sur les jours de la semaine (Lundi -> Vendredi)
2. Pour les jours passes, note "✓ Passe"
3. Pour aujourd'hui ({$now->format('l')}), propose 2-3 blocs prioritaires
4. Pour les jours futurs, assigne les taches par importance
5. Applique la regle 80/20 : taches critiques en debut de semaine
6. Laisse du temps tampon (30%) pour les imprevu
7. Evite de surcharger un seul jour
8. Format WhatsApp avec emojis et separateurs
9. Termine par un resume et les actions disponibles
10. Langue: {$lang}

FORMAT OBLIGATOIRE:
📅 *Planning semaine {$weekLabel}*
━━━━━━━━━━━━━━━━━━━━

*Lundi [date]* [✓ Passe | 🔴 Aujourd'hui | ⬜]
  🎯 [Tache 1] | 📧 [Admin] | ☕ Pauses

*Mardi [date]*
  🎯 [Tache 2]

...

━━━━━━━━━━━━━━━━━━━━
📊 *Resume semaine:*
• X taches planifiees sur X jours
• Charge par jour : [equilibre/charge/legere]
• Tache prioritaire : [titre]

💡 *Actions:*
• _"planifie [lundi/mardi/...]"_ pour un planning detaille de ce jour
• _"organise ma journee"_ pour le planning d'aujourd'hui
• _"que faire maintenant"_ pour le focus immediat

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Desole, je n'ai pas pu generer la vue hebdomadaire.\n\n"
                . "Essaie _\"organise ma journee\"_ pour commencer par aujourd'hui.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Weekly preview generated', [
            'week' => $weekLabel,
            'tasks_count' => count($taskData['todos'] ?? []),
        ]);

        return AgentResult::reply($response, ['action' => 'weekly_preview', 'week' => $weekLabel]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createBlockReminders(AgentContext $context, array $blocks, string $timezone): int
    {
        $createdReminders = 0;
        $notifyTypes = ['focus', 'reunion', 'admin', 'sport'];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if (!in_array($type, $notifyTypes)) {
                continue;
            }

            try {
                $startTime = now()->setTimezone($timezone)->setTimeFromTimeString($block['start']);
                if (!$startTime->isFuture()) {
                    continue;
                }

                $emoji = match ($type) {
                    'focus'   => '🎯',
                    'reunion' => '📞',
                    'admin'   => '📧',
                    'sport'   => '💪',
                    default   => '⏰',
                };

                Reminder::create([
                    'requester_phone' => $context->from,
                    'agent_id'        => $context->agent->id,
                    'message'         => "{$emoji} Bloc: {$block['label']} ({$block['start']} - {$block['end']})",
                    'scheduled_at'    => $startTime->setTimezone('UTC'),
                    'status'          => 'pending',
                ]);
                $createdReminders++;
            } catch (\Throwable $e) {
                Log::warning('TimeBlocker: failed to create reminder for block', [
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
            if (!is_array($block)) {
                continue;
            }

            $start = $block['start'] ?? null;
            $end   = $block['end'] ?? null;
            $type  = $block['type'] ?? null;
            $label = $block['label'] ?? null;

            if (!$start || !$end
                || !preg_match('/^\d{2}:\d{2}$/', $start)
                || !preg_match('/^\d{2}:\d{2}$/', $end)
            ) {
                continue;
            }

            if (!$type || !in_array($type, $allowedTypes)) {
                continue;
            }

            if (!$label || !is_string($label) || mb_strlen(trim($label)) === 0) {
                continue;
            }

            $valid[] = [
                'start' => $start,
                'end'   => $end,
                'type'  => $type,
                'label' => mb_substr(trim($label), 0, 255),
            ];
        }

        return $valid;
    }

    private function extractJson(?string $text): ?string
    {
        if (!$text) {
            return null;
        }
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

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
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
                'plan'       => $plan,
                'day'        => $day,
                'created_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('TimeBlocker: failed to cache plan', ['error' => $e->getMessage()]);
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
            Log::warning('TimeBlocker: failed to get cached plan', ['error' => $e->getMessage()]);
        }
        return null;
    }

    private function getCachedPlanDay(string $userId): ?string
    {
        try {
            $data = Redis::get("time_plan:{$userId}");
            if ($data) {
                $decoded = json_decode($data, true);
                return $decoded['day'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('TimeBlocker: failed to get cached plan day', ['error' => $e->getMessage()]);
        }
        return null;
    }
}
