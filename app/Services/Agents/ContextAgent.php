<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\ContextMemoryBridge;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Log;

class ContextAgent extends BaseAgent
{
    private ContextMemoryBridge $bridge;

    public function __construct()
    {
        parent::__construct();
        $this->bridge = ContextMemoryBridge::getInstance();
    }

    public function name(): string
    {
        return 'context_memory_bridge';
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function description(): string
    {
        return 'Memoire conversationnelle intelligente inter-agents. Extrait entites (projets, taches, dates, domaines) de chaque message et maintient un cache Redis chaud partage entre tous les agents pour enrichir les decisions.';
    }

    public function keywords(): array
    {
        return [
            'contexte', 'context', 'memoire partagee', 'shared memory',
            'inter-agent', 'bridge', 'cache', 'profil contextuel',
            'retire projet', 'retire tag', 'note contexte', 'ajoute note',
            'recherche contexte', 'stats contexte', 'aide contexte',
            'historique contexte', 'affiche contexte', 'reset contexte',
            'ajoute projet', 'mes notes', 'liste notes', 'supprime note',
            'fuseau', 'timezone', 'fuseau horaire',
        ];
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

        if (!$body) {
            return AgentResult::reply(
                "Je suis le ContextMemoryBridge v{$this->version()}. Je travaille en arriere-plan pour partager le contexte entre tous les agents.\n\nEnvoyez *aide contexte* pour voir les commandes disponibles."
            );
        }

        // Guard: ensure user identity is present
        if (empty($context->from)) {
            return AgentResult::reply("Impossible d'identifier l'utilisateur. Veuillez reessayer.");
        }

        // Help
        if (preg_match('/^(aide|help)\s*(contexte?|bridge|context)?$/iu', $body)) {
            return $this->showHelp();
        }

        // Show context
        if (preg_match('/^(show|affiche|voir|montre)\s*(context|contexte|bridge)/iu', $body)) {
            return $this->showContext($context);
        }

        // Stats
        if (preg_match('/^(stats?|statistiques?|resume|résumé)\s*(context|contexte|bridge)?/iu', $body)) {
            return $this->contextStats($context);
        }

        // Clear/reset context
        if (preg_match('/^(clear|efface|reset|reinitialise)\s*(context|contexte|bridge)/iu', $body)) {
            $this->bridge->clearContext($context->from);
            return AgentResult::reply("Contexte partage reinitialise avec succes.\n\nTous vos projets, tags, notes et preferences ont ete effaces.");
        }

        // Add project manually: "ajoute projet <nom>"
        if (preg_match('/^(?:ajoute?|nouveau)\s+projet\s+(.+)$/iu', $body, $m)) {
            return $this->addProject($context, trim($m[1]));
        }

        // Remove project: "retire projet <nom>"
        if (preg_match('/^(retire|supprime|enleve)\s+projet\s+(.+)$/iu', $body, $m)) {
            return $this->removeProject($context, trim($m[2]));
        }

        // Remove tag: "retire tag <tag>"
        if (preg_match('/^(retire|supprime|enleve)\s+tag\s+(.+)$/iu', $body, $m)) {
            return $this->removeTag($context, trim($m[2]));
        }

        // Add note: "note: <texte>" or "ajoute note: <texte>" or "note contexte: <texte>"
        if (preg_match('/^(?:ajoute?\s+)?note(?:\s+contexte)?\s*:\s*(.+)$/iu', $body, $m)) {
            return $this->addNote($context, trim($m[1]));
        }

        // List notes: "mes notes" or "liste notes"
        if (preg_match('/^(mes\s+notes?|liste\s+notes?|voir\s+notes?)$/iu', $body)) {
            return $this->listNotes($context);
        }

        // Delete note by number: "supprime note 2"
        if (preg_match('/^(?:supprime|efface|retire)\s+note\s+(\d+)$/iu', $body, $m)) {
            return $this->deleteNote($context, (int) $m[1]);
        }

        // Set timezone: "fuseau Europe/Paris" or "timezone America/New_York"
        if (preg_match('/^(?:fuseau(?:\s+horaire)?|timezone|tz)\s+(.+)$/iu', $body, $m)) {
            return $this->setTimezone($context, trim($m[1]));
        }

        // Search history: "recherche <terme>" or "cherche <terme>"
        if (preg_match('/^(?:recherche|cherche)\s+(.+)$/iu', $body, $m)) {
            return $this->searchHistory($context, trim($m[1]));
        }

        // Show history
        if (preg_match('/^(historique|history)\s*(context|contexte|bridge)?/iu', $body)) {
            return $this->showHistory($context);
        }

        return AgentResult::reply(
            $this->bridge->formatForPrompt($context->from)
                ?: "Aucun contexte partage pour le moment.\n\nEnvoyez *aide contexte* pour voir les commandes disponibles."
        );
    }

    /**
     * Extract entities from an incoming message and update the shared context.
     * Called by RouterAgent before routing — must be fast and non-blocking.
     */
    public function extractAndUpdate(AgentContext $context): void
    {
        $body = trim($context->body ?? '');
        if (!$body || mb_strlen($body) < 5 || empty($context->from)) {
            return;
        }

        try {
            $this->extractEntities($context->from, $body);
        } catch (\Throwable $e) {
            Log::warning('ContextAgent: extractAndUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract entities from text and update context bridge.
     */
    private function extractEntities(string $userId, string $text): void
    {
        $tomorrow = now()->addDay()->format('Y-m-d');
        $today    = now()->format('Y-m-d');

        $prompt = <<<PROMPT
Analyse ce message et extrait les entites pertinentes. Reponds UNIQUEMENT avec du JSON valide, sans commentaire ni bloc markdown.

Message: "{$text}"

Format JSON attendu:
{
  "projects": ["nom_projet"],
  "tags": ["tag1", "tag2"],
  "dates": ["2026-03-15"],
  "domains": ["web", "finance"],
  "intent": "action_principale"
}

Definitions et exemples:
- projects: noms de projets, applications, clients mentionnes EXPLICITEMENT. Ex: message "travaille sur ZeniClaw" -> ["ZeniClaw"]. Vide si aucun nom de projet clair.
- tags: mots-cles importants, max 5, en minuscules sans accents. Ex: message "j'ai un bug urgent sur l'API" -> ["bug", "urgent", "api"]. Exclure les mots vides (le, la, et, de...).
- dates: dates mentionnees en ISO YYYY-MM-DD uniquement. "aujourd'hui" -> "{$today}", "demain" -> "{$tomorrow}", "lundi prochain" -> date ISO du prochain lundi. Vide si aucune date.
- domains: domaines thematiques, max 3, parmi: dev, finance, cuisine, sante, marketing, rh, legal, sport, education, logistique. Ex: "faire la comptabilite" -> ["finance"].
- intent: intention principale en snake_case, 1-3 mots. Ex: "creer_tache", "consulter_budget", "planifier_reunion", "demander_recette", "corriger_bug", "ajouter_depense".

Regles strictes:
- Message trivial (salut, ok, merci, oui, non, +1) -> tous les tableaux vides, intent "salutation".
- Ne devine JAMAIS ce qui n'est pas explicitement mentionne dans le message.
- Reponds UNIQUEMENT avec le JSON, rien d'autre.
PROMPT;

        $response = $this->claude->chat(
            $prompt,
            ModelResolver::fast(),
            'Tu es un extracteur d\'entites JSON. Reponds uniquement avec le JSON demande, sans aucun texte supplementaire ni bloc markdown.'
        );

        if (!$response) {
            return;
        }

        $clean = trim($response);

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $m)) {
            $clean = trim($m[1]);
        }

        // Extract JSON object if there is surrounding text
        if (preg_match('/\{[\s\S]*\}/u', $clean, $m)) {
            $clean = $m[0];
        }

        $parsed = json_decode($clean, true);
        if (!is_array($parsed)) {
            Log::debug('ContextAgent: invalid JSON from LLM extraction', ['raw' => mb_substr($clean, 0, 200)]);
            return;
        }

        // Update projects
        foreach (($parsed['projects'] ?? []) as $project) {
            if (is_string($project) && mb_strlen(trim($project)) > 1) {
                $this->bridge->addActiveProject($userId, trim($project));
            }
        }

        // Update tags
        $validTags = array_filter(
            $parsed['tags'] ?? [],
            fn($t) => is_string($t) && mb_strlen(trim($t)) > 1
        );
        if (!empty($validTags)) {
            $this->bridge->addTags($userId, array_values(array_map('trim', $validTags)));
        }

        // Store dates
        $dates = array_values(array_filter(
            $parsed['dates'] ?? [],
            fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($d))
        ));
        if (!empty($dates)) {
            $ctx      = $this->bridge->getContext($userId);
            $existing = $ctx['recentDates'] ?? [];
            $merged   = array_values(array_unique(array_merge(array_map('trim', $dates), $existing)));
            $this->bridge->updateContext($userId, ['recentDates' => array_slice($merged, 0, 10)]);
        }

        // Store domains
        $validDomains = array_filter(
            $parsed['domains'] ?? [],
            fn($d) => is_string($d) && mb_strlen(trim($d)) > 1
        );
        if (!empty($validDomains)) {
            $this->bridge->updateContext($userId, [
                'preferences' => ['recent_domains' => implode(',', array_slice(array_map('trim', array_values($validDomains)), 0, 3))],
            ]);
        }

        // Store intent
        $intent = trim($parsed['intent'] ?? '');
        if ($intent && $intent !== 'salutation') {
            $this->bridge->updateContext($userId, [
                'preferences' => ['last_intent' => $intent],
            ]);
        }
    }

    private function showContext(AgentContext $context): AgentResult
    {
        $ctx = $this->bridge->getContext($context->from);

        $lines = ["*Contexte partage — ContextMemoryBridge v{$this->version()}*", ''];

        $projects = $ctx['activeProjects'] ?? [];
        $lines[]  = "*Projets actifs:* " . ($projects ? implode(', ', $projects) : 'aucun');

        $tags    = $ctx['recentTags'] ?? [];
        $lines[] = "*Tags recents:* " . ($tags ? implode(', ', array_slice($tags, 0, 10)) : 'aucun');

        $dates = $ctx['recentDates'] ?? [];
        if ($dates) {
            $lines[] = "*Dates recentes:* " . implode(', ', $dates);
        }

        $lastAgentName = $ctx['lastAgent']['name'] ?? null;
        $lastAgentAt   = isset($ctx['lastAgent']['at']) ? ' (' . substr($ctx['lastAgent']['at'], 0, 16) . ')' : '';
        $lines[]       = "*Dernier agent:* " . ($lastAgentName ? "{$lastAgentName}{$lastAgentAt}" : 'aucun');

        $lines[] = "*Fuseau horaire:* " . ($ctx['timeZone'] ?? 'non defini');

        $prefs       = $ctx['preferences'] ?? [];
        $prefDisplay = [];
        if (!empty($prefs['last_intent'])) {
            $prefDisplay[] = "intention: " . $prefs['last_intent'];
        }
        if (!empty($prefs['recent_domains'])) {
            $prefDisplay[] = "domaines: " . $prefs['recent_domains'];
        }
        if (!empty($prefs['language'])) {
            $prefDisplay[] = "langue: " . $prefs['language'];
        }
        if ($prefDisplay) {
            $lines[] = "*Preferences:* " . implode(' | ', $prefDisplay);
        }

        $notes = $ctx['contextNotes'] ?? [];
        if ($notes) {
            $lines[] = '';
            $lines[] = "*Notes personnelles (" . count($notes) . "):*";
            foreach (array_slice($notes, -3) as $i => $note) {
                $num     = count($notes) - count(array_slice($notes, -3)) + $i + 1;
                $lines[] = "  {$num}. " . mb_substr($note['text'] ?? '', 0, 80);
            }
            if (count($notes) > 3) {
                $lines[] = "  _(voir toutes les notes: *mes notes*)_";
            }
        }

        $history = $ctx['conversationHistory'] ?? [];
        $lines[] = '';
        $lines[] = "*Historique inter-agents:* " . count($history) . " entree(s)";

        if (!empty($ctx['updated_at'])) {
            $lines[] = "*Mis a jour:* " . substr($ctx['updated_at'], 0, 16);
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function contextStats(AgentContext $context): AgentResult
    {
        $ctx     = $this->bridge->getContext($context->from);
        $history = $ctx['conversationHistory'] ?? [];
        $projects = $ctx['activeProjects'] ?? [];
        $tags    = $ctx['recentTags'] ?? [];
        $notes   = $ctx['contextNotes'] ?? [];
        $dates   = $ctx['recentDates'] ?? [];
        $prefs   = $ctx['preferences'] ?? [];

        $agentCount = [];
        foreach ($history as $entry) {
            $agent              = $entry['agent'] ?? 'inconnu';
            $agentCount[$agent] = ($agentCount[$agent] ?? 0) + 1;
        }
        arsort($agentCount);
        $topAgents = array_slice($agentCount, 0, 3, true);

        $lines   = ["*Statistiques du contexte partage*", ''];
        $lines[] = "Projets suivis: " . count($projects);
        $lines[] = "Tags memorises: " . count($tags);
        $lines[] = "Dates recentes: " . count($dates);
        $lines[] = "Notes personnelles: " . count($notes);
        $lines[] = "Entrees d'historique: " . count($history);
        $lines[] = "Fuseau horaire: " . ($ctx['timeZone'] ?? 'non defini');

        if (!empty($prefs['recent_domains'])) {
            $lines[] = "Domaines recents: " . $prefs['recent_domains'];
        }

        if (!empty($prefs['last_intent'])) {
            $lines[] = "Derniere intention: " . $prefs['last_intent'];
        }

        if ($topAgents) {
            $lines[] = '';
            $lines[] = "*Agents les plus utilises:*";
            foreach ($topAgents as $agent => $count) {
                $lines[] = "  - {$agent}: {$count} echange(s)";
            }
        }

        if (!empty($ctx['updated_at'])) {
            $lines[] = '';
            $lines[] = "Derniere activite: " . substr($ctx['updated_at'], 0, 16);
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    private function addProject(AgentContext $context, string $name): AgentResult
    {
        if (mb_strlen($name) < 2) {
            return AgentResult::reply("Nom de projet trop court. Ex: *ajoute projet ZeniClaw*");
        }

        if (mb_strlen($name) > 100) {
            return AgentResult::reply("Nom de projet trop long (max 100 caracteres).");
        }

        $this->bridge->addActiveProject($context->from, $name);

        $ctx      = $this->bridge->getContext($context->from);
        $projects = $ctx['activeProjects'] ?? [];

        return AgentResult::reply(
            "Projet \"{$name}\" ajoute au contexte.\n\nProjets actifs: " . implode(', ', $projects)
        );
    }

    private function removeProject(AgentContext $context, string $name): AgentResult
    {
        $ctx      = $this->bridge->getContext($context->from);
        $projects = $ctx['activeProjects'] ?? [];
        $nameLower = mb_strtolower($name);

        $filtered = array_values(array_filter(
            $projects,
            fn($p) => mb_strtolower($p) !== $nameLower
        ));

        if (count($filtered) === count($projects)) {
            return AgentResult::reply(
                "Projet \"{$name}\" introuvable dans le contexte.\n\nProjets actifs: " .
                (empty($projects) ? 'aucun' : implode(', ', $projects))
            );
        }

        $this->bridge->updateContext($context->from, ['activeProjects' => $filtered]);

        return AgentResult::reply(
            "Projet \"{$name}\" retire du contexte.\n\nProjets restants: " .
            (empty($filtered) ? 'aucun' : implode(', ', $filtered))
        );
    }

    private function removeTag(AgentContext $context, string $tag): AgentResult
    {
        $ctx      = $this->bridge->getContext($context->from);
        $tags     = $ctx['recentTags'] ?? [];
        $tagLower = mb_strtolower($tag);

        $filtered = array_values(array_filter(
            $tags,
            fn($t) => mb_strtolower($t) !== $tagLower
        ));

        if (count($filtered) === count($tags)) {
            return AgentResult::reply(
                "Tag \"{$tag}\" introuvable dans le contexte.\n\nTags recents: " .
                (empty($tags) ? 'aucun' : implode(', ', array_slice($tags, 0, 10)))
            );
        }

        $this->bridge->updateContext($context->from, ['recentTags' => $filtered]);

        return AgentResult::reply(
            "Tag \"{$tag}\" retire du contexte.\n\nTags restants: " .
            (empty($filtered) ? 'aucun' : implode(', ', array_slice($filtered, 0, 10)))
        );
    }

    private function addNote(AgentContext $context, string $text): AgentResult
    {
        if (mb_strlen($text) < 3) {
            return AgentResult::reply(
                "Note trop courte. Ajoutez un texte plus descriptif.\nEx: *note: je suis developpeur senior specialise Laravel*"
            );
        }

        if (mb_strlen($text) > 500) {
            return AgentResult::reply(
                "Note trop longue (max 500 caracteres). Votre note fait " . mb_strlen($text) . " caracteres."
            );
        }

        $ctx   = $this->bridge->getContext($context->from);
        $notes = $ctx['contextNotes'] ?? [];

        $notes[] = [
            'text' => $text,
            'at'   => now()->toIso8601String(),
        ];

        // Keep last 10 notes
        $notes = array_slice($notes, -10);

        $this->bridge->updateContext($context->from, ['contextNotes' => $notes]);

        $noteNumber = count($notes);
        return AgentResult::reply(
            "Note #{$noteNumber} enregistree dans le contexte :\n\"{$text}\"\n\nTotal notes: {$noteNumber} (envoyez *mes notes* pour les voir toutes)"
        );
    }

    private function listNotes(AgentContext $context): AgentResult
    {
        $ctx   = $this->bridge->getContext($context->from);
        $notes = $ctx['contextNotes'] ?? [];

        if (empty($notes)) {
            return AgentResult::reply(
                "Aucune note personnelle enregistree.\n\nPour en ajouter: *note: votre texte*"
            );
        }

        $lines   = ["*Vos notes personnelles (" . count($notes) . ")*", ''];

        foreach ($notes as $i => $note) {
            $num     = $i + 1;
            $at      = substr($note['at'] ?? '', 0, 10);
            $lines[] = "*{$num}.* {$note['text']}";
            $lines[] = "    _{$at}_";
            $lines[] = '';
        }

        $lines[] = "Pour supprimer: *supprime note <numero>*";

        return AgentResult::reply(rtrim(implode("\n", $lines)));
    }

    private function deleteNote(AgentContext $context, int $number): AgentResult
    {
        $ctx   = $this->bridge->getContext($context->from);
        $notes = $ctx['contextNotes'] ?? [];

        if (empty($notes)) {
            return AgentResult::reply("Aucune note a supprimer.");
        }

        $index = $number - 1;
        if ($index < 0 || $index >= count($notes)) {
            return AgentResult::reply(
                "Numero de note invalide. Vous avez " . count($notes) . " note(s) (numeros 1 a " . count($notes) . ").\n\nEnvoyez *mes notes* pour les voir."
            );
        }

        $deleted = $notes[$index];
        array_splice($notes, $index, 1);

        $this->bridge->updateContext($context->from, ['contextNotes' => $notes]);

        return AgentResult::reply(
            "Note #{$number} supprimee :\n\"" . mb_substr($deleted['text'], 0, 100) . "\"\n\nNotes restantes: " . count($notes)
        );
    }

    private function setTimezone(AgentContext $context, string $tz): AgentResult
    {
        // Validate timezone
        try {
            new \DateTimeZone($tz);
        } catch (\Exception $e) {
            // Suggest common ones
            $suggestions = 'Europe/Paris, America/New_York, America/Los_Angeles, Asia/Tokyo, Africa/Casablanca';
            return AgentResult::reply(
                "Fuseau horaire \"{$tz}\" invalide.\n\nExemples valides: {$suggestions}"
            );
        }

        $this->bridge->updateContext($context->from, ['timeZone' => $tz]);

        $localTime = now()->setTimezone($tz)->format('H:i');
        return AgentResult::reply(
            "Fuseau horaire defini: *{$tz}*\nHeure actuelle dans ce fuseau: {$localTime}"
        );
    }

    private function searchHistory(AgentContext $context, string $query): AgentResult
    {
        $ctx     = $this->bridge->getContext($context->from);
        $history = $ctx['conversationHistory'] ?? [];
        $notes   = $ctx['contextNotes'] ?? [];

        $queryLower   = mb_strtolower($query);
        $histMatches  = [];
        $noteMatches  = [];

        // Search in conversation history
        if (!empty($history)) {
            $histMatches = array_values(array_filter($history, function (array $entry) use ($queryLower) {
                return str_contains(mb_strtolower($entry['message'] ?? ''), $queryLower)
                    || str_contains(mb_strtolower($entry['reply'] ?? ''), $queryLower)
                    || str_contains(mb_strtolower($entry['agent'] ?? ''), $queryLower);
            }));
        }

        // Search in personal notes
        if (!empty($notes)) {
            foreach ($notes as $i => $note) {
                if (str_contains(mb_strtolower($note['text'] ?? ''), $queryLower)) {
                    $noteMatches[] = ['num' => $i + 1, 'note' => $note];
                }
            }
        }

        if (empty($histMatches) && empty($noteMatches)) {
            return AgentResult::reply("Aucun resultat pour \"{$query}\" dans l'historique ni dans les notes.");
        }

        $lines = ["*Resultats pour \"{$query}\"*", ''];

        if (!empty($noteMatches)) {
            $lines[] = "*Dans les notes (" . count($noteMatches) . "):*";
            foreach ($noteMatches as $match) {
                $at      = substr($match['note']['at'] ?? '', 0, 10);
                $lines[] = "  #{$match['num']} ({$at}): " . mb_substr($match['note']['text'], 0, 100);
            }
            $lines[] = '';
        }

        if (!empty($histMatches)) {
            $lines[] = "*Dans l'historique inter-agents (" . count($histMatches) . "):*";
            foreach (array_slice($histMatches, 0, 5) as $entry) {
                $at      = substr($entry['at'] ?? '', 0, 16);
                $msg     = mb_substr($entry['message'] ?? '', 0, 80);
                $rep     = mb_substr($entry['reply'] ?? '', 0, 80);
                $lines[] = "[{$entry['agent']}] {$at}";
                $lines[] = "  Msg: {$msg}";
                $lines[] = "  Rep: {$rep}";
                $lines[] = '';
            }
        }

        return AgentResult::reply(rtrim(implode("\n", $lines)));
    }

    private function showHistory(AgentContext $context): AgentResult
    {
        $ctx     = $this->bridge->getContext($context->from);
        $history = $ctx['conversationHistory'] ?? [];

        if (empty($history)) {
            return AgentResult::reply("Aucun historique inter-agents disponible.");
        }

        $lines = ["*Historique inter-agents (" . count($history) . " entree(s))*", ''];

        foreach (array_slice($history, -7) as $entry) {
            $at  = substr($entry['at'] ?? '', 0, 16);
            $msg = $entry['message'] ?? '';
            $rep = $entry['reply'] ?? '';

            // Only add ellipsis if actually truncated
            $msgDisplay = mb_strlen($msg) > 60 ? mb_substr($msg, 0, 60) . '...' : $msg;
            $repDisplay = mb_strlen($rep) > 60 ? mb_substr($rep, 0, 60) . '...' : $rep;

            $lines[] = "[{$entry['agent']}] {$at}";
            $lines[] = "  > {$msgDisplay}";
            $lines[] = "  < {$repDisplay}";
            $lines[] = '';
        }

        return AgentResult::reply(rtrim(implode("\n", $lines)));
    }

    private function showHelp(): AgentResult
    {
        $lines = [
            "*ContextMemoryBridge v{$this->version()} — Commandes*",
            '',
            "*Consultation*",
            "  affiche contexte — voir tout le contexte partage",
            "  historique contexte — voir l'historique inter-agents",
            "  stats contexte — statistiques d'utilisation",
            "  mes notes — lister toutes vos notes",
            '',
            "*Gestion des projets*",
            "  ajoute projet <nom> — ajouter un projet manuellement",
            "  retire projet <nom> — supprimer un projet actif",
            '',
            "*Gestion des notes*",
            "  note: <texte> — ajouter une note persistante",
            "  supprime note <N> — supprimer la note numero N",
            '',
            "*Autres*",
            "  retire tag <tag> — supprimer un tag",
            "  fuseau <timezone> — definir votre fuseau horaire",
            "  reset contexte — reinitialiser tout le contexte",
            '',
            "*Recherche*",
            "  recherche <terme> — chercher dans l'historique et les notes",
        ];

        return AgentResult::reply(implode("\n", $lines));
    }
}
