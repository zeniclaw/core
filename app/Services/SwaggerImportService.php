<?php

namespace App\Services;

use App\Models\CustomAgent;
use App\Models\CustomAgentEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SwaggerImportService — auto-detect and configure business endpoints from OpenAPI/Swagger spec.
 *
 * Flow: Fetch spec → Parse endpoints → LLM generates trigger phrases → Create endpoint records.
 */
class SwaggerImportService
{
    private LLMClient $claude;

    public function __construct()
    {
        $this->claude = new LLMClient();
    }

    /**
     * Fetch and parse an OpenAPI spec from URL or raw JSON string.
     * Returns a preview of detected endpoints (not yet saved).
     *
     * @return array{success: bool, endpoints?: array, error?: string, spec_info?: array}
     */
    public function analyze(string $urlOrJson, CustomAgent $agent): array
    {
        // Step 1: Fetch the spec
        $spec = $this->fetchSpec($urlOrJson);
        if (!$spec) {
            return ['success' => false, 'error' => 'Impossible de recuperer ou parser le document OpenAPI.'];
        }

        // Step 2: Extract raw endpoints from spec
        $rawEndpoints = $this->extractEndpoints($spec);
        if (empty($rawEndpoints)) {
            return ['success' => false, 'error' => 'Aucun endpoint detecte dans le document.'];
        }

        // Step 3: Detect base URL (resolve relative paths using source URL)
        $baseUrl = $this->extractBaseUrl($spec, $urlOrJson);

        // Step 4: Use LLM to generate trigger phrases and descriptions for each endpoint
        $enriched = $this->enrichWithAI($rawEndpoints, $spec['info'] ?? []);

        // Step 5: Get existing credential keys for suggestions
        $credKeys = $agent->credentials()->where('is_active', true)->pluck('key')->toArray();

        // Step 6: Detect auth scheme from spec
        $authScheme = $this->detectAuthScheme($spec);

        // Build preview
        $preview = [];
        foreach ($enriched as $ep) {
            $preview[] = [
                'name' => $ep['name'],
                'description' => $ep['description'],
                'method' => $ep['method'],
                'url' => $baseUrl . $ep['path'],
                'path' => $ep['path'],
                'trigger_phrases' => $ep['trigger_phrases'] ?? [],
                'parameters' => $ep['parameters'] ?? [],
                'response_path' => $ep['response_path'] ?? null,
                'request_body_template' => $ep['request_body_template'] ?? null,
                'auth_type' => $authScheme['type'] ?? 'bearer',
                'selected' => true, // user can deselect
            ];
        }

        return [
            'success' => true,
            'endpoints' => $preview,
            'spec_info' => [
                'title' => $spec['info']['title'] ?? 'API',
                'version' => $spec['info']['version'] ?? '?',
                'description' => mb_substr($spec['info']['description'] ?? '', 0, 200),
                'base_url' => $baseUrl,
                'total_paths' => count($spec['paths'] ?? []),
                'total_endpoints' => count($rawEndpoints),
            ],
            'auth_scheme' => $authScheme,
            'available_credentials' => $credKeys,
        ];
    }

    /**
     * Save selected endpoints from a preview to the database.
     */
    public function import(array $endpoints, CustomAgent $agent, ?int $shareId = null, ?string $authCredentialKey = null): int
    {
        $created = 0;

        foreach ($endpoints as $ep) {
            if (empty($ep['selected'])) {
                continue;
            }

            CustomAgentEndpoint::create([
                'custom_agent_id' => $agent->id,
                'name' => $ep['name'],
                'description' => $ep['description'] ?? null,
                'method' => strtoupper($ep['method']),
                'url' => $ep['url'],
                'auth_type' => $ep['auth_type'] ?? 'bearer',
                'auth_credential_key' => $authCredentialKey,
                'trigger_phrases' => $ep['trigger_phrases'] ?? [],
                'parameters' => $ep['parameters'] ?? null,
                'response_path' => $ep['response_path'] ?? null,
                'headers' => null,
                'request_body_template' => $ep['request_body_template'] ?? null,
                'is_active' => true,
                'created_by_share_id' => $shareId,
            ]);
            $created++;
        }

        return $created;
    }

    // ── Private helpers ──────────────────────────────────────────

