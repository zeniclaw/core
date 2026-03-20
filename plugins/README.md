# ZeniClaw Plugin SDK

## Creating a Plugin

1. Create a directory: `plugins/your-plugin-name/`
2. Create `Plugin.php` implementing `App\Services\Plugins\PluginInterface`
3. Restart the app — plugins are auto-discovered

## Plugin Interface

```php
interface PluginInterface
{
    public function name(): string;        // Unique identifier
    public function version(): string;     // Semantic version
    public function description(): string; // Human-readable description
    public function register(): void;      // Register hooks/listeners
    public function boot(): void;          // Post-registration init
    public function tools(): array;        // Tool definitions
    public function executeTool(string $name, array $input, AgentContext $context): ?string;
}
```

## Available Lifecycle Events

Subscribe in `register()`:

- `BeforeRouting` — before message routing
- `AfterRouting` — after routing decision
- `BeforeAgentHandle` — before agent processes message
- `AfterAgentHandle` — after agent response
- `BeforeToolCall` — before tool execution
- `AfterToolCall` — after tool execution (includes timing)
- `MessageReceived` — incoming message on any channel
- `MessageSent` — outgoing message on any channel
- `SubagentSpawned` — background task started
- `SubagentEnded` — background task completed
- `SessionStarted` — new user session created
- `SessionEnded` — session terminated
- `BeforeMemorySave` — before storing a memory fact
- `ProviderFallback` — when LLM provider fails over

## Example Plugin

See `plugins/example/Plugin.php` for a working example.
