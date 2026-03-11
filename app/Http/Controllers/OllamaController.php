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

        // Check if already pulling (skip if force retry)
        $current = Cache::get($cacheKey);
        if (!$force && $current && ($current['status'] ?? '') === 'pulling') {
            return response()->json(['message' => 'Already pulling', 'progress' => $current]);
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
