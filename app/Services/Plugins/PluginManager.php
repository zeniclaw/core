<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Log;

/**
 * Plugin manager (D12.2) — auto-discovers and manages plugins.
 * Plugins are PHP classes in the plugins/ directory that implement PluginInterface.
 */
class PluginManager
{
    private static ?self $instance = null;

    /** @var PluginInterface[] */
    private array $plugins = [];
    private bool $booted = false;

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Discover and load all plugins from the plugins/ directory (D12.2).
     */
    public function discover(): void
    {
        $pluginDir = base_path('plugins');
        if (!is_dir($pluginDir)) {
            return;
        }

        foreach (glob("{$pluginDir}/*/Plugin.php") as $file) {
            try {
                require_once $file;
                $className = $this->resolveClassName($file);
                if ($className && class_exists($className) && is_subclass_of($className, PluginInterface::class)) {
                    $plugin = new $className();
                    $this->plugins[$plugin->name()] = $plugin;
                    Log::info("Plugin loaded: {$plugin->name()} v{$plugin->version()}");
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to load plugin from {$file}: " . $e->getMessage());
            }
        }
    }

    /**
     * Register all plugins.
     */
    public function registerAll(): void
    {
        foreach ($this->plugins as $plugin) {
            try {
                $plugin->register();
            } catch (\Throwable $e) {
                Log::error("Plugin {$plugin->name()} registration failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Boot all plugins.
     */
    public function bootAll(): void
    {
        if ($this->booted) return;

        foreach ($this->plugins as $plugin) {
            try {
                $plugin->boot();
            } catch (\Throwable $e) {
                Log::error("Plugin {$plugin->name()} boot failed: " . $e->getMessage());
            }
        }

        $this->booted = true;
    }

    /**
     * Get all tool definitions from all plugins.
     */
    public function allTools(): array
    {
        $tools = [];
        foreach ($this->plugins as $plugin) {
            foreach ($plugin->tools() as $tool) {
                $tools[] = $tool;
            }
        }
        return $tools;
    }

    /**
     * Execute a plugin tool.
     */
    public function executeTool(string $name, array $input, \App\Services\AgentContext $context): ?string
    {
        foreach ($this->plugins as $plugin) {
            $result = $plugin->executeTool($name, $input, $context);
            if ($result !== null) return $result;
        }
        return null;
    }

    /**
     * Get all loaded plugins.
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * Get a plugin by name.
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Resolve class name from plugin file path.
     */
    private function resolveClassName(string $file): ?string
    {
        $content = file_get_contents($file);
        if (preg_match('/namespace\s+(.+?)\s*;/', $content, $nsMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return $nsMatch[1] . '\\' . $classMatch[1];
        }
        return null;
    }
}
