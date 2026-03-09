<?php

namespace App\Services\ContentCurator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentAggregator
{
    private const CACHE_TTL = 1800; // 30 minutes

    /**
     * Aggregate articles from multiple sources based on categories and keywords.
     */
    public function aggregate(array $categories, array $keywords = [], int $limit = 10): array
    {
        $articles = [];

        foreach ($categories as $category) {
            $cacheKey = "content_curator:aggregate:{$category}:" . md5(implode(',', $keywords));

            $cached = Cache::get($cacheKey);
            if ($cached) {
                $articles = array_merge($articles, $cached);
                continue;
            }

            $categoryArticles = [];

            // HackerNews for tech categories
            if (in_array($category, ['technology', 'tech', 'ai', 'crypto', 'startup', 'security', 'design', 'gaming'])) {
                $hnArticles = $this->fetchHackerNews($category, $keywords);
                $categoryArticles = array_merge($categoryArticles, $hnArticles);
            }

            // Reddit for broader categories
            $redditArticles = $this->fetchReddit($category, $keywords);
            $categoryArticles = array_merge($categoryArticles, $redditArticles);

            // NewsAPI if configured
            $newsApiKey = config('services.newsapi.key');
            if ($newsApiKey) {
                $newsArticles = $this->fetchNewsApi($category, $keywords, $newsApiKey);
                $categoryArticles = array_merge($categoryArticles, $newsArticles);
            }

            Cache::put($cacheKey, $categoryArticles, self::CACHE_TTL);
            $articles = array_merge($articles, $categoryArticles);
        }

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($articles as $article) {
            $url = $article['url'] ?? '';
            if ($url && !isset($seen[$url])) {
                $seen[$url] = true;
                $unique[] = $article;
            }
        }

        // Sort by score/relevance
        usort($unique, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($unique, 0, $limit);
    }

    /**
     * Get trending content for a specific domain.
     */
    public function getTrending(string $domain, int $limit = 8): array
    {
        $cacheKey = "content_curator:trending:{$domain}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($domain, $limit) {
            $articles = [];

            // HackerNews top stories
            $hnArticles = $this->fetchHackerNewsTop($domain, $limit);
            $articles = array_merge($articles, $hnArticles);

            // Reddit hot
            $redditArticles = $this->fetchRedditHot($domain, $limit);
            $articles = array_merge($articles, $redditArticles);

            // Deduplicate
            $seen = [];
            $unique = [];
            foreach ($articles as $article) {
                $url = $article['url'] ?? '';
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[] = $article;
                }
            }

            usort($unique, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

            return array_slice($unique, 0, $limit);
        });
    }

    private function fetchHackerNews(string $category, array $keywords = []): array
    {
        try {
            $response = Http::timeout(10)->get('https://hacker-news.firebaseio.com/v0/topstories.json');

            if (!$response->successful()) return [];

            $ids = array_slice($response->json() ?? [], 0, 30);
            $articles = [];

            foreach ($ids as $id) {
                try {
                    $item = Http::timeout(5)
                        ->get("https://hacker-news.firebaseio.com/v0/item/{$id}.json")
                        ->json();

                    if (!$item || ($item['type'] ?? '') !== 'story') continue;

                    $title = $item['title'] ?? '';
                    $url = $item['url'] ?? "https://news.ycombinator.com/item?id={$id}";
                    $score = $item['score'] ?? 0;

                    // Filter by keywords if provided
                    if (!empty($keywords)) {
                        $titleLower = mb_strtolower($title);
                        $match = false;
                        foreach ($keywords as $kw) {
                            if (str_contains($titleLower, mb_strtolower($kw))) {
                                $match = true;
                                break;
                            }
                        }
                        if (!$match && $score < 100) continue;
                    }

                    $articles[] = [
                        'title' => $title,
                        'url' => $url,
                        'source' => 'HackerNews',
                        'score' => $score,
                        'published_at' => isset($item['time']) ? date('Y-m-d H:i', $item['time']) : null,
                    ];

                    if (count($articles) >= 8) break;
                } catch (\Throwable $e) {
                    continue;
                }
            }

            return $articles;
        } catch (\Throwable $e) {
            Log::debug("[ContentAggregator] HackerNews fetch failed: " . $e->getMessage());
            return [];
        }
    }

    private function fetchHackerNewsTop(string $domain, int $limit): array
    {
        return $this->fetchHackerNews($domain, []);
    }

    private function fetchReddit(string $category, array $keywords = []): array
    {
        $subredditMap = [
            'technology' => 'technology',
            'tech' => 'technology',
            'science' => 'science',
            'business' => 'business',
            'health' => 'health',
            'sports' => 'sports',
            'entertainment' => 'entertainment',
            'gaming' => 'gaming',
            'ai' => 'artificial',
            'crypto' => 'cryptocurrency',
            'startup' => 'startups',
            'design' => 'design',
            'security' => 'netsec',
        ];

        $subreddit = $subredditMap[$category] ?? $category;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ContentCurator/1.0'])
                ->get("https://www.reddit.com/r/{$subreddit}/hot.json", ['limit' => 15]);

            if (!$response->successful()) return [];

            $posts = $response->json()['data']['children'] ?? [];
            $articles = [];

            foreach ($posts as $post) {
                $data = $post['data'] ?? [];
                if ($data['stickied'] ?? false) continue;
                if ($data['is_self'] ?? false) continue;

                $title = $data['title'] ?? '';
                $url = $data['url'] ?? '';
                $score = $data['score'] ?? 0;

                // Filter by keywords
                if (!empty($keywords)) {
                    $titleLower = mb_strtolower($title);
                    $match = false;
                    foreach ($keywords as $kw) {
                        if (str_contains($titleLower, mb_strtolower($kw))) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match && $score < 500) continue;
                }

                $articles[] = [
                    'title' => $title,
                    'url' => $url,
                    'source' => "r/{$subreddit}",
                    'score' => $score,
                    'published_at' => isset($data['created_utc']) ? date('Y-m-d H:i', (int) $data['created_utc']) : null,
                ];

                if (count($articles) >= 5) break;
            }

            return $articles;
        } catch (\Throwable $e) {
            Log::debug("[ContentAggregator] Reddit fetch failed for r/{$subreddit}: " . $e->getMessage());
            return [];
        }
    }

    private function fetchRedditHot(string $domain, int $limit): array
    {
        return $this->fetchReddit($domain, []);
    }

    private function fetchNewsApi(string $category, array $keywords, string $apiKey): array
    {
        $categoryMap = [
            'technology' => 'technology',
            'tech' => 'technology',
            'science' => 'science',
            'business' => 'business',
            'health' => 'health',
            'sports' => 'sports',
            'entertainment' => 'entertainment',
        ];

        $apiCategory = $categoryMap[$category] ?? null;

        try {
            $params = [
                'apiKey' => $apiKey,
                'language' => 'fr',
                'pageSize' => 5,
            ];

            if ($apiCategory) {
                $params['category'] = $apiCategory;
                $params['country'] = 'fr';
                $url = 'https://newsapi.org/v2/top-headlines';
            } else {
                $query = !empty($keywords) ? implode(' OR ', $keywords) : $category;
                $params['q'] = $query;
                $params['sortBy'] = 'relevancy';
                $url = 'https://newsapi.org/v2/everything';
            }

            $response = Http::timeout(10)->get($url, $params);

            if (!$response->successful()) return [];

            $newsArticles = $response->json()['articles'] ?? [];
            $articles = [];

            foreach ($newsArticles as $article) {
                $title = $article['title'] ?? '';
                if (!$title || $title === '[Removed]') continue;

                $articles[] = [
                    'title' => $title,
                    'url' => $article['url'] ?? '',
                    'source' => $article['source']['name'] ?? 'NewsAPI',
                    'score' => 50,
                    'description' => $article['description'] ?? '',
                    'published_at' => $article['publishedAt'] ?? null,
                ];
            }

            return $articles;
        } catch (\Throwable $e) {
            Log::debug("[ContentAggregator] NewsAPI fetch failed: " . $e->getMessage());
            return [];
        }
    }
}