    private function fetchSpec(string $urlOrJson): ?array
    {
        // Try as JSON string first
        if (str_starts_with(trim($urlOrJson), '{')) {
            $parsed = json_decode($urlOrJson, true);
            if ($parsed && (isset($parsed['openapi']) || isset($parsed['swagger']) || isset($parsed['paths']))) {
                return $parsed;
            }
        }

        // Try as URL
        try {
            $response = Http::timeout(30)->connectTimeout(10)->get($urlOrJson);
            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            // Try JSON
            $parsed = json_decode($body, true);
            if ($parsed && (isset($parsed['openapi']) || isset($parsed['swagger']) || isset($parsed['paths']))) {
                return $parsed;
            }

            // Try YAML (basic detection)
            if (str_contains($body, 'openapi:') || str_contains($body, 'swagger:')) {
                // Use symfony/yaml if available, otherwise try basic parsing
                if (function_exists('yaml_parse')) {
                    $parsed = yaml_parse($body);
                    if (is_array($parsed)) {
                        return $parsed;
                    }
                }
                // Fallback: ask LLM to convert YAML to JSON
                return $this->yamlToJson($body);
            }
        } catch (\Throwable $e) {
            Log::warning('SwaggerImport: fetch failed', ['url' => $urlOrJson, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function extractEndpoints(array $spec): array
    {
        $endpoints = [];
        $paths = $spec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) continue;

            foreach ($methods as $method => $details) {
                $method = strtoupper($method);
                if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])) {
                    continue;
                }
                if (!is_array($details)) continue;

                $params = $this->extractParameters($details, $spec);
                $bodyTemplate = $this->extractRequestBody($details, $spec);
                $responsePath = $this->guessResponsePath($details, $spec);

                $endpoints[] = [
                    'path' => $path,
                    'method' => $method,
                    'summary' => $details['summary'] ?? '',
                    'description' => $details['description'] ?? $details['summary'] ?? '',
                    'operationId' => $details['operationId'] ?? null,
                    'tags' => $details['tags'] ?? [],
                    'parameters' => $params,
                    'request_body_template' => $bodyTemplate,
                    'response_path' => $responsePath,
                ];
            }
        }

