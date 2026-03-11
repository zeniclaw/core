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
        // On-prem (Ollama/vLLM)
        'qwen2.5:3b'                => 'Qwen 2.5 3B (on-prem, leger)',
        'qwen2.5:7b'                => 'Qwen 2.5 7B (on-prem, intelligent)',
        'qwen2.5:14b'               => 'Qwen 2.5 14B (on-prem, puissant)',
        'qwen2.5-coder:7b'          => 'Qwen 2.5 Coder 7B (on-prem, code)',
        'llama3.2:3b'               => 'Llama 3.2 3B (on-prem, Meta)',
        'gemma2:2b'                 => 'Gemma 2 2B (on-prem, Google)',
        'phi3:mini'                 => 'Phi-3 Mini (on-prem, Microsoft)',
        'deepseek-coder-v2:16b'     => 'DeepSeek Coder V2 (on-prem, code)',
    ];

    private static ?array $cache = null;

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
            foreach (array_keys(self::DEFAULTS) as $r) {
                $setting = AppSetting::get("model_role_{$r}");
                self::$cache[$r] = ($setting && isset(self::AVAILABLE_MODELS[$setting]))
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
