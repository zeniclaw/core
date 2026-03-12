<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Centralized model resolution by role.
 *
 * Instead of hardcoding model names everywhere, agents call:
 *   ModelResolver::fast()      — classification, JSON parsing, simple extraction
 *   ModelResolver::balanced()  — routing, analysis, summarization
 *   ModelResolver::powerful()  — complex reasoning, code generation, API agents
 *
 * Configurable via Settings UI (model_role_fast, model_role_balanced, model_role_powerful).
 */
class ModelResolver
{
    private const DEFAULTS = [
        'fast'     => 'claude-haiku-4-5-20251001',
        'balanced' => 'claude-sonnet-4-20250514',
        'powerful' => 'claude-opus-4-20250514',
    ];

    public const AVAILABLE_MODELS = [
        // Cloud (Anthropic)
        'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (rapide, economique)',
        'claude-sonnet-4-20250514'   => 'Claude Sonnet 4 (equilibre)',
        'claude-opus-4-20250514'     => 'Claude Opus 4 (puissant)',
        // On-prem (Ollama/vLLM) — ultra-light
        'qwen2.5:0.5b'              => 'Qwen 2.5 0.5B (on-prem, ultra-rapide, ~0.4 Go)',
        'qwen2.5:1.5b'              => 'Qwen 2.5 1.5B (on-prem, rapide, ~1 Go)',
        'gemma2:2b'                 => 'Gemma 2 2B (on-prem, Google, ~1.6 Go)',
        // On-prem — standard
        'qwen2.5:3b'                => 'Qwen 2.5 3B (on-prem, leger, ~2 Go)',
        'phi3:mini'                 => 'Phi-3 Mini 3.8B (on-prem, Microsoft, ~2.3 Go)',
        'llama3.2:3b'               => 'Llama 3.2 3B (on-prem, Meta, ~2 Go)',
        'qwen2.5:7b'                => 'Qwen 2.5 7B (on-prem, intelligent, ~4.7 Go)',
        'qwen2.5-coder:7b'          => 'Qwen 2.5 Coder 7B (on-prem, code, ~4.7 Go)',
        'qwen2.5:14b'               => 'Qwen 2.5 14B (on-prem, puissant, ~9 Go)',
        'deepseek-coder-v2:16b'     => 'DeepSeek Coder V2 16B (on-prem, code, ~9 Go)',
    ];

    private static ?array $cache = null;
    private static ?array $ollamaModelsCache = null;

    /**
     * Fetch models from Ollama API and merge with static list.
     */
    public static function allModels(): array
    {
        $models = self::AVAILABLE_MODELS;

        // Add Ollama models dynamically
        $ollamaModels = self::getOllamaModels();
        foreach ($ollamaModels as $name) {
            if (!isset($models[$name])) {
                $models[$name] = "{$name} (on-prem, importe)";
            }
        }

        return $models;
    }

    /**
     * Fetch installed model names from Ollama.
     */
    public static function getOllamaModels(): array
    {
        if (self::$ollamaModelsCache !== null) {
            return self::$ollamaModelsCache;
        }

        self::$ollamaModelsCache = [];

        try {
            $url = AppSetting::get('onprem_api_url');
            if (!$url) {
                return self::$ollamaModelsCache;
            }

            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $response = @file_get_contents("{$url}/api/tags", false, $ctx);
            if ($response) {
                $data = json_decode($response, true);
                foreach ($data['models'] ?? [] as $model) {
                    $name = $model['name'] ?? '';
                    if ($name) {
                        self::$ollamaModelsCache[] = $name;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ollama not available — ignore
        }

        return self::$ollamaModelsCache;
    }

    public static function fast(): string
    {
        return self::resolve('fast');
    }

    public static function balanced(): string
    {
        return self::resolve('balanced');
    }

    public static function powerful(): string
    {
        return self::resolve('powerful');
    }

    public static function resolve(string $role): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            $all = self::allModels();
            foreach (array_keys(self::DEFAULTS) as $r) {
                $setting = AppSetting::get("model_role_{$r}");
                self::$cache[$r] = ($setting && isset($all[$setting]))
                    ? $setting
                    : self::DEFAULTS[$r];
            }
        }
        return self::$cache[$role] ?? self::DEFAULTS[$role] ?? self::DEFAULTS['fast'];
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function current(): array
    {
        return [
            'fast'     => self::fast(),
            'balanced' => self::balanced(),
            'powerful' => self::powerful(),
        ];
    }
}
