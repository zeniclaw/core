<?php

namespace App\Services\ContentCurator;

use App\Services\LLMClient;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContentSummarizer
{
    private LLMClient $claude;

    public function __construct()
    {
        $this->claude = new LLMClient();
    }

    /**
     * Summarize a batch of articles using Claude API.
     * Results are cached 30 min by article URL fingerprint.
     */
    public function summarizeBatch(array $articles, int $limit = 8): array
    {
        $articles = array_slice($articles, 0, $limit);

        // Cache key based on article URLs fingerprint
        $urlFingerprint = md5(implode(',', array_column($articles, 'url')));
        $cacheKey = "content_curator:summaries:{$urlFingerprint}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && count($cached) === count($articles)) {
            return $cached;
        }

        // Build a single prompt with all articles for efficient API usage
        $articlesText = '';
        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $title = $article['title'] ?? 'Sans titre';
            $description = $article['description'] ?? '';
            $source = $article['source'] ?? '';
            $url = $article['url'] ?? '';

            $articlesText .= "ARTICLE {$num}:\n";
            $articlesText .= "Titre: {$title}\n";
            if ($source) $articlesText .= "Source: {$source}\n";
            if ($description) $articlesText .= "Description: {$description}\n";
            if ($url) $articlesText .= "URL: {$url}\n";
            $articlesText .= "\n";
        }

        $systemPrompt = <<<PROMPT
Tu es un assistant de curation de contenu expert. Resume chaque article de manière concise et percutante pour un lecteur sur mobile (WhatsApp).

FORMAT DE REPONSE (JSON array):
[
  {"index": 1, "summary": "Fait principal en 1 phrase directe, style journalistique. Max 120 caractères."},
  {"index": 2, "summary": "Fait principal en 1 phrase directe, style journalistique. Max 120 caractères."}
]

REGLES STRICTES:
- 1 phrase maximum par article, commencer par le fait principal (jamais par "L'article..." ou "Cet article...")
- Style journalistique direct : sujet + verbe + information clé
- Maximum 120 caractères par résumé
- Factuel, sans opinion ni supposition
- En français, sans markdown ni guillemets
- Retourner UNIQUEMENT le JSON, rien d'autre

EXEMPLES DE BON RÉSUMÉ:
- "Meta licencie 3 600 employés dans sa division réalité augmentée pour réorienter vers l'IA."
- "Laravel 12 introduit le support natif des types PHP 8.4 et améliore les performances de 30%."
- "Bitcoin dépasse les 100 000$ pour la première fois après l'approbation des ETF spot aux États-Unis."
PROMPT;

        try {
            $response = $this->claude->chat(
                $articlesText,
                ModelResolver::fast(),
                $systemPrompt
            );

            if (!$response) {
                return $this->fallbackSummaries($articles);
            }

            // Parse JSON response
            $clean = trim($response);
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
                $clean = $m[1];
            }
            if (!str_starts_with($clean, '[') && preg_match('/(\[.*\])/s', $clean, $m)) {
                $clean = $m[1];
            }

            $summaries = json_decode($clean, true);

            if (!is_array($summaries)) {
                return $this->fallbackSummaries($articles);
            }

            // Merge summaries back into articles
            $result = [];
            foreach ($articles as $i => $article) {
                $summary = '';
                foreach ($summaries as $s) {
                    if (($s['index'] ?? 0) === $i + 1) {
                        $summary = $s['summary'] ?? '';
                        break;
                    }
                }

                $result[] = array_merge($article, [
                    'summary' => $summary ?: ($article['description'] ?? 'Pas de resume disponible.'),
                ]);
            }

            Cache::put($cacheKey, $result, 1800);
            return $result;
        } catch (\Throwable $e) {
            Log::error("[ContentSummarizer] Batch summarization failed: " . $e->getMessage());
            return $this->fallbackSummaries($articles);
        }
    }

    /**
     * Evaluate relevance of an article to user preferences.
     */
    public function evaluateRelevance(array $article, array $userKeywords): float
    {
        $title = mb_strtolower($article['title'] ?? '');
        $description = mb_strtolower($article['description'] ?? '');
        $text = "{$title} {$description}";

        $score = 0.0;
        foreach ($userKeywords as $keyword) {
            $keyword = mb_strtolower($keyword);
            if (str_contains($text, $keyword)) {
                $score += 1.0;
            }
        }

        // Normalize to 0-1
        return empty($userKeywords) ? 0.5 : min(1.0, $score / count($userKeywords));
    }

    /**
     * Fallback: use descriptions as summaries when Claude fails.
     */
    private function fallbackSummaries(array $articles): array
    {
        return array_map(function ($article) {
            $article['summary'] = $article['description'] ?? 'Pas de resume disponible.';
            return $article;
        }, $articles);
    }
}
