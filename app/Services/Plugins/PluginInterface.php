<?php

namespace App\Services\Plugins;

/**
 * Plugin interface (D12.2) — defines the contract for ZeniClaw plugins.
 * Plugins can add tools, hooks, and custom behavior.
 */
interface PluginInterface
{
    /**
     * Get the plugin name (unique identifier).
     */
    public function name(): string;

    /**
     * Get the plugin version.
     */
    public function version(): string;

    /**
     * Get plugin description.
     */
    public function description(): string;

    /**
     * Register hooks/listeners when the plugin is loaded.
     */
    public function register(): void;

    /**
     * Boot the plugin after all plugins are registered.
     */
    public function boot(): void;

    /**
     * Get tool definitions this plugin provides.
     */
    public function tools(): array;

    /**
     * Execute a tool by name.
     */
    public function executeTool(string $name, array $input, \App\Services\AgentContext $context): ?string;
}
