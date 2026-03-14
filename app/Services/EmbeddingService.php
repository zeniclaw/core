<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embedding service (D4.5) — semantic vector search via Ollama or OpenAI.
 * Provides text-to-vector conversion and cosine similarity search.
 * Uses local Ollama (free) with OpenAI fallback.
 */
class EmbeddingService
{
    private const CACHE_TTL = 3600; // 1 hour cache for embeddings
    private const DIMENSION = 384; // nomic-embed-text dimension

    /**
     * Generate an embedding vector for a text string.
     *
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        $text = trim($text);
        if (!$text) return null;

        // Check cache first
        $cacheKey = 'embed:' . md5($text);
        $cached = Cache::get($cacheKey);
        if ($cached) return $cached;

        // Try Ollama first (free, local)
        $vector = $this->embedOllama($text);

        // Fallback to OpenAI
        if (!$vector) {
            $vector = $this->embedOpenAI($text);
        }

        if ($vector) {
            Cache::put($cacheKey, $vector, self::CACHE_TTL);
        }

        return $vector;
    }

    /**
     * Embed via Ollama (local, free).
     */
    private function embedOllama(string $text): ?array
    {
        $url = $this->getOllamaUrl();
        if (!$url) return null;

        try {
            $response = Http::timeout(15)->post("{$url}/api/embeddings", [
                'model' => 'nomic-embed-text',
                'prompt' => mb_substr($text, 0, 8000),
            ]);

            if ($response->successful()) {
                return $response->json('embedding');
            }

            // Try pulling the model if not found
            if ($response->status() === 404) {
                Log::info('EmbeddingService: pulling nomic-embed-text model...');
                Http::timeout(300)->post("{$url}/api/pull", ['name' => 'nomic-embed-text']);
                // Retry after pull
                $response = Http::timeout(15)->post("{$url}/api/embeddings", [
                    'model' => 'nomic-embed-text',
                    'prompt' => mb_substr($text, 0, 8000),
                ]);
                if ($response->successful()) {
                    return $response->json('embedding');
                }
            }
        } catch (\Exception $e) {
            Log::debug('EmbeddingService: Ollama embedding failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Embed via OpenAI (cloud fallback).
     */
    private function embedOpenAI(string $text): ?array
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) return null;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => 'text-embedding-3-small',
                    'input' => mb_substr($text, 0, 8000),
                ]);

            if ($response->successful()) {
                return $response->json('data.0.embedding');
            }
        } catch (\Exception $e) {
            Log::debug('EmbeddingService: OpenAI embedding failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Compute cosine similarity between two vectors.
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) return 0.0;

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) return 0.0;

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Search an array of texts semantically, returning ranked results.
     *
     * @param string $query The search query
     * @param array $items Array of ['id' => ..., 'text' => ..., ...] items
     * @param float $threshold Minimum similarity score (0-1)
     * @param int $limit Max results
     * @return array Ranked results with similarity scores
     */
    public function semanticSearch(string $query, array $items, float $threshold = 0.3, int $limit = 10): array
    {
        $queryVector = $this->embed($query);
        if (!$queryVector) return [];

        $results = [];
        foreach ($items as $item) {
            $text = $item['text'] ?? $item['content'] ?? '';
            if (!$text) continue;

            $itemVector = $this->embed($text);
            if (!$itemVector) continue;

            $similarity = self::cosineSimilarity($queryVector, $itemVector);
            if ($similarity >= $threshold) {
                $item['similarity'] = round($similarity, 4);
                $results[] = $item;
            }
        }

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Hybrid search: combines keyword + semantic results (D4.5).
     * Gives best of both worlds — exact matches + conceptual matches.
     */
    public function hybridSearch(string $query, array $items, float $keywordWeight = 0.4, float $semanticWeight = 0.6): array
    {
        // Keyword scoring
        $keywords = preg_split('/\s+/', mb_strtolower($query));
        $keywordScores = [];
        foreach ($items as $i => $item) {
            $text = mb_strtolower($item['text'] ?? $item['content'] ?? '');
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $score += 1.0 / count($keywords);
                }
            }
            $keywordScores[$i] = $score;
        }

        // Semantic scoring
        $queryVector = $this->embed($query);
        $semanticScores = [];
        if ($queryVector) {
            foreach ($items as $i => $item) {
                $text = $item['text'] ?? $item['content'] ?? '';
                $itemVector = $this->embed($text);
                $semanticScores[$i] = $itemVector ? self::cosineSimilarity($queryVector, $itemVector) : 0;
            }
        }

        // Combine scores
        $combined = [];
        foreach ($items as $i => $item) {
            $kScore = $keywordScores[$i] ?? 0;
            $sScore = $semanticScores[$i] ?? 0;
            $finalScore = ($kScore * $keywordWeight) + ($sScore * $semanticWeight);

            if ($finalScore > 0.15) {
                $item['score'] = round($finalScore, 4);
                $item['keyword_score'] = round($kScore, 4);
                $item['semantic_score'] = round($sScore, 4);
                $combined[] = $item;
            }
        }

        usort($combined, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($combined, 0, 10);
    }

    /**
     * Check if embedding service is available.
     */
    public static function isAvailable(): bool
    {
        // Check Ollama
        $url = (new self())->getOllamaUrl();
        if ($url) {
            try {
                $response = Http::timeout(3)->get("{$url}/api/tags");
                if ($response->successful()) return true;
            } catch (\Exception $e) {}
        }

        // Check OpenAI
        return (bool) AppSetting::get('openai_api_key');
    }

    private function getOllamaUrl(): ?string
    {
        $url = AppSetting::get('onprem_api_url');
        if ($url) return $url;

        foreach (['http://ollama:11434', 'http://localhost:11434'] as $candidate) {
            try {
                $response = Http::timeout(2)->get("{$candidate}/api/tags");
                if ($response->successful()) return $candidate;
            } catch (\Exception $e) {}
        }

        return null;
    }
}
