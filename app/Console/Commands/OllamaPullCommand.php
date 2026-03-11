<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OllamaPullCommand extends Command
{
    protected $signature = 'ollama:pull {model}';
    protected $description = 'Pull an Ollama model with progress tracking via cache';

    public function handle(): int
    {
        $model = $this->argument('model');
        $cacheKey = "ollama_pull_{$model}";

        $baseUrl = AppSetting::get('onprem_api_url');
        if (!$baseUrl) {
            Cache::put($cacheKey, ['status' => 'error', 'detail' => 'On-prem URL not configured'], 3600);
            return 1;
        }

        $headers = ['Content-Type: application/json'];
        $apiKey = AppSetting::get('onprem_api_key');
        if ($apiKey) {
            $headers[] = "Authorization: Bearer {$apiKey}";
        }

        $url = rtrim($baseUrl, '/') . '/api/pull';
        $body = json_encode(['name' => $model, 'stream' => true]);

        $pullFailed = false;
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 7200, // 2 hours for large models
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($cacheKey, $model, &$pullFailed) {
                $lines = explode("\n", trim($data));
                foreach ($lines as $line) {
                    if (empty($line)) continue;
                    $json = json_decode($line, true);
                    if (!$json) continue;

                    // Detect error in stream (Ollama returns 200 even on failure)
                    $error = $json['error'] ?? null;
                    if ($error) {
                        $pullFailed = true;
                        $detail = $error;
                        // Simplify common errors for display
                        if (str_contains($error, 'AuthorizedOnly') || str_contains($error, 'authentication required')) {
                            $detail = "Telechargement bloque (proxy/firewall). Verifiez que HTTPS_PROXY est configure dans le container Ollama.";
                        } elseif (str_contains($error, 'no such host') || str_contains($error, 'lookup')) {
                            $detail = "DNS impossible — registry.ollama.ai injoignable. Verifiez le proxy/reseau.";
                        } elseif (str_contains($error, 'connection refused')) {
                            $detail = "Connexion refusee vers le registry Ollama.";
                        }
                        Cache::put($cacheKey, [
                            'status' => 'error',
                            'detail' => $detail,
                            'raw_error' => $error,
                            'model' => $model,
                        ], 3600);
                        Log::error('ollama:pull stream error', ['model' => $model, 'error' => $error]);
                        return strlen($data);
                    }

                    $status = $json['status'] ?? '';
                    $total = $json['total'] ?? 0;
                    $completed = $json['completed'] ?? 0;
                    $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

                    Cache::put($cacheKey, [
                        'status' => 'pulling',
                        'percent' => $percent,
                        'detail' => $status,
                        'total' => $total,
                        'completed' => $completed,
                        'model' => $model,
                    ], 3600);
                }
                return strlen($data);
            },
        ];

        // Enterprise proxy support
        $proxy = env('HTTPS_PROXY') ?: env('HTTP_PROXY');
        if ($proxy) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
            $noProxy = env('NO_PROXY', '');
            if ($noProxy) {
                $curlOpts[CURLOPT_NOPROXY] = $noProxy;
            }
        }

        curl_setopt_array($ch, $curlOpts);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            $detail = $error ?: "HTTP {$httpCode}";
            Cache::put($cacheKey, ['status' => 'error', 'detail' => "Pull failed: {$detail}"], 3600);
            Log::error('ollama:pull failed', ['model' => $model, 'error' => $detail]);
            return 1;
        }

        // Stream error was detected (Ollama returns 200 but error in JSON body)
        if ($pullFailed) {
            Log::error('ollama:pull stream reported error', ['model' => $model]);
            return 1;
        }

        Cache::put($cacheKey, [
            'status' => 'done',
            'percent' => 100,
            'detail' => 'Model ready',
            'model' => $model,
        ], 3600);

        Log::info('ollama:pull complete', ['model' => $model]);
        return 0;
    }
}
