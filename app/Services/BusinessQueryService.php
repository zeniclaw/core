<?php

namespace App\Services;

use App\Models\CustomAgent;
use App\Models\CustomAgentEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BusinessQueryService — strict NLP pipeline for enterprise data queries.
 *
 * Flow: Intent match (LLM) → Validate params (PHP) → API call (HTTP) → Format response (LLM)
 * The LLM NEVER builds URLs or decides what data to return. It only understands and formats.
 */
class BusinessQueryService
{
    private LLMClient $claude;

    public function __construct()
    {
        $this->claude = new LLMClient();
    }

    /**
     * Try to match a user message against the custom agent's business endpoints.
     * Returns null if no endpoint matched (fall back to classic LLM).
     *
     * @return array{matched: bool, endpoint?: CustomAgentEndpoint, params?: array, confidence?: int}|null
     */
    /**
     * Try to match a user message against the custom agent's business endpoints.
     * Supports single and multi-endpoint chains (e.g., "invoices for client Acme"
     * may need: 1. search client → get client_id → 2. list invoices with client_id).
     *
     * @return array{matched: bool, chain?: array, confidence?: int}|null
     */
    public function tryMatch(CustomAgent $agent, string $message): ?array
    {
        $endpoints = $agent->endpoints()
            ->where('is_active', true)
            ->get();

        if ($endpoints->isEmpty()) {
            return null;
        }

        // Step 0: Fast trigger phrase matching (no LLM needed)
        $fastMatch = $this->matchByTriggerPhrases($endpoints, $message);
        if ($fastMatch) {
            return $fastMatch;
        }

        // Step 1: LLM-based matching for complex/ambiguous queries
        $catalog = $this->buildCatalog($endpoints);

        $prompt = <<<PROMPT
Tu es un classificateur d'intentions pour des requetes de donnees d'entreprise.
L'utilisateur fait une demande. Determine si elle correspond a un ou PLUSIEURS endpoints API.

ENDPOINTS DISPONIBLES:
{$catalog}

REGLES:
- Si le message correspond a UN seul endpoint: retourne un "chain" avec 1 step
- Si le message NECESSITE PLUSIEURS appels enchaines (ex: "factures du client Acme" → chercher le client d'abord, puis ses factures): retourne un "chain" avec plusieurs steps
- Dans une chaine, un step peut utiliser le resultat d'un step precedent via "use_from_step" (numero du step) et "extract_field" (champ a extraire du resultat)
- Extrais les parametres du message en langage naturel (ex: "de mars" → month: 3)
- Si le message ne correspond a rien, retourne {"match": false}
- Ne force JAMAIS un match douteux — en cas de doute, retourne {"match": false}

FORMAT DE REPONSE (JSON uniquement):

Pour un appel simple:
{"match": true, "confidence": 88, "chain": [
  {"endpoint_id": 123, "params": {"month": 3, "status": "pending"}}
]}

Pour une chaine (ex: "factures du client Acme"):
{"match": true, "confidence": 85, "chain": [
  {"endpoint_id": 10, "params": {"search": "Acme"}, "label": "Recherche du client"},
  {"endpoint_id": 15, "params": {"client_id": null}, "use_from_step": 0, "extract_field": "id", "inject_as": "client_id", "label": "Factures du client"}
]}

ou
{"match": false}
PROMPT;

        $response = $this->claude->chat(
            "Message: \"{$message}\"",
            ModelResolver::fast(),
            $prompt
        );

        if (!$response) {
            return null;
        }

        $parsed = $this->parseJson($response);
        if (!$parsed || empty($parsed['match'])) {
            return null;
        }

        $confidence = (int) ($parsed['confidence'] ?? 50);
        if ($confidence < 60) {
            return null;
        }

        $chain = $parsed['chain'] ?? [];
        if (empty($chain)) {
            return null;
        }

        // Validate all endpoint IDs exist
        foreach ($chain as $step) {
            $epId = $step['endpoint_id'] ?? null;
            if (!$epId || !$endpoints->firstWhere('id', $epId)) {
                return null;
            }
        }

        // Backwards compat: single endpoint shortcut
        if (count($chain) === 1) {
            $step = $chain[0];
            return [
                'matched' => true,
                'endpoint' => $endpoints->firstWhere('id', $step['endpoint_id']),
                'params' => $step['params'] ?? [],
                'confidence' => $confidence,
            ];
        }

        // Multi-endpoint chain
        $resolvedChain = [];
        foreach ($chain as $step) {
            $resolvedChain[] = [
                'endpoint' => $endpoints->firstWhere('id', $step['endpoint_id']),
                'params' => $step['params'] ?? [],
                'use_from_step' => $step['use_from_step'] ?? null,
                'extract_field' => $step['extract_field'] ?? null,
                'inject_as' => $step['inject_as'] ?? null,
                'label' => $step['label'] ?? null,
            ];
        }

        return [
            'matched' => true,
            'chain' => $resolvedChain,
            'confidence' => $confidence,
        ];
    }

    /**
     * Execute a chain of API calls, passing data between steps.
     *
     * @return array{success: bool, reply?: string, error?: string, debug?: array}
     */
    public function executeChain(array $chain, string $originalMessage): array
    {
        $stepResults = [];
        $allData = [];
        $debugSteps = [];

        foreach ($chain as $i => $step) {
            $endpoint = $step['endpoint'];
            $params = $step['params'] ?? [];

            // Inject value from a previous step's result
            if (isset($step['use_from_step']) && $step['use_from_step'] !== null) {
                $prevData = $stepResults[$step['use_from_step']] ?? null;
                $field = $step['extract_field'] ?? null;
                $injectAs = $step['inject_as'] ?? $field;

                if ($prevData && $field && $injectAs) {
                    $extracted = $this->extractFieldFromData($prevData, $field);
                    if ($extracted !== null) {
                        $params[$injectAs] = $extracted;
                    } else {
                        return [
                            'success' => false,
                            'error' => "Etape " . ($i + 1) . ": impossible d'extraire '{$field}' du resultat precedent.",
                        ];
                    }
                }
            }

            // Validate & execute
            $validation = $endpoint->validateParams($params);
            // For chained calls, skip validation of params injected from previous steps
            $params = array_merge($validation['sanitized'], array_filter($params, fn($v) => $v !== null));

            $apiResult = $this->callApi($endpoint, $params);
            if (!$apiResult['success']) {
                return [
                    'success' => false,
                    'error' => "Etape " . ($i + 1) . " ({$endpoint->name}): " . ($apiResult['error'] ?? 'Erreur'),
                ];
            }

            $data = $this->extractData($apiResult['response'], $endpoint->response_path);
            $stepResults[$i] = $data;
            $allData[] = ['step' => $step['label'] ?? $endpoint->name, 'data' => $data];
            $debugSteps[] = [
                'step' => $i + 1,
                'endpoint' => $endpoint->name,
                'method' => $endpoint->method,
                'url' => $endpoint->buildUrl($params),
                'params' => $params,
            ];
        }

        // Format the final response using ALL collected data
        $reply = $this->formatChainResponse($allData, $originalMessage);

        return [
            'success' => true,
            'reply' => $reply,
            'debug' => ['chain' => $debugSteps, 'steps_count' => count($chain)],
        ];
    }

    /**
     * Execute the full pipeline: validate → call API → format response.
     *
     * @return array{success: bool, reply?: string, error?: string, raw_data?: mixed, debug?: array}
     */
    public function execute(CustomAgentEndpoint $endpoint, array $extractedParams, string $originalMessage): array
    {
        // Step 1: Validate parameters
        $validation = $endpoint->validateParams($extractedParams);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Parametres invalides: ' . implode(', ', $validation['errors']),
            ];
        }

