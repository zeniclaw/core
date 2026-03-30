<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Code execution sandbox (D11.1) — runs code in an isolated Docker container.
 * Safety guards (D11.4): no network, memory limit, timeout.
 * Reuses existing Docker infrastructure (D11.5).
 */
class CodeSandbox
{
    private const TIMEOUT_SECONDS = 30;
    private const MEMORY_LIMIT = '256m';
    private const MAX_OUTPUT_LENGTH = 10000;

    /**
     * Supported languages and their Docker images/commands.
     */
    private const LANGUAGES = [
        'python' => ['image' => 'python:3.12-slim', 'cmd' => 'python3'],
        'php' => ['image' => 'php:8.4-cli', 'cmd' => 'php'],
        'bash' => ['image' => 'bash:5', 'cmd' => 'bash'],
        'node' => ['image' => 'node:22-slim', 'cmd' => 'node'],
    ];

    /**
     * Execute code in a sandboxed container.
     *
     * @param string $code Code to execute
     * @param string $language Language (python, php, bash, node)
     * @return array{success: bool, output: string, error: ?string, language: string, duration_ms: int}
     */
    public function run(string $code, string $language = 'python'): array
    {
        $language = strtolower($language);

        if (!isset(self::LANGUAGES[$language])) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'Langage non supporte. Langages disponibles: ' . implode(', ', array_keys(self::LANGUAGES)),
                'language' => $language,
                'duration_ms' => 0,
            ];
        }

        // Safety check: block dangerous commands
        $blocked = $this->checkSafety($code, $language);
        if ($blocked) {
            return [
                'success' => false,
                'output' => '',
                'error' => "Code bloque pour raison de securite: {$blocked}",
                'language' => $language,
                'duration_ms' => 0,
            ];
        }

        $config = self::LANGUAGES[$language];
        $start = microtime(true);

        try {
            // Write code to a temp file
            $tmpDir = sys_get_temp_dir() . '/sandbox_' . uniqid();
            mkdir($tmpDir, 0755, true);
            $ext = match ($language) {
                'python' => 'py',
                'php' => 'php',
                'bash' => 'sh',
                'node' => 'js',
            };
            $codeFile = "{$tmpDir}/code.{$ext}";

            // For PHP, ensure <?php prefix
            if ($language === 'php' && !str_starts_with(trim($code), '<?')) {
                $code = "<?php\n" . $code;
            }

            file_put_contents($codeFile, $code);

            // Detect container runtime
            $runtime = self::detectRuntime();

            // Run in container with safety guards
            $dockerCmd = sprintf(
                '%s run --rm --network=none --memory=%s --cpus=1 ' .
                '--read-only --tmpfs /tmp:rw,noexec,nosuid ' .
                '-v %s:/code:ro ' .
                '-w /code ' .
                '%s ' .
                'timeout %d %s /code/code.%s 2>&1',
                $runtime,
                self::MEMORY_LIMIT,
                escapeshellarg($tmpDir),
                escapeshellarg($config['image']),
                self::TIMEOUT_SECONDS,
                $config['cmd'],
                $ext
            );

            $process = Process::timeout(self::TIMEOUT_SECONDS + 5)->run($dockerCmd);
            $output = $process->output();
            $exitCode = $process->exitCode();

            // Cleanup
            @unlink($codeFile);
            @rmdir($tmpDir);

            $durationMs = (int) ((microtime(true) - $start) * 1000);

            // Truncate output
            if (mb_strlen($output) > self::MAX_OUTPUT_LENGTH) {
                $output = mb_substr($output, 0, self::MAX_OUTPUT_LENGTH) . "\n... (output tronque)";
            }

            return [
                'success' => $exitCode === 0,
                'output' => $output,
                'error' => $exitCode !== 0 ? "Exit code: {$exitCode}" : null,
                'language' => $language,
                'duration_ms' => $durationMs,
                'exit_code' => $exitCode,
            ];
        } catch (\Exception $e) {
            // Cleanup on error
            if (isset($codeFile)) @unlink($codeFile);
            if (isset($tmpDir)) @rmdir($tmpDir);

            $durationMs = (int) ((microtime(true) - $start) * 1000);

            Log::error('CodeSandbox execution failed', ['error' => $e->getMessage(), 'language' => $language]);

            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
                'language' => $language,
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Safety guards: check for dangerous patterns.
     */
    private function checkSafety(string $code, string $language): ?string
    {
        $patterns = [
            '/\brm\s+-rf\s+\//i' => 'destruction de fichiers systeme',
            '/\bfork\s*bomb\b/i' => 'fork bomb',
            '/:\(\)\s*\{\s*:\|:\s*&\s*\}\s*;/i' => 'fork bomb',
            '/\bdd\s+if=\/dev\//i' => 'acces disque direct',
            '/\b(curl|wget)\b/i' => 'acces reseau (container isole)',
        ];

        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Check if a container runtime is available for sandbox execution.
     */
    public static function isAvailable(): bool
    {
        try {
            $runtime = self::detectRuntime();
            $result = Process::timeout(5)->run("{$runtime} info 2>/dev/null");
            return $result->exitCode() === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Detect available container runtime (podman preferred over docker).
     */
    private static function detectRuntime(): string
    {
        try {
            if (Process::timeout(3)->run('which podman')->successful()) {
                return 'podman';
            }
        } catch (\Exception $e) {
            // fall through
        }

        return 'docker';
    }
}