        return $endpoints;
    }

    private function extractParameters(array $operation, array $spec): array
    {
        $params = [];
        $rawParams = $operation['parameters'] ?? [];

        foreach ($rawParams as $p) {
            // Resolve $ref
            if (isset($p['$ref'])) {
                $p = $this->resolveRef($p['$ref'], $spec) ?? $p;
            }

            // Only query and path params (not header/cookie)
            $in = $p['in'] ?? '';
            if (!in_array($in, ['query', 'path'])) continue;

            $schema = $p['schema'] ?? [];
            $type = $this->mapSchemaType($schema);
            $enumValues = $schema['enum'] ?? null;

            $param = [
                'name' => $p['name'],
                'type' => $enumValues ? 'enum' : $type,
                'mapping' => $p['name'],
                'required' => $p['required'] ?? ($in === 'path'),
            ];

            if ($enumValues) {
                $param['values'] = $enumValues;
            }

            $params[] = $param;
        }

        return $params;
    }

    private function extractRequestBody(array $operation, array $spec): ?array
    {
        $body = $operation['requestBody'] ?? null;
        if (!$body) return null;

        // Resolve $ref
        if (isset($body['$ref'])) {
            $body = $this->resolveRef($body['$ref'], $spec) ?? $body;
        }

        $content = $body['content']['application/json']['schema'] ?? null;
        if (!$content) return null;

        // Resolve schema ref
        if (isset($content['$ref'])) {
            $content = $this->resolveRef($content['$ref'], $spec) ?? $content;
        }

        return $this->schemaToTemplate($content, $spec);
    }

    private function schemaToTemplate(array $schema, array $spec, int $depth = 0): ?array
    {
        if ($depth > 3) return null;

        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref'], $spec) ?? $schema;
        }

        $type = $schema['type'] ?? 'object';
        if ($type !== 'object' || empty($schema['properties'])) {
            return null;
        }

        $template = [];
        foreach ($schema['properties'] as $name => $prop) {
            if (isset($prop['$ref'])) {
                $prop = $this->resolveRef($prop['$ref'], $spec) ?? $prop;
            }

            $propType = $prop['type'] ?? 'string';
            $template[$name] = match ($propType) {
                'object' => $this->schemaToTemplate($prop, $spec, $depth + 1) ?? '{{' . $name . '}}',
                'array' => [],
                'integer', 'number' => '{{' . $name . '}}',
                default => '{{' . $name . '}}',
            };
        }

        return $template;
    }

    private function guessResponsePath(array $operation, array $spec): ?string
    {
        $responses = $operation['responses'] ?? [];
        $successResponse = $responses['200'] ?? $responses['201'] ?? $responses[200] ?? $responses[201] ?? null;
        if (!$successResponse) return null;

        $schema = $successResponse['content']['application/json']['schema'] ?? null;
        if (!$schema) return null;

        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref'], $spec) ?? $schema;
        }

        // Look for a "data", "results", "items" wrapper
        $props = $schema['properties'] ?? [];
        foreach (['data', 'results', 'items', 'records', 'list', 'rows', 'content', 'entries'] as $key) {
            if (isset($props[$key])) {
                $inner = $props[$key];
                if (isset($inner['$ref'])) {
                    $inner = $this->resolveRef($inner['$ref'], $spec) ?? $inner;
                }
                // If it's an array, this is likely the data path
                if (($inner['type'] ?? '') === 'array') {
                    return $key;
                }
                // Nested wrapper: data.items, data.results
                if (($inner['type'] ?? '') === 'object' && isset($inner['properties'])) {
                    foreach (['items', 'results', 'records', 'list', 'rows'] as $subkey) {
                        if (isset($inner['properties'][$subkey])) {
                            return "{$key}.{$subkey}";
                        }
                    }
                    return $key;
                }
            }
        }

        // If root response is an array, no path needed
        if (($schema['type'] ?? '') === 'array') {
            return null;
        }

        return null;
    }

    private function extractBaseUrl(array $spec, string $sourceUrl = ''): string
    {
        // Extract origin from the source URL for resolving relative paths
        $origin = '';
        if ($sourceUrl && filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($sourceUrl);
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            if (!empty($parsed['port'])) {
                $origin .= ':' . $parsed['port'];
            }
        }

        // OpenAPI 3.x
        if (!empty($spec['servers'])) {
            $serverUrl = rtrim($spec['servers'][0]['url'] ?? '', '/');
            // If it's a relative path, prepend the origin
            if ($serverUrl && !preg_match('#^https?://#', $serverUrl)) {
                return $origin . $serverUrl;
            }
            return $serverUrl;
        }

        // Swagger 2.0
        $scheme = ($spec['schemes'] ?? ['https'])[0] ?? 'https';
        $host = $spec['host'] ?? '';
        $basePath = $spec['basePath'] ?? '';

        if ($host) {
            return rtrim("{$scheme}://{$host}{$basePath}", '/');
        }

        return $origin;
    }

    private function detectAuthScheme(array $spec): array
    {
        // OpenAPI 3.x
        $schemes = $spec['components']['securitySchemes'] ?? $spec['securityDefinitions'] ?? [];

        foreach ($schemes as $name => $scheme) {
            $type = $scheme['type'] ?? '';
            $schemeVal = $scheme['scheme'] ?? '';
            $in = $scheme['in'] ?? '';

            if ($type === 'http' && $schemeVal === 'bearer') {
                return ['type' => 'bearer', 'name' => $name];
            }
            if ($type === 'apiKey' && $in === 'header') {
                return ['type' => 'header', 'name' => $name, 'header_name' => $scheme['name'] ?? 'X-API-Key'];
            }
            if ($type === 'apiKey' && $in === 'query') {
                return ['type' => 'query', 'name' => $name, 'param_name' => $scheme['name'] ?? 'api_key'];
            }
        }

        return ['type' => 'bearer', 'name' => 'default'];
    }

    /**
     * Use LLM to generate human-readable names and trigger phrases for endpoints.
     */
    private function enrichWithAI(array $rawEndpoints, array $specInfo): array
    {
        // Build a compact summary of all endpoints for the LLM
        $lines = [];
        foreach ($rawEndpoints as $i => $ep) {
            $paramNames = collect($ep['parameters'] ?? [])->pluck('name')->implode(', ');
            $lines[] = "#{$i}: {$ep['method']} {$ep['path']} — {$ep['summary']} [params: {$paramNames}] [tags: " . implode(',', $ep['tags'] ?? []) . "]";
        }
        $endpointList = implode("\n", $lines);
        $apiTitle = $specInfo['title'] ?? 'API';

        $prompt = <<<PROMPT
Tu configures un agent IA d'entreprise qui doit comprendre les requetes en langage naturel.
Voici les endpoints de l'API "{$apiTitle}":

{$endpointList}

Pour CHAQUE endpoint, genere:
1. "name": nom court et clair en francais (ex: "Lister les factures")
2. "description": description concise de ce que fait l'endpoint
3. "trigger_phrases": liste de 4-6 phrases qu'un utilisateur dirait naturellement en francais ET en anglais
   Ex pour GET /invoices: ["mes factures", "liste des factures", "factures du mois", "show invoices", "list invoices", "get my bills"]
4. "response_path": suggestion de chemin JSON pour extraire les donnees utiles (ou null si pas evident)

Reponds UNIQUEMENT en JSON (array d'objets):
[{"index": 0, "name": "...", "description": "...", "trigger_phrases": [...], "response_path": "..."}]

JSON UNIQUEMENT, pas de commentaires.
PROMPT;

        $response = $this->claude->chat(
            'Genere les metadonnees pour ces endpoints.',
            ModelResolver::resolve('balanced'),
            $prompt,
            8192
        );

        $parsed = $response ? $this->parseJsonArray($response) : null;

        // Merge AI enrichment back into raw endpoints (with fallback if LLM unavailable)
        $enriched = [];
        foreach ($rawEndpoints as $i => $ep) {
            $ai = $parsed ? (collect($parsed)->firstWhere('index', $i) ?? []) : [];

            // Generate basic trigger phrases from path if AI unavailable
            $fallbackPhrases = $this->generateFallbackPhrases($ep);

            $enriched[] = array_merge($ep, [
                'name' => $ai['name'] ?? $ep['summary'] ?: "{$ep['method']} {$ep['path']}",
                'description' => $ai['description'] ?? $ep['description'] ?? '',
                'trigger_phrases' => $ai['trigger_phrases'] ?? $fallbackPhrases,
                'response_path' => $ai['response_path'] ?? $ep['response_path'],
            ]);
        }

        return $enriched;
    }

    private function resolveRef(string $ref, array $spec): ?array
    {
        // Handle "#/components/schemas/Invoice" or "#/definitions/Invoice"
        $path = ltrim(str_replace('#/', '', $ref), '/');
        $segments = explode('/', $path);

        $current = $spec;
        foreach ($segments as $segment) {
            if (!is_array($current) || !isset($current[$segment])) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_array($current) ? $current : null;
    }

    private function mapSchemaType(array $schema): string
    {
        $type = $schema['type'] ?? 'string';
        $format = $schema['format'] ?? '';

        return match (true) {
            $type === 'integer' || $type === 'int' => 'int',
            $type === 'number' => 'float',
            $type === 'boolean' => 'bool',
            $format === 'date' || $format === 'date-time' => 'date',
            default => 'string',
        };
    }

    private function yamlToJson(string $yaml): ?array
    {
        $prompt = "Convertis ce YAML OpenAPI en JSON valide. Retourne UNIQUEMENT le JSON, rien d'autre.\n\nYAML:\n" . mb_substr($yaml, 0, 15000);
        $response = $this->claude->chat('Convertis en JSON.', ModelResolver::fast(), $prompt, 8192);
        if (!$response) return null;
        return json_decode(trim($response), true);
    }

    /**
     * Generate basic trigger phrases from endpoint path/summary when LLM is unavailable.
     */
    private function generateFallbackPhrases(array $ep): array
    {
        $phrases = [];
        $path = $ep['path'] ?? '';
        $summary = $ep['summary'] ?? '';
        $method = $ep['method'] ?? 'GET';

        // Extract resource name from path: /api/v1/invoices → invoices
        if (preg_match('/\/([a-z_-]+)\/?(?:\{|$)/i', $path, $m)) {
            $resource = str_replace(['-', '_'], ' ', $m[1]);
            $phrases[] = match ($method) {
                'GET' => "liste des {$resource}",
                'POST' => "creer {$resource}",
                'PUT', 'PATCH' => "modifier {$resource}",
                'DELETE' => "supprimer {$resource}",
                default => $resource,
            };
            $phrases[] = "mes {$resource}";
            $phrases[] = $resource;
        }

        if ($summary) {
            $phrases[] = mb_strtolower($summary);
        }

        return array_values(array_unique(array_filter($phrases)));
    }

    private function parseJsonArray(?string $text): ?array
    {
        if (!$text) return null;
        $clean = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '[') && preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }
}
