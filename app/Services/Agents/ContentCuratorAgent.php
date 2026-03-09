<?php

namespace App\Services\Agents;

use App\Models\SavedArticle;
use App\Models\UserContentPreference;
use App\Models\ContentDigestLog;
use App\Services\AgentContext;
use App\Services\ContentCurator\ContentAggregator;
use App\Services\ContentCurator\ContentSummarizer;
use Illuminate\Support\Facades\Log;

class ContentCuratorAgent extends BaseAgent
{
    private ContentAggregator $aggregator;
    private ContentSummarizer $summarizer;

    public function __construct()
    {
        parent::__construct();
        $this->aggregator = new ContentAggregator();
        $this->summarizer = new ContentSummarizer();
    }

    public function name(): string
    {
        return 'content_curator';
    }

    public function description(): string
    {
        return 'Agent de curation de contenu personnalise. Agregation de news, trending topics, bookmarking, digests quotidiens selon les interets de l\'utilisateur via NewsAPI, HackerNews, Reddit et flux RSS.';
    }

    public function keywords(): array
    {
        return [
            'digest', 'trending', 'tendance', 'follow', 'suivre',
            'content', 'contenu', 'news', 'actualite', 'actualites',
            'veille', 'curation', 'save', 'sauvegarder', 'bookmark',
            'daily digest', 'resume quotidien', 'newsletter',
            'sources', 'flux', 'rss', 'feed',
            'preferences contenu', 'mes interets', 'topics',
            'hackernews', 'reddit', 'tech news',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match(
            '/\b(digest|trending|tendance|follow|suivre|veille|curation|bookmark|sauvegarder|daily\s+digest|resume\s+quotidien|newsletter|flux\s+rss|mes\s+interets|hackernews|reddit\s+news)\b/iu',
            $context->body
        );
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp();
        }

        $this->log($context, 'Content curator request', ['body' => mb_substr($body, 0, 100)]);

        $lower = mb_strtolower($body);

        // Route commands
        if (preg_match('/^(digest|resume\s+quotidien|daily\s+digest)\s*(.*)/iu', $body, $m)) {
            $category = trim($m[2]) ?: null;
            return $this->handleDigest($context, $category);
        }

        if (preg_match('/^(follow|suivre)\s+(.+)/iu', $body, $m)) {
            return $this->handleFollow($context, trim($m[2]));
        }

        if (preg_match('/^(unfollow|ne\s+plus\s+suivre)\s+(.+)/iu', $body, $m)) {
            return $this->handleUnfollow($context, trim($m[2]));
        }

        if (preg_match('/^(trending|tendance|tendances)\s*(.*)/iu', $body, $m)) {
            $domain = trim($m[2]) ?: 'tech';
            return $this->handleTrending($context, $domain);
        }

        if (preg_match('/^(save|sauvegarder|bookmark)\s+(.+)/iu', $body, $m)) {
            return $this->handleSave($context, trim($m[2]));
        }

        if (preg_match('/^(mes\s+bookmarks?|saved|sauvegardes?|mes\s+articles?)\s*$/iu', $body)) {
            return $this->handleListSaved($context);
        }

        if (preg_match('/\b(preferences?|interets?|mes\s+sources?|config)\b/iu', $body)) {
            return $this->handlePreferences($context);
        }

        // Default: show digest
        if (preg_match('/\b(news|actualite|actualites|veille|curation)\b/iu', $body)) {
            return $this->handleDigest($context, null);
        }

        return $this->showHelp();
    }

