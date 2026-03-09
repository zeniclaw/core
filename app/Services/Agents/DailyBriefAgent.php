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
    public function name(): string
    {
        return 'daily_brief';
    }

    public function description(): string
    {
        return 'Resume personnalise matinal multicanal. Agregation des rappels, taches, meteo, news et citation du jour en un seul message structure.';
    }

    public function keywords(): array
    {
        return [
            'brief', 'briefing', 'resume du jour', 'morning',
            'bonjour resume', 'mon brief', 'daily brief',
            'resume matinal', 'configure brief',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match(
            '/\b(daily\s*brief|mon\s+brief|briefing|resume\s+du\s+jour|resume\s+matinal|morning\s+brief|configure\s+brief)\b/iu',
            $context->body
        );
    }

    public function handle(AgentContext $context): AgentResult
    {
        $phone = $context->phone();
        $body = trim($context->body ?? '');

        $this->log($context, '[daily_brief] Received: ' . $body);

        // Handle configure commands
        if (preg_match('/configure\s+brief\s+(\d{1,2})[h:](\d{2})?/iu', $body, $m)) {
            return $this->handleConfigureTime($context, $phone, (int) $m[1], (int) ($m[2] ?? 0));
        }

        if (preg_match('/\b(disable|desactiver|stop)\s+brief/iu', $body)) {
            return $this->handleDisable($phone);
        }

        if (preg_match('/\b(enable|activer|start)\s+brief/iu', $body)) {
            return $this->handleEnable($phone);
        }

        if (preg_match('/configure\s+brief\s+sections?\s+(.+)/iu', $body, $m)) {
            return $this->handleConfigureSections($phone, $m[1]);
        }

        // Default: generate and return the brief
        return $this->generateBrief($context, $phone);
    }

    private function handleConfigureTime(AgentContext $context, string $phone, int $hour, int $minute): AgentResult
    {
        $time = sprintf('%02d:%02d', min($hour, 23), min($minute, 59));

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['brief_time' => $time, 'enabled' => true]
        );

        $this->log($context, "[daily_brief] Brief time configured: {$time}");

        return AgentResult::reply(
            "⏰ *Brief configure !*\n\n"
            . "Ton resume quotidien sera envoye chaque jour a *{$time}*.\n\n"
            . "_Commandes disponibles :_\n"
            . "- `configure brief HH:MM` — changer l'heure\n"
            . "- `configure brief sections meteo,taches,rappels` — choisir les sections\n"
            . "- `disable brief` — desactiver\n"
            . "- `mon brief` — recevoir le brief maintenant"
        );
    }

    private function handleDisable(string $phone): AgentResult
    {
        UserBriefPreference::where('user_phone', $phone)->update(['enabled' => false]);

        return AgentResult::reply("🔕 *Brief desactive.* Dis `enable brief` pour le reactiver.");
    }

    private function handleEnable(string $phone): AgentResult
    {
        $pref = UserBriefPreference::firstOrCreate(
            ['user_phone' => $phone],
            ['brief_time' => '07:00', 'enabled' => true, 'preferred_sections' => ['reminders', 'tasks', 'weather', 'news', 'quote']]
        );

        if (!$pref->enabled) {
            $pref->update(['enabled' => true]);
        }

        return AgentResult::reply("🔔 *Brief active !* Tu recevras ton resume chaque jour a *{$pref->brief_time}*.");
    }

    private function handleConfigureSections(string $phone, string $sectionsStr): AgentResult
    {
        $validSections = ['reminders', 'tasks', 'weather', 'news', 'quote', 'rappels', 'taches', 'meteo', 'citation'];
        $sectionMap = [
            'rappels' => 'reminders',
            'taches' => 'tasks',
            'meteo' => 'weather',
            'citation' => 'quote',
        ];

        $rawSections = array_map('trim', preg_split('/[,\s]+/', strtolower($sectionsStr)));
        $sections = [];
        foreach ($rawSections as $s) {
            $mapped = $sectionMap[$s] ?? $s;
            if (in_array($mapped, ['reminders', 'tasks', 'weather', 'news', 'quote'])) {
                $sections[] = $mapped;
            }
        }

        if (empty($sections)) {
            return AgentResult::reply("❌ Sections invalides. Choix possibles : `reminders`, `tasks`, `weather`, `news`, `quote`.");
        }

        UserBriefPreference::updateOrCreate(
            ['user_phone' => $phone],
            ['preferred_sections' => $sections]
        );

        return AgentResult::reply(
            "✅ *Sections mises a jour :* " . implode(', ', $sections) . "\n\n"
            . "_Dis `mon brief` pour voir le resultat._"
        );
    }

    public function generateBrief(AgentContext $context, string $phone): AgentResult
    {
        $pref = UserBriefPreference::where('user_phone', $phone)->first();
        $sections = $pref?->preferred_sections ?? ['reminders', 'tasks', 'weather', 'news', 'quote'];

        $message = "🌅 *DAILY BRIEF*\n";
        $message .= "_" . now()->translatedFormat('l d F Y') . "_\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";

        // Reminders section
        if (in_array('reminders', $sections)) {
            $message .= $this->buildRemindersSection($phone);
        }

        // Tasks section
        if (in_array('tasks', $sections)) {
            $message .= $this->buildTasksSection($phone);
        }

        // Weather section
        if (in_array('weather', $sections)) {
            $message .= $this->buildWeatherSection($phone);
        }

        // News section
        if (in_array('news', $sections)) {
            $message .= $this->buildNewsSection();
        }

        // Quote section
        if (in_array('quote', $sections)) {
            $message .= $this->buildQuoteSection();
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "_Configure : `configure brief HH:MM`_\n";
        $message .= "_Sections : `configure brief sections taches,meteo,news`_";

        $this->log($context, '[daily_brief] Brief generated for ' . $phone);

        return AgentResult::reply($message);
    }

    public function generateBriefForPhone(string $phone): string
    {
        $pref = UserBriefPreference::where('user_phone', $phone)->first();
        $sections = $pref?->preferred_sections ?? ['reminders', 'tasks', 'weather', 'news', 'quote'];

        $message = "🌅 *DAILY BRIEF*\n";
        $message .= "_" . now()->translatedFormat('l d F Y') . "_\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";

        if (in_array('reminders', $sections)) {
            $message .= $this->buildRemindersSection($phone);
        }
        if (in_array('tasks', $sections)) {
            $message .= $this->buildTasksSection($phone);
        }
        if (in_array('weather', $sections)) {
            $message .= $this->buildWeatherSection($phone);
        }
        if (in_array('news', $sections)) {
            $message .= $this->buildNewsSection();
        }
        if (in_array('quote', $sections)) {
            $message .= $this->buildQuoteSection();
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n";
        $message .= "_Dis `mon brief` pour rafraichir_";

        return $message;
    }

    private function buildRemindersSection(string $phone): string
    {
        $reminders = Reminder::where('requester_phone', $phone)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->where('scheduled_at', '<=', now()->endOfDay())
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();

        $section = "⏰ *RAPPELS DU JOUR*\n";

        if ($reminders->isEmpty()) {
            $section .= "_Aucun rappel pour aujourd'hui_\n\n";
        } else {
            foreach ($reminders as $r) {
                $time = $r->scheduled_at->format('H:i');
                $section .= "  • {$time} — {$r->message}\n";
            }
            $section .= "\n";
        }

        return $section;
    }

    private function buildTasksSection(string $phone): string
    {
        $todos = Todo::where('requester_phone', $phone)
            ->where('is_done', false)
            ->orderByRaw("CASE WHEN priority = 'high' THEN 1 WHEN priority = 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_at', 'asc')
            ->limit(5)
            ->get();

        $section = "✅ *TACHES PRIORITAIRES*\n";

        if ($todos->isEmpty()) {
            $section .= "_Aucune tache en cours_\n\n";
        } else {
            foreach ($todos as $t) {
                $priority = match($t->priority) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '⚪',
                };
                $due = $t->due_at ? ' (echeance: ' . $t->due_at->format('d/m') . ')' : '';
                $section .= "  {$priority} {$t->title}{$due}\n";
            }
            $section .= "\n";
        }

        return $section;
    }

    private function buildWeatherSection(string $phone): string
    {
        try {
            $prefs = PreferencesManager::getPreferences($phone);
            $city = $prefs['city'] ?? $prefs['timezone'] ?? 'Paris';

            // Use wttr.in for simple weather (no API key needed)
            $response = Http::timeout(5)->get("https://wttr.in/{$city}?format=3");

            if ($response->successful()) {
                $weather = trim($response->body());
                return "🌤️ *METEO*\n  {$weather}\n\n";
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Weather fetch failed: ' . $e->getMessage());
        }

        return "🌤️ *METEO*\n  _Indisponible_\n\n";
    }

    private function buildNewsSection(): string
    {
        $section = "📰 *HEADLINES*\n";

        try {
            $aggregator = new ContentAggregator();
            $articles = $aggregator->aggregate(['technology', 'general'], [], 3);

            if (!empty($articles)) {
                foreach (array_slice($articles, 0, 2) as $article) {
                    $title = $article['title'] ?? 'Sans titre';
                    $source = $article['source'] ?? '';
                    $section .= "  • *{$title}*";
                    if ($source) $section .= " _{$source}_";
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
        try {
            $response = Http::timeout(5)->get('https://api.quotable.io/random', [
                'maxLength' => 120,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['content'] ?? null;
                $author = $data['author'] ?? 'Inconnu';

                if ($content) {
                    return "💬 *CITATION DU JOUR*\n  _\"{$content}\"_\n  — {$author}\n\n";
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[daily_brief] Quote fetch failed: ' . $e->getMessage());
        }

        // Fallback quotes
        $fallback = [
            ['text' => 'Le succes est la somme de petits efforts repetes jour apres jour.', 'author' => 'Robert Collier'],
            ['text' => 'La seule facon de faire du bon travail est d\'aimer ce que vous faites.', 'author' => 'Steve Jobs'],
            ['text' => 'Chaque jour est une nouvelle chance de changer ta vie.', 'author' => 'Anonyme'],
            ['text' => 'La discipline est le pont entre les objectifs et les accomplissements.', 'author' => 'Jim Rohn'],
            ['text' => 'Ne remets pas a demain ce que tu peux faire aujourd\'hui.', 'author' => 'Benjamin Franklin'],
        ];

        $quote = $fallback[array_rand($fallback)];
        return "💬 *CITATION DU JOUR*\n  _\"{$quote['text']}\"_\n  — {$quote['author']}\n\n";
    }
}
