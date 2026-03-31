<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaController extends Controller
{
    private function getBaseUrl(): ?string
    {
        return AppSetting::get('onprem_api_url');
    }

    private function getOrDetectBaseUrl(): ?string
    {
        $url = $this->getBaseUrl();
        if ($url) {
            return $url;
        }

        foreach (['http://ollama:11434', 'http://localhost:11434'] as $candidate) {
            try {
                if (Http::timeout(3)->get($candidate . '/api/tags')->successful()) {
                    AppSetting::set('onprem_api_url', $candidate);
                    return $candidate;
                }
            } catch (\Exception $e) {}
        }

        return null;
    }

    private function getHeaders(): array
    {
        $headers = ['content-type' => 'application/json'];
        $apiKey = AppSetting::get('onprem_api_key');
        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }
        return $headers;
    }

    /**
     * Save on-prem URL (called automatically before pull).
     */
    public function saveUrl(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|string|url']);
        AppSetting::set('onprem_api_url', $request->input('url'));
        return response()->json(['ok' => true]);
    }

    /**
     * List locally available models from Ollama.
     */
    public function models(): JsonResponse
    {
        $baseUrl = $this->getOrDetectBaseUrl();

        if (!$baseUrl) {
            return response()->json(['error' => 'On-prem URL not configured'], 422);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->getHeaders())
                ->get(rtrim($baseUrl, '/') . '/api/tags');

            if ($response->successful()) {
                $models = collect($response->json('models') ?? [])
                    ->map(fn($m) => [
                        'name' => $m['name'] ?? $m['model'] ?? 'unknown',
                        'size' => $m['size'] ?? 0,
                        'modified_at' => $m['modified_at'] ?? null,
                    ]);
                return response()->json(['models' => $models]);
            }

            return response()->json(['error' => 'Ollama unreachable', 'status' => $response->status()], 502);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Cannot connect to Ollama: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Start pulling a model. Runs in a background process via cache-based progress tracking.
     */
    public function pull(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string|max:100']);

        $model = $request->input('model');
        $baseUrl = $this->getOrDetectBaseUrl();

        if (!$baseUrl) {
            return response()->json(['error' => 'On-prem URL not configured'], 422);
        }

        $cacheKey = "ollama_pull_{$model}";
        $force = $request->boolean('force', false);

        // Check if already pulling (skip if force retry or error)
        $current = Cache::get($cacheKey);
        if (!$force && $current && ($current['status'] ?? '') === 'pulling') {
            return response()->json(['message' => 'Already pulling', 'progress' => $current]);
        }

        // Auto-clear error status (allow retry without force)
        if ($current && ($current['status'] ?? '') === 'error') {
            $force = true;
        }

        // Clear previous error/status on force retry
        if ($force) {
            Cache::forget($cacheKey);
        }

        // Mark as started
        Cache::put($cacheKey, [
            'status' => 'pulling',
            'percent' => 0,
            'detail' => 'Starting download...',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        // Dispatch background pull via artisan command
        $artisanPath = base_path('artisan');
        $cmd = sprintf(
            'nohup php %s ollama:pull %s > /dev/null 2>&1 &',
            escapeshellarg($artisanPath),
            escapeshellarg($model)
        );
        exec($cmd);

        return response()->json(['message' => 'Pull started', 'model' => $model]);
    }

    /**
     * Check if the Ollama container is running.
     */
    public function status(): JsonResponse
    {
        $baseUrl = $this->getBaseUrl() ?? 'http://ollama:11434';

        // Try to reach Ollama
        $running = false;
        $version = null;
        try {
            $res = Http::timeout(3)->get(rtrim($baseUrl, '/') . '/api/version');
            if ($res->successful()) {
                $running = true;
                $version = $res->json('version');
            }
        } catch (\Exception $e) {}

        // Check Docker container status via socket
        $containerStatus = null;
        $socketPath = '/var/run/docker.sock';
        if (file_exists($socketPath)) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_UNIX_SOCKET_PATH => $socketPath,
                    CURLOPT_URL => 'http://localhost/v1.41/containers/json?all=true&filters=' . urlencode('{"name":["zeniclaw_ollama"]}'),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 3,
                ]);
                $result = curl_exec($ch);
                curl_close($ch);
                if ($result) {
                    $containers = json_decode($result, true);
                    if (!empty($containers[0])) {
                        $containerStatus = $containers[0]['State'] ?? 'unknown';
                    }
                }
            } catch (\Exception $e) {}
        }

        return response()->json([
            'running' => $running,
            'version' => $version,
            'container_status' => $containerStatus, // running, exited, created, null (not found)
            'url' => $baseUrl,
        ]);
    }

    /**
     * Start the Ollama container via Docker socket.
     */
    public function start(): JsonResponse
    {
        $socketPath = '/var/run/docker.sock';
        if (!file_exists($socketPath)) {
            return response()->json(['error' => 'Docker socket not available'], 500);
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_UNIX_SOCKET_PATH => $socketPath,
                CURLOPT_URL => 'http://localhost/v1.41/containers/zeniclaw_ollama/start',
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 204 || $httpCode === 304) {
                return response()->json(['ok' => true, 'message' => 'Ollama demarre']);
            }

            return response()->json(['error' => "Docker returned HTTP {$httpCode}", 'detail' => $result], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Preload/warm a model into Ollama memory to avoid cold start.
     */
    public function warmup(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string|max:100']);
        $model = $request->input('model');

        $baseUrl = $this->getOrDetectBaseUrl();
        if (!$baseUrl) {
            return response()->json(['error' => 'Ollama non configure'], 422);
        }

        // Check if it's an on-prem model (not cloud)
        if (str_starts_with($model, 'claude-') || str_starts_with($model, 'gpt-')) {
            return response()->json(['ok' => true, 'message' => 'Modele cloud, pas de warm-up necessaire']);
        }

        try {
            // Send a minimal generate request with keep_alive=-1 to load model into memory
            $response = Http::timeout(120)
                ->withHeaders($this->getHeaders())
                ->post(rtrim($baseUrl, '/') . '/api/generate', [
                    'model' => $model,
                    'prompt' => 'hi',
                    'keep_alive' => -1,
                    'stream' => false,
                    'options' => ['num_predict' => 1],
                ]);

            if ($response->successful()) {
                return response()->json(['ok' => true, 'message' => "Modele {$model} charge en memoire"]);
            }

            return response()->json(['error' => 'Ollama error: ' . $response->body()], 502);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Get currently loaded models in Ollama memory.
     */
    public function loaded(): JsonResponse
    {
        $baseUrl = $this->getOrDetectBaseUrl();
        if (!$baseUrl) {
            return response()->json(['models' => []]);
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders($this->getHeaders())
                ->get(rtrim($baseUrl, '/') . '/api/ps');

            if ($response->successful()) {
                $models = collect($response->json('models') ?? [])->map(fn($m) => [
                    'name' => $m['name'] ?? '',
                    'size' => $m['size'] ?? 0,
                    'vram' => $m['size_vram'] ?? 0,
                    'expires_at' => $m['expires_at'] ?? null,
                ]);
                return response()->json(['models' => $models]);
            }
        } catch (\Exception $e) {}

        return response()->json(['models' => []]);
    }

    /**
     * Unload a model from Ollama memory.
     */
    public function unload(Request $request): JsonResponse
    {
        $request->validate(['model' => 'required|string|max:100']);
        $model = $request->input('model');

        $baseUrl = $this->getOrDetectBaseUrl();
        if (!$baseUrl) {
            return response()->json(['error' => 'Ollama non configure'], 422);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders($this->getHeaders())
                ->post(rtrim($baseUrl, '/') . '/api/generate', [
                    'model' => $model,
                    'keep_alive' => 0,
                    'stream' => false,
                ]);

            if ($response->successful()) {
                return response()->json(['ok' => true, 'message' => "Modele {$model} decharge de la memoire"]);
            }

            return response()->json(['error' => 'Ollama error: ' . $response->body()], 502);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Server hardware check + model compatibility analysis.
     */
    public function serverCheck(): JsonResponse
    {
        // CPU
        $cpuModel = 'Unknown';
        $cpuCores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
                $cpuModel = trim($m[1]);
            }
            $cpuCores = substr_count($cpuinfo, 'processor');
        }

        // RAM
        $ramTotalKb = 0;
        $ramAvailableKb = 0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $m)) $ramTotalKb = (int)$m[1];
            if (preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $m)) $ramAvailableKb = (int)$m[1];
        }
        $ramTotalGb = round($ramTotalKb / 1024 / 1024, 1);
        $ramAvailableGb = round($ramAvailableKb / 1024 / 1024, 1);
        $ramUsedGb = round($ramTotalGb - $ramAvailableGb, 1);

        // Disk
        $diskTotal = @disk_total_space('/');
        $diskFree = @disk_free_space('/');
        $diskTotalGb = $diskTotal ? round($diskTotal / 1024 / 1024 / 1024, 1) : 0;
        $diskFreeGb = $diskFree ? round($diskFree / 1024 / 1024 / 1024, 1) : 0;
        $diskUsedGb = round($diskTotalGb - $diskFreeGb, 1);

        // GPU detection
        $gpu = null;
        $gpuVram = 0;
        $nvidiaSmi = @shell_exec('nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits 2>/dev/null');
        if ($nvidiaSmi && !str_contains($nvidiaSmi, 'not found')) {
            $parts = explode(',', trim($nvidiaSmi));
            $gpu = trim($parts[0] ?? '');
            $gpuVram = (int)trim($parts[1] ?? 0); // MB
        }

        // Load average
        $loadAvg = sys_getloadavg() ?: [0, 0, 0];

        // Model catalog with requirements
        $models = [
            // On-prem / Ollama models
            ['id' => 'qwen2.5:0.5b', 'name' => 'Qwen 2.5 0.5B', 'type' => 'onprem', 'ram_gb' => 1, 'disk_gb' => 0.4, 'min_cpu' => 1, 'gpu_required' => false, 'speed' => 'ultra-rapide', 'quality' => 1, 'tags' => ['chat', 'rapide']],
            ['id' => 'qwen2.5:1.5b', 'name' => 'Qwen 2.5 1.5B', 'type' => 'onprem', 'ram_gb' => 2, 'disk_gb' => 1, 'min_cpu' => 1, 'gpu_required' => false, 'speed' => 'rapide', 'quality' => 2, 'tags' => ['chat', 'polyvalent']],
            ['id' => 'gemma2:2b', 'name' => 'Gemma 2 2B (Google)', 'type' => 'onprem', 'ram_gb' => 4, 'disk_gb' => 1.6, 'min_cpu' => 2, 'gpu_required' => false, 'speed' => 'rapide', 'quality' => 2, 'tags' => ['chat', 'google']],
            ['id' => 'qwen2.5:3b', 'name' => 'Qwen 2.5 3B', 'type' => 'onprem', 'ram_gb' => 4, 'disk_gb' => 2, 'min_cpu' => 2, 'gpu_required' => false, 'speed' => 'standard', 'quality' => 3, 'tags' => ['chat', 'polyvalent']],
            ['id' => 'phi3:mini', 'name' => 'Phi-3 Mini 3.8B (Microsoft)', 'type' => 'onprem', 'ram_gb' => 4, 'disk_gb' => 2.3, 'min_cpu' => 2, 'gpu_required' => false, 'speed' => 'standard', 'quality' => 3, 'tags' => ['chat', 'raisonnement']],
            ['id' => 'llama3.2:3b', 'name' => 'Llama 3.2 3B (Meta)', 'type' => 'onprem', 'ram_gb' => 4, 'disk_gb' => 2, 'min_cpu' => 2, 'gpu_required' => false, 'speed' => 'standard', 'quality' => 3, 'tags' => ['chat', 'meta']],
            ['id' => 'qwen2.5:7b', 'name' => 'Qwen 2.5 7B', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.7, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['chat', 'intelligent']],
            ['id' => 'qwen2.5-coder:7b', 'name' => 'Qwen 2.5 Coder 7B', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.7, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['code', 'dev']],
            ['id' => 'mistral:7b', 'name' => 'Mistral 7B', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.1, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['chat', 'francais']],
            ['id' => 'llama3.1:8b', 'name' => 'Llama 3.1 8B (Meta)', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.7, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['chat', 'meta']],
            ['id' => 'qwen2.5:14b', 'name' => 'Qwen 2.5 14B', 'type' => 'onprem', 'ram_gb' => 16, 'disk_gb' => 9, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'lent', 'quality' => 5, 'tags' => ['chat', 'puissant']],
            ['id' => 'deepseek-coder-v2:16b', 'name' => 'DeepSeek Coder V2 16B', 'type' => 'onprem', 'ram_gb' => 16, 'disk_gb' => 9, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'lent', 'quality' => 5, 'tags' => ['code', 'puissant']],
            ['id' => 'mistral-small:22b', 'name' => 'Mistral Small 22B', 'type' => 'onprem', 'ram_gb' => 16, 'disk_gb' => 13, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'lent', 'quality' => 5, 'tags' => ['chat', 'francais', 'puissant']],
            ['id' => 'mixtral:8x7b', 'name' => 'Mixtral 8x7B (MoE)', 'type' => 'onprem', 'ram_gb' => 32, 'disk_gb' => 26, 'min_cpu' => 8, 'gpu_required' => false, 'speed' => 'tres-lent', 'quality' => 5, 'tags' => ['chat', 'expert']],
            ['id' => 'llama3.1:70b', 'name' => 'Llama 3.1 70B (Meta)', 'type' => 'onprem', 'ram_gb' => 48, 'disk_gb' => 40, 'min_cpu' => 8, 'gpu_required' => true, 'speed' => 'tres-lent', 'quality' => 5, 'tags' => ['chat', 'top']],
            ['id' => 'qwen2.5:72b', 'name' => 'Qwen 2.5 72B', 'type' => 'onprem', 'ram_gb' => 48, 'disk_gb' => 42, 'min_cpu' => 8, 'gpu_required' => true, 'speed' => 'tres-lent', 'quality' => 5, 'tags' => ['chat', 'top']],
            // Vision models
            ['id' => 'llava:7b', 'name' => 'LLaVA 7B', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.7, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 3, 'tags' => ['vision', 'ocr']],
            ['id' => 'llava:13b', 'name' => 'LLaVA 13B', 'type' => 'onprem', 'ram_gb' => 16, 'disk_gb' => 8, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'lent', 'quality' => 4, 'tags' => ['vision', 'ocr']],
            ['id' => 'minicpm-v', 'name' => 'MiniCPM-V (OCR)', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 5.5, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['vision', 'ocr', 'documents']],
            ['id' => 'llama3.2-vision:11b', 'name' => 'Llama 3.2 Vision 11B', 'type' => 'onprem', 'ram_gb' => 12, 'disk_gb' => 7.9, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 5, 'tags' => ['vision', 'ocr', 'documents']],
            // Function calling models
            ['id' => 'hermes3:8b', 'name' => 'Hermes 3 8B', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4.7, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['function-calling', 'tools']],
            ['id' => 'mistral-nemo:12b', 'name' => 'Mistral Nemo 12B', 'type' => 'onprem', 'ram_gb' => 12, 'disk_gb' => 7.1, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['function-calling', 'tools', 'francais']],
            ['id' => 'command-r:7b', 'name' => 'Command R 7B (Cohere)', 'type' => 'onprem', 'ram_gb' => 8, 'disk_gb' => 4, 'min_cpu' => 4, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 4, 'tags' => ['function-calling', 'rag', 'tools']],
            ['id' => 'qwen2.5:32b', 'name' => 'Qwen 2.5 32B', 'type' => 'onprem', 'ram_gb' => 24, 'disk_gb' => 19, 'min_cpu' => 8, 'gpu_required' => false, 'speed' => 'lent', 'quality' => 5, 'tags' => ['function-calling', 'tools', 'puissant']],
            // Cloud models (always available)
            ['id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5', 'type' => 'cloud', 'provider' => 'Anthropic', 'ram_gb' => 0, 'disk_gb' => 0, 'min_cpu' => 0, 'gpu_required' => false, 'speed' => 'ultra-rapide', 'quality' => 4, 'tags' => ['chat', 'rapide', 'cloud']],
            ['id' => 'claude-sonnet-4-6-20250514', 'name' => 'Claude Sonnet 4.6', 'type' => 'cloud', 'provider' => 'Anthropic', 'ram_gb' => 0, 'disk_gb' => 0, 'min_cpu' => 0, 'gpu_required' => false, 'speed' => 'rapide', 'quality' => 5, 'tags' => ['chat', 'code', 'cloud']],
            ['id' => 'claude-opus-4-6-20250602', 'name' => 'Claude Opus 4.6', 'type' => 'cloud', 'provider' => 'Anthropic', 'ram_gb' => 0, 'disk_gb' => 0, 'min_cpu' => 0, 'gpu_required' => false, 'speed' => 'modere', 'quality' => 5, 'tags' => ['raisonnement', 'code', 'cloud']],
            ['id' => 'gpt-4o', 'name' => 'GPT-4o (OpenAI)', 'type' => 'cloud', 'provider' => 'OpenAI', 'ram_gb' => 0, 'disk_gb' => 0, 'min_cpu' => 0, 'gpu_required' => false, 'speed' => 'rapide', 'quality' => 5, 'tags' => ['chat', 'multimodal', 'cloud']],
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini (OpenAI)', 'type' => 'cloud', 'provider' => 'OpenAI', 'ram_gb' => 0, 'disk_gb' => 0, 'min_cpu' => 0, 'gpu_required' => false, 'speed' => 'ultra-rapide', 'quality' => 3, 'tags' => ['chat', 'rapide', 'cloud']],
        ];

        // Score each model
        $scored = [];
        foreach ($models as $model) {
            $compatible = true;
            $warnings = [];
            $status = 'ok'; // ok, warning, impossible

            if ($model['type'] === 'onprem') {
                if ($model['ram_gb'] > $ramTotalGb) {
                    $compatible = false;
                    $status = 'impossible';
                    $warnings[] = "Necessite {$model['ram_gb']} Go RAM (vous avez {$ramTotalGb} Go)";
                } elseif ($model['ram_gb'] > $ramAvailableGb) {
                    $status = 'warning';
                    $warnings[] = "RAM limite ({$ramAvailableGb} Go dispo / {$model['ram_gb']} Go requis)";
                }
                if ($model['disk_gb'] > $diskFreeGb) {
                    $compatible = false;
                    $status = 'impossible';
                    $warnings[] = "Espace disque insuffisant ({$diskFreeGb} Go libre / {$model['disk_gb']} Go requis)";
                }
                if ($model['min_cpu'] > $cpuCores) {
                    $status = $status === 'impossible' ? 'impossible' : 'warning';
                    $warnings[] = "Recommande {$model['min_cpu']} CPU (vous avez {$cpuCores})";
                }
                if ($model['gpu_required'] && !$gpu) {
                    $status = 'warning';
                    $warnings[] = "GPU recommande pour des performances acceptables";
                }
            }

            $scored[] = array_merge($model, [
                'compatible' => $compatible,
                'status' => $status,
                'warnings' => $warnings,
            ]);
        }

        // Check which are already installed
        $installed = [];
        $baseUrl = $this->getOrDetectBaseUrl();
        if ($baseUrl) {
            try {
                $res = Http::timeout(5)->withHeaders($this->getHeaders())->get(rtrim($baseUrl, '/') . '/api/tags');
                if ($res->successful()) {
                    $installed = collect($res->json('models') ?? [])->pluck('name')->toArray();
                }
            } catch (\Exception $e) {}
        }

        return response()->json([
            'server' => [
                'cpu_model' => $cpuModel,
                'cpu_cores' => $cpuCores,
                'ram_total_gb' => $ramTotalGb,
                'ram_available_gb' => $ramAvailableGb,
                'ram_used_gb' => $ramUsedGb,
                'ram_percent' => $ramTotalGb > 0 ? round(($ramUsedGb / $ramTotalGb) * 100) : 0,
                'disk_total_gb' => $diskTotalGb,
                'disk_free_gb' => $diskFreeGb,
                'disk_used_gb' => $diskUsedGb,
                'disk_percent' => $diskTotalGb > 0 ? round(($diskUsedGb / $diskTotalGb) * 100) : 0,
                'gpu' => $gpu,
                'gpu_vram_mb' => $gpuVram,
                'load_avg' => array_map(fn($v) => round($v, 2), $loadAvg),
                'ollama_url' => $baseUrl,
                'ollama_connected' => !empty($installed) || ($baseUrl !== null),
            ],
            'models' => $scored,
            'installed' => $installed,
        ]);
    }

    /**
     * Get pull progress for a model.
     */
    public function pullStatus(Request $request): JsonResponse
    {
        $model = $request->query('model');
        if (!$model) {
            return response()->json(['error' => 'Model parameter required'], 422);
        }

        $cacheKey = "ollama_pull_{$model}";
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            return response()->json(['status' => 'idle']);
        }

        return response()->json($progress);
    }
}
