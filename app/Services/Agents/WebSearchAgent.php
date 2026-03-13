<?php

namespace App\Services\Agents;

use App\Models\ApiUsageLog;
use App\Models\AppSetting;
use App\Services\AgentContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchAgent extends BaseAgent implements AgentInterface
{
    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Recherche web en temps reel via Brave Search API. Cherche des infos actuelles, actualites, meteo, definitions, comparaisons, prix. Analyse en profondeur un sujet (10 resultats, synthese structuree). Recherche restreinte a un site (sur domain.com). Gere favoris (sauvegarder, supprimer, relancer), historique, tendances, stats API. Peut relancer la derniere recherche. Peut etre appele par d\'autres agents.';
    }

    public function keywords(): array
    {
        return [
            'cherche', 'recherche', 'google', 'search', 'trouve', 'find',
            'actualite', 'news', 'actu', 'quoi de neuf',
            'c\'est quoi', 'qu\'est-ce que', 'definition', 'who is', 'what is',
            'meteo', 'weather', 'temps qu\'il fait', 'temperature',
            'compare', 'vs', 'versus', 'difference entre',
            'prix de', 'combien coute', 'price of', 'tarif',
            'derniere version', 'latest', 'recent',
            'stats api', 'api usage', 'utilisation api', 'combien d\'appels',
            'historique recherche', 'mes recherches',
            'tendances', 'top recherches', 'plus recherches',
            'relance', 'repete', 'again',
            'effacer historique', 'clear history',
            'analyse', 'analyser', 'deep search', 'recherche approfondie',
            'favoris', 'sauvegarde', 'bookmark', 'mes favoris',
            'supprimer favori', 'effacer favori', 'remove favori',
            'site:', 'cherche sur', 'search on',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'web_search';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (!$body) {
            return $this->sendReply($context, $this->helpMessage());
        }

        $lower = mb_strtolower($body);

        // Help command
        if (preg_match('/^\s*(aide|help|\?)\s*$/iu', $lower)) {
            return $this->sendReply($context, $this->helpMessage());
        }

        // Stats commands
        if (preg_match('/\b(stats?\s*api|api\s*usage|utilisation\s*api|combien\s*d.appels|api\s*stats)\b/iu', $lower)) {
            $days = $this->extractDays($lower, 30);
            return $this->handleStats($context, $days);
        }

        // Favorites: list
        if (preg_match('/\b(mes\s+favoris?|liste\s+favoris?|voir\s+favoris?|favoris?\s+list)\b/iu', $lower)) {
            return $this->handleListFavorites($context);
        }

        // Favorites: run by number
        if (preg_match('/\b(?:relancer?\s+favori[ts]?\s+(\d+)|favori[ts]?\s+(\d+))\b/iu', $lower, $m)) {
            $num = (int) (!empty($m[1]) ? $m[1] : $m[2]);
            return $this->handleRunFavorite($context, $num);
        }

        // Favorites: delete by number
        if (preg_match('/\b(?:supprimer?\s+favori[ts]?\s+(\d+)|effacer?\s+favori[ts]?\s+(\d+)|remove\s+favori[ts]?\s+(\d+)|delete\s+favori[ts]?\s+(\d+))\b/iu', $lower, $m)) {
            $num = (int) (!empty($m[1]) ? $m[1] : (!empty($m[2]) ? $m[2] : (!empty($m[3]) ? $m[3] : $m[4])));
            return $this->handleDeleteFavorite($context, $num);
        }

        // Favorites: save with explicit query
        if (preg_match('/^\s*(?:sauvegard[eo][rz]?|ajouter?\s+(?:aux\s+)?favoris?|bookmark)\s+(.+)/iu', $body, $m)) {
            return $this->handleSaveFavorite($context, trim($m[1]));
        }

        // Favorites: save last search
        if (preg_match('/^\s*(?:sauvegard[eo][rz]?(?:\s+(?:cette\s+)?recherche)?|ajouter?\s+(?:aux\s+)?favoris?|bookmark(?:\s+this)?)\s*$/iu', $lower)) {
            return $this->handleSaveFavorite($context, null);
        }

        // History command
        if (preg_match('/\b(historique|mes\s+recherches|last\s+search(?:es)?|dernieres?\s+recherches?)\b/iu', $lower)
            && !preg_match('/\b(effacer?|supprimer?|clear|delete)\b/iu', $lower)) {
            $limit = $this->extractNumber($lower, 10);
            return $this->handleHistory($context, min($limit, 20));
        }

        // Clear history command
        if (preg_match('/\b(effacer?\s+(?:mon\s+)?historique|supprimer?\s+(?:mon\s+)?historique|clear\s+history|delete\s+history)\b/iu', $lower)) {
            return $this->handleClearHistory($context);
        }

        // Trends command
        if (preg_match('/\b(tendances?|top\s+recherches?|plus\s+recherch[eé]s?|mes\s+tendances?)\b/iu', $lower)) {
            return $this->handleTrends($context);
        }

        // Repeat last search
        if (preg_match('/^\s*(relancer?|r[eé]p[eè]ter?|again|recommence[rz]?)\s*$/iu', $lower)) {
            return $this->handleRepeatSearch($context);
        }

        // API key check
        $apiKey = AppSetting::get('brave_search_api_key');
        if (!$apiKey) {
            $reply = "La cle API Brave Search n'est pas configuree.\n\n"
                . "Configure-la dans les settings:\n"
                . "  Cle: `brave_search_api_key`\n"
                . "  Gratuit: https://api.search.brave.com/register";
            return $this->sendReply($context, $reply);
        }

        // Detect deep analysis mode
        $isDeep = (bool) preg_match('/\b(analys[eo][rz]?|analyse\s+approfondie?|deep\s+search|recherche\s+approfondie?|detail[lé][eé]?)\b/iu', $lower);

        // Extract optional custom result count from query
        ['count' => $customCount, 'query' => $cleanQuery] = $this->extractCountAndQuery($body);

        // Strip deep analysis keywords from the start of the query
        if ($isDeep) {
            $cleanQuery = preg_replace('/^\s*(?:analys[eo][rz]?|analyse\s+approfondie?|deep\s+search|recherche\s+approfondie?|detail[lé][eé]?)\s+/iu', '', $cleanQuery);
            $cleanQuery = trim($cleanQuery);
        }

        if (!$cleanQuery) {
            return $this->sendReply($context, $this->helpMessage());
        }

        // Detect search type and language
        $cleanLower = mb_strtolower($cleanQuery);
        $searchType = $isDeep ? 'analysis' : $this->detectSearchType($cleanLower);

        // Strip leading command keywords (e.g. "cherche", "actu", "c'est quoi")
        $cleanQuery = $this->stripSearchKeywords($cleanQuery, $searchType);
        $cleanLower = mb_strtolower($cleanQuery);

        // Validate minimum query length
        if (mb_strlen($cleanQuery) < 2) {
            return $this->sendReply($context, $this->helpMessage());
        }

        // Site-restricted search: "sur domain.com sujet" or "on domain.com subject"
        if (preg_match('/^\s*(?:sur|on)\s+((?:[\w-]+\.)+[a-z]{2,})\s+(.+)/iu', $cleanQuery, $sm)) {
            $siteDomain = mb_strtolower(trim($sm[1]));
            $siteQuery  = trim($sm[2]);
            $cleanQuery = "site:{$siteDomain} {$siteQuery}";
            $cleanLower = mb_strtolower($cleanQuery);
        }

        $searchLang = $this->detectLanguage($cleanLower, $cleanQuery);
        $freshness  = $this->detectFreshness($searchType);
        $defaultCount = match ($searchType) {
            'news'     => 8,
            'analysis' => 10,
            default    => 5,
        };
        $count = $customCount ?? $defaultCount;

        return $this->executeSearch($context, $cleanQuery, $searchType, $searchLang, $apiKey, $freshness, $count);
    }

    // ── Public API for cross-agent calls ──

    /**
     * Search the web — callable by other agents.
     */
    public static function searchFor(
        string $query,
        string $callerAgent,
        int $agentId,
        ?string $phone = null,
        int $count = 5
    ): ?array {
        $apiKey = AppSetting::get('brave_search_api_key');
        if (!$apiKey) {
            Log::warning('WebSearchAgent::searchFor — no API key configured');
            return null;
        }

        $instance = new self();
        return $instance->executeBraveSearch($query, $apiKey, $callerAgent, $agentId, $phone, $count);
    }

    /**
     * Get a formatted text summary of search results — for other agents to inject into prompts.
     */
    public static function searchAndSummarize(
        string $query,
        string $callerAgent,
        int $agentId,
        ?string $phone = null,
        int $count = 5
    ): ?string {
        $results = self::searchFor($query, $callerAgent, $agentId, $phone, $count);
        if (!$results) return null;

        $lines = [];
        foreach ($results as $i => $r) {
            $lines[] = ($i + 1) . ". {$r['title']}";
            if (!empty($r['description'])) {
                $lines[] = "   {$r['description']}";
            }
            if (!empty($r['url'])) {
                $lines[] = "   -> {$r['url']}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get API usage stats — callable by other agents or admin.
     */
    public static function getUsageStats(?int $agentId = null, int $days = 30): array
    {
        $query = ApiUsageLog::where('created_at', '>=', now()->subDays($days));
        if ($agentId) {
            $query->where('agent_id', $agentId);
        }

        $logs = $query->get();

        $totalCalls      = $logs->count();
        $successCalls    = $logs->where('response_status', '>=', 200)->where('response_status', '<', 300)->count();
        $errorCalls      = $logs->whereNotNull('error_message')->count();
        $avgResponseTime = $logs->avg('response_time_ms');
        $totalResults    = $logs->sum('result_count');

        $byApi    = $logs->groupBy('api_name')->map(fn($g) => $g->count())->sortDesc()->all();
        $byCaller = $logs->groupBy('caller_agent')->map(fn($g) => $g->count())->sortDesc()->all();
        $byDay    = $logs->groupBy(fn($l) => $l->created_at->format('Y-m-d'))->map(fn($g) => $g->count())->all();

        return [
            'period_days'            => $days,
            'total_calls'            => $totalCalls,
            'success_calls'          => $successCalls,
            'error_calls'            => $errorCalls,
            'success_rate'           => $totalCalls > 0 ? round(($successCalls / $totalCalls) * 100, 1) : 0,
            'avg_response_time_ms'   => $avgResponseTime ? round($avgResponseTime) : 0,
            'total_results_returned' => $totalResults,
            'calls_by_api'           => $byApi,
            'calls_by_agent'         => $byCaller,
            'calls_by_day'           => $byDay,
        ];
    }

    // ── Private methods ──

    private function sendReply(AgentContext $context, string $reply): AgentResult
    {
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function helpMessage(): string
    {
        return "*Recherche Web* — Commandes disponibles\n\n"
            . "*Rechercher:*\n"
            . "  cherche <sujet>\n"
            . "  cherche 3 resultats <sujet>  _(1-20 resultats)_\n"
            . "  actu <sujet>  _(actualites recentes)_\n"
            . "  c'est quoi <terme>  _(definition)_\n"
            . "  compare <A> vs <B>\n"
            . "  prix de <produit>\n"
            . "  meteo <ville>\n"
            . "  analyse <sujet>  _(synthese approfondie, 10 resultats)_\n"
            . "  cherche sur github.com <sujet>  _(site specifique)_\n\n"
            . "*Favoris:*\n"
            . "  sauvegarde  _(sauvegarde la derniere recherche)_\n"
            . "  mes favoris  _(liste tes favoris)_\n"
            . "  relance favori 2  _(rejoue le favori n°2)_\n"
            . "  supprimer favori 2  _(supprime le favori n°2)_\n\n"
            . "*Historique & tendances:*\n"
            . "  historique  _(10 dernieres recherches)_\n"
            . "  historique 20  _(jusqu'a 20 recherches)_\n"
            . "  tendances  _(tes recherches les plus frequentes)_\n"
            . "  relance  _(repete la derniere recherche)_\n"
            . "  effacer historique  _(supprimer tout l'historique)_\n\n"
            . "*Stats API:*\n"
            . "  stats api\n"
            . "  stats api 7j  _(7 derniers jours)_\n"
            . "  stats api 90j  _(90 derniers jours)_\n\n"
            . "_Astuce: ajoute \"en anglais\" ou \"en francais\" pour forcer la langue des resultats._";
    }

    private function detectSearchType(string $lower): string
    {
        if (preg_match('/\b(actu|actualit[eé]?s?|news|quoi de neuf|dernieres?\s+nouvelles|breaking)\b/iu', $lower)) {
            return 'news';
        }
        if (preg_match('/\b(meteo|weather|temps\s+qu.il\s+fait|temperature|pluie|soleil|precipitation|prevision)\b/iu', $lower)) {
            return 'weather';
        }
        if (preg_match('/\b(c.est\s+quoi|qu.est.ce\s+que|definition|qui\s+est|who\s+is|what\s+is|wiki|signifie|signification)\b/iu', $lower)) {
            return 'definition';
        }
        if (preg_match('/\b(compare|vs\.?|versus|difference\s+entre|comparaison|meilleur\s+entre|quel\s+est\s+le\s+meilleur)\b/iu', $lower)) {
            return 'compare';
        }
        if (preg_match('/\b(prix\s+de|combien\s+coute|price\s+of|tarif|cost\s+of|acheter|combien\s+vaut)\b/iu', $lower)) {
            return 'price';
        }
        return 'web';
    }

    /**
     * Determine Brave Search freshness parameter based on search type.
     * 'pd' = past day, 'pw' = past week, 'pm' = past month, null = any time.
     */
    private function detectFreshness(string $type): ?string
    {
        return match ($type) {
            'news'    => 'pw', // news: past week
            'weather' => 'pd', // weather: past day (most recent conditions)
            default   => null,
        };
    }

    private function detectLanguage(string $lower, string $body): string
    {
        // Force English if explicitly requested
        if (preg_match('/\b(en\s+anglais|in\s+english|english\s+results?)\b/iu', $lower)) {
            return 'en';
        }

        // Force French if explicitly requested
        if (preg_match('/\b(en\s+fran[cç]ais|french\s+results?)\b/iu', $lower)) {
            return 'fr';
        }

        // Detect if query is mostly French (heuristic: presence of common French words)
        $frenchIndicators = [
            'le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'est', 'sont',
            'je', 'tu', 'il', 'nous', 'vous', 'ils', 'et', 'ou', 'pas', 'que', 'qui',
            'mon', 'ton', 'son', 'ma', 'ta', 'sa', 'pour', 'sur', 'dans', 'avec',
        ];
        $words       = preg_split('/\s+/', $lower);
        $frenchCount = count(array_intersect($words, $frenchIndicators));

        return $frenchCount > 0 ? 'fr' : 'en';
    }

    private function extractDays(string $lower, int $default): int
    {
        if (preg_match('/(\d+)\s*j(?:ours?)?\b/iu', $lower, $m)) {
            return max(1, min(365, (int) $m[1]));
        }
        if (preg_match('/\b(\d+)\s*(?:days?|jours?)\b/iu', $lower, $m)) {
            return max(1, min(365, (int) $m[1]));
        }
        return $default;
    }

    private function extractNumber(string $lower, int $default): int
    {
        if (preg_match_all('/\b(\d+)\b/', $lower, $m)) {
            $valid = array_filter(array_map('intval', $m[1]), fn($n) => $n >= 1 && $n <= 100);
            if ($valid) return end($valid);
        }
        return $default;
    }

    /**
     * Extract an optional result count prefix from a query.
     * e.g. "cherche 5 resultats sur intelligence artificielle"
     *   → ['count' => 5, 'query' => 'intelligence artificielle']
     */
    private function extractCountAndQuery(string $body): array
    {
        if (preg_match('/\b(\d+)\s+r[eé]sultats?\s+(?:sur\s+|pour\s+|de\s+)?(.+)/iu', $body, $m)) {
            return [
                'count' => min(20, max(1, (int) $m[1])),
                'query' => trim($m[2]),
            ];
        }
        return ['count' => null, 'query' => $body];
    }

    // ── Favorites ──

    private function getFavoritesKey(string $phone): string
    {
        return 'web_search_fav_' . md5($phone);
    }

    private function getFavorites(string $phone): array
    {
        $raw = AppSetting::get($this->getFavoritesKey($phone));
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveFavorites(string $phone, array $favorites): void
    {
        AppSetting::set($this->getFavoritesKey($phone), json_encode(array_values($favorites)));
    }

    private function handleListFavorites(AgentContext $context): AgentResult
    {
        $favorites = $this->getFavorites($context->from);

        if (empty($favorites)) {
            $reply = "Aucun favori sauvegarde.\n\nAjoute-en avec: *sauvegarde* apres une recherche.";
            return $this->sendReply($context, $reply);
        }

        $total = count($favorites);
        $lines = ["*Tes recherches favorites* ({$total})\n"];
        foreach ($favorites as $i => $fav) {
            $date  = isset($fav['saved_at']) ? date('d/m', strtotime($fav['saved_at'])) : '';
            $type  = (isset($fav['type']) && $fav['type'] !== 'web') ? " _[{$fav['type']}]_" : '';
            $lines[] = ($i + 1) . ". *" . mb_substr($fav['query'], 0, 50) . "*{$type}";
            if ($date) {
                $lines[] = "   Sauvegarde le {$date}";
            }
        }
        $lines[] = "\n_Tape *relance favori <n>* pour relancer l'un d'eux._";

        $reply = implode("\n", $lines);
        return $this->sendReply($context, $reply);
    }

    private function handleSaveFavorite(AgentContext $context, ?string $explicitQuery): AgentResult
    {
        // If no explicit query, use the last search
        if (!$explicitQuery) {
            $last = ApiUsageLog::where('requester_phone', $context->from)
                ->where('api_name', 'brave_search')
                ->where('caller_agent', 'web_search')
                ->whereNull('error_message')
                ->orderByDesc('created_at')
                ->first(['request_params', 'created_at']);

            if (!$last) {
                return $this->sendReply($context, "Aucune recherche recente a sauvegarder.\n\nFais d'abord une recherche: *cherche <sujet>*");
            }

            $params        = is_array($last->request_params) ? $last->request_params : [];
            $explicitQuery = $params['q'] ?? null;

            if (!$explicitQuery) {
                return $this->sendReply($context, "Impossible de recuperer la derniere recherche.");
            }
        }

        $favorites = $this->getFavorites($context->from);

        // Avoid duplicates (case-insensitive)
        foreach ($favorites as $fav) {
            if (mb_strtolower($fav['query']) === mb_strtolower($explicitQuery)) {
                return $this->sendReply($context, "Cette recherche est deja dans tes favoris: *{$fav['query']}*");
            }
        }

        // Max 20 favorites
        if (count($favorites) >= 20) {
            return $this->sendReply($context, "Tu as atteint la limite de 20 favoris.\n\nSupprime-en un avec: *supprimer favori <n>*\nVoir la liste: *mes favoris*");
        }

        $lower    = mb_strtolower($explicitQuery);
        $type     = $this->detectSearchType($lower);
        $favorites[] = [
            'query'    => $explicitQuery,
            'type'     => $type,
            'saved_at' => now()->toIso8601String(),
        ];

        $this->saveFavorites($context->from, $favorites);
        $num   = count($favorites);
        $reply = "Favori #{$num} sauvegarde: *{$explicitQuery}*\n\nRelance avec: *relance favori {$num}*\nVoir tous: *mes favoris*";
        return $this->sendReply($context, $reply);
    }

    private function handleRunFavorite(AgentContext $context, int $num): AgentResult
    {
        $favorites = $this->getFavorites($context->from);

        if (empty($favorites)) {
            return $this->sendReply($context, "Aucun favori sauvegarde.\n\nAjoute-en avec: *sauvegarde* apres une recherche.");
        }

        $idx = $num - 1;
        if ($idx < 0 || $idx >= count($favorites)) {
            $max = count($favorites);
            return $this->sendReply($context, "Favori #{$num} introuvable. Tu as {$max} favori(s). Tape *mes favoris* pour voir la liste.");
        }

        $fav    = $favorites[$idx];
        $query  = $fav['query'];
        $apiKey = AppSetting::get('brave_search_api_key');

        if (!$apiKey) {
            return $this->sendReply($context, "La cle API Brave Search n'est pas configuree. (`brave_search_api_key`)");
        }

        $this->sendText($context->from, "Favori #{$num}: *{$query}*");

        $lower      = mb_strtolower($query);
        $searchType = $this->detectSearchType($lower);
        $searchLang = $this->detectLanguage($lower, $query);
        $freshness  = $this->detectFreshness($searchType);
        $count      = ($searchType === 'news') ? 8 : 5;

        return $this->executeSearch($context, $query, $searchType, $searchLang, $apiKey, $freshness, $count);
    }

    // ── Core search ──

    /**
     * Central method that executes a search and formats/sends the reply.
     */
    private function executeSearch(
        AgentContext $context,
        string $query,
        string $type,
        string $lang,
        string $apiKey,
        ?string $freshness,
        int $count
    ): AgentResult {
        $braveType = ($type === 'news') ? 'news' : 'web';
        $results   = $this->executeBraveSearch(
            $query, $apiKey, 'web_search', $context->agent->id,
            $context->from, $count, $braveType, $lang, $freshness
        );

        if ($results === null) {
            // Check if it might be an auth error by re-inspecting logs
            $lastLog = \App\Models\ApiUsageLog::where('agent_id', $context->agent->id)
                ->whereIn('response_status', [401, 403])
                ->where('created_at', '>=', now()->subMinutes(1))
                ->exists();

            if ($lastLog) {
                $reply = "La cle API Brave Search semble invalide ou expiree.\n\n"
                    . "Mets a jour la cle dans les settings: `brave_search_api_key`\n"
                    . "Obtiens une cle gratuite: https://api.search.brave.com/register";
            } else {
                $reply = "Desole, la recherche a echoue (erreur reseau ou limite atteinte).\n\nReessaie dans un instant ou tape *aide* pour les commandes disponibles.";
            }
            return $this->sendReply($context, $reply);
        }

        if (empty($results)) {
            $reply = "Aucun resultat trouve pour: *{$query}*\n\nEssaie une formulation differente ou elargis ta recherche.";
            return $this->sendReply($context, $reply);
        }

        $reply = $this->formatResults($context, $query, $results, $type, $lang);

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reply sent (web_search)', [
            'model'        => $this->resolveModel($context),
            'routed_agent' => $context->routedAgent,
            'search_type'  => $type,
            'search_lang'  => $lang,
            'result_count' => count($results),
            'reply'        => mb_substr($reply, 0, 200),
        ]);

        return AgentResult::reply($reply, [
            'model'        => $this->resolveModel($context),
            'search_type'  => $type,
            'search_lang'  => $lang,
            'result_count' => count($results),
        ]);
    }

    private function executeBraveSearch(
        string $query,
        string $apiKey,
        string $callerAgent,
        int $agentId,
        ?string $phone,
        int $count = 5,
        string $type = 'web',
        string $lang = 'fr',
        ?string $freshness = null
    ): ?array {
        $endpoint = ($type === 'news')
            ? 'https://api.search.brave.com/res/v1/news/search'
            : 'https://api.search.brave.com/res/v1/web/search';

        $params = [
            'q'           => $query,
            'count'       => $count,
            'search_lang' => $lang,
            'ui_lang'     => $lang === 'fr' ? 'fr-FR' : 'en-US',
            'safesearch'  => 'moderate',
        ];

        if ($freshness) {
            $params['freshness'] = $freshness;
        }

        $start       = microtime(true);
        $status      = null;
        $error       = null;
        $resultCount = 0;
        $results     = [];

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept'               => 'application/json',
                    'Accept-Encoding'      => 'gzip',
                    'X-Subscription-Token' => $apiKey,
                ])
                ->get($endpoint, $params);

            $status  = $response->status();
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            if ($status === 401 || $status === 403) {
                // Invalid or expired API key
                $error = "HTTP {$status} (cle API invalide ou expiree)";
                $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
                Log::error('WebSearchAgent: Brave API auth error', ['status' => $status]);
                return null;
            }

            if ($status === 422) {
                // Quota or invalid param — log and return empty
                $error = "HTTP 422 (quota ou parametre invalide): " . mb_substr($response->body(), 0, 200);
                $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
                Log::warning('WebSearchAgent: Brave API 422', ['body' => mb_substr($response->body(), 0, 300)]);
                return [];
            }

            if ($status === 429) {
                // Rate limit — treat as transient failure
                $error = "HTTP 429 (limite de debit atteinte)";
                $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
                Log::warning('WebSearchAgent: Brave API rate limit hit');
                return null;
            }

            if (!$response->successful()) {
                $error = "HTTP {$status}: " . mb_substr($response->body(), 0, 200);
                $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
                Log::warning('WebSearchAgent: Brave API error', ['status' => $status, 'body' => $response->body()]);
                return null;
            }

            $data = $response->json();

            if ($type === 'news') {
                $items = $data['results'] ?? [];
            } else {
                $items = $data['web']['results'] ?? [];
            }

            foreach ($items as $item) {
                $results[] = [
                    'title'       => $item['title'] ?? '',
                    'url'         => $item['url'] ?? '',
                    'description' => $item['description'] ?? $item['meta_url']['hostname'] ?? '',
                    'age'         => $item['age'] ?? null,
                    'source'      => $item['meta_url']['hostname'] ?? parse_url($item['url'] ?? '', PHP_URL_HOST) ?? '',
                ];
            }

            $resultCount = count($results);
            $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, $resultCount, null);

            return $results;

        } catch (\Exception $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            $error   = $e->getMessage();
            $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
            Log::error('WebSearchAgent: exception', ['error' => $error]);
            return null;
        }
    }

    private function logApiCall(
        int $agentId,
        ?string $phone,
        string $callerAgent,
        string $apiName,
        string $endpoint,
        array $params,
        ?int $status,
        int $elapsed,
        int $resultCount,
        ?string $error
    ): void {
        try {
            ApiUsageLog::create([
                'agent_id'         => $agentId,
                'requester_phone'  => $phone,
                'caller_agent'     => $callerAgent,
                'api_name'         => $apiName,
                'endpoint'         => $endpoint,
                'method'           => 'GET',
                'request_params'   => $params,
                'response_status'  => $status,
                'response_time_ms' => $elapsed,
                'result_count'     => $resultCount,
                'error_message'    => $error,
            ]);
        } catch (\Exception $e) {
            Log::warning('WebSearchAgent: failed to log API call', ['error' => $e->getMessage()]);
        }
    }

    private function formatResults(AgentContext $context, string $query, array $results, string $type, string $lang = 'fr'): string
    {
        $model = $this->resolveModel($context);

        // Build context for Claude to summarize
        $resultText = '';
        foreach ($results as $i => $r) {
            $resultText .= ($i + 1) . ". [{$r['title']}]({$r['url']})\n";
            if (!empty($r['source'])) {
                $resultText .= "   Source: {$r['source']}\n";
            }
            if (!empty($r['description'])) {
                $resultText .= "   {$r['description']}\n";
            }
            if (!empty($r['age'])) {
                $resultText .= "   (il y a {$r['age']})\n";
            }
            $resultText .= "\n";
        }

        $replyLang = $lang === 'en' ? 'en anglais' : 'en francais';

        $typeInstruction = match ($type) {
            'news'       => "Resume les actualites en 3-5 points cles avec date si disponible. Commence chaque point par une puce (·). Cite les sources (_nom_).",
            'definition' => "Donne une definition claire et concise en 2-4 phrases. Si c'est une personne/organisation, resume qui c'est. Cite la source la plus fiable.",
            'compare'    => "Structure la comparaison: *Option A* puis *Option B* avec 2-3 points cles chacun. Conclus avec une recommandation courte si possible.",
            'price'      => "Liste les prix trouves avec source et date entre parentheses. Si plusieurs vendeurs, donne une fourchette. Precise si c'est neuf/occasion.",
            'weather'    => "Donne les conditions actuelles (temperature, ciel, humidite si dispo) et previsions 2-3 jours. Utilise des emojis meteo (☀️🌧️❄️🌤️⛅🌩️). Cite la ville.",
            'analysis'   => "Fais une synthese approfondie et structuree en sections (*Titre de section*). Couvre les aspects cles, les faits importants, les debats existants et les perspectives. Cite tes sources importantes (_source_). Vise 400-500 mots.",
            default      => "Reponds directement a la question en 3-5 phrases. Cite 1-2 sources entre parentheses (_source_). Commence par l'information la plus importante.",
        };

        $wordLimit = ($type === 'analysis') ? '500 mots max' : '350 mots max';

        $systemPrompt = <<<PROMPT
Tu es ZeniClaw, un assistant WhatsApp. Reponds {$replyLang}.

FORMATAGE WHATSAPP:
- *gras* pour titres et mots importants
- _italique_ pour sources et precisions
- Emojis pertinents avec moderation (max 4)
- {$wordLimit}, sois direct et informatif
- URLs completes uniquement si vraiment utiles
- Commence DIRECTEMENT par la reponse, sans phrase d'intro comme "Voici..." ou "D'apres les resultats..."

CONTENU:
{$typeInstruction}
PROMPT;

        $userMessage = "Question: {$query}\n\nResultats de recherche:\n{$resultText}";

        $reply = $this->claude->chat($userMessage, $model, $systemPrompt);

        if (!$reply) {
            // Fallback: plain formatted list
            $lines = ["*Resultats pour:* {$query}\n"];
            foreach ($results as $i => $r) {
                $lines[] = ($i + 1) . ". *{$r['title']}*";
                if (!empty($r['description'])) {
                    $lines[] = "   " . mb_substr($r['description'], 0, 120);
                }
                $lines[] = "   {$r['url']}";
            }
            return implode("\n", $lines);
        }

        return $reply;
    }

    private function handleStats(AgentContext $context, int $days = 30): AgentResult
    {
        $stats = self::getUsageStats($context->agent->id, $days);

        $todayCount = ApiUsageLog::where('agent_id', $context->agent->id)
            ->whereDate('created_at', today())
            ->count();

        $lines = ["*Stats API — {$days} derniers jours*\n"];
        $lines[] = "Aujourd'hui: *{$todayCount}* appels";
        $lines[] = "Total periode: *{$stats['total_calls']}*";
        $lines[] = "Succes: *{$stats['success_calls']}* ({$stats['success_rate']}%)";
        $lines[] = "Erreurs: *{$stats['error_calls']}*";
        $lines[] = "Temps moyen: *{$stats['avg_response_time_ms']}ms*";
        $lines[] = "Resultats retournes: *{$stats['total_results_returned']}*";

        if (!empty($stats['calls_by_api'])) {
            $lines[] = "\n*Par API:*";
            foreach ($stats['calls_by_api'] as $api => $count) {
                $lines[] = "  · {$api}: {$count}";
            }
        }

        if (!empty($stats['calls_by_agent'])) {
            $lines[] = "\n*Par agent appelant:*";
            foreach ($stats['calls_by_agent'] as $agent => $count) {
                $lines[] = "  · {$agent}: {$count}";
            }
        }

        if (!empty($stats['calls_by_day'])) {
            $recent = array_slice($stats['calls_by_day'], -7, 7, true);
            $lines[] = "\n*7 derniers jours:*";
            foreach ($recent as $day => $count) {
                $lines[] = "  · {$day}: {$count} appels";
            }
        }

        if ($stats['total_calls'] === 0) {
            $lines[] = "\n_Aucun appel API enregistre sur cette periode._";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['stats' => $stats]);
    }

    private function handleHistory(AgentContext $context, int $limit = 10): AgentResult
    {
        $logs = ApiUsageLog::where('requester_phone', $context->from)
            ->where('api_name', 'brave_search')
            ->whereNull('error_message')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['request_params', 'result_count', 'created_at', 'caller_agent']);

        if ($logs->isEmpty()) {
            $reply = "Aucune recherche enregistree pour ce compte.\n\nLance ta premiere recherche: *cherche <sujet>*";
            return $this->sendReply($context, $reply);
        }

        $lines = ["*Historique — {$logs->count()} dernieres recherches*\n"];
        foreach ($logs as $i => $log) {
            $params = is_array($log->request_params) ? $log->request_params : [];
            $query  = $params['q'] ?? '(inconnu)';
            $date   = $log->created_at->format('d/m H:i');
            $count  = $log->result_count ?? 0;
            $caller = ($log->caller_agent !== 'web_search') ? " _[{$log->caller_agent}]_" : '';
            $lines[] = ($i + 1) . ". *" . mb_substr($query, 0, 50) . "*";
            $lines[] = "   {$date} · {$count} resultats{$caller}";
        }

        $lines[] = "\n_Tape *relance* pour repeter la derniere. *sauvegarde* pour mettre en favori._";

        $reply = implode("\n", $lines);
        return $this->sendReply($context, $reply);
    }

    /**
     * Show user's most frequently searched queries (top 5).
     */
    private function handleTrends(AgentContext $context): AgentResult
    {
        $logs = ApiUsageLog::where('requester_phone', $context->from)
            ->where('api_name', 'brave_search')
            ->whereNull('error_message')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['request_params']);

        if ($logs->isEmpty()) {
            $reply = "Aucune recherche enregistree pour ce compte.\n\nLance ta premiere recherche: *cherche <sujet>*";
            return $this->sendReply($context, $reply);
        }

        // Count query frequency
        $counts = [];
        foreach ($logs as $log) {
            $params = is_array($log->request_params) ? $log->request_params : [];
            $q      = mb_strtolower(trim($params['q'] ?? ''));
            if ($q) {
                $counts[$q] = ($counts[$q] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = array_slice($counts, 0, 5, true);

        $lines = ["*Tes recherches les plus frequentes*\n"];
        $rank  = 1;
        foreach ($top as $query => $freq) {
            $times = $freq === 1 ? '1 fois' : "{$freq} fois";
            $medal = match ($rank) {
                1 => '🥇',
                2 => '🥈',
                3 => '🥉',
                default => "{$rank}.",
            };
            $lines[] = "{$medal} *" . mb_substr($query, 0, 50) . "* _({$times})_";
            $rank++;
        }

        if (count($counts) > 5) {
            $lines[] = "\n_" . count($counts) . " recherches uniques au total._";
        }

        $lines[] = "_Tape *sauvegarde* apres une recherche pour l'ajouter a tes favoris._";

        $reply = implode("\n", $lines);
        return $this->sendReply($context, $reply);
    }

    /**
     * Re-execute the user's last search query.
     */
    private function handleRepeatSearch(AgentContext $context): AgentResult
    {
        $last = ApiUsageLog::where('requester_phone', $context->from)
            ->where('api_name', 'brave_search')
            ->where('caller_agent', 'web_search')
            ->whereNull('error_message')
            ->orderByDesc('created_at')
            ->first(['request_params', 'created_at']);

        if (!$last) {
            return $this->sendReply($context, "Aucune recherche precedente a relancer.\n\nLance une recherche: *cherche <sujet>*");
        }

        $params = is_array($last->request_params) ? $last->request_params : [];
        $query  = $params['q'] ?? null;

        if (!$query) {
            return $this->sendReply($context, "Impossible de recuperer la derniere recherche.");
        }

        $apiKey = AppSetting::get('brave_search_api_key');
        if (!$apiKey) {
            return $this->sendReply($context, "La cle API Brave Search n'est pas configuree. (`brave_search_api_key`)");
        }

        $age = $last->created_at->diffForHumans();
        $this->sendText($context->from, "Relance: *{$query}* _(il y a {$age})_");

        $lower      = mb_strtolower($query);
        $searchType = $this->detectSearchType($lower);
        $searchLang = $this->detectLanguage($lower, $query);
        $freshness  = $this->detectFreshness($searchType);
        $count      = ($searchType === 'news') ? 8 : 5;

        return $this->executeSearch($context, $query, $searchType, $searchLang, $apiKey, $freshness, $count);
    }

    /**
     * Strip leading command/intent keywords from a query before sending to Brave.
     * e.g. "cherche intelligence artificielle" → "intelligence artificielle"
     *      "c'est quoi le Bitcoin" → "le Bitcoin"
     *      "actu guerre Ukraine" → "guerre Ukraine"
     */
    private function stripSearchKeywords(string $query, string $type): string
    {
        $typePatterns = [
            'news'       => '/^\s*(?:actu(?:alit[eé]s?)?\s+|news\s+|quoi\s+de\s+neuf\s+|les?\s+actu\s+|dernieres?\s+nouvelles\s+(?:sur\s+|de\s+)?)/iu',
            'weather'    => '/^\s*(?:m[eé]t[eé]o\s+(?:[aà]\s+|de\s+|pour\s+|en\s+)?|weather\s+(?:in\s+|for\s+)?|temps\s+(?:qu.il\s+fait\s+)?(?:[aà]\s+)?)/iu',
            'definition' => '/^\s*(?:c.est\s+quoi\s+(?:le\s+|la\s+|les\s+|un\s+|une\s+|l.)?|qu.est.ce\s+que\s+(?:c.est\s+(?:que\s+)?)?(?:le\s+|la\s+|un\s+|une\s+|l.)?|definition\s+(?:de\s+(?:la\s+|du\s+|des\s+|l.)?|d.)?|qui\s+est\s+|what\s+is\s+(?:a\s+|an\s+)?|who\s+is\s+)/iu',
            'compare'    => '/^\s*(?:compare[rz]?\s+|comparaison\s+(?:entre\s+)?|difference\s+entre\s+|comparer?\s+)/iu',
            'price'      => '/^\s*(?:prix\s+d(?:e\s+(?:la\s+|du\s+|des?\s+|l.)?|u\s+|es\s+)?|combien\s+co[uû]te\s+(?:le\s+|la\s+|un\s+|une\s+)?|combien\s+vaut\s+(?:le\s+|la\s+|un\s+|une\s+)?|tarif\s+(?:de\s+(?:la\s+|du\s+|des?\s+|l.)?)?)/iu',
        ];

        // Try type-specific pattern first
        if (isset($typePatterns[$type])) {
            $cleaned = preg_replace($typePatterns[$type], '', $query);
            if ($cleaned !== null && trim($cleaned) !== '') {
                $query = trim($cleaned);
            }
        }

        // Then try generic search verb prefix
        $genericPattern = '/^\s*(?:cherche[rz]?\s+|recherche[rz]?\s+|trouve[rz]?\s+|googl[eo][rz]?\s+|search\s+(?:for\s+)?|find\s+(?:me\s+)?)/iu';
        $cleaned = preg_replace($genericPattern, '', $query);
        if ($cleaned !== null && trim($cleaned) !== '') {
            $query = trim($cleaned);
        }

        return $query;
    }

    /**
     * Remove a specific favorite by its 1-based index.
     */
    private function handleDeleteFavorite(AgentContext $context, int $num): AgentResult
    {
        $favorites = $this->getFavorites($context->from);

        if (empty($favorites)) {
            return $this->sendReply($context, "Aucun favori sauvegarde.\n\nAjoute-en avec: *sauvegarde* apres une recherche.");
        }

        $idx = $num - 1;
        if ($idx < 0 || $idx >= count($favorites)) {
            $max = count($favorites);
            return $this->sendReply($context, "Favori #{$num} introuvable. Tu as {$max} favori(s). Tape *mes favoris* pour voir la liste.");
        }

        $deleted  = $favorites[$idx];
        array_splice($favorites, $idx, 1);
        $this->saveFavorites($context->from, $favorites);

        $remaining = count($favorites);
        $reply = "Favori #{$num} supprime: *{$deleted['query']}*\n\n"
            . ($remaining > 0
                ? "_Il te reste {$remaining} favori(s). Tape *mes favoris* pour voir la liste._"
                : "_Tu n'as plus de favoris._");

        return $this->sendReply($context, $reply);
    }

    /**
     * Delete all search history for this user.
     */
    private function handleClearHistory(AgentContext $context): AgentResult
    {
        $deleted = ApiUsageLog::where('requester_phone', $context->from)
            ->where('api_name', 'brave_search')
            ->delete();

        if ($deleted === 0) {
            $reply = "Aucun historique a supprimer pour ce compte.";
        } else {
            $reply = "*Historique efface* — {$deleted} entree(s) supprimee(s).\n\n_Tes prochaines recherches seront de nouveau enregistrees._";
        }

        return $this->sendReply($context, $reply);
    }

    // ── ToolProviderInterface ──────────────────────────────────────

    public function tools(): array
    {
        return array_merge(parent::tools(), [
            [
                'name' => 'web_search',
                'description' => 'Search the web for real-time information, news, definitions, prices, weather, etc. Use this when the user asks about current events, facts you don\'t know, or anything that requires up-to-date information.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The search query (e.g. "meteo Paris", "derniere version Laravel", "prix bitcoin")'],
                        'type' => ['type' => 'string', 'enum' => ['web', 'news'], 'description' => 'Search type: "web" for general, "news" for current events'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'web_fetch',
                'description' => 'Fetch and read the full text content of a web page URL. Use this when you need to read an article, documentation, or any specific page. Returns the extracted text content. For learning/memorizing a site, combine with teach_skill to save what you learn.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'The full URL to fetch (e.g. "https://example.com/page")'],
                        'extract_links' => ['type' => 'boolean', 'description' => 'Also extract links from the page (useful to discover sub-pages of a site)'],
                    ],
                    'required' => ['url'],
                ],
            ],
        ]);
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'web_search' => $this->toolWebSearch($input, $context),
            'web_fetch' => $this->toolWebFetch($input, $context),
            default => parent::executeTool($name, $input, $context),
        };
    }

    private function toolWebSearch(array $input, AgentContext $context): string
    {
        $query = $input['query'] ?? '';
        if (!$query) {
            return json_encode(['error' => 'Missing query parameter']);
        }

        $results = self::searchFor(
            $query,
            $context->routedAgent ?? 'chat',
            $context->agent->id,
            $context->from,
            5
        );

        if ($results === null) {
            return json_encode(['error' => 'Web search failed — API key may not be configured. Go to settings and add brave_search_api_key.']);
        }

        if (empty($results)) {
            return json_encode(['results' => [], 'message' => 'No results found']);
        }

        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    private function toolWebFetch(array $input, AgentContext $context): string
    {
        $url = $input['url'] ?? '';
        $extractLinks = $input['extract_links'] ?? false;

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return json_encode(['error' => 'Invalid or missing URL']);
        }

        // Delegate to WebFetchService (handles safety: private IP blocking, size limits, timeouts)
        $fetcher = new \App\Services\WebFetchService();
        $fetchResult = $fetcher->fetch($url);

        if (!$fetchResult['success']) {
            return json_encode(['error' => $fetchResult['error']]);
        }

        $text = $fetchResult['text'] ?? '';
        $title = $fetchResult['title'] ?? '';

        // Extract links if requested (need raw HTML — re-fetch is avoided by using text)
        $result = [
            'url' => $url,
            'title' => $title,
            'text' => mb_substr($text, 0, 8000),
            'char_count' => mb_strlen($text),
            'truncated' => mb_strlen($text) > 8000,
        ];

        // For link extraction, do a lightweight re-parse
        if ($extractLinks) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'ZeniClaw/1.0 (Web Fetch Bot)'])
                    ->get($url);
                if ($response->successful() && str_contains($response->header('Content-Type') ?? '', 'html')) {
                    $links = $this->extractLinksFromHtml($response->body(), $url);
                    $result['links'] = array_slice($links, 0, 50);
                    $result['link_count'] = count($links);
                }
            } catch (\Exception $e) {
                // Link extraction is best-effort
            }
        }

        // Log API usage
        \App\Models\ApiUsageLog::create([
            'agent_id' => $context->agent->id,
            'api_name' => 'web_fetch',
            'endpoint' => $url,
            'method' => 'GET',
            'caller_agent' => $context->routedAgent ?? 'chat',
            'requester_phone' => $context->from,
            'response_status' => 200,
            'result_count' => mb_strlen($text),
        ]);

        return json_encode($result);
    }

    /**
     * Extract readable text from HTML, removing scripts, styles, nav, etc.
     */
    private function extractTextFromHtml(string $html): string
    {
        // Remove scripts, styles, nav, footer, header, aside
        $html = preg_replace('/<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?<\/\1>/si', '', $html);

        // Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convert block elements to newlines
        $html = preg_replace('/<\/(p|div|li|h[1-6]|tr|blockquote|section|article)>/i', "\n", $html);
        $html = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n- ", $html);
        $html = preg_replace('/<h([1-6])[^>]*>/i', "\n## ", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Extract links from HTML, resolving relative URLs.
     */
    private function extractLinksFromHtml(string $html, string $baseUrl): array
    {
        $links = [];
        $baseParts = parse_url($baseUrl);
        $baseHost = ($baseParts['scheme'] ?? 'https') . '://' . ($baseParts['host'] ?? '');

        if (preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>([^<]*)</i', $html, $matches, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($matches as $match) {
                $href = trim($match[1]);
                $label = trim(strip_tags($match[2]));

                // Skip non-http links
                if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                    continue;
                }

                // Resolve relative URLs
                if (str_starts_with($href, '/')) {
                    $href = $baseHost . $href;
                } elseif (!str_starts_with($href, 'http')) {
                    $href = rtrim($baseUrl, '/') . '/' . $href;
                }

                // Deduplicate
                if (isset($seen[$href])) continue;
                $seen[$href] = true;

                // Only keep same-domain links if extracting site structure
                $linkHost = parse_url($href, PHP_URL_HOST);
                $sameOrigin = $linkHost === ($baseParts['host'] ?? '');

                if ($label || $sameOrigin) {
                    $links[] = [
                        'url' => $href,
                        'label' => $label ?: null,
                        'same_origin' => $sameOrigin,
                    ];
                }
            }
        }

        return $links;
    }
}
