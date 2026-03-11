<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Models\Todo;
use App\Models\UserBriefPreference;
use App\Services\AgentContext;
use App\Services\ContentCurator\ContentAggregator;
use App\Services\PreferencesManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DailyBriefAgent extends BaseAgent
{
    private const DEFAULT_SECTIONS = ['reminders', 'tasks', 'weather', 'news', 'quote'];

    private const SECTION_ALIASES = [
        'rappels'      => 'reminders',
        'taches'       => 'tasks',
        'meteo'        => 'weather',
        'citation'     => 'quote',
        'actualites'   => 'news',
        'actu'         => 'news',
        'headlines'    => 'news',
        'productivite' => 'productivity',
        'tip'          => 'productivity',
        'conseil'      => 'productivity',
        'prod'         => 'productivity',
    ];

    private const VALID_SECTIONS = ['reminders', 'tasks', 'weather', 'news', 'quote', 'productivity'];

    public function name(): string
    {
        return 'daily_brief';
    }

    public function description(): string
    {
        return 'Resume personnalise matinal multicanal. Agregation des rappels, taches, meteo, news, citation et conseil productivite en un seul message structure.';
    }

    public function keywords(): array
    {
        return [
            'brief', 'briefing', 'resume du jour', 'morning',
            'bonjour resume', 'mon brief', 'daily brief',
            'resume matinal', 'configure brief',
            'statut brief', 'brief demain', 'preview demain',
            'ajouter section', 'retirer section', 'enlever section',
            'supprimer section', 'reset brief',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        $lower = mb_strtolower(trim($context->body));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($lower, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $phone = $context->phone();
        $body  = trim($context->body ?? '');

        $this->log($context, 'Received: ' . $body);

        if (preg_match('/configure\s+brief\s+(\d{1,2})[h:](\d{2})?/iu', $body, $m)) {
            return $this->handleConfigureTime($context, $phone, (int) $m[1], (int) ($m[2] ?? 0));
        }

        if (preg_match('/configure\s+brief\s+ville\s+(.+)/iu', $body, $m)) {
            return $this->handleConfigureCity($context, $phone, trim($m[1]));
        }

        if (preg_match('/configure\s+brief\s+sections?\s+(.+)/iu', $body, $m)) {
            return $this->handleConfigureSections($phone, $m[1]);
        }

        if (preg_match('/\b(reset|reinitialiser|réinitialiser)\s+brief\s+sections?/iu', $body)) {
            return $this->handleResetSections($phone);
        }

        if (preg_match('/\bajouter\s+section\s+(.+)/iu', $body, $m)) {
            return $this->handleAddSection($phone, trim($m[1]));
        }

        if (preg_match('/\b(retirer|supprimer|enlever)\s+section\s+(.+)/iu', $body, $m)) {
            return $this->handleRemoveSection($phone, trim($m[2]));
        }

        if (preg_match('/\b(disable|desactiver|stop)\s+brief/iu', $body)) {
            return $this->handleDisable($phone);
        }

        if (preg_match('/\b(enable|activer|start)\s+brief/iu', $body)) {
            return $this->handleEnable($phone);
        }

        if (preg_match('/\b(statut|status|config)\s+brief/iu', $body)) {
            return $this->handleStatus($phone);
        }

        if (preg_match('/\b(brief|resume|preview)\s+(de\s+)?demain/iu', $body)) {
            return $this->generateTomorrowBrief($context, $phone);
        }

        return $this->generateBrief($context, $phone);
    }

    // ─── Configuration handlers ───────────────────────────────────────────────

    private function handleConfigureTime(AgentContext $context, string $phone, int $hour, int $minute): AgentResult
    {
        $time = sprintf('%02d:%02d', min($hour, 23), min($minute, 59));

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['brief_time' => $time, 'enabled' => true]
        );

        $this->log($context, "Brief time configured: {$time}");

        return AgentResult::reply(
            "⏰ *Brief configure !*\n\n"
            . "Ton resume quotidien sera envoye chaque jour a *{$time}*.\n\n"
            . "_Commandes disponibles :_\n"
            . "• `configure brief HH:MM` — changer l'heure\n"
            . "• `configure brief ville Paris` — ville pour la meteo\n"
            . "• `configure brief sections meteo,taches,rappels` — choisir les sections\n"
            . "• `ajouter section productivite` — ajouter une section\n"
            . "• `retirer section news` — retirer une section\n"
            . "• `reset brief sections` — sections par defaut\n"
            . "• `disable brief` — desactiver\n"
            . "• `statut brief` — voir la configuration\n"
            . "• `mon brief` — recevoir le brief maintenant"
        );
    }

    private function handleConfigureCity(AgentContext $context, string $phone, string $city): AgentResult
    {
        $city = trim(preg_replace('/[^\p{L}\s\-]/u', '', $city));
        $city = mb_substr($city, 0, 50);

        if (empty($city)) {
            return AgentResult::reply(
                "❌ *Nom de ville invalide.*\n\n"
                . "Exemple : `configure brief ville Lyon`"
            );
        }

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['weather_city' => $city]
        );

        $this->log($context, "Brief city configured: {$city}");

        return AgentResult::reply(
            "🌍 *Ville mise a jour : {$city}*\n\n"
            . "La meteo de ton brief utilisera desormais *{$city}*.\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    private function handleDisable(string $phone): AgentResult
    {
        UserBriefPreference::where('user_phone', $phone)->update(['enabled' => false]);

        return AgentResult::reply(
            "🔕 *Brief desactive.*\n\n"
            . "Tu ne recevras plus de resume automatique.\n"
            . "_Dis `enable brief` pour le reactiver._"
        );
    }

    private function handleEnable(string $phone): AgentResult
    {
        $pref = UserBriefPreference::firstOrCreate(
            ['user_phone' => $phone],
            [
                'brief_time'         => '07:00',
                'enabled'            => true,
                'preferred_sections' => self::DEFAULT_SECTIONS,
            ]
        );

        if (!$pref->enabled) {
            $pref->update(['enabled' => true]);
        }

        return AgentResult::reply(
            "🔔 *Brief active !*\n\n"
            . "Tu recevras ton resume chaque jour a *{$pref->brief_time}*.\n"
            . "_Dis `mon brief` pour voir un apercu maintenant._"
        );
    }

    private function handleStatus(string $phone): AgentResult
    {
        $pref = UserBriefPreference::where('user_phone', $phone)->first();

        if (!$pref) {
            return AgentResult::reply(
                "📋 *Statut du brief*\n\n"
                . "❌ Aucune configuration trouvee.\n\n"
                . "_Dis `configure brief 07:00` pour commencer !_"
            );
        }

        $status   = $pref->enabled ? '✅ Actif' : '🔕 Desactive';
        $sections = implode(', ', $pref->preferred_sections ?? self::DEFAULT_SECTIONS);
        $city     = $pref->weather_city ?? '_Non configuree_';

        return AgentResult::reply(
            "📋 *Statut du brief*\n"
            . "━━━━━━━━━━━━━━━━━━\n\n"
            . "• *Statut* : {$status}\n"
            . "• *Heure d'envoi* : {$pref->brief_time}\n"
            . "• *Sections* : {$sections}\n"
            . "• *Ville meteo* : {$city}\n\n"
            . "_Commandes :_\n"
            . "• `configure brief HH:MM` — changer l'heure\n"
            . "• `configure brief ville Paris` — changer la ville\n"
            . "• `configure brief sections taches,meteo,news` — modifier les sections\n"
            . "• `ajouter section productivite` — ajouter une section\n"
            . "• `retirer section news` — retirer une section\n"
            . "• `reset brief sections` — sections par defaut\n"
            . "• `disable brief` / `enable brief` — activer/desactiver"
        );
    }

    private function handleConfigureSections(string $phone, string $sectionsStr): AgentResult
    {
        $rawSections = array_map('trim', preg_split('/[,\s]+/', mb_strtolower($sectionsStr)));
        $sections    = [];

        foreach ($rawSections as $s) {
            $mapped = self::SECTION_ALIASES[$s] ?? $s;
            if (in_array($mapped, self::VALID_SECTIONS) && !in_array($mapped, $sections)) {
                $sections[] = $mapped;
            }
        }

        if (empty($sections)) {
            return AgentResult::reply(
                "❌ *Sections invalides.*\n\n"
                . "Choix disponibles : `reminders` (rappels), `tasks` (taches), `weather` (meteo), `news` (actualites), `quote` (citation), `productivity` (productivite).\n\n"
                . "Exemple : `configure brief sections taches,meteo,citation,productivite`"
            );
        }

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['preferred_sections' => $sections]
        );

        $labels = implode(', ', $sections);

        return AgentResult::reply(
            "✅ *Sections mises a jour :*\n{$labels}\n\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    private function handleResetSections(string $phone): AgentResult
    {
        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['preferred_sections' => self::DEFAULT_SECTIONS]
        );

        $labels = implode(', ', self::DEFAULT_SECTIONS);

        return AgentResult::reply(
            "🔄 *Sections remises par defaut :*\n{$labels}\n\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    private function handleAddSection(string $phone, string $sectionStr): AgentResult
    {
        $sectionStr = mb_strtolower(trim($sectionStr));
        $section    = self::SECTION_ALIASES[$sectionStr] ?? $sectionStr;

        if (!in_array($section, self::VALID_SECTIONS)) {
            return AgentResult::reply(
                "❌ *Section inconnue : `{$sectionStr}`*\n\n"
                . "Sections disponibles : `reminders`, `tasks`, `weather`, `news`, `quote`, `productivity`."
            );
        }

        $pref     = UserBriefPreference::firstOrCreate(
            ['user_phone' => $phone],
            ['preferred_sections' => self::DEFAULT_SECTIONS]
        );
        $sections = $pref->preferred_sections ?? self::DEFAULT_SECTIONS;

        if (in_array($section, $sections)) {
            return AgentResult::reply("ℹ️ La section *{$section}* est deja dans ton brief.");
        }

        $sections[] = $section;
        $pref->update(['preferred_sections' => $sections]);

        return AgentResult::reply(
            "✅ *Section `{$section}` ajoutee !*\n\n"
            . "Sections actives : " . implode(', ', $sections) . "\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    private function handleRemoveSection(string $phone, string $sectionStr): AgentResult
    {
        $sectionStr = mb_strtolower(trim($sectionStr));
        $section    = self::SECTION_ALIASES[$sectionStr] ?? $sectionStr;

        if (!in_array($section, self::VALID_SECTIONS)) {
            return AgentResult::reply(
                "❌ *Section inconnue : `{$sectionStr}`*\n\n"
                . "Sections disponibles : `reminders`, `tasks`, `weather`, `news`, `quote`, `productivity`."
            );
        }

        $pref     = UserBriefPreference::where('user_phone', $phone)->first();
        $sections = $pref?->preferred_sections ?? self::DEFAULT_SECTIONS;
        $filtered = array_values(array_filter($sections, fn ($s) => $s !== $section));

        if (count($filtered) === count($sections)) {
            return AgentResult::reply("ℹ️ La section *{$section}* n'est pas dans ton brief.");
        }

        if (empty($filtered)) {
            return AgentResult::reply(
                "❌ *Impossible de retirer toutes les sections.*\n\n"
                . "Garde au moins une section active."
            );
        }

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['preferred_sections' => $filtered]
        );

        return AgentResult::reply(
            "✅ *Section `{$section}` retiree.*\n\n"
            . "Sections actives : " . implode(', ', $filtered) . "\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    // ─── Brief generation ─────────────────────────────────────────────────────

    public function generateBrief(AgentContext $context, string $phone): AgentResult
    {
        $message = $this->buildBriefMessage($phone, 'today');
        $this->log($context, 'Brief generated for ' . $phone);
        return AgentResult::reply($message);
    }

    public function generateTomorrowBrief(AgentContext $context, string $phone): AgentResult
    {
        $message = $this->buildBriefMessage($phone, 'tomorrow');
        $this->log($context, 'Tomorrow brief generated for ' . $phone);
        return AgentResult::reply($message);
    }

    /**
     * Called from scheduled command to dispatch brief for a phone number.
     */
    public function generateBriefForPhone(string $phone): string
    {
        return $this->buildBriefMessage($phone, 'today');
    }

    private function buildBriefMessage(string $phone, string $day = 'today'): string
    {
        $pref       = UserBriefPreference::where('user_phone', $phone)->first();
        $sections   = $pref?->preferred_sections ?? self::DEFAULT_SECTIONS;
        $isTomorrow = $day === 'tomorrow';

        $dateLabel = $isTomorrow
            ? now()->addDay()->translatedFormat('l d F Y')
            : now()->translatedFormat('l d F Y');

        $header   = $isTomorrow ? '🌙 *BRIEF DE DEMAIN*' : '🌅 *DAILY BRIEF*';
        $message  = "{$header}\n";
        $message .= "_{$dateLabel}_\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";

        if (in_array('reminders', $sections)) {
            $message .= $this->buildRemindersSection($phone, $isTomorrow);
        }

        if (in_array('tasks', $sections)) {
            $message .= $this->buildTasksSection($phone, $isTomorrow);
        }

        if (in_array('weather', $sections)) {
            $message .= $isTomorrow
                ? $this->buildWeatherTomorrowSection($phone, $pref)
                : $this->buildWeatherSection($phone, $pref);
        }

        if (!$isTomorrow) {
            if (in_array('news', $sections)) {
                $message .= $this->buildNewsSection();
            }
            if (in_array('quote', $sections)) {
                $message .= $this->buildQuoteSection();
            }
            if (in_array('productivity', $sections)) {
                $message .= $this->buildProductivitySection($phone);
            }
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n";

        if ($isTomorrow) {
            $message .= "_Apercu de demain. Dis `mon brief` pour le brief d'aujourd'hui._";
        } else {
            $message .= "_Configure : `configure brief HH:MM` | Sections : `configure brief sections taches,meteo`_\n";
            $message .= "_Statut : `statut brief` | Demain : `brief demain`_";
        }

        return $message;
    }

    // ─── Section builders ─────────────────────────────────────────────────────

    private function buildRemindersSection(string $phone, bool $tomorrow = false): string
    {
        $date  = $tomorrow ? now()->addDay() : now();
        $query = Reminder::where('requester_phone', $phone)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', $date->copy()->startOfDay())
            ->where('scheduled_at', '<=', $date->copy()->endOfDay())
            ->orderBy('scheduled_at');

        $total     = $query->count();
        $reminders = $query->limit(5)->get();
        $label     = $tomorrow ? "⏰ *RAPPELS DE DEMAIN*" : "⏰ *RAPPELS DU JOUR*";
        $section   = "{$label}\n";

        if ($reminders->isEmpty()) {
            $section .= '_Aucun rappel' . ($tomorrow ? ' pour demain' : " pour aujourd'hui") . "_\n\n";
        } else {
            foreach ($reminders as $r) {
                $time     = $r->scheduled_at->format('H:i');
                $section .= "  • {$time} — {$r->message}\n";
            }
            if ($total > 5) {
                $section .= '  _+ ' . ($total - 5) . " autre(s) rappel(s)_\n";
            }
            $section .= "\n";
        }

        return $section;
    }

    private function buildTasksSection(string $phone, bool $tomorrow = false): string
    {
        $label   = $tomorrow ? "✅ *TACHES POUR DEMAIN*" : "✅ *TACHES PRIORITAIRES*";
        $section = "{$label}\n";

        // Show overdue count in today's brief
        if (!$tomorrow) {
            $overdueCount = Todo::where('requester_phone', $phone)
                ->where('is_done', false)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()->startOfDay())
                ->count();

            if ($overdueCount > 0) {
                $section .= "  ⚠️ _*{$overdueCount} tache(s) en retard !*_\n";
            }
        }

        $query = Todo::where('requester_phone', $phone)
            ->where('is_done', false)
            ->orderByRaw("CASE WHEN priority = 'high' THEN 1 WHEN priority = 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_at', 'asc');

        if ($tomorrow) {
            $query->where(function ($q) {
                $q->whereDate('due_at', now()->addDay()->toDateString())
                  ->orWhere('priority', 'high');
            });
        }

        $total = $query->count();
        $todos = $query->limit(5)->get();

        if ($todos->isEmpty()) {
            $section .= '_Aucune tache' . ($tomorrow ? ' prioritaire pour demain' : ' en cours') . "_\n\n";
        } else {
            foreach ($todos as $t) {
                $priority  = match ($t->priority) {
                    'high'   => "🔴",
                    'medium' => "🟡",
                    default  => "⚪",
                };
                $due      = $t->due_at ? ' _(echeance: ' . $t->due_at->format('d/m') . ')_' : '';
                $section .= "  {$priority} {$t->title}{$due}\n";
            }
            if ($total > 5) {
                $section .= '  _+ ' . ($total - 5) . " autre(s) tache(s)_\n";
            }
            $section .= "\n";
        }

        return $section;
    }

    private function buildWeatherSection(string $phone, ?UserBriefPreference $pref = null): string
    {
        try {
            $city = $pref?->weather_city ?? null;

            if (!$city) {
                $userPrefs = PreferencesManager::getPreferences($phone);
                $city      = $userPrefs['city'] ?? null;
            }

            $city        = $city ?: 'Paris';
            $cityEncoded = rawurlencode($city);

            $response = Http::timeout(5)->get("https://wttr.in/{$cityEncoded}?format=3");

            if ($response->successful() && !empty(trim($response->body()))) {
                $weather = trim($response->body());
                return "🌤️ *METEO*\n  {$weather}\n\n";
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Weather fetch failed: ' . $e->getMessage());
        }

        return "🌤️ *METEO*\n  _Indisponible_\n\n";
    }

    private function buildWeatherTomorrowSection(string $phone, ?UserBriefPreference $pref = null): string
    {
        try {
            $city = $pref?->weather_city ?? null;

            if (!$city) {
                $userPrefs = PreferencesManager::getPreferences($phone);
                $city      = $userPrefs['city'] ?? null;
            }

            $city        = $city ?: 'Paris';
            $cityEncoded = rawurlencode($city);

            // wttr.in format=j1 returns JSON with forecast — use format=4 for concise tomorrow forecast
            $response = Http::timeout(5)->get("https://wttr.in/{$cityEncoded}?format=%2B1%27+%t+%h+%w");

            if ($response->successful() && !empty(trim($response->body()))) {
                $weather = trim($response->body());
                return "🌤️ *METEO DE DEMAIN*\n  {$weather}\n\n";
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Tomorrow weather fetch failed: ' . $e->getMessage());
        }

        return "🌤️ *METEO DE DEMAIN*\n  _Indisponible_\n\n";
    }

    private function buildNewsSection(): string
    {
        $section = "📰 *HEADLINES*\n";

        try {
            $aggregator = new ContentAggregator();
            $articles   = $aggregator->aggregate(['technology', 'general'], [], 3);

            if (!empty($articles)) {
                foreach (array_slice($articles, 0, 3) as $article) {
                    $title    = mb_substr($article['title'] ?? 'Sans titre', 0, 80);
                    $source   = $article['source'] ?? '';
                    $section .= "  • *{$title}*";
                    if ($source) {
                        $section .= " _{$source}_";
                    }
                    $section .= "\n";
                }
                $section .= "\n";
                return $section;
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] News fetch failed: ' . $e->getMessage());
        }

        $section .= "  _Aucune actualite disponible_\n\n";
        return $section;
    }

    private function buildQuoteSection(): string
    {
        $fallback = [
            ['text' => 'Le succes est la somme de petits efforts repetes jour apres jour.', 'author' => 'Robert Collier'],
            ['text' => "La seule facon de faire du bon travail est d'aimer ce que vous faites.", 'author' => 'Steve Jobs'],
            ['text' => 'Chaque jour est une nouvelle chance de changer ta vie.', 'author' => 'Anonyme'],
            ['text' => 'La discipline est le pont entre les objectifs et les accomplissements.', 'author' => 'Jim Rohn'],
            ['text' => "Ne remets pas a demain ce que tu peux faire aujourd'hui.", 'author' => 'Benjamin Franklin'],
            ['text' => 'La confiance en soi est le premier secret du succes.', 'author' => 'Ralph Waldo Emerson'],
            ['text' => 'Agis comme si ce que tu fais fait une difference. Cela en fait une.', 'author' => 'William James'],
            ['text' => 'La perseverance est la cle du succes.', 'author' => 'Charles de Gaulle'],
            ['text' => "Commence par faire ce qui est necessaire, puis ce qui est possible.", 'author' => "Francois d'Assise"],
        ];

        try {
            $response = Http::timeout(5)->get('https://api.quotable.io/random', ['maxLength' => 120]);

            if ($response->successful()) {
                $data    = $response->json();
                $content = $data['content'] ?? null;
                $author  = $data['author'] ?? 'Inconnu';

                if ($content && mb_strlen($content) <= 200) {
                    return "💬 *CITATION DU JOUR*\n  _\"{$content}\"_\n  — {$author}\n\n";
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Quote fetch failed: ' . $e->getMessage());
        }

        $quote = $fallback[array_rand($fallback)];
        return "💬 *CITATION DU JOUR*\n  _\"{$quote['text']}\"_\n  — {$quote['author']}\n\n";
    }

    private function buildProductivitySection(string $phone): string
    {
        $section = "🎯 *CONSEIL PRODUCTIVITE*\n";

        try {
            $pendingCount  = Todo::where('requester_phone', $phone)->where('is_done', false)->count();
            $highPriority  = Todo::where('requester_phone', $phone)->where('is_done', false)->where('priority', 'high')->count();
            $todayReminders = Reminder::where('requester_phone', $phone)
                ->where('status', 'pending')
                ->whereDate('scheduled_at', now()->toDateString())
                ->count();

            $systemPrompt = 'Tu es un coach en productivite personnelle. Tu fournis des conseils pratiques, concis et motivants en francais. Tes conseils doivent etre adaptes a la situation de l\'utilisateur et tenir en 1-2 phrases maximum. Reponds uniquement avec le conseil, sans introduction ni signature.';

            $userPrompt = "Donne un conseil de productivite personnalise pour aujourd'hui.\n"
                . "Contexte utilisateur : {$pendingCount} tache(s) en attente, {$highPriority} haute priorite, {$todayReminders} rappel(s) aujourd'hui.\n"
                . "Maximum 150 caracteres.";

            $tip = $this->claude->chat($userPrompt, 'claude-haiku-4-5-20251001', $systemPrompt);
            $tip = trim(strip_tags($tip ?? ''));

            if (!empty($tip) && mb_strlen($tip) <= 300) {
                return "{$section}  💡 _{$tip}_\n\n";
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Productivity tip generation failed: ' . $e->getMessage());
        }

        // Curated fallback tips
        $tips = [
            "Commence par ta tache la plus difficile — le reste de la journee sera plus facile.",
            "Groupe tes emails en 2 creneaux fixes. Evite de les consulter entre les deux.",
            "Applique la regle des 2 minutes : si une tache prend moins de 2 min, fais-la maintenant.",
            "Desactive les notifications pendant 90 min pour un bloc de travail concentre.",
            "Note tes 3 priorites du jour avant de commencer. Tout le reste est secondaire.",
            "Fais une pause de 5 min toutes les heures pour maintenir ta concentration.",
            "Bois un verre d'eau maintenant. L'hydratation ameliore la concentration.",
            "Prepare ton environnement : bureau range, outils a portee, distractions minimisees.",
        ];

        $tip = $tips[array_rand($tips)];
        return "{$section}  💡 _{$tip}_\n\n";
    }
}
