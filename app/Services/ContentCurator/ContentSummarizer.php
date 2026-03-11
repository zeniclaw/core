<?php

namespace App\Services\ContentCurator;

use App\Services\AnthropicClient;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Log;

class ContentSummarizer
{
    private AnthropicClient $claude;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
    }

    /**
     * Summarize a batch of articles using Claude API.
     */
    public function summarizeBatch(array $articles, int $limit = 8): array
    {
        $articles = array_slice($articles, 0, $limit);

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
Tu es un assistant de curation de contenu. Resume chaque article en 1-2 lignes concises et pertinentes.

FORMAT DE REPONSE (JSON array):
[
  {"index": 1, "summary": "Resume court et informatif de l'article 1"},
  {"index": 2, "summary": "Resume court et informatif de l'article 2"}
]

REGLES:
- Resume chaque article en 1-2 phrases maximum
- Sois factuel et informatif
- Mets en avant l'information cle
- Reponds en francais
- Retourne UNIQUEMENT le JSON, rien d'autre
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
