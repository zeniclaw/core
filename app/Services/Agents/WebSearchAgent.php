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
        return 'Recherche web en temps reel via Brave Search API. Cherche des infos actuelles, actualites, meteo, definitions, comparaisons, prix. Affiche un historique des recherches et les stats d\'utilisation API. Peut etre appele par d\'autres agents.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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

        // History command
        if (preg_match('/\b(historique|mes\s+recherches|last\s+search(?:es)?|dernieres?\s+recherches?)\b/iu', $lower)) {
            $limit = $this->extractNumber($lower, 10);
            return $this->handleHistory($context, min($limit, 20));
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

        // Detect search type and language
        $searchType = $this->detectSearchType($lower);
        $searchLang = $this->detectLanguage($lower, $body);
        $freshness = ($searchType === 'news') ? 'pw' : null; // past week for news

        // Execute search
        $results = $this->search($context, $body, $searchType, $apiKey, $searchLang, $freshness);

        if ($results === null) {
            $reply = "Desole, la recherche a echoue. Verifie ta connexion ou reessaie dans un instant.\n\nTu peux aussi taper *aide* pour voir les commandes disponibles.";
            return $this->sendReply($context, $reply);
        }

        if (empty($results)) {
            $reply = "Aucun resultat trouve pour: *{$body}*\n\nEssaie une formulation differente ou elargis la recherche.";
            return $this->sendReply($context, $reply);
        }

        // Format results with Claude
        $reply = $this->formatResults($context, $body, $results, $searchType, $searchLang);

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reply sent (web_search)', [
            'model' => $this->resolveModel($context),
            'routed_agent' => $context->routedAgent,
            'search_type' => $searchType,
            'search_lang' => $searchLang,
            'result_count' => count($results),
            'reply' => mb_substr($reply, 0, 200),
        ]);

        return AgentResult::reply($reply, [
            'model' => $this->resolveModel($context),
            'search_type' => $searchType,
            'search_lang' => $searchLang,
            'result_count' => count($results),
        ]);
    }

    // ── Public API for cross-agent calls ──

    /**
     * Search the web — callable by other agents.
     *
     * @param string $query      Search query
     * @param string $callerAgent  Name of the calling agent (for stats)
     * @param int $agentId       Agent model ID
     * @param string|null $phone User phone (for stats)
     * @param int $count         Max results
     * @return array|null        Array of results or null on failure
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

        $totalCalls = $logs->count();
        $successCalls = $logs->where('response_status', '>=', 200)->where('response_status', '<', 300)->count();
        $errorCalls = $logs->whereNotNull('error_message')->count();
        $avgResponseTime = $logs->avg('response_time_ms');
        $totalResults = $logs->sum('result_count');

        $byApi = $logs->groupBy('api_name')->map(fn($g) => $g->count())->sortDesc()->all();
        $byCaller = $logs->groupBy('caller_agent')->map(fn($g) => $g->count())->sortDesc()->all();
        $byDay = $logs->groupBy(fn($l) => $l->created_at->format('Y-m-d'))->map(fn($g) => $g->count())->all();

        return [
            'period_days' => $days,
            'total_calls' => $totalCalls,
            'success_calls' => $successCalls,
            'error_calls' => $errorCalls,
            'success_rate' => $totalCalls > 0 ? round(($successCalls / $totalCalls) * 100, 1) : 0,
            'avg_response_time_ms' => $avgResponseTime ? round($avgResponseTime) : 0,
            'total_results_returned' => $totalResults,
            'calls_by_api' => $byApi,
            'calls_by_agent' => $byCaller,
            'calls_by_day' => $byDay,
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
            . "  actu <sujet>  _(actualites recentes)_\n"
            . "  c'est quoi <terme>  _(definition)_\n"
            . "  compare <A> vs <B>\n"
            . "  prix de <produit>\n"
            . "  meteo <ville>\n\n"
            . "*Historique:*\n"
            . "  historique  _(10 dernieres recherches)_\n"
            . "  historique 20  _(jusqu'a 20 recherches)_\n\n"
            . "*Stats API:*\n"
            . "  stats api\n"
            . "  stats api 7j  _(7 derniers jours)_\n"
            . "  stats api 90j  _(90 derniers jours)_\n\n"
            . "_Note: ajoute \"en anglais\" pour forcer les resultats en anglais._";
    }

    private function detectSearchType(string $lower): string
    {
        if (preg_match('/\b(actu|actualit|news|quoi de neuf|dernieres?\s+nouvelles|breaking)\b/iu', $lower)) {
            return 'news';
        }
        if (preg_match('/\b(meteo|weather|temps\s+qu.il\s+fait|temperature|pluie|soleil|precipitation)\b/iu', $lower)) {
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

    private function detectLanguage(string $lower, string $body): string
    {
        // Force English if explicitly requested
        if (preg_match('/\b(en\s+anglais|in\s+english|english\s+results?)\b/iu', $lower)) {
            return 'en';
        }

        // Detect if query is mostly English (simple heuristic: common French words absent)
        $frenchIndicators = ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'est', 'sont',
            'je', 'tu', 'il', 'nous', 'vous', 'ils', 'et', 'ou', 'pas', 'que', 'qui'];
        $words = preg_split('/\s+/', $lower);
        $frenchCount = count(array_intersect($words, $frenchIndicators));

        return $frenchCount > 0 ? 'fr' : 'en';
    }

    private function extractDays(string $lower, int $default): int
    {
        if (preg_match('/(\d+)\s*j(?:ours?)?\b/iu', $lower, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b7\s*(?:days?|jours?)\b/iu', $lower)) return 7;
        if (preg_match('/\b90\s*(?:days?|jours?)\b/iu', $lower)) return 90;
        return $default;
    }

    private function extractNumber(string $lower, int $default): int
    {
        if (preg_match('/\b(\d+)\b/', $lower, $m)) {
            return (int) $m[1];
        }
        return $default;
    }

    private function search(
        AgentContext $context,
        string $query,
        string $type,
        string $apiKey,
        string $lang = 'fr',
        ?string $freshness = null
    ): ?array {
        $count = ($type === 'news') ? 8 : 5;
        $braveType = ($type === 'news') ? 'news' : 'web';
        return $this->executeBraveSearch($query, $apiKey, 'web_search', $context->agent->id, $context->from, $count, $braveType, $lang, $freshness);
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
            'q' => $query,
            'count' => $count,
            'search_lang' => $lang,
            'ui_lang' => $lang === 'fr' ? 'fr-FR' : 'en-US',
        ];

        if ($freshness) {
            $params['freshness'] = $freshness;
        }

        $start = microtime(true);
        $status = null;
        $error = null;
        $resultCount = 0;
        $results = [];

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip',
                    'X-Subscription-Token' => $apiKey,
                ])
                ->get($endpoint, $params);

            $status = $response->status();
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            if ($status === 422) {
                // Quota or invalid param — log and return empty
                $error = "HTTP 422 (quota ou parametre invalide): " . mb_substr($response->body(), 0, 200);
                $this->logApiCall($agentId, $phone, $callerAgent, 'brave_search', $endpoint, $params, $status, $elapsed, 0, $error);
                Log::warning('WebSearchAgent: Brave API 422', ['body' => mb_substr($response->body(), 0, 300)]);
                return [];
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
            $error = $e->getMessage();
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
            'news' => "Resume les actualites principales en 3-5 points cles avec date si disponible. Cite les sources.",
            'definition' => "Donne une definition claire et concise. Si c'est une personne, resume qui c'est en 2-3 phrases. Cite les sources.",
            'compare' => "Structure la comparaison en points forts/faibles pour chaque option. Conclus avec une recommandation si possible.",
            'price' => "Donne les prix trouves, precise la source et la date. Mentionne si les prix varient selon les vendeurs.",
            'weather' => "Resume les conditions meteorologiques actuelles et previsions. Sois concis.",
            default => "Synthetise les informations principales en reponse directe a la question. Cite les sources les plus fiables.",
        };

        $systemPrompt = <<<PROMPT
Tu es ZeniClaw, un assistant WhatsApp. Reponds {$replyLang}.

INSTRUCTIONS DE FORMATAGE WHATSAPP:
- Utilise * pour le gras (ex: *titre*)
- Utilise _ pour l'italique (ex: _source_)
- Utilise des emojis pertinents avec moderation
- Max 400 mots, sois direct et informatif
- Les URLs doivent etre completes (pas de raccourcissement)
- Commence directement par la reponse, pas de phrase d'introduction

INSTRUCTIONS DE CONTENU:
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

        $lines = ["*Stats API — {$days} derniers jours*\n"];
        $lines[] = "Total appels: *{$stats['total_calls']}*";
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
            $params = $log->request_params ?? [];
            $query = $params['q'] ?? '(inconnu)';
            $date = $log->created_at->format('d/m H:i');
            $count = $log->result_count;
            $caller = ($log->caller_agent !== 'web_search') ? " _[{$log->caller_agent}]_" : '';
            $lines[] = ($i + 1) . ". *" . mb_substr($query, 0, 50) . "*";
            $lines[] = "   {$date} · {$count} resultats{$caller}";
        }

        $reply = implode("\n", $lines);
        return $this->sendReply($context, $reply);
    }
}