    private function handleDigest(AgentContext $context, ?string $category): AgentResult
    {
        $userPhone = $context->from;

        // Get user preferences
        $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->pluck('category')->toArray();
        $keywords = $prefs->pluck('keywords')->flatten()->filter()->toArray();

        if ($category) {
            $categories = [$category];
        } elseif (empty($categories)) {
            $categories = ['technology', 'science'];
        }

        $this->log($context, 'Generating digest', ['categories' => $categories]);

        try {
            $articles = $this->aggregator->aggregate($categories, $keywords, 10);

            if (empty($articles)) {
                return AgentResult::reply(
                    "Aucun article trouve pour tes centres d'interet. Essaie une autre categorie ou ajoute des sources avec *follow [categorie]*."
                );
            }

            // Summarize articles
            $summaries = $this->summarizer->summarizeBatch($articles, 8);

            // Log digest
            ContentDigestLog::create([
                'user_phone' => $userPhone,
                'categories' => $categories,
                'article_count' => count($summaries),
                'sent_at' => now(),
            ]);

            $output = "*📰 DIGEST" . ($category ? " — " . ucfirst($category) : "") . "*\n";
            $output .= "_" . now()->format('d/m/Y H:i') . "_\n\n";

            foreach ($summaries as $i => $article) {
                $num = $i + 1;
                $title = $article['title'] ?? 'Sans titre';
                $summary = $article['summary'] ?? '';
                $source = $article['source'] ?? '';
                $url = $article['url'] ?? '';

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n{$summary}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "---\n";
            $output .= "_💡 Commandes: *save [url]* pour bookmarker, *follow [categorie]* pour personnaliser_";

            return AgentResult::reply($output);
        } catch (\Throwable $e) {
            Log::error("[content_curator] Digest generation failed: " . $e->getMessage());
            return AgentResult::reply("Erreur lors de la generation du digest. Reessaie dans quelques instants.");
        }
    }

    private function handleTrending(AgentContext $context, string $domain): AgentResult
    {
        $this->log($context, 'Fetching trending', ['domain' => $domain]);

        try {
            $articles = $this->aggregator->getTrending($domain, 8);

            if (empty($articles)) {
                return AgentResult::reply("Aucun contenu trending trouve pour *{$domain}*. Essaie: tech, science, business, health.");
            }

            $output = "*🔥 TRENDING — " . ucfirst($domain) . "*\n\n";

            foreach ($articles as $i => $article) {
                $num = $i + 1;
                $title = $article['title'] ?? 'Sans titre';
                $score = $article['score'] ?? '';
                $source = $article['source'] ?? '';
                $url = $article['url'] ?? '';

                $output .= "*{$num}. {$title}*";
                if ($score) $output .= " (🔺 {$score})";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker un article_";

            return AgentResult::reply($output);
        } catch (\Throwable $e) {
            Log::error("[content_curator] Trending fetch failed: " . $e->getMessage());
            return AgentResult::reply("Erreur lors de la recuperation des tendances. Reessaie plus tard.");
        }
    }

    private function handleFollow(AgentContext $context, string $source): AgentResult
    {
        $userPhone = $context->from;

        // Parse source: could be a category or keyword
        $clean = mb_strtolower(trim($source));

        $validCategories = ['technology', 'tech', 'science', 'business', 'health', 'sports', 'entertainment', 'gaming', 'ai', 'crypto', 'startup', 'design', 'security'];

        // Normalize aliases
        $categoryMap = [
            'tech' => 'technology',
            'ia' => 'ai',
            'sante' => 'health',
            'sport' => 'sports',
            'securite' => 'security',
            'jeux' => 'gaming',
        ];

        $normalized = $categoryMap[$clean] ?? $clean;

        if (in_array($normalized, $validCategories)) {
            $existing = UserContentPreference::where('user_phone', $userPhone)
                ->where('category', $normalized)
                ->first();

            if ($existing) {
                return AgentResult::reply("Tu suis deja la categorie *{$normalized}*.");
            }

            UserContentPreference::create([
                'user_phone' => $userPhone,
                'category' => $normalized,
                'keywords' => [],
                'sources' => [],
            ]);

            $this->log($context, 'User followed category', ['category' => $normalized]);

            return AgentResult::reply(
                "✅ Tu suis maintenant *{$normalized}*.\n\n"
                . "Ton prochain digest inclura du contenu de cette categorie.\n"
                . "_Dis *digest* pour voir ton feed maintenant._"
            );
        }

        // Treat as keyword
        $pref = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', 'custom')
            ->first();

        if ($pref) {
            $keywords = $pref->keywords ?? [];
            if (in_array($clean, $keywords)) {
                return AgentResult::reply("Tu suis deja le mot-cle *{$clean}*.");
            }
            $keywords[] = $clean;
            $pref->update(['keywords' => $keywords]);
        } else {
            UserContentPreference::create([
                'user_phone' => $userPhone,
                'category' => 'custom',
                'keywords' => [$clean],
                'sources' => [],
            ]);
        }

        $this->log($context, 'User followed keyword', ['keyword' => $clean]);

        return AgentResult::reply(
            "✅ Mot-cle *{$clean}* ajoute a tes interets.\n"
            . "_Dis *digest* pour voir ton feed personnalise._"
        );
    }

    private function handleUnfollow(AgentContext $context, string $source): AgentResult
    {
        $userPhone = $context->from;
        $clean = mb_strtolower(trim($source));

        $categoryMap = [
            'tech' => 'technology',
            'ia' => 'ai',
            'sante' => 'health',
            'sport' => 'sports',
            'securite' => 'security',
            'jeux' => 'gaming',
        ];
        $normalized = $categoryMap[$clean] ?? $clean;

        // Try removing category
        $deleted = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', $normalized)
            ->delete();

        if ($deleted) {
            return AgentResult::reply("✅ Tu ne suis plus *{$normalized}*.");
        }

        // Try removing keyword from custom
        $pref = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', 'custom')
            ->first();

        if ($pref) {
            $keywords = $pref->keywords ?? [];
            $filtered = array_values(array_filter($keywords, fn($k) => $k !== $clean));
            if (count($filtered) < count($keywords)) {
                $pref->update(['keywords' => $filtered]);
                return AgentResult::reply("✅ Mot-cle *{$clean}* retire de tes interets.");
            }
        }

        return AgentResult::reply("Tu ne suis pas *{$clean}*. Dis *preferences* pour voir tes abonnements.");
    }

    private function handleSave(AgentContext $context, string $input): AgentResult
    {
        $userPhone = $context->from;

        // Extract URL from input
        if (!preg_match('#https?://[^\s<>\[\]"\']+#i', $input, $m)) {
            return AgentResult::reply("Envoie une URL valide apres *save*. Exemple: *save https://example.com/article*");
        }

        $url = $m[0];

        // Check duplicate
        $existing = SavedArticle::where('user_phone', $userPhone)
            ->where('url', $url)
            ->first();

        if ($existing) {
            return AgentResult::reply("Cet article est deja dans tes bookmarks.");
        }

        // Try to fetch title
        $title = $this->fetchPageTitle($url);

        SavedArticle::create([
            'user_phone' => $userPhone,
            'url' => $url,
            'title' => $title,
            'source' => parse_url($url, PHP_URL_HOST) ?: 'unknown',
        ]);

        $this->log($context, 'Article saved', ['url' => $url]);

        $displayTitle = $title ?: $url;
        return AgentResult::reply(
            "🔖 Article sauvegarde !\n\n"
            . "*{$displayTitle}*\n"
            . "🔗 {$url}\n\n"
            . "_Dis *mes bookmarks* pour voir tous tes articles sauvegardes._"
        );
    }

    private function handleListSaved(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->take(15)
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun article sauvegarde.\n"
                . "_Utilise *save [url]* pour bookmarker un article._"
            );
        }

        $output = "*🔖 MES BOOKMARKS* ({$articles->count()} articles)\n\n";

        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $title = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date = $article->created_at->format('d/m');

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= "🔗 {$article->url}\n\n";
        }

