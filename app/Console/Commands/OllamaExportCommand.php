<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaExportCommand extends Command
{
    protected $signature = 'ollama:export {model? : Model to export (e.g. qwen2.5:3b). Omit to export all.}';
    protected $description = 'Export Ollama models as tar.gz for offline distribution';

    private string $exportDir;
    private string $ollamaContainer = 'zeniclaw_ollama';

    public function handle(): int
    {
        $this->exportDir = storage_path('app/ollama-exports');
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }

        $model = $this->argument('model');

        if ($model) {
            return $this->exportModel($model) ? 0 : 1;
        }

        // Export all models
        $models = $this->getInstalledModels();
        if (empty($models)) {
            $this->error('No models found in Ollama.');
            return 1;
        }

        $this->info("Found " . count($models) . " model(s). Exporting...");
        $failed = 0;
        foreach ($models as $m) {
            if (!$this->exportModel($m)) {
                $failed++;
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Execute a command inside the Ollama container via Docker socket API.
     */
    private function dockerExec(string $cmd): ?string
    {
        // Create exec instance
        $createPayload = json_encode([
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => ['sh', '-c', $cmd],
        ]);

        $createResult = $this->curlDockerSocket(
            "POST",
            "/containers/{$this->ollamaContainer}/exec",
            $createPayload
        );

        $execId = $createResult['Id'] ?? null;
        if (!$execId) {
            $this->error("  Failed to create exec: " . json_encode($createResult));
            return null;
        }

        // Start exec and capture output
        $startPayload = json_encode(['Detach' => false, 'Tty' => false]);
        $output = $this->curlDockerSocketRaw(
            "POST",
            "/exec/{$execId}/start",
            $startPayload
        );

        return $output;
    }

    /**
     * Call Docker socket API and return JSON.
     */
    private function curlDockerSocket(string $method, string $path, ?string $body = null): array
    {
        $raw = $this->curlDockerSocketRaw($method, $path, $body);
        return json_decode($raw, true) ?? [];
    }

    /**
     * Call Docker socket API and return raw response.
     */
    private function curlDockerSocketRaw(string $method, string $path, ?string $body = null): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_URL => "http://localhost/v1.45{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        curl_close($ch);
        return $result ?: '';
    }

    /**
     * Copy a file out of the Ollama container via Docker API archive endpoint.
     */
    private function dockerCpFrom(string $containerPath, string $hostPath): bool
    {
        $ch = curl_init();
        $fp = fopen($hostPath, 'w');

        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_URL => "http://localhost/v1.45/containers/{$this->ollamaContainer}/archive?path=" . urlencode($containerPath),
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 600,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $result && $httpCode === 200;
    }

    private function getInstalledModels(): array
    {
        $baseUrl = $this->getOllamaUrl();
        if (!$baseUrl) return [];

        try {
            $response = Http::timeout(10)->get("{$baseUrl}/api/tags");
            if ($response->successful()) {
                return collect($response->json('models') ?? [])
                    ->pluck('name')
                    ->all();
            }
        } catch (\Exception $e) {
            $this->error("Cannot connect to Ollama: " . $e->getMessage());
        }

        return [];
    }

    private function getOllamaUrl(): ?string
    {
        $url = AppSetting::get('onprem_api_url');
        if ($url) return $url;

        foreach (['http://ollama:11434', 'http://localhost:11434'] as $candidate) {
            try {
                if (Http::timeout(3)->get("{$candidate}/api/tags")->successful()) {
                    return $candidate;
                }
            } catch (\Exception $e) {}
        }

        return null;
    }

    private function exportModel(string $model): bool
    {
        $this->info("Exporting {$model}...");

        // Parse model name and tag
        $parts = explode(':', $model);
        $name = $parts[0];
        $tag = $parts[1] ?? 'latest';
        $slug = str_replace(['/', '.', ':'], '-', "{$name}:{$tag}");

        // Read the manifest via docker exec
        $manifestPath = "/root/.ollama/models/manifests/registry.ollama.ai/library/{$name}/{$tag}";
        $manifestJson = $this->dockerExec("cat " . escapeshellarg($manifestPath));

        // Docker exec stream has 8-byte header frames — strip them
        $manifestJson = $this->stripDockerStreamHeaders($manifestJson);

        if (!$manifestJson) {
            $this->error("  Model {$model} not found in Ollama.");
            return false;
        }

        $manifest = json_decode(trim($manifestJson), true);
        if (!$manifest) {
            $this->error("  Invalid manifest for {$model}.");
            return false;
        }

        // Collect all blob digests (config + layers)
        $digests = [];
        if (isset($manifest['config']['digest'])) {
            $digests[] = $manifest['config']['digest'];
        }
        foreach ($manifest['layers'] ?? [] as $layer) {
            if (isset($layer['digest'])) {
                $digests[] = $layer['digest'];
            }
        }

        // Build list of files to tar (manifest + blobs)
        $blobFiles = [];
        foreach ($digests as $digest) {
            $blobName = str_replace(':', '-', $digest);
            $blobFiles[] = "models/blobs/{$blobName}";
        }

        $manifestRelative = "models/manifests/registry.ollama.ai/library/{$name}/{$tag}";
        $allFiles = array_merge([$manifestRelative], $blobFiles);
        $fileList = implode(' ', array_map('escapeshellarg', $allFiles));

        $outputFile = "/tmp/ollama-export-{$slug}.tar.gz";
        $destFile = $this->exportDir . "/ollama-{$slug}.tar.gz";

        // Create tar.gz inside the Ollama container
        $this->info("  Packing " . count($digests) . " blob(s)...");
        $tarResult = $this->dockerExec("tar czf {$outputFile} -C /root/.ollama {$fileList}");

        // Copy the tar.gz out via Docker archive API.
        // The API returns a tar stream wrapping the file — we download it then extract.
        $this->info("  Copying out of container...");
        $wrapperTar = $destFile . '.wrapper.tar';
        $this->dockerArchiveDownload($outputFile, $wrapperTar);

        // Extract our tar.gz from the wrapper tar
        if (file_exists($wrapperTar) && filesize($wrapperTar) > 512) {
            $basename = basename($outputFile);
            $extractDir = sys_get_temp_dir() . '/ollama-extract-' . uniqid();
            @mkdir($extractDir, 0755, true);
            exec("tar xf " . escapeshellarg($wrapperTar) . " -C " . escapeshellarg($extractDir) . " 2>&1");
            $extracted = $extractDir . '/' . $basename;
            if (file_exists($extracted)) {
                rename($extracted, $destFile);
            }
            @unlink($wrapperTar);
            @rmdir($extractDir);
        }

        // Cleanup inside container
        $this->dockerExec("rm -f {$outputFile}");

        if (!file_exists($destFile) || filesize($destFile) < 1000) {
            $this->error("  Export failed for {$model}.");
            @unlink($destFile);
            return false;
        }

        $size = round(filesize($destFile) / 1024 / 1024);
        $this->info("  Exported: ollama-{$slug}.tar.gz ({$size} MB)");

        // Write metadata
        $metaFile = $this->exportDir . "/ollama-{$slug}.json";
        file_put_contents($metaFile, json_encode([
            'model' => "{$name}:{$tag}",
            'name' => $name,
            'tag' => $tag,
            'size' => filesize($destFile),
            'layers' => count($digests),
            'exported_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Download a file from a container using Docker archive API.
     * Streams directly to disk — no memory issues with large files.
     */
    private function dockerArchiveDownload(string $containerPath, string $hostPath): bool
    {
        $fp = fopen($hostPath, 'w');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_URL => "http://localhost/v1.45/containers/{$this->ollamaContainer}/archive?path=" . urlencode($containerPath),
            CURLOPT_TIMEOUT => 1200,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fp,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $result && $httpCode === 200;
    }

    /**
     * Execute a command in the container and stream binary output to a file.
     * Uses Docker exec API with raw stream capture.
     */
    private function dockerExecBinary(string $cmd, string $outputPath): bool
    {
        // Create exec
        $createPayload = json_encode([
            'AttachStdout' => true,
            'AttachStderr' => false,
            'Cmd' => ['sh', '-c', $cmd],
        ]);

        $createResult = $this->curlDockerSocket("POST", "/containers/{$this->ollamaContainer}/exec", $createPayload);
        $execId = $createResult['Id'] ?? null;
        if (!$execId) return false;

        // Start exec and stream to file, properly handling Docker multiplexed
        // stream frames that may be split across curl callback chunks.
        $fp = fopen($outputPath, 'w');
        $buffer = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
            CURLOPT_URL => "http://localhost/v1.45/exec/{$execId}/start",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{"Detach":false,"Tty":false}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fp, &$buffer) {
                // Docker multiplexed stream: 8-byte header + payload
                // Header: [type(1)][0][0][0][size(4 big-endian)]
                $buffer .= $data;

                while (strlen($buffer) >= 8) {
                    $header = unpack('Ctype/C3pad/Nsize', substr($buffer, 0, 8));
                    $payloadSize = $header['size'];
                    $frameTotal = 8 + $payloadSize;

                    if (strlen($buffer) < $frameTotal) {
                        // Incomplete frame — wait for more data
                        break;
                    }

                    if ($header['type'] === 1 && $payloadSize > 0) { // stdout
                        fwrite($fp, substr($buffer, 8, $payloadSize));
                    }
                    $buffer = substr($buffer, $frameTotal);
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return file_exists($outputPath) && filesize($outputPath) > 0;
    }

    /**
     * Strip Docker stream multiplexing headers from output.
     */
    private function stripDockerStreamHeaders(?string $data): string
    {
        if (!$data || strlen($data) < 8) return $data ?? '';

        $result = '';
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            if ($offset + 8 > $len) {
                $result .= substr($data, $offset);
                break;
            }

            $header = unpack('Ctype/C3pad/Nsize', substr($data, $offset, 8));
            $payloadSize = $header['size'];
            $offset += 8;

            if ($payloadSize > 0 && $offset + $payloadSize <= $len) {
                $result .= substr($data, $offset, $payloadSize);
                $offset += $payloadSize;
            } else {
                $result .= substr($data, $offset);
                break;
            }
        }

        return $result;
    }
}