        $params = $validation['sanitized'];

        // Step 2: Execute API call
        $apiResult = $this->callApi($endpoint, $params);
        if (!$apiResult['success']) {
            return $apiResult;
        }

        // Step 3: Extract data from response using JSON path
        $data = $this->extractData($apiResult['response'], $endpoint->response_path);

        // Step 4: Format response with LLM (skip when called from tool — LLM formats itself)
        $reply = $originalMessage
            ? $this->formatResponse($data, $endpoint, $originalMessage)
            : null;

        return [
            'success' => true,
            'reply' => $reply,
            'raw_data' => $data,
            'debug' => [
                'endpoint' => $endpoint->name,
                'method' => $endpoint->method,
                'url' => $endpoint->buildUrl($params),
                'params' => $params,
                'records_count' => is_array($data) ? count($data) : 1,
            ],
        ];
    }

    /**
     * Simulate an API call for preview in the UI (no LLM formatting).
     * Used by the partner to test endpoints before saving.
     */
    public function simulate(CustomAgentEndpoint $endpoint, array $testParams = []): array
    {
        $apiResult = $this->callApi($endpoint, $testParams);
        if (!$apiResult['success']) {
            return $apiResult;
        }

        $data = $this->extractData($apiResult['response'], $endpoint->response_path);

        // Detect available fields from response for UI help
        $fields = $this->detectFields($data);

        return [
            'success' => true,
            'status_code' => $apiResult['status_code'],
            'raw_response' => $apiResult['response'],
            'extracted_data' => $data,
            'record_count' => is_array($data) ? (isset($data[0]) ? count($data) : 1) : 0,
            'detected_fields' => $fields,
            'response_path_used' => $endpoint->response_path,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────

    private function callApi(CustomAgentEndpoint $endpoint, array $params): array
    {
        $url = $endpoint->buildUrl($params);
        $headers = $endpoint->buildHeaders();

        // Add API key as query param if auth_type is 'query'
        if ($endpoint->auth_type === 'query') {
            $authValue = $endpoint->getAuthValue();
            if ($authValue) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator . 'api_key=' . urlencode($authValue);
            }
        }

        try {
            $http = Http::withHeaders($headers)
                ->timeout(30)
                ->connectTimeout(10);

            $response = match ($endpoint->method) {
                'GET' => $http->get($url),
                'POST' => $http->post($url, $endpoint->buildBody($params) ?? []),
                'PUT' => $http->put($url, $endpoint->buildBody($params) ?? []),
                'PATCH' => $http->patch($url, $endpoint->buildBody($params) ?? []),
                'DELETE' => $http->delete($url),
                default => null,
            };

            if (!$response) {
                return ['success' => false, 'error' => "Methode HTTP non supportee: {$endpoint->method}"];
            }

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "API a repondu {$response->status()}: " . mb_substr($response->body(), 0, 500),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => true,
                'response' => $response->json() ?? $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('BusinessQuery API call failed', [
                'endpoint' => $endpoint->name,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => "Erreur de connexion: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Extract nested data from API response using dot-notation path.
     * Example: "data.invoices" extracts $response['data']['invoices']
     */
    private function extractData(mixed $response, ?string $path): mixed
    {
        if (!$path || !is_array($response)) {
            return $response;
        }

        $segments = explode('.', $path);
        $data = $response;

        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $response; // path not found, return full response
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * Format data for the user using LLM — strictly presentation, no invention.
     */
    private function formatResponse(mixed $data, CustomAgentEndpoint $endpoint, string $originalMessage): string
    {
        $dataJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Cap data size to avoid huge prompts
        if (mb_strlen($dataJson) > 8000) {
            $dataJson = mb_substr($dataJson, 0, 8000) . "\n... (donnees tronquees)";
        }

        $recordCount = is_array($data) ? (isset($data[0]) ? count($data) : 1) : 0;
        $endpointName = $endpoint->name;

        $prompt = <<<PROMPT
Tu es un assistant d'entreprise. L'utilisateur a demande: "{$originalMessage}"
L'endpoint "{$endpointName}" a retourne les donnees ci-dessous.

REGLES ABSOLUES:
- Presente UNIQUEMENT les donnees fournies ci-dessous
- N'INVENTE aucune donnee, aucun chiffre, aucun nom
- Si la liste est vide, dis "Aucun resultat trouve"
- Formate de facon lisible (liste a puces, tableaux si pertinent)
- Ajoute un total/resume si les donnees le permettent (somme, comptage)
- Sois concis et professionnel

DONNEES REELLES ({$recordCount} resultats):
{$dataJson}
PROMPT;

        $reply = $this->claude->chat('Formate ces donnees pour le user.', ModelResolver::fast(), $prompt);
        return $reply ?: "Voici les donnees brutes:\n{$dataJson}";
    }

    /**
     * Fast matching by trigger phrases — no LLM needed.
     * Checks if the user message contains or closely matches a trigger phrase.
     */
    /**
     * Fast matching by trigger phrases — no LLM needed.
     * Uses word-set overlap scoring for fuzzy matching.
     */
    private function matchByTriggerPhrases(\Illuminate\Database\Eloquent\Collection $endpoints, string $message): ?array
    {
        $normalized = mb_strtolower(trim($message));
        $fillers = ['moi', 'mes', 'les', 'des', 'du', 'de', 'la', 'le', 'un', 'une', 'te', 'vous',
            'tout', 'tous', 'toutes', 'please', 'show', 'me', 'my', 'the', 'all', 'give',
            'montre', 'affiche', 'donne', 'voir', 'je', 'veux', 'voudrais', 'peux', 'tu',
            'est', 'ce', 'que', 'qui', 'quoi', 'il', 'y', 'a', 'et', 'ou', 'en', 'au'];

        // Extract meaningful words from the message
        $messageWords = $this->extractWords($normalized, $fillers);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($endpoints as $endpoint) {
            $triggers = $endpoint->trigger_phrases ?? [];
            foreach ($triggers as $trigger) {
                $triggerLower = mb_strtolower($trigger);

                // 1. Exact match
                if ($normalized === $triggerLower) {
                    return [
                        'matched' => true,
                        'endpoint' => $endpoint,
                        'params' => [],
                        'confidence' => 95,
                    ];
                }

                // 2. Substring containment
                if (mb_strlen($triggerLower) >= 4 && str_contains($normalized, $triggerLower)) {
                    $score = mb_strlen($triggerLower) / mb_strlen($normalized) * 100;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $endpoint;
                    }
                }

                // 3. Word overlap scoring (handles "liste mes factures" ↔ "lister les factures")
                $triggerWords = $this->extractWords($triggerLower, $fillers);
                if (!empty($triggerWords) && !empty($messageWords)) {
                    $overlap = count(array_intersect($messageWords, $triggerWords));
                    // Also match stemmed (drop trailing e/s/r/er for French)
                    if ($overlap < count($triggerWords)) {
                        $stemMsg = array_map(fn($w) => preg_replace('/(er|re|es|e|s)$/u', '', $w), $messageWords);
                        $stemTrig = array_map(fn($w) => preg_replace('/(er|re|es|e|s)$/u', '', $w), $triggerWords);
                        $overlap = max($overlap, count(array_intersect($stemMsg, $stemTrig)));
                    }
                    $score = ($overlap / count($triggerWords)) * 90;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $endpoint;
                    }
                }
            }
        }

        if ($bestMatch && $bestScore >= 50) {
            return [
                'matched' => true,
                'endpoint' => $bestMatch,
                'params' => [],
                'confidence' => min(90, (int) $bestScore),
            ];
        }

        return null;
    }

    private function extractWords(string $text, array $fillers): array
    {
        $words = preg_split('/[\s,;.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $filtered = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2 && !in_array($w, $fillers)));

        // Expand with common FR↔EN business synonyms
        $synonyms = [
            'facture' => 'invoice', 'factures' => 'invoices',
            'invoice' => 'facture', 'invoices' => 'factures',
            'client' => 'customer', 'clients' => 'customers',
            'customer' => 'client', 'customers' => 'clients',
            'contact' => 'contact', 'contacts' => 'contacts',
            'produit' => 'product', 'produits' => 'products',
            'product' => 'produit', 'products' => 'produits',
            'commande' => 'order', 'commandes' => 'orders',
            'order' => 'commande', 'orders' => 'commandes',
            'paiement' => 'payment', 'paiements' => 'payments',
            'payment' => 'paiement', 'payments' => 'paiements',
            'devis' => 'quote', 'quote' => 'devis',
            'fournisseur' => 'supplier', 'fournisseurs' => 'suppliers',
            'supplier' => 'fournisseur', 'suppliers' => 'fournisseurs',
            'categorie' => 'category', 'categories' => 'categories',
            'utilisateur' => 'user', 'utilisateurs' => 'users',
            'user' => 'utilisateur', 'users' => 'utilisateurs',
            'rapport' => 'report', 'rapports' => 'reports',
            'report' => 'rapport', 'reports' => 'rapports',
            'depense' => 'expense', 'depenses' => 'expenses',
            'expense' => 'depense', 'expenses' => 'depenses',
            'impaye' => 'unpaid', 'impayes' => 'unpaid', 'impayees' => 'unpaid',
            'unpaid' => 'impaye',
            'paye' => 'paid', 'payee' => 'paid', 'payees' => 'paid',
            'paid' => 'paye',
            'creer' => 'create', 'create' => 'creer',
            'lister' => 'list', 'list' => 'lister', 'liste' => 'list',
            'supprimer' => 'delete', 'delete' => 'supprimer',
            'modifier' => 'update', 'update' => 'modifier',
        ];

        $expanded = $filtered;
        foreach ($filtered as $word) {
            if (isset($synonyms[$word]) && !in_array($synonyms[$word], $expanded)) {
                $expanded[] = $synonyms[$word];
            }
        }

        return $expanded;
    }

    private function buildCatalog(\Illuminate\Database\Eloquent\Collection $endpoints): string
    {
        $lines = [];
        foreach ($endpoints as $ep) {
            $triggers = implode(', ', $ep->trigger_phrases ?? []);
            $params = collect($ep->parameters ?? [])->map(fn($p) => $p['name'] . ' (' . ($p['type'] ?? 'string') . ')')->implode(', ');

            $lines[] = "- ID={$ep->id}: {$ep->name} [{$ep->method}]";
            $lines[] = "  Phrases: {$triggers}";
            if ($params) {
                $lines[] = "  Params: {$params}";
            }
        }
        return implode("\n", $lines);
    }

    private function detectFields(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // If it's a list, inspect first item
        $sample = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
        if (!is_array($sample)) {
            return [];
        }

        $fields = [];
        foreach ($sample as $key => $value) {
            $fields[] = [
                'name' => $key,
                'type' => match (true) {
                    is_int($value) => 'integer',
                    is_float($value) => 'float',
                    is_bool($value) => 'boolean',
                    is_array($value) => 'array',
                    is_null($value) => 'null',
                    default => 'string',
                },
                'sample' => is_scalar($value) ? mb_substr((string) $value, 0, 100) : '[...]',
            ];
        }

        return $fields;
    }

    /**
     * Extract a field value from API response data (handles arrays and objects).
     */
    private function extractFieldFromData(mixed $data, string $field): mixed
    {
        if (!is_array($data)) {
            return null;
        }

        // If it's a list of objects, take the first item
        if (isset($data[0]) && is_array($data[0])) {
            return $data[0][$field] ?? null;
        }

        // Direct field access
        return $data[$field] ?? null;
    }

    /**
     * Format a multi-step chain response using all collected data.
     */
    private function formatChainResponse(array $allData, string $originalMessage): string
    {
        $sections = [];
        foreach ($allData as $step) {
            $stepJson = json_encode($step['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (mb_strlen($stepJson) > 4000) {
                $stepJson = mb_substr($stepJson, 0, 4000) . "\n... (tronque)";
            }
            $sections[] = "--- {$step['step']} ---\n{$stepJson}";
        }
        $allDataText = implode("\n\n", $sections);

        $prompt = <<<PROMPT
Tu es un assistant d'entreprise. L'utilisateur a demande: "{$originalMessage}"
Plusieurs appels API ont ete enchaines pour obtenir la reponse.

REGLES ABSOLUES:
- Presente UNIQUEMENT les donnees fournies ci-dessous
- N'INVENTE aucune donnee
- Synthetise les resultats de toutes les etapes en une reponse coherente
- Si une etape n'a pas retourne de donnees, mentionne-le
- Sois concis et professionnel

DONNEES COLLECTEES:
{$allDataText}
PROMPT;

        $reply = $this->claude->chat('Synthetise ces donnees pour le user.', ModelResolver::fast(), $prompt);
        return $reply ?: "Donnees brutes:\n{$allDataText}";
    }

    private function parseJson(string $text): ?array
    {
        $clean = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        return json_decode($clean, true);
    }
}
