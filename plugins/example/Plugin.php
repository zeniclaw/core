<?php

namespace Plugins\Example;

use App\Services\AgentContext;
use App\Services\Plugins\PluginInterface;

/**
 * Example plugin demonstrating the ZeniClaw plugin system.
 * This plugin adds a simple "hello_world" tool to all agents.
 *
 * To create your own plugin:
 * 1. Create a directory in plugins/ with your plugin name
 * 2. Create a Plugin.php file implementing PluginInterface
 * 3. The PluginManager will auto-discover it on next boot
 */
class Plugin implements PluginInterface
{
    public function name(): string
    {
        return 'example';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Plugin d\'exemple pour ZeniClaw — ajoute un outil hello_world et un compteur de mots.';
    }

    public function register(): void
    {
        // Register hooks, event listeners, etc.
    }

    public function boot(): void
    {
        // Plugin initialization after all plugins are registered
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'hello_world',
                'description' => 'Un outil de test qui repond "Hello World!" avec des informations sur le plugin.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Nom a saluer (optionnel)'],
                    ],
                ],
            ],
            [
                'name' => 'word_count',
                'description' => 'Compter le nombre de mots, caracteres et phrases dans un texte.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Texte a analyser'],
                    ],
                    'required' => ['text'],
                ],
            ],
        ];
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'hello_world' => $this->helloWorld($input),
            'word_count' => $this->wordCount($input),
            default => null,
        };
    }

    private function helloWorld(array $input): string
    {
        $name = $input['name'] ?? 'World';
        return json_encode([
            'message' => "Hello, {$name}! 👋",
            'plugin' => $this->name(),
            'version' => $this->version(),
            'description' => $this->description(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function wordCount(array $input): string
    {
        $text = $input['text'] ?? '';
        $words = str_word_count($text, 0, 'àâäéèêëïîôùûüÿçœæ');
        $chars = mb_strlen($text);
        $sentences = preg_match_all('/[.!?]+/', $text);
        $paragraphs = count(array_filter(preg_split('/\n\s*\n/', $text)));

        return json_encode([
            'words' => $words,
            'characters' => $chars,
            'sentences' => $sentences,
            'paragraphs' => $paragraphs,
            'avg_word_length' => $words > 0 ? round($chars / $words, 1) : 0,
        ]);
    }
}
