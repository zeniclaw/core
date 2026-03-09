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
        return 'Recherche web en temps reel via Brave Search API. Cherche des infos actuelles, actualites, meteo, definitions, comparaisons. Fournit aussi des stats d\'utilisation API. Peut etre appele par d\'autres agents.';
    }

    public function keywords(): array
    {
        return [
            'cherche', 'recherche', 'google', 'search', 'trouve', 'find',
            'actualite', 'news', 'actu', 'quoi de neuf',
            'c\'est quoi', 'qu\'est-ce que', 'definition', 'who is', 'what is',
            'meteo', 'weather', 'temps qu\'il fait', 'temperature',
            'compare', 'vs', 'versus', 'difference entre',
            'prix de', 'combien coute', 'price of',
            'derniere version', 'latest', 'recent',
            'stats api', 'api usage', 'utilisation api', 'combien d\'appels',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'web_search';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (!$body) {
            $this->sendText($context->from, "Envoie-moi ce que tu veux chercher sur le web !");
            return AgentResult::reply("Envoie-moi ce que tu veux chercher sur le web !");
        }

        $lower = mb_strtolower($body);

        // Stats commands
        if (preg_match('/\b(stats?\s*api|api\s*usage|utilisation\s*api|combien\s*d.appels|api\s*stats)\b/iu', $lower)) {
            return $this->handleStats($context);
        }

        // API key check
        $apiKey = AppSetting::get('brave_search_api_key');
        if (!$apiKey) {
            $reply = "La cle API Brave Search n'est pas configuree.\n\n"
                . "Configure-la dans les settings:\n"
                . "→ Cle: `brave_search_api_key`\n"
                . "→ Gratuit: https://api.search.brave.com/register";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Detect search type
        $searchType = $this->detectSearchType($lower);

        // Execute search
        $results = $this->search($context, $body, $searchType, $apiKey);

        if ($results === null) {
            $reply = "Desole, la recherche a echoue. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Format results with Claude
        $reply = $this->formatResults($context, $body, $results, $searchType);

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reply sent (web_search)', [
            'model' => $this->resolveModel($context),
            'routed_agent' => $context->routedAgent,
            'search_type' => $searchType,
            'result_count' => count($results),
            'reply' => mb_substr($reply, 0, 200),
        ]);

        return AgentResult::reply($reply, [
            'model' => $this->resolveModel($context),
            'search_type' => $searchType,
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
                $lines[] = "   → {$r['url']}";
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

    private function detectSearchType(string $lower): string
    {
        if (preg_match('/\b(actu|actualit|news|quoi de neuf|dernières nouvelles)\b/iu', $lower)) {
            return 'news';
        }
        if (preg_match('/\b(meteo|weather|temps qu.il fait|temperature|pluie|soleil)\b/iu', $lower)) {
            return 'web'; // Brave handles weather in web results
        }
        if (preg_match('/\b(c.est quoi|qu.est.ce que|definition|who is|what is|wiki)\b/iu', $lower)) {
            return 'definition';
        }
        return 'web';
    }

    private function search(AgentContext $context, string $query, string $type, string $apiKey): ?array
    {
        $count = ($type === 'news') ? 8 : 5;
        return $this->executeBraveSearch($query, $apiKey, 'web_search', $context->agent->id, $context->from, $count, $type);
    }

    private function executeBraveSearch(
        string $query,
        string $apiKey,
        string $callerAgent,
        int $agentId,
        ?string $phone,
        int $count = 5,
        string $type = 'web'
    ): ?array {
        $endpoint = ($type === 'news')
            ? 'https://api.search.brave.com/res/v1/news/search'
            : 'https://api.search.brave.com/res/v1/web/search';

        $params = [
            'q' => $query,
            'count' => $count,
            'search_lang' => 'fr',
            'ui_lang' => 'fr-FR',
        ];

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
                    'title' => $item['title'] ?? '',
                    'url' => $item['url'] ?? '',
                    'description' => $item['description'] ?? $item['meta_url']['hostname'] ?? '',
                    'age' => $item['age'] ?? null,
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
                'agent_id' => $agentId,
                'requester_phone' => $phone,
                'caller_agent' => $callerAgent,
                'api_name' => $apiName,
                'endpoint' => $endpoint,
                'method' => 'GET',
                'request_params' => $params,
                'response_status' => $status,
                'response_time_ms' => $elapsed,
                'result_count' => $resultCount,
                'error_message' => $error,
            ]);
        } catch (\Exception $e) {
            Log::warning('WebSearchAgent: failed to log API call', ['error' => $e->getMessage()]);
        }
    }

    private function formatResults(AgentContext $context, string $query, array $results, string $type): string
    {
        if (empty($results)) {
            return "Aucun resultat trouve pour: *{$query}*";
        }

        $model = $this->resolveModel($context);

        // Build context for Claude to summarize
        $resultText = '';
        foreach ($results as $i => $r) {
            $resultText .= ($i + 1) . ". [{$r['title']}]({$r['url']})\n";
            $resultText .= "   {$r['description']}\n";
            if (!empty($r['age'])) {
                $resultText .= "   (il y a {$r['age']})\n";
            }
            $resultText .= "\n";
        }

        $typeLabel = match ($type) {
            'news' => 'actualites',
            'definition' => 'definition/explication',
            default => 'recherche web',
        };

        $systemPrompt = <<<PROMPT
Tu es ZeniClaw, un assistant WhatsApp. L'utilisateur a demande une {$typeLabel}.
Voici les resultats de recherche. Synthetise-les en une reponse claire et utile.

REGLES:
- Formate pour WhatsApp (pas de markdown complexe, utilise * pour gras, _ pour italique)
- Sois concis mais informatif (max 500 mots)
- Cite les sources avec les URLs
- Si c'est une question factuelle, donne la reponse directement puis les sources
- Si c'est des actualites, resume les points cles
- Reponds en francais sauf si la question est en anglais
PROMPT;

        $userMessage = "Question: {$query}\n\nResultats:\n{$resultText}";

        $reply = $this->claude->chat($userMessage, $model, $systemPrompt);

        return $reply ?? "Voici les resultats pour *{$query}* :\n\n{$resultText}";
    }

    private function handleStats(AgentContext $context): AgentResult
    {
        $stats = self::getUsageStats($context->agent->id, 30);

        $lines = ["*Stats API — 30 derniers jours*\n"];
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
            $lines[] = "\n*Par agent:*";
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

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['stats' => $stats]);
    }
}