        return AgentResult::reply($output);
    }

    private function handlePreferences(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $prefs = UserContentPreference::where('user_phone', $userPhone)->get();

        if ($prefs->isEmpty()) {
            return AgentResult::reply(
                "*⚙️ PREFERENCES CONTENU*\n\n"
                . "Tu n'as pas encore configure tes interets.\n\n"
                . "*Categories disponibles :*\n"
                . "technology, science, business, health, sports, entertainment, gaming, ai, crypto, startup, design, security\n\n"
                . "*Exemples :*\n"
                . "- *follow tech* — suivre la tech\n"
                . "- *follow ai* — suivre l'IA\n"
                . "- *follow laravel* — mot-cle personnalise\n"
                . "- *unfollow tech* — arreter de suivre\n"
            );
        }

        $output = "*⚙️ MES PREFERENCES CONTENU*\n\n";

        $categories = $prefs->where('category', '!=', 'custom');
        $custom = $prefs->where('category', 'custom')->first();

        if ($categories->isNotEmpty()) {
            $output .= "*Categories suivies :*\n";
            foreach ($categories as $p) {
                $output .= "- {$p->category}\n";
            }
            $output .= "\n";
        }

        if ($custom && !empty($custom->keywords)) {
            $output .= "*Mots-cles personnalises :*\n";
            foreach ($custom->keywords as $kw) {
                $output .= "- {$kw}\n";
            }
            $output .= "\n";
        }

        $output .= "_Utilise *follow [categorie]* ou *unfollow [categorie]* pour modifier._";

        return AgentResult::reply($output);
    }

    private function fetchPageTitle(string $url): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ContentCurator/1.0)',
                ])
                ->get($url);

            if ($response->successful()) {
                if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $response->body(), $m)) {
                    return html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return null;
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*📰 Content Curator — Veille personnalisee*\n\n"
            . "*Commandes :*\n"
            . "- *digest* — Recevoir ton digest personnalise\n"
            . "- *digest [categorie]* — Digest d'une categorie specifique\n"
            . "- *trending [domaine]* — Contenu trending (tech, science, etc.)\n"
            . "- *follow [categorie/mot-cle]* — Suivre une categorie ou un mot-cle\n"
            . "- *unfollow [categorie/mot-cle]* — Arreter de suivre\n"
            . "- *save [url]* — Bookmarker un article\n"
            . "- *mes bookmarks* — Voir tes articles sauvegardes\n"
            . "- *preferences* — Voir/modifier tes interets\n\n"
            . "*Categories disponibles :*\n"
            . "technology, science, business, health, sports, entertainment, gaming, ai, crypto, startup, design, security\n\n"
            . "*Exemples :*\n"
            . "- _digest tech_ — Derniers articles tech\n"
            . "- _trending ai_ — Tendances en IA\n"
            . "- _follow laravel_ — Suivre le mot-cle Laravel\n"
            . "- _save https://example.com/article_ — Bookmarker"
        );
    }
}
