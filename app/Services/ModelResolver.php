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
        'balanced' => 'claude-sonnet-4-6',
        'powerful' => 'claude-opus-4-6',
    ];

    /** Cloud models — only current versions */
    public const CLOUD_MODELS = [
        'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5',
        'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
        'claude-opus-4-6'           => 'Claude Opus 4.6',
    ];

    /** On-prem model labels (used when installed via Ollama) */
    public const ONPREM_LABELS = [
        'qwen2.5:0.5b'              => 'Qwen 2.5 0.5B (ultra-rapide)',
        'qwen2.5:1.5b'              => 'Qwen 2.5 1.5B (rapide)',
        'gemma2:2b'                 => 'Gemma 2 2B (Google)',
        'qwen2.5:3b'                => 'Qwen 2.5 3B (leger)',
        'phi3:mini'                 => 'Phi-3 Mini 3.8B (Microsoft)',
        'llama3.2:3b'               => 'Llama 3.2 3B (Meta)',
        'qwen2.5:7b'                => 'Qwen 2.5 7B (intelligent)',
        'qwen2.5-coder:7b'          => 'Qwen 2.5 Coder 7B (code)',
        'qwen2.5:14b'               => 'Qwen 2.5 14B (puissant)',
        'deepseek-coder-v2:16b'     => 'DeepSeek Coder V2 16B (code)',
        'mistral:7b'                => 'Mistral 7B (francais)',
        'llama3.1:8b'               => 'Llama 3.1 8B (Meta)',
        'mistral-small:22b'         => 'Mistral Small 22B (francais+)',
        'mixtral:8x7b'              => 'Mixtral 8x7B (MoE)',
        'llava:7b'                  => 'LLaVA 7B (vision)',
        'llava:13b'                 => 'LLaVA 13B (vision+)',
        'minicpm-v'                 => 'MiniCPM-V (vision, OCR)',
        'llama3.2-vision:11b'       => 'Llama 3.2 Vision 11B (vision, OCR)',
        'hermes3:8b'                => 'Hermes 3 8B (function calling)',
        'mistral-nemo:12b'          => 'Mistral Nemo 12B (function calling)',
        'command-r:7b'              => 'Command R 7B (function calling, RAG)',
        'qwen2.5:32b'               => 'Qwen 2.5 32B (function calling, puissant)',
    ];

    /** @deprecated Use CLOUD_MODELS + ONPREM_LABELS instead */
    public const AVAILABLE_MODELS = self::CLOUD_MODELS;

    private static ?array $cache = null;
    private static ?array $ollamaModelsCache = null;

    /**
     * Return only available models: cloud + installed on-prem.
     */
    public static function allModels(): array
    {
        $ollamaModels = self::getOllamaModels();
        $models = [];

        // Cloud models — always available
        foreach (self::CLOUD_MODELS as $key => $label) {
            $models[$key] = $label;
        }

        // On-prem — only installed models
        foreach ($ollamaModels as $name) {
            $label = self::ONPREM_LABELS[$name] ?? $name;
            $models[$name] = $label . ' [on-prem]';
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
