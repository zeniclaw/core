<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentEndpoint extends Model
{
    protected $fillable = [
        'custom_agent_id', 'name', 'description', 'method', 'url',
        'auth_type', 'auth_credential_key', 'trigger_phrases', 'parameters',
        'response_path', 'headers', 'request_body_template',
        'is_active', 'created_by_share_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trigger_phrases' => 'array',
        'parameters' => 'array',
        'headers' => 'array',
        'request_body_template' => 'array',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    public function share(): BelongsTo
    {
        return $this->belongsTo(CustomAgentShare::class, 'created_by_share_id');
    }

    /**
     * Get the decrypted auth credential value for this endpoint.
     */
    public function getAuthValue(): ?string
    {
        if (!$this->auth_credential_key) {
            return null;
        }

        return $this->customAgent->getCredential($this->auth_credential_key);
    }

    /**
     * Validate extracted parameters against the declared schema.
     * Returns ['valid' => bool, 'errors' => [...], 'sanitized' => [...]]
     */
    public function validateParams(array $extracted): array
    {
        $schema = $this->parameters ?? [];
        $errors = [];
        $sanitized = [];

        foreach ($schema as $param) {
            $name = $param['name'];
            $type = $param['type'] ?? 'string';
            $required = $param['required'] ?? false;
            $value = $extracted[$name] ?? null;

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = "Parametre requis manquant: {$name}";
                }
                continue;
            }

            // Type validation
            $sanitized[$name] = match ($type) {
                'int', 'integer' => is_numeric($value) ? (int) $value : ($errors[] = "{$name}: entier attendu") ?? null,
                'float', 'number' => is_numeric($value) ? (float) $value : ($errors[] = "{$name}: nombre attendu") ?? null,
                'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ($errors[] = "{$name}: booleen attendu") ?? null,
                'enum' => in_array($value, $param['values'] ?? [])
                    ? $value
                    : ($errors[] = "{$name}: valeur invalide (attendu: " . implode(', ', $param['values'] ?? []) . ")") ?? null,
                'date' => $this->parseDate($value) ?: ($errors[] = "{$name}: date invalide") ?? null,
                default => (string) $value,
            };
        }

        // Remove nulls from sanitized (failed validations)
        $sanitized = array_filter($sanitized, fn($v) => $v !== null);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * Build the full URL with query parameters for GET requests.
     */
    public function buildUrl(array $params): string
    {
        $url = $this->url;

        // Replace path placeholders: /api/invoices/{id}
        foreach ($params as $key => $value) {
            $url = str_replace("{{$key}}", urlencode((string) $value), $url);
        }

        if ($this->method === 'GET' && !empty($params)) {
            $schema = collect($this->parameters ?? []);
            $queryParams = [];
            foreach ($params as $key => $value) {
                $paramDef = $schema->firstWhere('name', $key);
                $mapping = $paramDef['mapping'] ?? $key;
                // Don't add params that were used as path placeholders
                if (!str_contains($this->url, "{{$key}}")) {
                    $queryParams[$mapping] = $value;
                }
            }
            if ($queryParams) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator . http_build_query($queryParams);
            }
        }

        return $url;
    }

    /**
     * Build request body for POST/PUT/PATCH, merging template with extracted params.
     */
    public function buildBody(array $params): ?array
    {
        if (in_array($this->method, ['GET', 'DELETE'])) {
            return null;
        }

        $template = $this->request_body_template ?? [];
        if (empty($template)) {
            return $params;
        }

        // Replace {{param}} placeholders in template
        return $this->replacePlaceholders($template, $params);
    }

    /**
     * Build HTTP headers including auth.
     */
    public function buildHeaders(): array
    {
        $headers = $this->headers ?? [];
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        $authValue = $this->getAuthValue();
        if ($authValue) {
            match ($this->auth_type) {
                'bearer' => $headers['Authorization'] = "Bearer {$authValue}",
                'header' => $headers['X-API-Key'] = $authValue,
                'query' => null, // handled in buildUrl
                default => null,
            };
        }

        return $headers;
    }

    private function parseDate(mixed $value): ?string
    {
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function replacePlaceholders(array $template, array $params): array
    {
        $result = [];
        foreach ($template as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->replacePlaceholders($value, $params);
            } elseif (is_string($value) && preg_match('/^\{\{(\w+)\}\}$/', $value, $m)) {
                $result[$key] = $params[$m[1]] ?? $value;
            } elseif (is_string($value)) {
                $result[$key] = preg_replace_callback('/\{\{(\w+)\}\}/', fn($m) => $params[$m[1]] ?? $m[0], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
