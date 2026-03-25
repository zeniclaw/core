<?php

namespace App\Services\Agents;

use App\Models\SavedArticle;
use App\Models\UserContentPreference;
use App\Models\ContentDigestLog;
use App\Services\AgentContext;
use App\Services\ContentCurator\ContentAggregator;
use App\Services\ContentCurator\ContentSummarizer;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentCuratorAgent extends BaseAgent
{
    private ContentAggregator $aggregator;
    private ContentSummarizer $summarizer;

    private const VALID_CATEGORIES = [
        'technology', 'tech', 'science', 'business', 'health', 'sports',
        'entertainment', 'gaming', 'ai', 'crypto', 'startup', 'design', 'security',
    ];

    private const CATEGORY_ALIASES = [
        // English aliases
        'tech'          => 'technology',
        'ia'            => 'ai',
        // French aliases
        'sante'         => 'health',
        'santé'         => 'health',
        'sport'         => 'sports',
        'securite'      => 'security',
        'sécurité'      => 'security',
        'jeux'          => 'gaming',
        'affaires'      => 'business',
        'sciences'      => 'science',
        'cryptos'       => 'crypto',
        'startups'      => 'startup',
        // Extended French aliases (v1.3.0)
        'informatique'  => 'technology',
        'programmation' => 'technology',
        'developpement' => 'technology',
        'développement' => 'technology',
        'logiciel'      => 'technology',
        'finances'      => 'business',
        'finance'       => 'business',
        'investissement' => 'business',
        'bourse'        => 'business',
        'economie'      => 'business',
        'économie'      => 'business',
        'divertissement' => 'entertainment',
        'cinema'        => 'entertainment',
        'cinéma'        => 'entertainment',
        'films'         => 'entertainment',
        'musique'       => 'entertainment',
        'bitcoin'       => 'crypto',
        'blockchain'    => 'crypto',
        'cybersecurite' => 'security',
        'cybersécurité' => 'security',
        'hacking'       => 'security',
        'design'        => 'design',
        'ux'            => 'design',
        // Extended v1.7.0
        'politique'     => 'business',
        'politiques'    => 'business',
        'football'      => 'sports',
        'basket'        => 'sports',
        'basketball'    => 'sports',
        'tennis'        => 'sports',
        'athletisme'    => 'sports',
        'athlétisme'    => 'sports',
        'esport'        => 'gaming',
        'esports'       => 'gaming',
        'jeu video'     => 'gaming',
        'jeux video'    => 'gaming',
        'voyage'        => 'entertainment',
        'voyages'       => 'entertainment',
        'medias'        => 'entertainment',
        'médias'        => 'entertainment',
        'mode'          => 'design',
        'ui'            => 'design',
        'cybersecurity' => 'security',
        'malware'       => 'security',
        'phishing'      => 'security',
    ];

    // Category icons for display
    private const CATEGORY_ICONS = [
        'technology'    => '💻',
        'science'       => '🔬',
        'business'      => '💼',
        'health'        => '❤️',
        'sports'        => '⚽',
        'entertainment' => '🎬',
        'gaming'        => '🎮',
        'ai'            => '🤖',
        'crypto'        => '🪙',
        'startup'       => '🚀',
        'design'        => '🎨',
        'security'      => '🔒',
    ];

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
        return 'Agent de curation de contenu personnalisé. Digest, trending, flash news, recherche d\'articles, résumé d\'URL, bookmarking, partage, articles similaires, recherche dans les bookmarks, recommandations IA et statistiques selon les intérêts de l\'utilisateur via NewsAPI, HackerNews et Reddit.';
    }

    public function keywords(): array
    {
        return [
            'digest', 'trending', 'tendance', 'tendances', 'follow', 'suivre',
            'content', 'contenu', 'news', 'actualite', 'actualites', 'actualité', 'actualités',
            'veille', 'curation', 'save', 'sauvegarder', 'bookmark', 'bookmarks',
            'daily digest', 'resume quotidien', 'newsletter',
            'sources', 'flux', 'rss', 'feed',
            'preferences contenu', 'mes interets', 'mes intérêts', 'topics',
            'hackernews', 'reddit', 'tech news',
            'mes bookmarks', 'mes articles', 'saved', 'sauvegardes',
            'supprimer bookmark', 'delete bookmark', 'supprimer article',
            'cherche', 'recherche', 'search', 'trouver articles',
            'stats digest', 'historique digest', 'mon historique',
            'unfollow', 'ne plus suivre',
            'résume', 'resumer', 'résumer', 'summarize article',
            'recommande', 'recommandations', 'suggestions', 'pour moi',
            'vider bookmarks', 'effacer tout',
            'categories disponibles', 'quoi lire',
            // v1.3.0
            'flash', 'news rapides', 'quoi de neuf',
            'cherche bookmarks', 'trouver dans mes articles', 'recherche bookmarks',
            // v1.4.0
            'exporter bookmarks', 'export bookmarks', 'exporter mes articles',
            'confirmer vider', 'vider confirmer',
            // v1.5.0
            'lire', 'ouvrir bookmark', 'read bookmark',
            'best of', 'top du jour', 'meilleurs articles', 'briefing', 'matin',
            // v1.6.0
            'renommer', 'renommer bookmark', 'rename bookmark',
            'bilan semaine', 'mes lectures', 'bilan lecture', 'résumé semaine',
            // v1.7.0
            'tldr', 'en bref', 'resume rapide', 'résumé rapide', 'court',
            'digest multi', 'multi categories', 'multi catégories',
            // v1.8.0
            'compare', 'comparer', 'comparaison', 'vs article', 'versus',
            // v1.9.0
            'cite', 'citation', 'extrait', 'extraire citation',
            'top sources', 'mes sources', 'sources populaires', 'meilleures sources',
            // v1.10.0
            'surprends moi', 'surprise', 'hasard', 'aléatoire', 'aleatoire', 'article surprise', 'article aléatoire',
            'digest express', 'digest rapide', 'quick digest', 'express', 'rapide',
            // v1.11.0
            'mes bookmarks page', 'page bookmarks', 'bookmarks suivant', 'bookmarks precedent',
            'digest sur', 'news sur', 'actualites sur', 'actualités sur', 'articles sur',
            // v1.12.0
            'mes bookmarks aujourd\'hui', 'mes bookmarks cette semaine', 'mes bookmarks ce mois',
            'aujourd\'hui', 'cette semaine', 'ce mois',
            'trending multi', 'tendances multi',
            // v1.13.0
            'analyse mes bookmarks', 'analyser mes bookmarks', 'profil lecture', 'analyse biblio',
            'résumé biblio', 'resume biblio', 'intelligence lecture', 'mon profil lecture',
            'article du jour', 'lecture du jour', 'deep read', 'selection du jour', 'sélection du jour',
            'lecture recommandée', 'lecture recommandee', 'que lire aujourd\'hui',
            // v1.14.0
            'partager', 'share bookmark', 'envoyer bookmark',
            'similaire', 'similar', 'articles similaires', 'comme bookmark',
            // v1.15.0
            'quiz', 'quiz bookmark', 'tester mes connaissances',
            'inspire moi', 'détends moi', 'detends moi', 'positif', 'bonne nouvelle',
            'feel good', 'motivant', 'mood',
            // v1.16.0
            'highlights', 'points clés', 'points cles', 'takeaways', 'essentiel',
            'news liées', 'news liees', 'actualités liées', 'actualites liees', 'related news',
            'en rapport', 'lié à mes bookmarks', 'lie a mes bookmarks',
            // v1.17.0
            'défi lecture', 'defi lecture', 'reading challenge', 'challenge lecture',
            'analytics bookmarks', 'stats bookmarks', 'dashboard lecture',
            // v1.18.0
            'note', 'annoter', 'objectif lecture', 'reading goal', 'goal lecture',
        ];
    }

    public function version(): string
    {
        return '1.18.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        return (bool) preg_match(
            '/\b(digest|trending|tendances?|follow|suivre|unfollow|ne\s+plus\s+suivre|veille|curation|bookmark|sauvegarder|save|daily\s+digest|resume\s+quotidien|newsletter|flux\s+rss|mes\s+inter[eé]ts?|hackernews|reddit|news|actualit[eé]s?|mes\s+bookmarks?|mes\s+articles?|supprimer\s+(bookmark|article)|cherche|recherche|search|stats\s+digest|historique\s+digest|mon\s+historique|pr[eé]f[eé]rences?|mes\s+sources?|r[eé]sum[eé]|resumer|summarize|recommande|recommandation|suggestions?|pour\s+moi|vider\s+bookmarks?|quoi\s+lire|effacer\s+tout|flash|news\s+rapides|quoi\s+de\s+neuf|trouver\s+dans\s+mes\s+articles|exporter?\s+bookmarks?|exporter?\s+mes\s+articles?|confirmer\s+vider|vider\s+confirmer|lire\s+#?\d+|ouvrir\s+#?\d+|read\s+#?\d+|best\s+of|top\s+du\s+jour|meilleurs?\s+articles?|briefing|matin|renommer\s+#?\d+|rename\s+#?\d+|bilan\s+semaine|mes\s+lectures|bilan\s+lecture|r[eé]sum[eé]\s+semaine|tldr|en\s+bref|r[eé]sum[eé]\s+rapide|compare|comparer|comparaison|cite|citation|extrait|top\s+sources?|sources?\s+populaires?|meilleures?\s+sources?|surprends?\s+moi|hasard|al[eé]atoire|article\s+surprise|article\s+al[eé]atoire|digest\s+express|digest\s+rapide|quick\s+digest|aujourd.?hui|cette\s+semaine|ce\s+mois|analyser?\s+mes\s+bookmarks?|profil\s+lecture|analyse\s+biblio|r[eé]sum[eé]\s+biblio|intelligence\s+lecture|article\s+du\s+jour|lecture\s+du\s+jour|deep\s+read|s[eé]lection\s+du\s+jour|que\s+lire\s+aujourd.?hui|partager\s+#?\d+|share\s+#?\d+|envoyer\s+#?\d+|similaire\s+#?\d+|similar\s+#?\d+|comme\s+#?\d+|quiz\s+#?\d+|inspire\s*moi|d[ée]tends?\s*moi|positif|bonne\s+nouvelle|feel\s*good|motivant|mood\s+\S+|highlights?\s+#?\d+|points?\s+cl[eé]s?\s+#?\d+|takeaways?\s+#?\d+|essentiel\s+#?\d+|news\s+li[eé]es?|actualit[eé]s?\s+li[eé]es?|related\s+news|en\s+rapport|li[eé]\s+[àa]\s+mes\s+bookmarks?|d[eé]fi\s+lecture|reading\s+challenge|challenge\s+lecture|analytics?\s+bookmarks?|stats?\s+bookmarks?|dashboard\s+lecture|note\s+#?\d+|objectif\s+lecture|reading\s+goal|goal\s+lecture)\b/iu',
            $context->body
        );
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            Log::error('[content_curator] handle() exception', [
                'from'  => $context->from,
                'body'  => mb_substr($context->body ?? '', 0, 300),
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);

            $errMsg = mb_strtolower($e->getMessage());

            $isDbError    = $e instanceof \Illuminate\Database\QueryException;
            $isRateLimit  = str_contains($errMsg, 'rate_limit') || str_contains($errMsg, '429');
            $isTimeout    = str_contains($errMsg, 'timed out') || str_contains($errMsg, 'timeout');
            $isOverload   = str_contains($errMsg, 'overloaded') || str_contains($errMsg, '529');
            $isConnection = str_contains($errMsg, 'connection refused') || str_contains($errMsg, 'could not resolve');

            $reply = match (true) {
                $isDbError    => "⚠ Erreur temporaire de base de données. Réessaie dans quelques instants.",
                $isRateLimit  => "⚠ Trop de requêtes en cours. Attends quelques secondes et réessaie.",
                $isTimeout    => "⚠ Le traitement a pris trop de temps. Réessaie avec une requête plus simple.",
                $isOverload   => "⚠ Le service IA est surchargé. Réessaie dans une minute.",
                $isConnection => "⚠ Service externe inaccessible. Réessaie dans quelques instants.",
                default       => "⚠ Erreur interne du Content Curator. Réessaie ou dis *aide contenu* pour voir les commandes.",
            };

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['error' => $e->getMessage()]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp();
        }

        $this->log($context, 'Content curator request', ['body' => mb_substr($body, 0, 100)]);

        // Compare two articles (NEW v1.8.0) — check early for 2 URLs
        if (preg_match('/^(compare|comparer|vs)\s+(https?:\/\/\S+)\s+(https?:\/\/\S+)/iu', $body, $m)) {
            return $this->handleCompare($context, trim($m[2]), trim($m[3]));
        }

        // Article aléatoire (NEW v1.10.0) — before flash/digest to avoid false match
        if (preg_match('/^(surprends?\s+moi|hasard|al[eé]atoire|article\s+surprise|article\s+al[eé]atoire)\s*$/iu', $body)) {
            return $this->handleRandom($context);
        }

        // Digest express (NEW v1.10.0) — before regular digest
        if (preg_match('/^(digest\s+express|digest\s+rapide|quick\s+digest)\s*$/iu', $body)) {
            return $this->handleDigestExpress($context);
        }

        // Flash news (NEW v1.3.0) — must be before digest match
        if (preg_match('/^(flash|news\s+rapides?|quoi\s+de\s+neuf)\s*(.*)$/iu', $body, $m)) {
            $domain = trim($m[2]) ?: null;
            return $this->handleFlash($context, $domain);
        }

        // Digest thématique libre (NEW v1.11.0) — must be BEFORE generic digest to intercept "digest sur X"
        if (preg_match('/^(?:digest|actualit[eé]s?|news|articles?)\s+sur\s+(.+)$/iu', $body, $m)) {
            return $this->handleDigestTopic($context, trim($m[1]));
        }

        // Digest (+ optional refresh flag + multi-category v1.7.0)
        if (preg_match('/^(digest|resume\s+quotidien|daily\s+digest)\s*(.*)/iu', $body, $m)) {
            $rest    = trim($m[2]);
            $refresh = (bool) preg_match('/^(rafraichir|refresh|force|nouveau)\s*$/iu', $rest);

            // Multi-category: "digest tech + ai" or "digest tech,ai"
            if (!$refresh && preg_match('/[+,]/', $rest)) {
                $parts       = preg_split('/\s*[+,]\s*/', $rest);
                $refreshFlag = false;
                $cleanCats   = [];
                foreach (array_map('trim', $parts) as $part) {
                    if ($part === '') continue;
                    if (preg_match('/^(rafraichir|refresh|force|nouveau)$/iu', $part)) {
                        $refreshFlag = true;
                    } else {
                        $cleanCats[] = $part;
                    }
                }
                if (count($cleanCats) >= 2) {
                    return $this->handleDigestMulti($context, $cleanCats, $refreshFlag);
                }
            }

            $category = $refresh ? null : ($rest ?: null);
            if (!$refresh && $rest && preg_match('/\b(rafraichir|refresh|force|nouveau)\b/iu', $rest)) {
                $category = preg_replace('/\b(rafraichir|refresh|force|nouveau)\b\s*/iu', '', $rest);
                $category = trim($category) ?: null;
                $refresh  = true;
            }
            return $this->handleDigest($context, $category, $refresh);
        }

        // TLDR express (NEW v1.7.0) — ultra-compact summary
        if (preg_match('/^(tldr|en\s+bref|r[eé]sum[eé]\s+rapide|r[eé]sum[eé]\s+court)\s+(https?:\/\/\S+)/iu', $body, $m)) {
            return $this->handleTldr($context, trim($m[2]));
        }

        // Résumé d'URL
        if (preg_match('/^(r[eé]sum[eé]|resumer|r[eé]sumer|summarize)\s+(https?:\/\/\S+)/iu', $body, $m)) {
            return $this->handleSummarizeUrl($context, trim($m[2]));
        }

        // Recommandations IA
        if (preg_match('/^(recommande|recommandations?|suggestions?|pour\s+moi|quoi\s+lire)\s*$/iu', $body)) {
            return $this->handleRecommend($context);
        }

        // Follow / Unfollow
        if (preg_match('/^(follow|suivre)\s+(.+)/iu', $body, $m)) {
            return $this->handleFollow($context, trim($m[2]));
        }

        if (preg_match('/^(unfollow|ne\s+plus\s+suivre)\s+(.+)/iu', $body, $m)) {
            return $this->handleUnfollow($context, trim($m[2]));
        }

        // Trending multi-catégories (NEW v1.12.0) — must be before single trending
        if (preg_match('/^(trending|tendances?)\s+(\S+)\s*[+,]\s*(.+)$/iu', $body, $m)) {
            $cats = preg_split('/\s*[+,]\s*/', trim($m[2]) . ',' . trim($m[3]));
            return $this->handleTrendingMulti($context, $cats);
        }

        // Trending
        if (preg_match('/^(trending|tendances?)\s*(.*)/iu', $body, $m)) {
            $domain = trim($m[2]) ?: 'tech';
            return $this->handleTrending($context, $domain);
        }

        // Search in bookmarks (NEW v1.3.0) — must be before generic search
        if (preg_match('/^(cherche\s+bookmarks?|recherche\s+bookmarks?|trouver\s+dans\s+mes\s+articles?)\s+(.+)/iu', $body, $m)) {
            return $this->handleSearchBookmarks($context, trim($m[2]));
        }

        // Export bookmarks (NEW v1.4.0) — before list/clear to avoid false match
        if (preg_match('/^(exporter?\s+bookmarks?|exporter?\s+mes\s+articles?|export\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleExportBookmarks($context);
        }

        // Vider bookmarks avec confirmation (NEW v1.4.0)
        if (preg_match('/^(vider|effacer\s+tout|clear)\s+(bookmarks?|tout|articles?)\s+confirmer\s*$/iu', $body)
            || preg_match('/^confirmer\s+(vider|effacer)\s+(bookmarks?|tout|articles?)\s*$/iu', $body)
        ) {
            return $this->handleClearBookmarksConfirmed($context);
        }

        // Vider tous les bookmarks
        if (preg_match('/^(vider|effacer\s+tout|clear)\s+(bookmarks?|tout|articles?)\s*$/iu', $body)) {
            return $this->handleClearBookmarks($context);
        }

        // Save bookmark
        if (preg_match('/^(save|sauvegarder|bookmark)\s+(.+)/iu', $body, $m)) {
            return $this->handleSave($context, trim($m[2]));
        }

        // Delete bookmark
        if (preg_match('/^(supprimer|delete|effacer|remove)\s+(bookmark|article|saved)?\s*#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleDeleteSaved($context, (int) $m[3]);
        }
        if (preg_match('/^(supprimer|delete|effacer|remove)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleDeleteSaved($context, (int) $m[2]);
        }

        // Filter by period (NEW v1.12.0) — must be before pagination and generic list match
        if (preg_match('/^mes\s+bookmarks?\s+(aujourd.?hui|ce\s+jour|cette?\s+semaine|ce\s+mois)\s*$/iu', $body, $m)) {
            return $this->handleListSavedByPeriod($context, trim($m[1]));
        }

        // Pagination bookmarks (NEW v1.11.0) — must be before generic list match
        if (preg_match('/^mes\s+bookmarks?\s+page\s*(\d+)\s*$/iu', $body, $m)) {
            return $this->handleListSavedPage($context, (int) $m[1]);
        }

        // List bookmarks
        if (preg_match('/^(mes\s+bookmarks?|saved|sauvegardes?|mes\s+articles?)\s*$/iu', $body)) {
            return $this->handleListSaved($context);
        }

        // Search articles
        if (preg_match('/^(cherche|recherche|search|trouver\s+articles?)\s+(.+)/iu', $body, $m)) {
            return $this->handleSearch($context, trim($m[2]));
        }

        // Digest stats
        if (preg_match('/\b(stats\s+digest|historique\s+digest|mon\s+historique|stats\s+contenu)\b/iu', $body)) {
            return $this->handleDigestStats($context);
        }

        // Preferences
        if (preg_match('/\b(pr[eé]f[eé]rences?|inter[eé]ts?|mes\s+sources?|config)\b/iu', $body)) {
            return $this->handlePreferences($context);
        }

        // Read/summarize bookmark by position number (NEW v1.5.0)
        if (preg_match('/^(lire|ouvrir|read)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleReadBookmark($context, (int) $m[2]);
        }

        // Best of du jour (NEW v1.5.0)
        if (preg_match('/^(best\s+of|top\s+du\s+jour|meilleurs?\s+articles?|briefing|matin)\s*$/iu', $body)) {
            return $this->handleBestOf($context);
        }

        // Renommer bookmark (NEW v1.6.0)
        if (preg_match('/^(renommer|rename)\s+#?(\d+)\s+(.+)$/iu', $body, $m)) {
            return $this->handleRenameBookmark($context, (int) $m[2], trim($m[3]));
        }

        // Bilan de lecture hebdomadaire (NEW v1.6.0)
        if (preg_match('/^(bilan\s+semaine|mes\s+lectures|bilan\s+lecture|r[eé]sum[eé]\s+semaine)\s*$/iu', $body)) {
            return $this->handleWeeklyBilan($context);
        }

        // Top sources (NEW v1.9.0)
        if (preg_match('/^(top\s+sources?|mes\s+sources?|sources?\s+populaires?|meilleures?\s+sources?)\s*$/iu', $body)) {
            return $this->handleTopSources($context);
        }

        // Citation / extrait (NEW v1.9.0)
        if (preg_match('/^(cite|citation|extrait|extraire\s+citation)\s+(https?:\/\/\S+)/iu', $body, $m)) {
            return $this->handleCite($context, trim($m[2]));
        }

        // Analyse IA de la bibliothèque (NEW v1.13.0)
        if (preg_match('/^(analyser?\s+mes\s+bookmarks?|profil\s+lecture|analyse\s+biblio|r[eé]sum[eé]\s+biblio|intelligence\s+lecture|mon\s+profil\s+lecture)\s*$/iu', $body)) {
            return $this->handleAnalyseLibrary($context);
        }

        // Article du jour — sélection IA personnalisée (NEW v1.13.0)
        if (preg_match('/^(article\s+du\s+jour|lecture\s+du\s+jour|deep\s+read|s[eé]lection\s+du\s+jour|que\s+lire\s+aujourd.?hui|lecture\s+recommand[eé]e?)\s*$/iu', $body)) {
            return $this->handleArticleDuJour($context);
        }

        // Partager un bookmark (NEW v1.14.0)
        if (preg_match('/^(partager|share|envoyer)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleShareBookmark($context, (int) $m[2]);
        }

        // Articles similaires (NEW v1.14.0)
        if (preg_match('/^(similaire|similar|comme)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleSimilar($context, (int) $m[2]);
        }

        // Quiz sur un bookmark (NEW v1.15.0)
        if (preg_match('/^quiz\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleQuizArticle($context, (int) $m[1]);
        }

        // Highlights / points clés d'un bookmark (NEW v1.16.0)
        if (preg_match('/^(highlights?|points?\s+cl[eé]s?|takeaways?|essentiel)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleHighlights($context, (int) $m[2]);
        }

        // News liées aux bookmarks récents (NEW v1.16.0)
        if (preg_match('/^(news\s+li[eé]es?|actualit[eé]s?\s+li[eé]es?|related\s+news|en\s+rapport|li[eé]\s+[àa]\s+mes\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleRelatedNews($context);
        }

        // Défi lecture quotidien (NEW v1.17.0)
        if (preg_match('/^(d[eé]fi\s+lecture|reading\s+challenge|d[eé]fi\s+du\s+jour|challenge\s+lecture)\s*$/iu', $body)) {
            return $this->handleReadingChallenge($context);
        }

        // Stats bookmarks avancées (NEW v1.17.0)
        if (preg_match('/^(analytics?\s+bookmarks?|stats?\s+bookmarks?|mes\s+analytics?|dashboard\s+lecture)\s*$/iu', $body)) {
            return $this->handleBookmarkAnalytics($context);
        }

        // Note sur un bookmark (NEW v1.18.0)
        if (preg_match('/^note\s+#?(\d+)\s+(.+)$/iu', $body, $m)) {
            return $this->handleBookmarkNote($context, (int) $m[1], trim($m[2]));
        }

        // Objectif de lecture hebdomadaire (NEW v1.18.0)
        if (preg_match('/^(objectif\s+lecture|reading\s+goal|goal\s+lecture)\s*(\d+)?\s*$/iu', $body, $m)) {
            $target = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            return $this->handleReadingGoal($context, $target);
        }

        // Digest par mood/ambiance (NEW v1.15.0)
        if (preg_match('/^(inspire\s*moi|d[ée]tends?\s*moi|positif|bonne\s+nouvelle|feel\s*good|motivant|mood\s+.+)\s*$/iu', $body, $m)) {
            return $this->handleMoodDigest($context, trim($m[1]));
        }

        // Generic news / veille → digest
        if (preg_match('/\b(news|actualit[eé]s?|veille|curation)\b/iu', $body)) {
            return $this->handleDigest($context, null, false);
        }

        return $this->showHelp();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FLASH NEWS (NEW v1.3.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleFlash(AgentContext $context, ?string $domain): AgentResult
    {
        $normalized = $domain
            ? (self::CATEGORY_ALIASES[mb_strtolower($domain)] ?? mb_strtolower($domain))
            : null;

        // Validate category if specified
        if ($normalized && !in_array($normalized, self::VALID_CATEGORIES)) {
            return $this->invalidCategoryReply($domain, 'flash ai');
        }

        // If a specific domain is requested, use it; otherwise use user prefs
        if ($normalized) {
            $categories = [$normalized];
        } else {
            $prefs      = UserContentPreference::where('user_phone', $context->from)->get();
            $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            if (empty($categories)) {
                $categories = ['technology', 'ai'];
            }
        }

        $this->log($context, 'Flash news requested', ['categories' => $categories]);

        try {
            // Pull from all user categories (up to 4), deduplicate by URL
            $articles = [];
            $seen     = [];
            $needed   = 5;
            foreach (array_slice($categories, 0, 4) as $cat) {
                $fetched = $this->aggregator->getTrending($cat, $needed);
                foreach ($fetched as $art) {
                    $url = $art['url'] ?? '';
                    if ($url && isset($seen[$url])) continue;
                    if ($url) $seen[$url] = true;
                    $articles[] = $art;
                    if (count($articles) >= $needed) break 2;
                }
            }

            if (empty($articles)) {
                return AgentResult::reply(
                    "⚡ Aucun flash disponible pour le moment.\n"
                    . "_Essaie *digest* pour un résumé complet._"
                );
            }

            $label  = $normalized ? ucfirst($normalized) : implode(' + ', array_map('ucfirst', array_slice($categories, 0, 3)));
            $hour   = (int) now()->format('H');
            $greet  = match (true) {
                $hour < 12  => '☀️ Bon matin',
                $hour < 18  => '📰 Cet après-midi',
                default     => '🌙 Ce soir',
            };
            $output = "*⚡ FLASH NEWS — {$label}*\n";
            $output .= "_{$greet} · " . now()->format('H:i') . " · Top " . count($articles) . " du moment_\n\n";

            foreach ($articles as $i => $article) {
                $title  = $article['title'] ?? 'Sans titre';
                $source = $article['source'] ?? '';
                $score  = $article['score'] ?? 0;
                $url    = $article['url'] ?? '';

                $output .= "*" . ($i + 1) . ".* {$title}";
                if ($source) $output .= " _{$source}_";
                if ($score >= 100) $output .= " 🔥";
                $output .= "\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *résume [url]* pour lire en détail · *digest* pour plus_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Flash failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du flash news. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIGEST
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDigest(AgentContext $context, ?string $category, bool $forceRefresh = false): AgentResult
    {
        $userPhone = $context->from;

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        if ($category) {
            $normalized = $this->resolveCategory($category);
            if (!$normalized) {
                return $this->invalidCategoryReply($category, 'digest tech');
            }
            $categories = [$normalized];
        } elseif (empty($categories)) {
            $categories = ['technology', 'science'];
        }

        // Bust cache if user requests fresh content
        if ($forceRefresh) {
            foreach ($categories as $cat) {
                $cacheKey = "content_curator:aggregate:{$cat}:" . md5(implode(',', $keywords));
                Cache::forget($cacheKey);
            }
        }

        $this->log($context, 'Generating digest', ['categories' => $categories, 'refresh' => $forceRefresh]);

        try {
            $articles = $this->aggregator->aggregate($categories, $keywords, 10);

            if (empty($articles)) {
                $hint = $category
                    ? "Aucun article trouvé pour *{$category}*.\n_Essaie une autre catégorie : tech, ai, science, business, health, crypto..._"
                    : "Aucun article trouvé pour tes centres d'intérêt.\n_Utilise *follow [catégorie]* pour personnaliser ton feed._";
                return AgentResult::reply($hint);
            }

            $summaries = $this->summarizer->summarizeBatch($articles, 8);

            ContentDigestLog::create([
                'user_phone'    => $userPhone,
                'categories'    => $categories,
                'article_count' => count($summaries),
                'sent_at'       => now(),
            ]);

            $catLabel = $category ? " — " . ucfirst($category) : "";
            $icon     = $category ? (self::CATEGORY_ICONS[self::CATEGORY_ALIASES[mb_strtolower($category)] ?? mb_strtolower($category)] ?? '📰') : '📰';

            $header  = "*{$icon} DIGEST{$catLabel}*\n";
            $header .= "_" . now()->format('d/m/Y H:i') . " · " . count($summaries) . " articles_";
            if ($forceRefresh) $header .= " _(actualisé)_";
            $header .= "\n\n";

            $output = $header;
            foreach ($summaries as $i => $article) {
                $num     = $i + 1;
                $title   = $article['title'] ?? 'Sans titre';
                $summary = $article['summary'] ?? '';
                $source  = $article['source'] ?? '';
                $url     = $article['url'] ?? '';

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n{$summary}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "---\n";
            $output .= "_💡 *save [url]* · *follow [cat]* · *cherche [sujet]* · *digest rafraichir* · *recommande* · *flash*_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Digest generation failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération du digest. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRENDING
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTrending(AgentContext $context, string $domain): AgentResult
    {
        $normalized = $this->resolveCategory($domain);

        if (!$normalized) {
            return $this->invalidCategoryReply($domain, 'trending tech');
        }

        $icon = self::CATEGORY_ICONS[$normalized] ?? '🔥';
        $this->log($context, 'Fetching trending', ['domain' => $normalized]);

        try {
            $articles = $this->aggregator->getTrending($normalized, 8);

            if (empty($articles)) {
                return AgentResult::reply(
                    "Aucun contenu trending trouvé pour *{$domain}*.\n"
                    . "_Domaines disponibles : tech, science, business, health, ai, crypto, gaming, security_"
                );
            }

            $output = "*{$icon} TRENDING — " . ucfirst($domain) . "*\n";
            $output .= "_" . now()->format('d/m/Y H:i') . "_\n\n";

            foreach ($articles as $i => $article) {
                $num    = $i + 1;
                $title  = $article['title'] ?? 'Sans titre';
                $score  = $article['score'] ?? 0;
                $source = $article['source'] ?? '';
                $url    = $article['url'] ?? '';

                $heatIcon = match(true) {
                    $score >= 500  => '🔥',
                    $score >= 100  => '⬆️',
                    default        => '▪️',
                };

                $output .= "*{$num}. {$title}*";
                if ($score) $output .= " {$heatIcon} ({$score})";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour résumer un article · *flash* pour un résumé express_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Trending fetch failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la récupération des tendances. Réessaie plus tard.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEARCH
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSearch(AgentContext $context, string $query): AgentResult
    {
        $queryDisplay = trim($query); // v1.12.0: keep original casing for display
        $query        = mb_strtolower($queryDisplay);

        if (mb_strlen($query) < 2) {
            return AgentResult::reply("Précise un sujet de recherche. Exemple: *cherche laravel 12*");
        }

        if (mb_strlen($query) > 200) {
            return AgentResult::reply("Requête trop longue. Utilise quelques mots-clés. Exemple: *cherche laravel 12*");
        }

        $this->log($context, 'Searching articles', ['query' => $queryDisplay]);

        // Cache key per user + query (5 min)
        $cacheKey = "content_curator:search:{$context->from}:" . md5($query);
        $cached   = Cache::get($cacheKey);

        if ($cached) {
            return AgentResult::reply($cached);
        }

        // Use user's followed categories for search, fall back to broad defaults
        $prefs      = UserContentPreference::where('user_phone', $context->from)->get();
        $userCats   = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $categories = !empty($userCats)
            ? array_slice($userCats, 0, 4)
            : ['technology', 'science', 'business', 'health'];

        try {
            $articles = $this->aggregator->aggregate($categories, [$query], 15);

            if (empty($articles)) {
                return AgentResult::reply(
                    "🔍 Aucun article trouvé pour *{$queryDisplay}*.\n\n"
                    . "_Essaie un terme plus général ou vérifie l'orthographe._\n"
                    . "_Ou utilise *trending tech* pour voir les tendances du moment._"
                );
            }

            // Score articles by relevance: title match = 2pts, description match = 1pt, source match = 1pt
            $queryWords = array_filter(preg_split('/\s+/', $query), fn($w) => mb_strlen($w) >= 3);
            $scored = array_map(function ($a) use ($queryWords) {
                $titleText  = mb_strtolower($a['title'] ?? '');
                $descText   = mb_strtolower($a['description'] ?? '');
                $sourceText = mb_strtolower($a['source'] ?? '');
                $score = 0;
                foreach ($queryWords as $word) {
                    if (str_contains($titleText, $word))  $score += 2;
                    if (str_contains($descText, $word))   $score += 1;
                    if (str_contains($sourceText, $word)) $score += 1;
                }
                $a['_relevance'] = $score;
                return $a;
            }, $articles);

            // Keep only articles with at least one match; fallback to all if none match
            $relevant = array_filter($scored, fn($a) => ($a['_relevance'] ?? 0) > 0);
            if (empty($relevant)) {
                $relevant = $scored;
            }

            // Sort by relevance desc then by source score
            usort($relevant, fn($a, $b) => ($b['_relevance'] ?? 0) <=> ($a['_relevance'] ?? 0));

            $results = array_values($relevant);
            $results = array_slice($results, 0, 6);

            $output = "*🔍 RECHERCHE — {$queryDisplay}*\n";
            $output .= "_" . count($results) . " résultat(s)_\n\n";

            foreach ($results as $i => $article) {
                $num    = $i + 1;
                $title  = $article['title'] ?? 'Sans titre';
                $source = $article['source'] ?? '';
                $url    = $article['url'] ?? '';
                $desc   = mb_strimwidth($article['description'] ?? '', 0, 120, '...');

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($desc) $output .= "{$desc}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour résumer_";

            Cache::put($cacheKey, $output, 300); // 5 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Search failed for query='{$query}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la recherche. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEARCH IN BOOKMARKS (NEW v1.3.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSearchBookmarks(AgentContext $context, string $query): AgentResult
    {
        $query     = mb_strtolower(trim($query));
        $userPhone = $context->from;

        if (mb_strlen($query) < 2) {
            return AgentResult::reply("Précise un mot-clé. Exemple: *cherche bookmarks laravel*");
        }

        $total = SavedArticle::where('user_phone', $userPhone)->count();
        if ($total === 0) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $this->log($context, 'Searching bookmarks', ['query' => $query]);

        $queryLower = mb_strtolower($query);
        $queryWords = array_values(array_filter(
            preg_split('/\s+/', $queryLower),
            fn($w) => mb_strlen($w) >= 2
        ));

        // DB-level LIKE search (performant, no full in-memory load)
        $buildWhere = function ($q) use ($queryLower, $queryWords) {
            $q->where('title', 'LIKE', "%{$queryLower}%")
              ->orWhere('url', 'LIKE', "%{$queryLower}%")
              ->orWhere('source', 'LIKE', "%{$queryLower}%");
            foreach ($queryWords as $word) {
                $q->orWhere('title', 'LIKE', "%{$word}%");
            }
        };

        $matchCount = SavedArticle::where('user_phone', $userPhone)
            ->where($buildWhere)
            ->count();

        if ($matchCount === 0) {
            return AgentResult::reply(
                "🔍 Aucun bookmark trouvé pour *{$query}*.\n\n"
                . "_Ta recherche porte sur {$total} bookmark(s)._\n"
                . "_Essaie un autre mot-clé ou *mes bookmarks* pour tout voir._"
            );
        }

        $results = SavedArticle::where('user_phone', $userPhone)
            ->where($buildWhere)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $output = "*🔍 BOOKMARKS — {$query}*\n";
        $output .= "_" . $results->count() . " résultat(s)";
        if ($matchCount > 10) $output .= " (+" . ($matchCount - 10) . " autres)";
        $output .= " sur {$total} bookmark(s)_\n\n";

        foreach ($results as $i => $article) {
            $num    = $i + 1;
            $title  = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date   = $article->created_at->format('d/m');

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= "🔗 {$article->url}\n\n";
        }

        $output .= "_💡 *résume [url]* pour lire · *supprimer [n°]* pour effacer_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RÉSUMÉ D'URL
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSummarizeUrl(AgentContext $context, string $url): AgentResult
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return AgentResult::reply(
                "❌ URL invalide. Envoie une URL complète commençant par https://.\n"
                . "_Exemple : *résume https://example.com/article*_"
            );
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
        }

        // Cache summary for 1 hour (same URL = same content)
        $cacheKey = "content_curator:summary:" . md5($url);
        $cached   = Cache::get($cacheKey);

        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Summarizing URL', ['url' => mb_substr($url, 0, 100)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok']) {
                return AgentResult::reply(
                    "❌ Impossible d'accéder à cette page (erreur {$content['status']}).\n"
                    . "_Vérifie que l'URL est accessible publiquement._"
                );
            }

            $title  = $content['title'];
            $ogDesc = $content['desc'];
            $text   = $content['text'];

            // If text extraction failed but we have og:description, use it
            if (mb_strlen($text) < 100) {
                if ($ogDesc && mb_strlen($ogDesc) >= 80) {
                    $text = $ogDesc;
                } else {
                    return AgentResult::reply(
                        "❌ Impossible d'extraire le contenu de cet article (contenu insuffisant ou page protégée).\n"
                        . "_Certains sites bloquent la lecture automatique._"
                    );
                }
            }

            // Prepend og:description to excerpt for richer summary context
            if ($ogDesc && mb_strlen($ogDesc) > 50) {
                $text = $ogDesc . "\n\n" . $text;
            }

            // Estimate reading time (avg 200 words/min)
            $wordCount   = str_word_count(mb_substr($text, 0, 5000));
            $readingMins = max(1, (int) ceil($wordCount / 200));

            // Limit to ~3500 chars for the prompt
            $excerpt = mb_substr($text, 0, 3500);

            $systemPrompt = <<<PROMPT
Tu es un assistant de lecture intelligente spécialisé dans les résumés pour mobile (WhatsApp). Fournis un résumé structuré, informatif et directement utilisable.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp):
📌 *Sujet principal* : [1 phrase directe — sujet + fait central]

🔑 *Points clés* :
• [fait concret 1 — commence par un verbe d'action ou un chiffre si disponible]
• [fait concret 2]
• [fait concret 3]
• [fait concret 4 si l'article est long]
• [fait concret 5 si pertinent]

💡 *À retenir* : [1-2 phrases — conclusion ou impact pratique pour le lecteur]

RÈGLES STRICTES:
- Factuel à 100% : n'invente AUCUNE information absente du texte source
- Style journalistique direct : chiffres, noms propres, faits vérifiables
- Si le texte est trop court ou peu informatif, résume ce qui est disponible sans compléter
- Adapte le nombre de points clés au contenu réel (3 minimum, 5 maximum)
- Réponds en français même si l'article est en anglais
- N'utilise pas de guillemets ni de markdown lourd (*, _, etc. sont autorisés pour le formatage WhatsApp)
PROMPT;

            $userMessage = "Titre: " . ($title ?? 'Inconnu') . "\nURL: {$url}\n\nContenu:\n{$excerpt}";

            $summary = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt);

            if (!$summary) {
                return AgentResult::reply("❌ Impossible de générer le résumé. Réessaie dans quelques instants.");
            }

            // Detect article language from content
            $langHint = preg_match('/[àâéèêëïîôùûüç]{3,}/u', $text) ? '🇫🇷' : '🇬🇧';

            $output = "*📖 RÉSUMÉ D'ARTICLE* {$langHint}\n";
            if ($title) $output .= "*" . mb_strimwidth($title, 0, 80, '...') . "*\n";
            $output .= "🔗 {$url}\n";
            $output .= "⏱️ ~{$readingMins} min de lecture · ~{$wordCount} mots\n\n";
            $output .= $summary . "\n\n";
            $output .= "_💡 *save {$url}* pour bookmarker · *tldr {$url}* pour la version express_";

            Cache::put($cacheKey, $output, 3600); // 1 heure

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] SummarizeUrl failed for '{$url}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du résumé de l'article. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECOMMANDATIONS IA
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRecommend(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        $recentBookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->take(5)
            ->pluck('title')
            ->filter()
            ->values()
            ->toArray();

        if (empty($categories) && empty($keywords) && empty($recentBookmarks)) {
            return AgentResult::reply(
                "*🤖 RECOMMANDATIONS PERSONNALISÉES*\n\n"
                . "Je n'ai pas encore assez d'informations sur tes intérêts.\n\n"
                . "_Pour des recommandations personnalisées :_\n"
                . "• *follow tech* — suis une catégorie\n"
                . "• *follow laravel* — ajoute un mot-clé\n"
                . "• *digest* — génère d'abord un digest\n\n"
                . "_Plus tu utilises le curator, meilleures sont les reco !_"
            );
        }

        $this->log($context, 'Generating recommendations', [
            'categories' => $categories,
            'keywords'   => array_slice($keywords, 0, 5),
        ]);

        try {
            $profileText = '';
            if (!empty($categories)) {
                $profileText .= "Catégories suivies: " . implode(', ', $categories) . "\n";
            }
            if (!empty($keywords)) {
                $profileText .= "Mots-clés d'intérêt: " . implode(', ', array_slice($keywords, 0, 10)) . "\n";
            }
            if (!empty($recentBookmarks)) {
                $profileText .= "Articles récemment sauvegardés: " . implode(' | ', $recentBookmarks) . "\n";
            }

            $currentDate  = now()->translatedFormat('F Y');
            $dayOfWeek    = now()->translatedFormat('l');
            $systemPrompt = <<<PROMPT
Tu es un conseiller expert en veille informationnelle (date actuelle : {$dayOfWeek}, {$currentDate}). Analyse le profil de lecture et identifie 3 sujets pertinents à explorer MAINTENANT.

FORMAT DE RÉPONSE (JSON array, exactement 3 éléments):
[
  {"topic": "sujet court en anglais (2-4 mots max, idéal pour recherche d'articles)", "label": "intitulé affiché en français", "reason": "pourquoi ce sujet est important MAINTENANT en 1 phrase directe", "difficulty": "débutant|intermédiaire|avancé"},
  ...
]

RÈGLES STRICTES:
- Topics en anglais, labels et reasons en français
- Sujets précis et actionnables (ex: "AI agents open source" plutôt que "intelligence artificielle")
- Prioritise les sujets qui émergent ou évoluent rapidement en 2026
- Diversifie les 3 sujets (évite 3 sujets trop similaires entre eux)
- Chaque reason doit mentionner un fait concret ou une tendance récente
- Le champ difficulty doit refléter la complexité du sujet pour adapter les recherches
- Si l'utilisateur a des bookmarks récents, propose des sujets complémentaires (pas identiques)
- Retourne UNIQUEMENT le JSON valide, aucun texte avant ou après
PROMPT;

            $topicsJson = $this->claude->chat($profileText, ModelResolver::fast(), $systemPrompt);

            $topics = $this->parseJsonResponse($topicsJson);

            if (!$topics || empty($topics)) {
                return $this->handleDigest($context, !empty($categories) ? $categories[0] : null, false);
            }

            $topics = array_slice($topics, 0, 3);

            $topKeywords = array_map(fn($t) => $t['topic'] ?? '', $topics);
            $searchCats  = !empty($categories) ? array_slice($categories, 0, 3) : ['technology', 'science', 'business'];

            $articles = $this->aggregator->aggregate($searchCats, $topKeywords, 6);

            $output = "*🤖 RECOMMANDATIONS POUR TOI*\n";
            $output .= "_Basées sur tes " . (count($categories) + count($keywords)) . " centre(s) d'intérêt_\n\n";

            $output .= "*🎯 Sujets recommandés :*\n";
            $diffIcons = ['débutant' => '🟢', 'intermédiaire' => '🟡', 'avancé' => '🔴'];
            foreach ($topics as $i => $t) {
                $label      = $t['label'] ?? $t['topic'] ?? "Sujet " . ($i + 1);
                $reason     = $t['reason'] ?? '';
                $difficulty = $t['difficulty'] ?? '';
                $diffIcon   = $diffIcons[$difficulty] ?? '';
                $output .= "  " . ($i + 1) . ". *{$label}*";
                if ($diffIcon) $output .= " {$diffIcon}";
                if ($reason) $output .= " — _{$reason}_";
                $output .= "\n";
            }
            $output .= "\n";

            if (!empty($articles)) {
                $output .= "*📰 Articles suggérés :*\n\n";
                foreach (array_slice($articles, 0, 5) as $i => $article) {
                    $num    = $i + 1;
                    $title  = $article['title'] ?? 'Sans titre';
                    $source = $article['source'] ?? '';
                    $url    = $article['url'] ?? '';

                    $output .= "*{$num}. {$title}*";
                    if ($source) $output .= " _{$source}_";
                    $output .= "\n";
                    if ($url) $output .= "🔗 {$url}\n";
                    $output .= "\n";
                }
            }

            $output .= "_💡 *digest* · *follow [cat]* · *résume [url]* · *save [url]* · *flash*_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Recommend failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération des recommandations. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FOLLOW / UNFOLLOW
    // ─────────────────────────────────────────────────────────────────────────

    private function handleFollow(AgentContext $context, string $source): AgentResult
    {
        $userPhone  = $context->from;
        $clean      = mb_strtolower(trim($source));
        $normalized = self::CATEGORY_ALIASES[$clean] ?? $clean;

        if (in_array($normalized, self::VALID_CATEGORIES)) {
            $existing = UserContentPreference::where('user_phone', $userPhone)
                ->where('category', $normalized)
                ->first();

            if ($existing) {
                return AgentResult::reply("Tu suis déjà la catégorie *{$normalized}*.\n_Dis *preferences* pour voir tous tes abonnements._");
            }

            UserContentPreference::create([
                'user_phone' => $userPhone,
                'category'   => $normalized,
                'keywords'   => [],
                'sources'    => [],
            ]);

            $this->log($context, 'User followed category', ['category' => $normalized]);

            $icon = self::CATEGORY_ICONS[$normalized] ?? '✅';
            return AgentResult::reply(
                "{$icon} Tu suis maintenant *{$normalized}*.\n\n"
                . "Ton prochain digest inclura du contenu de cette catégorie.\n"
                . "_Dis *digest* pour voir ton feed maintenant._"
            );
        }

        // Invalid category that looks like a category attempt (short word, no spaces)
        if (mb_strlen($clean) <= 4 && !str_contains($clean, ' ') && !in_array($normalized, self::VALID_CATEGORIES)) {
            $list = implode(', ', self::VALID_CATEGORIES);
            return AgentResult::reply(
                "❌ Catégorie *{$clean}* inconnue.\n\n"
                . "*Catégories disponibles :*\n_{$list}_\n\n"
                . "_Ou suis un mot-clé libre : *follow laravel*, *follow bitcoin*_"
            );
        }

        // Keyword custom
        $pref = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', 'custom')
            ->first();

        if ($pref) {
            $keywords = $pref->keywords ?? [];
            // Case-insensitive duplicate check (v1.10.0)
            if (in_array($clean, array_map('mb_strtolower', $keywords))) {
                return AgentResult::reply("Tu suis déjà le mot-clé *{$clean}*.\n_Dis *preferences* pour voir tous tes mots-clés._");
            }
            $keywords[] = $clean;
            $pref->update(['keywords' => $keywords]);
        } else {
            UserContentPreference::create([
                'user_phone' => $userPhone,
                'category'   => 'custom',
                'keywords'   => [$clean],
                'sources'    => [],
            ]);
        }

        $this->log($context, 'User followed keyword', ['keyword' => $clean]);

        return AgentResult::reply(
            "✅ Mot-clé *{$clean}* ajouté à tes intérêts.\n"
            . "_Dis *digest* pour voir ton feed personnalisé._"
        );
    }

    private function handleUnfollow(AgentContext $context, string $source): AgentResult
    {
        $userPhone  = $context->from;
        $clean      = mb_strtolower(trim($source));
        $normalized = self::CATEGORY_ALIASES[$clean] ?? $clean;

        $deleted = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', $normalized)
            ->delete();

        if ($deleted) {
            return AgentResult::reply("✅ Tu ne suis plus *{$normalized}*.\n_Dis *preferences* pour voir tes abonnements restants._");
        }

        // Try keyword
        $pref = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', 'custom')
            ->first();

        if ($pref) {
            $keywords = $pref->keywords ?? [];
            // v1.12.0: case-insensitive match so "Laravel" and "laravel" are treated as the same
            $filtered = array_values(array_filter($keywords, fn($k) => mb_strtolower($k) !== mb_strtolower($clean)));

            if (count($filtered) < count($keywords)) {
                $pref->update(['keywords' => $filtered]);
                return AgentResult::reply("✅ Mot-clé *{$clean}* retiré de tes intérêts.\n_Dis *preferences* pour voir tes intérêts restants._");
            }
        }

        return AgentResult::reply(
            "Tu ne suis pas *{$clean}*.\n_Dis *preferences* pour voir tes abonnements actuels._"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAVE / LIST / DELETE / CLEAR BOOKMARKS
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSave(AgentContext $context, string $input): AgentResult
    {
        $userPhone = $context->from;

        if (!preg_match('#https?://[^\s<>\[\]"\']+#i', $input, $m)) {
            return AgentResult::reply(
                "Envoie une URL valide après *save*.\n"
                . "_Exemple : *save https://example.com/article*_\n"
                . "_Ou avec un titre : *save https://example.com Mon titre personnalisé*_"
            );
        }

        $url = $m[0];

        if (mb_strlen($url) > 2048) {
            return AgentResult::reply("❌ L'URL est trop longue pour être sauvegardée (max 2048 caractères).");
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
        }

        // Check for custom title after the URL (v1.10.0)
        $afterUrl    = trim(str_replace($m[0], '', $input));
        $customTitle = ($afterUrl !== '' && mb_strlen($afterUrl) >= 3 && mb_strlen($afterUrl) <= 255)
            ? $afterUrl
            : null;

        $existing = SavedArticle::where('user_phone', $userPhone)
            ->where('url', $url)
            ->first();

        if ($existing) {
            return AgentResult::reply(
                "Cet article est déjà dans tes bookmarks (ajouté le " . $existing->created_at->format('d/m/Y') . ").\n"
                . "_Dis *mes bookmarks* pour voir ta liste · *renommer #N [titre]* pour changer son titre._"
            );
        }

        $title  = $customTitle ?: $this->fetchPageTitle($url);
        $source = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        // Remove 'www.' prefix for cleaner display
        $source = preg_replace('/^www\./i', '', $source);

        SavedArticle::create([
            'user_phone' => $userPhone,
            'url'        => $url,
            'title'      => $title,
            'source'     => $source,
        ]);

        $this->log($context, 'Article saved', ['url' => $url, 'custom_title' => (bool) $customTitle]);

        $total        = SavedArticle::where('user_phone', $userPhone)->count();
        $displayTitle = $title ?: $url;
        $titleNote    = $customTitle ? " _(titre personnalisé)_" : '';

        // Milestone celebrations
        $milestone = '';
        if ($total === 10)  $milestone = "\n🎉 *10 bookmarks !* Tu construis une belle bibliothèque.";
        if ($total === 50)  $milestone = "\n🏆 *50 bookmarks !* Lecteur confirmé. Essaie *profil lecture* !";
        if ($total === 100) $milestone = "\n🌟 *100 bookmarks !* Bibliothèque impressionnante. *analytics bookmarks* pour voir tes stats.";

        return AgentResult::reply(
            "🔖 Article sauvegardé !\n\n"
            . "*{$displayTitle}*{$titleNote}\n"
            . "_{$source}_\n"
            . "🔗 {$url}\n\n"
            . "📚 Bookmark #{$total}{$milestone}\n"
            . "_*résume {$url}* pour lire · *similaire #{$total}* pour des articles proches_"
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
                "Tu n'as aucun article sauvegardé.\n"
                . "_Utilise *save [url]* pour bookmarker un article._"
            );
        }

        $total      = SavedArticle::where('user_phone', $userPhone)->count();
        $totalPages = (int) ceil($total / 15);

        $output = "*🔖 MES BOOKMARKS — Page 1";
        if ($totalPages > 1) $output .= "/{$totalPages}";
        $output .= "* ({$articles->count()}";
        if ($total > 15) $output .= " / {$total} au total";
        $output .= ")\n\n";

        foreach ($articles as $i => $article) {
            $num    = $i + 1;
            $title  = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date   = $article->created_at->format('d/m');

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= "🔗 {$article->url}\n\n";
        }

        $hints = ["*supprimer [n°]*", "*vider bookmarks*", "*résume [url]* pour lire", "*cherche bookmarks [mot]* pour filtrer"];
        if ($totalPages > 1) {
            $hints[] = "*mes bookmarks page 2* pour la suite";
        }
        $output .= "_💡 " . implode(' · ', $hints) . "_";

        return AgentResult::reply($output);
    }

    private function handleDeleteSaved(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* ou *exporter bookmarks* pour voir ta liste.");
        }

        // Load full list (matches export numbering) — no artificial take() limit
        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply("Tu n'as aucun bookmark à supprimer.");
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Numéro {$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* (15 derniers) ou *exporter bookmarks* (tous) pour voir les numéros._"
            );
        }

        $title = $article->title ?: $article->url;
        $article->delete();

        $this->log($context, 'Article deleted', ['position' => $position, 'url' => $article->url]);

        $remaining = SavedArticle::where('user_phone', $userPhone)->count();
        return AgentResult::reply(
            "🗑️ Bookmark supprimé :\n*{$title}*\n\n"
            . "_Il te reste {$remaining} bookmark(s). Dis *mes bookmarks* pour voir ta liste._"
        );
    }

    private function handleClearBookmarks(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $count = SavedArticle::where('user_phone', $userPhone)->count();

        if ($count === 0) {
            return AgentResult::reply("Tu n'as aucun bookmark à effacer.");
        }

        // v1.4.0 — require explicit confirmation before destructive delete
        return AgentResult::reply(
            "⚠️ *Supprimer tous les bookmarks ?*\n\n"
            . "Tu as *{$count} bookmark(s)* sauvegardé(s). Cette action est irréversible.\n\n"
            . "Pour confirmer, envoie :\n"
            . "*vider bookmarks confirmer*\n\n"
            . "_Ou *mes bookmarks* pour revoir ta liste avant de décider._"
        );
    }

    private function handleClearBookmarksConfirmed(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $count = SavedArticle::where('user_phone', $userPhone)->count();

        if ($count === 0) {
            return AgentResult::reply("Tu n'as aucun bookmark à effacer.");
        }

        SavedArticle::where('user_phone', $userPhone)->delete();

        $this->log($context, 'All bookmarks cleared (confirmed)', ['count' => $count]);

        return AgentResult::reply(
            "🗑️ *{$count} bookmark(s) supprimé(s).*\n\n"
            . "Ta liste de bookmarks est maintenant vide.\n"
            . "_Utilise *save [url]* pour en ajouter de nouveaux._"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READ BOOKMARK BY POSITION (NEW v1.5.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadBookmark(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $this->log($context, 'Reading bookmark by position', [
            'position' => $position,
            'url'      => $article->url,
        ]);

        // Show note if present (v1.18.0)
        $noteKey = "content_curator:note:{$article->id}";
        $note    = Cache::get($noteKey);

        $result = $this->handleSummarizeUrl($context, $article->url);

        if ($note && $result->text) {
            $result = AgentResult::reply(
                $result->text . "\n\n📝 *Ta note :* _{$note}_"
            );
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BEST OF DU JOUR (NEW v1.5.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBestOf(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $prefs    = UserContentPreference::where('user_phone', $userPhone)->get();
        $userCats = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();

        // Mix user categories with safe defaults for a broad "best of"
        $broadCats = !empty($userCats)
            ? array_unique(array_merge($userCats, ['technology', 'ai']))
            : ['technology', 'ai', 'science', 'business'];
        $broadCats = array_slice($broadCats, 0, 5);

        // Cache 20 min — avoids hammering aggregator on repeated calls
        $cacheKey = "content_curator:bestof:{$userPhone}:" . md5(implode(',', $broadCats));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Best of requested', ['categories' => $broadCats]);

        try {
            // Fetch trending from each category, merge and rank by score
            $allArticles = [];
            foreach ($broadCats as $cat) {
                $trending = $this->aggregator->getTrending($cat, 5);
                foreach ($trending as $article) {
                    $article['_cat'] = $cat;
                    $allArticles[]   = $article;
                }
            }

            if (empty($allArticles)) {
                return AgentResult::reply(
                    "⭐ Aucun contenu disponible pour le moment.\n"
                    . "_Réessaie dans quelques minutes ou utilise *flash* pour un résumé rapide._"
                );
            }

            // Sort by score desc, deduplicate by URL, keep top 5
            usort($allArticles, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

            $seen = [];
            $best = [];
            foreach ($allArticles as $article) {
                $url = $article['url'] ?? '';
                if (!$url || isset($seen[$url])) continue;
                $seen[$url] = true;
                $best[]     = $article;
                if (count($best) >= 5) break;
            }

            $date  = now()->format('d/m/Y');
            $hour  = now()->format('H:i');
            $count = count($best);

            $output = "*⭐ BEST OF DU JOUR — {$date}*\n";
            $output .= "_Top {$count} · calculé à {$hour}_\n\n";

            foreach ($best as $i => $article) {
                $num    = $i + 1;
                $title  = $article['title'] ?? 'Sans titre';
                $score  = $article['score'] ?? 0;
                $source = $article['source'] ?? '';
                $url    = $article['url'] ?? '';
                $cat    = $article['_cat'] ?? '';
                $icon   = self::CATEGORY_ICONS[$cat] ?? '▪️';

                $heatIcon = match(true) {
                    $score >= 500 => '🔥',
                    $score >= 100 => '⬆️',
                    default       => '▪️',
                };

                $output .= "*{$num}.* {$icon} *{$title}*";
                if ($score >= 100) $output .= " {$heatIcon} ({$score})";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *résume [url]* · *save [url]* · *lire #N* · *digest* pour plus_";

            Cache::put($cacheKey, $output, 1200); // 20 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] BestOf failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du calcul du best of. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXPORT BOOKMARKS (NEW v1.4.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleExportBookmarks(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark à exporter.\n"
                . "_Utilise *save [url]* pour sauvegarder des articles._"
            );
        }

        $total = $articles->count();

        $output = "*📋 EXPORT BOOKMARKS* ({$total})\n";
        $output .= "_" . now()->format('d/m/Y H:i') . "_\n";
        $output .= str_repeat('─', 28) . "\n\n";

        foreach ($articles as $i => $article) {
            $num    = $i + 1;
            $title  = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date   = $article->created_at->format('d/m/Y');

            $output .= "*{$num}.* {$title}";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= $article->url . "\n\n";
        }

        $output .= str_repeat('─', 28) . "\n";
        $output .= "_Total : {$total} bookmark(s) · Copie ce message pour archiver ta liste_";

        $this->log($context, 'Bookmarks exported', ['count' => $total]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIGEST STATS
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDigestStats(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $totalDigests = ContentDigestLog::where('user_phone', $userPhone)->count();

        if ($totalDigests === 0) {
            return AgentResult::reply(
                "*📊 MES STATS CONTENU*\n\n"
                . "Tu n'as pas encore généré de digest.\n"
                . "_Dis *digest* pour commencer !_"
            );
        }

        $lastDigest    = ContentDigestLog::where('user_phone', $userPhone)->orderByDesc('sent_at')->first();
        $totalArticles = (int) ContentDigestLog::where('user_phone', $userPhone)->sum('article_count');
        $savedCount    = SavedArticle::where('user_phone', $userPhone)->count();

        $prefsCount = UserContentPreference::where('user_phone', $userPhone)
            ->where('category', '!=', 'custom')
            ->count();

        $customPref   = UserContentPreference::where('user_phone', $userPhone)->where('category', 'custom')->first();
        $keywordCount = $customPref ? count($customPref->keywords ?? []) : 0;

        // Top categories from digest history (last 20)
        $recentCategories = ContentDigestLog::where('user_phone', $userPhone)
            ->orderByDesc('sent_at')
            ->take(20)
            ->get()
            ->pluck('categories')
            ->flatten()
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(3);

        $streak = $this->computeStreak($userPhone);

        $output = "*📊 MES STATS CONTENU*\n\n";
        $output .= "📰 Digests générés : *{$totalDigests}*\n";
        $output .= "📄 Articles lus : *{$totalArticles}*\n";
        $output .= "🔖 Bookmarks sauvegardés : *{$savedCount}*\n";
        $output .= "🏷️ Catégories suivies : *{$prefsCount}*\n";
        if ($keywordCount > 0) {
            $output .= "🔑 Mots-clés personnalisés : *{$keywordCount}*\n";
        }
        if ($streak > 1) {
            $output .= "🔥 Streak : *{$streak} jours* consécutifs\n";
        }

        if ($lastDigest && $lastDigest->sent_at) {
            $output .= "\n📅 Dernier digest : *" . $lastDigest->sent_at->format('d/m/Y à H:i') . "*\n";
        }

        if ($recentCategories->isNotEmpty()) {
            $output .= "\n🏆 Catégories les plus lues :\n";
            foreach ($recentCategories as $cat => $count) {
                $icon = self::CATEGORY_ICONS[$cat] ?? '▪️';
                $output .= "  {$icon} {$cat} ({$count}x)\n";
            }
        }

        $output .= "\n_💡 *digest* pour un nouveau digest · *article du jour* pour la sélection IA · *profil lecture* pour analyser ta bibliothèque_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PREFERENCES
    // ─────────────────────────────────────────────────────────────────────────

    private function handlePreferences(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $prefs     = UserContentPreference::where('user_phone', $userPhone)->get();

        if ($prefs->isEmpty()) {
            return AgentResult::reply(
                "*⚙️ PRÉFÉRENCES CONTENU*\n\n"
                . "Tu n'as pas encore configuré tes intérêts.\n\n"
                . "*Catégories disponibles :*\n"
                . "💻 technology · 🔬 science · 💼 business · ❤️ health · ⚽ sports\n"
                . "🎬 entertainment · 🎮 gaming · 🤖 ai · 🪙 crypto · 🚀 startup · 🎨 design · 🔒 security\n\n"
                . "*Exemples :*\n"
                . "• *follow tech* — suivre la tech\n"
                . "• *follow ai* — suivre l'IA\n"
                . "• *follow laravel* — mot-clé personnalisé\n"
                . "• *unfollow tech* — arrêter de suivre\n"
            );
        }

        $output = "*⚙️ MES PRÉFÉRENCES CONTENU*\n\n";

        $categories = $prefs->where('category', '!=', 'custom');
        $custom     = $prefs->where('category', 'custom')->first();

        if ($categories->isNotEmpty()) {
            $output .= "*Catégories suivies :*\n";
            foreach ($categories as $p) {
                $icon = self::CATEGORY_ICONS[$p->category] ?? '▪️';
                $output .= "  {$icon} {$p->category}\n";
            }
            $output .= "\n";
        }

        if ($custom && !empty($custom->keywords)) {
            $output .= "*Mots-clés personnalisés :*\n";
            foreach ($custom->keywords as $kw) {
                $output .= "  🔑 {$kw}\n";
            }
            $output .= "\n";
        }

        $savedCount   = SavedArticle::where('user_phone', $userPhone)->count();
        $digestsCount = ContentDigestLog::where('user_phone', $userPhone)->count();
        $output .= "*Activité :* {$savedCount} bookmark(s) · {$digestsCount} digest(s) généré(s)\n\n";

        // Show unsubscribed categories so user knows what to add
        $followedCats   = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $availableCats  = array_diff(array_keys(self::CATEGORY_ICONS), $followedCats);
        if (!empty($availableCats)) {
            $output .= "*Catégories disponibles :*\n";
            $row = '';
            foreach ($availableCats as $cat) {
                $icon = self::CATEGORY_ICONS[$cat] ?? '▪️';
                $row .= "{$icon} {$cat}  ";
            }
            $output .= "_" . trim($row) . "_\n\n";
        }

        $output .= "_💡 *follow [cat]* · *unfollow [cat]* · *profil lecture* · *article du jour* · *bilan semaine*_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENAME BOOKMARK (NEW v1.6.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRenameBookmark(AgentContext $context, int $position, string $newTitle): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $newTitle = trim($newTitle);
        if (mb_strlen($newTitle) < 3) {
            return AgentResult::reply(
                "Le nouveau titre est trop court (minimum 3 caractères).\n"
                . "_Exemple : *renommer #2 Mon article préféré sur Laravel*_"
            );
        }
        if (mb_strlen($newTitle) > 255) {
            $newTitle = mb_strimwidth($newTitle, 0, 252, '...');
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $oldTitle = $article->title ?: 'Sans titre';
        $article->update(['title' => $newTitle]);

        $this->log($context, 'Bookmark renamed', [
            'position'  => $position,
            'old_title' => mb_substr($oldTitle, 0, 60),
            'new_title' => mb_substr($newTitle, 0, 60),
        ]);

        return AgentResult::reply(
            "✏️ *Bookmark n°{$position} renommé*\n\n"
            . "Avant : _{$oldTitle}_\n"
            . "Après : *{$newTitle}*\n\n"
            . "_Dis *mes bookmarks* pour voir ta liste mise à jour._"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BILAN DE LECTURE HEBDOMADAIRE (NEW v1.6.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleWeeklyBilan(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $since     = now()->subDays(7)->startOfDay();

        $weekArticles = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $totalBookmarks = SavedArticle::where('user_phone', $userPhone)->count();
        $weekDigests    = ContentDigestLog::where('user_phone', $userPhone)
            ->where('sent_at', '>=', $since)
            ->count();
        $weekArticleCount = (int) ContentDigestLog::where('user_phone', $userPhone)
            ->where('sent_at', '>=', $since)
            ->sum('article_count');

        $streak = $this->computeStreak($userPhone, 14);

        $startDate = $since->format('d/m');
        $endDate   = now()->format('d/m/Y');

        $output = "*📖 BILAN DE LECTURE*\n";
        $output .= "_Semaine du {$startDate} au {$endDate}_\n\n";

        $output .= "📰 *Digests générés :* {$weekDigests}\n";
        if ($weekArticleCount > 0) {
            $output .= "📄 *Articles reçus :* {$weekArticleCount}\n";
        }
        $output .= "🔖 *Bookmarks sauvés cette semaine :* {$weekArticles->count()}\n";
        $output .= "📚 *Total bookmarks :* {$totalBookmarks}\n";
        if ($streak > 1) {
            $output .= "🔥 *Streak actif :* {$streak} jours\n";
        }
        $output .= "\n";

        if ($weekArticles->isNotEmpty()) {
            $output .= "*Articles sauvés cette semaine :*\n";
            foreach ($weekArticles->take(5) as $i => $article) {
                $title  = $article->title ?: 'Sans titre';
                $source = $article->source ?: '';
                $date   = $article->created_at->format('d/m');
                $output .= "  " . ($i + 1) . ". *" . mb_strimwidth($title, 0, 60, '...') . "*";
                if ($source) $output .= " _{$source}_";
                $output .= " ({$date})\n";
            }
            if ($weekArticles->count() > 5) {
                $output .= "  _... et " . ($weekArticles->count() - 5) . " autre(s)_\n";
            }
            $output .= "\n";
        } elseif ($weekDigests === 0) {
            $output .= "_Tu n'as pas encore utilisé le curator cette semaine._\n";
            $output .= "_Dis *digest* ou *flash* pour démarrer !_\n\n";
        }

        // Motivational tip based on activity
        if ($weekDigests >= 5) {
            $output .= "_🏆 Excellente semaine ! Tu consultes l'actu tous les jours._";
        } elseif ($weekDigests >= 2) {
            $output .= "_👍 Bonne veille cette semaine. Continue sur ta lancée !_";
        } else {
            $output .= "_💡 *digest* pour reprendre ta veille · *recommande* pour des sujets du moment_";
        }

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TLDR EXPRESS (NEW v1.7.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTldr(AgentContext $context, string $url): AgentResult
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return AgentResult::reply(
                "❌ URL invalide.\n"
                . "_Exemple : *tldr https://example.com/article*_"
            );
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
        }

        $cacheKey = "content_curator:tldr:" . md5($url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'TLDR requested', ['url' => mb_substr($url, 0, 100)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok']) {
                return AgentResult::reply(
                    "❌ Page inaccessible (erreur {$content['status']}).\n"
                    . "_Vérifie que l'URL est publique._"
                );
            }

            $title = $content['title'];
            $desc  = $content['desc'];
            $text  = $content['text'];

            // If body too short, fall back to description
            if (mb_strlen($text) < 100 && $desc && mb_strlen($desc) >= 50) {
                $text = $desc;
            } elseif (mb_strlen($text) < 100) {
                return AgentResult::reply(
                    "❌ Contenu insuffisant (page protégée ou JavaScript requis).\n"
                    . "_Essaie *résume {$url}* pour un mode de lecture différent._"
                );
            }

            if ($desc && mb_strlen($desc) > 30) {
                $text = $desc . "\n\n" . $text;
            }

            $excerpt = mb_substr($text, 0, 2500);

            $systemPrompt = <<<PROMPT
Tu es un assistant de résumé ultra-rapide pour mobile. Fournis un TLDR en exactement 3 points.

FORMAT STRICT (texte brut WhatsApp):
⚡ *TLDR*
• [point 1 — fait principal, max 70 caractères]
• [point 2 — détail clé ou chiffre, max 70 caractères]
• [point 3 — impact ou conclusion, max 70 caractères]

RÈGLES STRICTES:
- Exactement 3 points, pas plus, pas moins
- Chaque point commence par un verbe d'action ou un chiffre concret
- Maximum 70 caractères par point (sans le "• ")
- Factuel uniquement — zéro opinion, zéro supposition
- En français même si l'article est en anglais
- Retourne UNIQUEMENT les 4 lignes du format ci-dessus, rien d'autre
PROMPT;

            $userMsg = "Titre: " . ($title ?? 'Inconnu') . "\nURL: {$url}\n\nContenu:\n{$excerpt}";
            $tldr    = $this->claude->chat($userMsg, ModelResolver::fast(), $systemPrompt);

            if (!$tldr) {
                return AgentResult::reply("❌ Impossible de générer le TLDR. Essaie *résume {$url}* pour un résumé complet.");
            }

            $output = $tldr . "\n\n";
            if ($title) $output .= "_" . mb_strimwidth($title, 0, 70, '...') . "_\n";
            $output .= "🔗 {$url}\n";
            $output .= "_💡 *résume {$url}* pour un résumé complet · *save {$url}* pour bookmarker_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] TLDR failed for '{$url}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du TLDR. Réessaie ou utilise *résume {$url}*.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIGEST MULTI-CATÉGORIES (NEW v1.7.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDigestMulti(AgentContext $context, array $rawCats, bool $forceRefresh = false): AgentResult
    {
        $userPhone = $context->from;

        // Validate and normalize each category
        [$validCats, $invalidCats] = $this->validateCategories($rawCats);

        if (empty($validCats)) {
            return $this->invalidCategoryReply(implode(', ', $rawCats), 'digest tech + ai');
        }

        $invalidNote = '';
        if (!empty($invalidCats)) {
            $invalidNote = "_⚠️ Catégorie(s) ignorée(s) : " . implode(', ', $invalidCats) . "_\n\n";
        }

        $keywords = [];
        $prefs    = UserContentPreference::where('user_phone', $userPhone)->get();
        if ($prefs->isNotEmpty()) {
            $keywords = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();
        }

        if ($forceRefresh) {
            foreach ($validCats as $cat) {
                Cache::forget("content_curator:aggregate:{$cat}:" . md5(implode(',', $keywords)));
            }
        }

        $this->log($context, 'Multi-category digest', ['categories' => $validCats, 'refresh' => $forceRefresh]);

        try {
            $articles  = $this->aggregator->aggregate($validCats, $keywords, 12);

            if (empty($articles)) {
                return AgentResult::reply(
                    $invalidNote
                    . "Aucun article trouvé pour *" . implode(' + ', $validCats) . "*.\n"
                    . "_Essaie chaque catégorie séparément ou *flash* pour un aperçu rapide._"
                );
            }

            $summaries = $this->summarizer->summarizeBatch($articles, 8);

            ContentDigestLog::create([
                'user_phone'    => $userPhone,
                'categories'    => $validCats,
                'article_count' => count($summaries),
                'sent_at'       => now(),
            ]);

            $catIcons = implode(' + ', array_map(function ($cat) {
                return (self::CATEGORY_ICONS[$cat] ?? '') . ' ' . ucfirst($cat);
            }, array_slice($validCats, 0, 3)));

            $header = "*📰 DIGEST MULTI — {$catIcons}*\n";
            $header .= "_" . now()->format('d/m/Y H:i') . " · " . count($summaries) . " articles";
            if ($forceRefresh) $header .= " · actualisé";
            $header .= "_\n";
            if ($invalidNote) $header .= $invalidNote;
            $header .= "\n";

            $output = $header;
            foreach ($summaries as $i => $article) {
                $num     = $i + 1;
                $title   = $article['title'] ?? 'Sans titre';
                $summary = $article['summary'] ?? '';
                $source  = $article['source'] ?? '';
                $url     = $article['url'] ?? '';

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n{$summary}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "---\n";
            $output .= "_💡 *save [url]* · *tldr [url]* · *résume [url]* · *recommande* · *flash*_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] DigestMulti failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du digest multi-catégories. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CITATION EXTRACTION (NEW v1.9.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCite(AgentContext $context, string $url): AgentResult
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return AgentResult::reply(
                "❌ URL invalide.\n"
                . "_Exemple : *cite https://example.com/article*_"
            );
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
        }

        $cacheKey = "content_curator:cite:" . md5($url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Citation requested', ['url' => mb_substr($url, 0, 100)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok']) {
                return AgentResult::reply(
                    "❌ Page inaccessible (erreur {$content['status']}).\n"
                    . "_Vérifie que l'URL est accessible publiquement._"
                );
            }

            $text  = $content['text'];
            $title = $content['title'];
            $desc  = $content['desc'];

            if (mb_strlen($text) < 100) {
                if ($desc && mb_strlen($desc) >= 50) {
                    $text = $desc;
                } else {
                    return AgentResult::reply(
                        "❌ Contenu insuffisant pour extraire une citation (page protégée ou JavaScript requis).\n"
                        . "_Essaie *résume {$url}* pour un résumé complet._"
                    );
                }
            }

            if ($desc && mb_strlen($desc) > 30) {
                $text = $desc . "\n\n" . $text;
            }

            $excerpt = mb_substr($text, 0, 3000);

            $systemPrompt = <<<PROMPT
Tu es un expert en extraction de citations percutantes. Identifie la citation la plus marquante, factuelle et mémorable de cet article.

FORMAT DE RÉPONSE (texte brut WhatsApp) :
💬 *"[citation exacte ou reformulation fidèle du passage clé, max 150 caractères]"*
[contexte : 1 phrase expliquant qui a dit ça ou dans quel contexte — uniquement si disponible dans le texte]

RÈGLES STRICTES :
- Préfère une citation directe (entre guillemets) si disponible, sinon reformule fidèlement le passage le plus fort
- Citation : maximum 150 caractères, commence par un fait, un chiffre ou une affirmation forte
- Contexte : uniquement si clairement mentionné dans le texte (auteur, expert, entreprise, étude...)
- En français même si l'article est en anglais
- Factuel uniquement — zéro invention, zéro extrapolation
- Retourne UNIQUEMENT le format ci-dessus (2 lignes max), rien d'autre
PROMPT;

            $userMsg  = "Titre: " . ($title ?? 'Inconnu') . "\nURL: {$url}\n\nContenu:\n{$excerpt}";
            $citation = $this->claude->chat($userMsg, ModelResolver::fast(), $systemPrompt);

            if (!$citation) {
                return AgentResult::reply("❌ Impossible d'extraire une citation. Essaie *résume {$url}* pour un résumé complet.");
            }

            $output = "*📌 CITATION*\n";
            if ($title) $output .= "_" . mb_strimwidth($title, 0, 70, '...') . "_\n";
            $output .= "🔗 {$url}\n\n";
            $output .= $citation . "\n\n";
            $output .= "_💡 *résume {$url}* pour résumé complet · *tldr {$url}* pour les 3 points · *save {$url}* pour bookmarker_";

            Cache::put($cacheKey, $output, 3600); // 1 heure

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Cite failed for '{$url}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'extraction de la citation. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOP SOURCES (NEW v1.9.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTopSources(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "*📡 MES SOURCES*\n\n"
                . "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour commencer à construire ta bibliothèque._"
            );
        }

        $this->log($context, 'Top sources requested', ['total' => $total]);

        $sources = SavedArticle::where('user_phone', $userPhone)
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->selectRaw('source, COUNT(*) as cnt')
            ->groupBy('source')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        if ($sources->isEmpty()) {
            return AgentResult::reply(
                "*📡 MES SOURCES*\n\n"
                . "Aucune source identifiée dans tes {$total} bookmark(s).\n"
                . "_Les sources sont détectées automatiquement lors d'un *save [url]*._"
            );
        }

        $maxCount = (int) ($sources->first()->cnt ?? 1);

        $output = "*📡 MES SOURCES — Top " . $sources->count() . "*\n";
        $output .= "_Basé sur {$total} bookmark(s)_\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($sources as $i => $row) {
            $medal  = $medals[$i] ?? '▪️';
            $source = $row->source;
            $count  = (int) $row->cnt;
            $ratio  = $maxCount > 0 ? (int) round($count / $maxCount * 5) : 0;
            $bar    = str_repeat('█', max(1, $ratio));
            $pct    = $total > 0 ? (int) round($count / $total * 100) : 0;

            $output .= "{$medal} *{$source}* — {$count} article(s) ({$pct}%) {$bar}\n";
        }

        $output .= "\n_💡 *cherche bookmarks [source]* pour filtrer · *mes bookmarks* pour voir ta liste_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ARTICLE ALÉATOIRE (NEW v1.10.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRandom(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "🎲 *ARTICLE ALÉATOIRE*\n\n"
                . "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour commencer ta bibliothèque, puis *surprends moi* pour redécouvrir un article au hasard._"
            );
        }

        // Pick a random offset to avoid always returning recent articles
        $offset  = random_int(0, max(0, $total - 1));
        $article = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->skip($offset)
            ->first();

        if (!$article) {
            return AgentResult::reply("❌ Impossible de récupérer un article. Réessaie.");
        }

        $this->log($context, 'Random bookmark requested', [
            'total'    => $total,
            'position' => $offset + 1,
            'url'      => $article->url,
        ]);

        $title  = $article->title ?: 'Article sans titre';
        $source = $article->source ?: '';
        $saved  = $article->created_at->format('d/m/Y');

        $intros = [
            "Voici un article de ta bibliothèque — tu te souviens de celui-là ?",
            "Redécouvrons ensemble cet article sauvegardé.",
            "Ta bibliothèque cache des trésors — en voilà un !",
            "Article n°" . ($offset + 1) . " sur {$total} dans ta collection.",
            "Un classique de tes lectures passées te revient...",
        ];
        $intro = $intros[array_rand($intros)];

        $header  = "*🎲 ARTICLE ALÉATOIRE*\n";
        $header .= "_{$intro}_\n\n";
        $header .= "*{$title}*";
        if ($source) $header .= " _{$source}_";
        $header .= "\n_Sauvegardé le {$saved}_\n";
        $header .= "🔗 {$article->url}\n\n";

        // Try to summarize it — if it fails, just show the bookmark info
        try {
            $content = $this->extractHtmlContent($article->url);

            if ($content['ok'] && mb_strlen($content['text']) >= 100) {
                $text    = ($content['desc'] && mb_strlen($content['desc']) > 50 ? $content['desc'] . "\n\n" : '') . $content['text'];
                $excerpt = mb_substr($text, 0, 2500);

                $systemPrompt = <<<PROMPT
Tu es un assistant de lecture intelligente spécialisé dans les résumés pour mobile (WhatsApp). Fournis un résumé structuré, informatif et directement utilisable.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp):
📌 *Sujet principal* : [1 phrase directe — sujet + fait central]

🔑 *Points clés* :
• [fait concret 1 — commence par un verbe d'action ou un chiffre si disponible]
• [fait concret 2]
• [fait concret 3]

💡 *À retenir* : [1 phrase — conclusion ou impact pratique pour le lecteur]

RÈGLES STRICTES:
- Factuel à 100% : n'invente AUCUNE information absente du texte source
- Style journalistique direct : chiffres, noms propres, faits vérifiables
- Maximum 3 points clés, 1 ligne chacun
- Réponds en français même si l'article est en anglais
- N'utilise pas de guillemets ni de markdown lourd
PROMPT;

                $userMsg = "Titre: {$title}\nURL: {$article->url}\n\nContenu:\n{$excerpt}";
                $summary = $this->claude->chat($userMsg, ModelResolver::fast(), $systemPrompt);

                if ($summary) {
                    $output  = $header . $summary . "\n\n";
                    $output .= "_💡 *save {$article->url}* déjà bookmarké · *surprends moi* pour un autre · *supprimer " . ($offset + 1) . "* pour retirer_";
                    return AgentResult::reply($output);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[content_curator] Random summary failed (non-blocking): " . $e->getMessage());
        }

        // Fallback: show bookmark without summary
        $output  = $header;
        $output .= "_💡 *résume {$article->url}* pour lire · *surprends moi* pour un autre article_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIGEST EXPRESS (NEW v1.10.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDigestExpress(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        if (empty($categories)) {
            $categories = ['technology', 'ai', 'science'];
        }

        // Cache 10 min per user + categories fingerprint
        $cacheKey = "content_curator:digest_express:{$userPhone}:" . md5(implode(',', $categories));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Digest express requested', ['categories' => $categories]);

        try {
            $articles = $this->aggregator->aggregate($categories, $keywords, 8);

            if (empty($articles)) {
                return AgentResult::reply(
                    "⚡ Aucun article disponible pour le moment.\n"
                    . "_Essaie *flash* ou *digest* pour d'autres sources._"
                );
            }

            // Take top 5 only and summarize with ultra-compact prompt
            $articles = array_slice($articles, 0, 5);

            $articlesText = '';
            foreach ($articles as $i => $article) {
                $num         = $i + 1;
                $title       = $article['title'] ?? 'Sans titre';
                $description = $article['description'] ?? '';
                $source      = $article['source'] ?? '';
                $url         = $article['url'] ?? '';

                $articlesText .= "ARTICLE {$num}:\n";
                $articlesText .= "Titre: {$title}\n";
                if ($source)      $articlesText .= "Source: {$source}\n";
                if ($description) $articlesText .= "Description: {$description}\n";
                if ($url)         $articlesText .= "URL: {$url}\n";
                $articlesText .= "\n";
            }

            $systemPrompt = <<<PROMPT
Tu es un assistant de veille ultra-rapide. Pour chaque article, génère un résumé en UNE phrase percutante de maximum 90 caractères.

FORMAT DE RÉPONSE (JSON array):
[
  {"index": 1, "summary": "Fait principal en 1 phrase, style télégraphique, max 90 caractères."},
  {"index": 2, "summary": "..."}
]

RÈGLES STRICTES:
- Style télégraphique : commence par un verbe ou un chiffre (ex: "Meta licencie 5000 ingénieurs.")
- Maximum 90 caractères par résumé, pas de point de suspension
- Factuel uniquement, aucune opinion ni supposition
- En français même si l'article est en anglais
- Retourne UNIQUEMENT le JSON valide, rien d'autre
PROMPT;

            $response = $this->claude->chat($articlesText, ModelResolver::fast(), $systemPrompt);

            $summaries = [];
            $decoded   = $this->parseJsonResponse($response);
            if (is_array($decoded)) {
                foreach ($decoded as $s) {
                    if (isset($s['index'], $s['summary'])) {
                        $summaries[$s['index']] = $s['summary'];
                    }
                }
            }

            $catLabel = implode(' + ', array_map('ucfirst', array_slice($categories, 0, 3)));
            $output   = "*⚡ DIGEST EXPRESS — {$catLabel}*\n";
            $output  .= "_" . now()->format('H:i') . " · 5 articles · lecture 1 min_\n\n";

            foreach ($articles as $i => $article) {
                $num     = $i + 1;
                $title   = mb_strimwidth($article['title'] ?? 'Sans titre', 0, 70, '...');
                $summary = $summaries[$num] ?? mb_strimwidth($article['description'] ?? '', 0, 90, '...');
                $source  = $article['source'] ?? '';

                $output .= "*{$num}.* {$title}";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($summary) $output .= "  ↳ {$summary}\n";
                $output .= "\n";
            }

            $output .= "_💡 *résume [url]* · *tldr [url]* · *save [url]* · *digest* pour plus_";

            Cache::put($cacheKey, $output, 600); // 10 min

            // Log digest
            ContentDigestLog::create([
                'user_phone'    => $userPhone,
                'categories'    => $categories,
                'article_count' => count($articles),
                'sent_at'       => now(),
            ]);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] DigestExpress failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du digest express. Réessaie avec *flash* ou *digest*.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // COMPARE ARTICLES (NEW v1.8.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCompare(AgentContext $context, string $url1, string $url2): AgentResult
    {
        if (!filter_var($url1, FILTER_VALIDATE_URL) || !filter_var($url2, FILTER_VALIDATE_URL)) {
            return AgentResult::reply(
                "❌ URLs invalides. Envoie deux URLs complètes.\n"
                . "_Exemple : *compare https://site1.com/article https://site2.com/article*_"
            );
        }

        foreach ([$url1, $url2] as $checkUrl) {
            if ($this->isPrivateUrl($checkUrl)) {
                return AgentResult::reply("❌ URL privée non autorisée. Utilise des URLs publiques.");
            }
        }

        if ($url1 === $url2) {
            return AgentResult::reply("❌ Les deux URLs sont identiques. Envoie deux articles différents.");
        }

        // Order-independent cache key
        $cacheKey = "content_curator:compare:" . md5(min($url1, $url2) . ':' . max($url1, $url2));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Comparing articles', [
            'url1' => mb_substr($url1, 0, 80),
            'url2' => mb_substr($url2, 0, 80),
        ]);

        try {
            $c1 = $this->extractHtmlContent($url1);
            $c2 = $this->extractHtmlContent($url2);

            $host1 = preg_replace('/^www\./i', '', parse_url($url1, PHP_URL_HOST) ?: $url1);
            $host2 = preg_replace('/^www\./i', '', parse_url($url2, PHP_URL_HOST) ?: $url2);

            if (!$c1['ok']) {
                return AgentResult::reply(
                    "❌ Impossible d'accéder à l'article 1 ({$host1}, erreur {$c1['status']}).\n"
                    . "_Vérifie que l'URL est accessible publiquement._"
                );
            }
            if (!$c2['ok']) {
                return AgentResult::reply(
                    "❌ Impossible d'accéder à l'article 2 ({$host2}, erreur {$c2['status']}).\n"
                    . "_Vérifie que l'URL est accessible publiquement._"
                );
            }

            $text1 = ($c1['desc'] && mb_strlen($c1['desc']) > 30 ? $c1['desc'] . "\n\n" : '') . $c1['text'];
            $text2 = ($c2['desc'] && mb_strlen($c2['desc']) > 30 ? $c2['desc'] . "\n\n" : '') . $c2['text'];

            if (mb_strlen($text1) < 80) {
                return AgentResult::reply(
                    "❌ Contenu insuffisant pour l'article 1 ({$host1}).\n"
                    . "_Page protégée ou JavaScript requis._"
                );
            }
            if (mb_strlen($text2) < 80) {
                return AgentResult::reply(
                    "❌ Contenu insuffisant pour l'article 2 ({$host2}).\n"
                    . "_Page protégée ou JavaScript requis._"
                );
            }

            $title1   = $c1['title'] ? mb_strimwidth($c1['title'], 0, 70, '...') : $host1;
            $title2   = $c2['title'] ? mb_strimwidth($c2['title'], 0, 70, '...') : $host2;
            $excerpt1 = mb_substr($text1, 0, 1800);
            $excerpt2 = mb_substr($text2, 0, 1800);

            $systemPrompt = <<<PROMPT
Tu es un analyste de presse expert. Compare deux articles sur le même sujet ou des sujets liés.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp):
📝 *Points communs :*
• [point commun 1 — fait partagé par les deux articles]
• [point commun 2 si pertinent]

⚡ *Différences clés :*
• *Article 1 :* [angle, chiffre ou argument distinctif]
• *Article 2 :* [angle, chiffre ou argument distinctif]

🏆 *Verdict :* [quelle source apporte le plus de valeur, et pourquoi — 1 phrase factuelle]

RÈGLES STRICTES:
- Factuel uniquement : ne cite que des éléments présents dans les textes fournis
- Maximum 5 points au total (communs + différences)
- En français, même si les articles sont en anglais
- Si les articles traitent de sujets trop différents, le signaler clairement dès le début
- Retourne uniquement le texte formaté, aucun autre contenu
PROMPT;

            $userMsg    = "ARTICLE 1 — {$title1}\nURL: {$url1}\n{$excerpt1}\n\n───\n\nARTICLE 2 — {$title2}\nURL: {$url2}\n{$excerpt2}";
            $comparison = $this->claude->chat($userMsg, ModelResolver::fast(), $systemPrompt);

            if (!$comparison) {
                return AgentResult::reply("❌ Impossible de générer la comparaison. Réessaie dans quelques instants.");
            }

            $output  = "*🔄 COMPARAISON D'ARTICLES*\n\n";
            $output .= "1️⃣ *{$title1}*\n🔗 {$url1}\n\n";
            $output .= "2️⃣ *{$title2}*\n🔗 {$url2}\n\n";
            $output .= $comparison . "\n\n";
            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour lire en détail · *tldr [url]* pour un résumé rapide_";

            Cache::put($cacheKey, $output, 1800); // 30 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Compare failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la comparaison. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse a JSON response from Claude, handling markdown code blocks and stray text.
     * Returns decoded array/object or null on failure.
     */
    private function parseJsonResponse(?string $response): mixed
    {
        if (!$response) return null;

        $clean = trim($response);

        // Strip markdown code fences
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Extract JSON array
        if (str_starts_with($clean, '[') || str_starts_with($clean, '{')) {
            // already looks like JSON
        } elseif (preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve a user-provided category name to a canonical category.
     * Returns the normalized category or null if invalid.
     */
    private function resolveCategory(string $input): ?string
    {
        $clean      = mb_strtolower(trim($input));
        $normalized = self::CATEGORY_ALIASES[$clean] ?? $clean;
        return in_array($normalized, self::VALID_CATEGORIES) ? $normalized : null;
    }

    /**
     * Validate and normalize an array of category names.
     * Returns [validCats[], invalidCats[]].
     */
    private function validateCategories(array $rawCats): array
    {
        $validCats   = [];
        $invalidCats = [];
        foreach ($rawCats as $cat) {
            $normalized = $this->resolveCategory(trim($cat));
            if ($normalized) {
                $validCats[] = $normalized;
            } else {
                $invalidCats[] = trim($cat);
            }
        }
        return [array_values(array_unique($validCats)), $invalidCats];
    }

    /**
     * Format a category-invalid error message with available categories.
     */
    private function invalidCategoryReply(string $input, string $context = ''): AgentResult
    {
        $list = implode(', ', self::VALID_CATEGORIES);
        $example = $context ?: "digest tech";
        return AgentResult::reply(
            "❌ Catégorie *{$input}* inconnue.\n\n"
            . "_Catégories disponibles : {$list}_\n\n"
            . "_Exemple : *{$example}*_"
        );
    }

    /**
     * Check if a URL points to a private/local network (SSRF protection).
     */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return true;

        $host = mb_strtolower($host);

        if ($host === 'localhost' || $host === '[::1]') return true;

        // IPv4 private ranges
        if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|0\.|169\.254\.)/', $host)) {
            return true;
        }

        // Resolve hostname to check for DNS rebinding
        $ip = @gethostbyname($host);
        if ($ip && $ip !== $host) {
            if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|0\.|169\.254\.)/', $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared HTML content extractor used by summarize, TLDR and compare.
     * Returns ['ok', 'status', 'title', 'desc', 'text'].
     */
    private function extractHtmlContent(string $url): array
    {
        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Accept-Encoding' => 'identity',
                ])
                ->get($url);

            if (!$response->successful()) {
                return ['ok' => false, 'status' => $response->status(), 'title' => null, 'desc' => null, 'text' => ''];
            }

            $html = $response->body();

            // Title: og:title > twitter:title > <title>
            $title = null;
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+content=["\'](.*?)["\']\s+property=["\']og:title["\']/si', $html, $m)) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+name=["\']twitter:title["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
                $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
            }
            if ($title) {
                $title = mb_strimwidth(preg_replace('/\s+/', ' ', $title), 0, 255, '...');
            }

            // Description: og:description > twitter:description > meta description
            $desc = null;
            if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $desc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+content=["\'](.*?)["\']\s+property=["\']og:description["\']/si', $html, $m)) {
                $desc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+name=["\']twitter:description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $desc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
                $desc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // Body: <article> > <main> > div[class*=content/post/entry] > <body> (v1.10.0: extended fallback chain)
            $text = '';
            if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $html, $m)) {
                $text = $m[1];
            } elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $html, $m)) {
                $text = $m[1];
            } elseif (preg_match('/<div[^>]+class=["\'][^"\']*\b(post|entry|content|article-body|story|text)\b[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $m)) {
                $text = $m[2];
            } else {
                $stripped = preg_replace('/<(script|style|nav|header|footer|aside|noscript|form|iframe)[^>]*>.*?<\/\1>/si', '', $html);
                if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $stripped, $m)) {
                    $text = $m[1];
                }
            }
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));

            return ['ok' => true, 'status' => 200, 'title' => $title, 'desc' => $desc, 'text' => $text];

        } catch (\Throwable $e) {
            Log::error("[content_curator] extractHtmlContent failed for '{$url}': " . $e->getMessage());
            return ['ok' => false, 'status' => 0, 'title' => null, 'desc' => null, 'text' => ''];
        }
    }

    /**
     * Compute the current consecutive-day streak for digest usage.
     * Returns 0 if no digests found, 1 if only today, N for N consecutive days.
     */
    private function computeStreak(string $userPhone, int $maxDays = 30): int
    {
        $digestDays = ContentDigestLog::where('user_phone', $userPhone)
            ->orderByDesc('sent_at')
            ->take($maxDays)
            ->pluck('sent_at')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->unique()
            ->values();

        $streak = 0;
        foreach ($digestDays as $i => $day) {
            if ($day === now()->subDays($i)->format('Y-m-d')) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    private function fetchPageTitle(string $url): ?string
    {
        $content = $this->extractHtmlContent($url);
        return ($content['ok'] && $content['title']) ? $content['title'] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGINATION BOOKMARKS (NEW v1.11.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleListSavedPage(AgentContext $context, int $page): AgentResult
    {
        $userPhone = $context->from;
        $perPage   = 15;
        $page      = max(1, $page);
        $offset    = ($page - 1) * $perPage;

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "Tu n'as aucun article sauvegardé.\n"
                . "_Utilise *save [url]* pour bookmarker un article._"
            );
        }

        $totalPages = (int) ceil($total / $perPage);

        if ($page > $totalPages) {
            return AgentResult::reply(
                "❌ Page {$page} inexistante. Tu as *{$totalPages} page(s)* de bookmarks ({$total} au total).\n"
                . "_Dis *mes bookmarks page 1* pour commencer._"
            );
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->skip($offset)
            ->take($perPage)
            ->get();

        $this->log($context, 'Listing bookmarks page', ['page' => $page, 'total_pages' => $totalPages]);

        $output = "*🔖 MES BOOKMARKS — Page {$page}/{$totalPages}* ({$total} au total)\n\n";

        foreach ($articles as $i => $article) {
            $num    = $offset + $i + 1;
            $title  = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date   = $article->created_at->format('d/m');

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= "🔗 {$article->url}\n\n";
        }

        $navParts = [];
        if ($page > 1) {
            $navParts[] = "*mes bookmarks page " . ($page - 1) . "* ← précédent";
        }
        if ($page < $totalPages) {
            $navParts[] = "*mes bookmarks page " . ($page + 1) . "* → suivant";
        }

        $output .= "_💡 ";
        if (!empty($navParts)) {
            $output .= implode(' · ', $navParts) . " · ";
        }
        $output .= "*supprimer [n°]* pour effacer · *résume [url]* pour lire_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIGEST THÉMATIQUE LIBRE (NEW v1.11.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDigestTopic(AgentContext $context, string $topic): AgentResult
    {
        $topic = trim($topic);

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply(
                "Précise un sujet. Exemple : *digest sur React 19*, *news sur le Bitcoin*, *articles sur l'IA en 2026*"
            );
        }

        if (mb_strlen($topic) > 120) {
            $topic = mb_strimwidth($topic, 0, 120, '');
        }

        $this->log($context, 'Digest topic requested', ['topic' => mb_substr($topic, 0, 60)]);

        // 15 min cache per user + topic (lower-cased for consistency)
        $cacheKey = "content_curator:digest_topic:{$context->from}:" . md5(mb_strtolower($topic));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        try {
            // Use user's categories + ALL categories as broad net, topic as keyword
            $prefs      = UserContentPreference::where('user_phone', $context->from)->get();
            $userCats   = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            $categories = !empty($userCats)
                ? array_values(array_unique(array_merge($userCats, self::VALID_CATEGORIES)))
                : self::VALID_CATEGORIES;

            $articles = $this->aggregator->aggregate($categories, [$topic], 12);

            if (empty($articles)) {
                return AgentResult::reply(
                    "🔍 Aucun article trouvé sur *{$topic}*.\n\n"
                    . "_Essaie *cherche " . mb_strtolower($topic) . "* pour une recherche élargie, ou *trending tech* pour les tendances._"
                );
            }

            // Score by relevance to the topic
            $topicLower = mb_strtolower($topic);
            $topicWords = array_values(array_filter(
                preg_split('/\s+/', $topicLower),
                fn($w) => mb_strlen($w) >= 2
            ));

            $scored = array_map(function ($a) use ($topicLower, $topicWords) {
                $titleLower = mb_strtolower($a['title'] ?? '');
                $descLower  = mb_strtolower($a['description'] ?? '');
                $score      = 0;
                // Full phrase match = heavy bonus
                if (str_contains($titleLower, $topicLower)) $score += 5;
                if (str_contains($descLower, $topicLower))  $score += 3;
                foreach ($topicWords as $word) {
                    if (str_contains($titleLower, $word)) $score += 2;
                    if (str_contains($descLower, $word))  $score += 1;
                }
                $a['_relevance'] = $score;
                return $a;
            }, $articles);

            // Sort by relevance, keep top relevant, fallback to all
            usort($scored, fn($a, $b) => ($b['_relevance'] ?? 0) <=> ($a['_relevance'] ?? 0));
            $relevant = array_values(array_filter($scored, fn($a) => ($a['_relevance'] ?? 0) > 0));
            if (empty($relevant)) {
                $relevant = array_values($scored);
            }
            $relevant = array_slice($relevant, 0, 8);

            // Summarize with full digest quality
            $summaries = $this->summarizer->summarizeBatch($relevant, 6);

            if (empty($summaries)) {
                return AgentResult::reply(
                    "🔍 Résumés indisponibles pour *{$topic}* pour le moment.\n"
                    . "_Essaie *cherche " . mb_strtolower($topic) . "* pour voir les titres._"
                );
            }

            $topicDisplay = ucwords(mb_strtolower($topic));
            $output  = "*🔎 DIGEST — {$topicDisplay}*\n";
            $output .= "_" . now()->format('d/m/Y H:i') . " · " . count($summaries) . " article(s)_\n\n";

            foreach ($summaries as $i => $article) {
                $num     = $i + 1;
                $title   = $article['title'] ?? 'Sans titre';
                $summary = $article['summary'] ?? '';
                $source  = $article['source'] ?? '';
                $url     = $article['url'] ?? '';

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n{$summary}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "---\n";
            $output .= "_💡 *save [url]* · *résume [url]* · *cherche " . mb_strtolower($topic) . "* pour plus · *follow [cat]* pour personnaliser_";

            Cache::put($cacheKey, $output, 900); // 15 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] DigestTopic failed for topic='{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du digest sur *{$topic}*. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARKS PAR PÉRIODE (NEW v1.12.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleListSavedByPeriod(AgentContext $context, string $period): AgentResult
    {
        $userPhone = $context->from;

        [$since, $periodLabel] = match (true) {
            (bool) preg_match("/aujourd.?hui|ce\s+jour/iu", $period)  => [now()->startOfDay(), "aujourd'hui"],
            (bool) preg_match('/cette?\s+semaine/iu', $period)        => [now()->subDays(7)->startOfDay(), 'cette semaine'],
            (bool) preg_match('/ce\s+mois/iu', $period)               => [now()->startOfMonth(), 'ce mois'],
            default                                                    => [now()->subDays(7)->startOfDay(), 'cette semaine'],
        };

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            $total = SavedArticle::where('user_phone', $userPhone)->count();
            return AgentResult::reply(
                "🔖 Aucun bookmark sauvegardé *{$periodLabel}*.\n\n"
                . ($total > 0
                    ? "_Tu as {$total} bookmark(s) au total. Dis *mes bookmarks* pour tout voir._"
                    : "_Utilise *save [url]* pour commencer ta bibliothèque._"
                )
            );
        }

        $count  = $articles->count();
        $output = "*🔖 BOOKMARKS — {$periodLabel}* ({$count})\n\n";

        foreach ($articles as $i => $article) {
            $num    = $i + 1;
            $title  = $article->title ?: 'Sans titre';
            $source = $article->source ?: '';
            $date   = $article->created_at->format('d/m H:i');

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= " ({$date})\n";
            $output .= "🔗 {$article->url}\n\n";
        }

        $output .= "_💡 *résume [url]* · *save [url]* · *lire #N* · *mes bookmarks* pour tout voir_";

        $this->log($context, 'Bookmarks by period', ['period' => $periodLabel, 'count' => $count]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRENDING MULTI-CATÉGORIES (NEW v1.12.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTrendingMulti(AgentContext $context, array $rawCats): AgentResult
    {
        [$validCats, $invalidCats] = $this->validateCategories($rawCats);

        if (empty($validCats)) {
            return $this->invalidCategoryReply(implode(', ', $rawCats), 'trending tech + ai');
        }

        // Fallback to single trending if only one valid cat remains
        if (count($validCats) === 1) {
            return $this->handleTrending($context, $validCats[0]);
        }

        $invalidNote = '';
        if (!empty($invalidCats)) {
            $invalidNote = "_⚠️ Catégorie(s) ignorée(s) : " . implode(', ', $invalidCats) . "_\n\n";
        }

        $this->log($context, 'Multi-trending requested', ['categories' => $validCats]);

        try {
            $allArticles = [];
            $seen        = [];

            foreach ($validCats as $cat) {
                $trending = $this->aggregator->getTrending($cat, 6);
                foreach ($trending as $article) {
                    $url = $article['url'] ?? '';
                    if ($url && isset($seen[$url])) continue;
                    if ($url) $seen[$url] = true;
                    $article['_cat'] = $cat;
                    $allArticles[]   = $article;
                }
            }

            if (empty($allArticles)) {
                return AgentResult::reply(
                    "Aucun contenu trending trouvé pour *" . implode(' + ', $validCats) . "*.\n"
                    . "_Essaie chaque catégorie séparément avec *trending [cat]*._"
                );
            }

            // Rank by score, keep top 8 deduplicated
            usort($allArticles, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $top = array_slice($allArticles, 0, 8);

            $catIcons = implode(' + ', array_map(
                fn($c) => (self::CATEGORY_ICONS[$c] ?? '') . ' ' . ucfirst($c),
                array_slice($validCats, 0, 3)
            ));

            $output  = "*🔥 TRENDING MULTI — {$catIcons}*\n";
            $output .= "_" . now()->format('d/m/Y H:i') . " · Top " . count($top) . " inter-catégories_\n";
            if ($invalidNote) $output .= $invalidNote;
            $output .= "\n";

            foreach ($top as $i => $article) {
                $num      = $i + 1;
                $title    = $article['title'] ?? 'Sans titre';
                $score    = $article['score'] ?? 0;
                $source   = $article['source'] ?? '';
                $url      = $article['url'] ?? '';
                $cat      = $article['_cat'] ?? '';
                $catIcon  = self::CATEGORY_ICONS[$cat] ?? '▪️';

                $heatIcon = match(true) {
                    $score >= 500 => '🔥',
                    $score >= 100 => '⬆️',
                    default       => '▪️',
                };

                $output .= "*{$num}.* {$catIcon} *{$title}*";
                if ($score >= 50) $output .= " {$heatIcon} ({$score})";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* · *résume [url]* · *tldr [url]* · *flash* pour un résumé rapide_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] TrendingMulti failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du trending multi. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ANALYSE IA DE LA BIBLIOTHÈQUE (NEW v1.13.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleAnalyseLibrary(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "*🧠 PROFIL DE LECTURE*\n\n"
                . "Tu n'as aucun bookmark sauvegardé.\n\n"
                . "_Pour construire ton profil de lecture :_\n"
                . "• *save [url]* — sauvegarde des articles\n"
                . "• *digest* — reçois ton digest et save les articles qui t'intéressent\n\n"
                . "_L'analyse IA est disponible dès 3 bookmarks._"
            );
        }

        if ($total < 3) {
            return AgentResult::reply(
                "*🧠 PROFIL DE LECTURE*\n\n"
                . "Tu n'as que *{$total} bookmark(s)* — sauvegarde au moins 3 articles pour obtenir une analyse pertinente.\n\n"
                . "_Utilise *save [url]* ou explore avec *digest* pour trouver des articles._"
            );
        }

        // Cache 30 min — analyze is expensive
        $cacheKey = "content_curator:analyse_library:{$userPhone}:" . md5((string) $total);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Library analysis requested', ['total' => $total]);

        try {
            // Gather all bookmarks: title, source, date
            $articles = SavedArticle::where('user_phone', $userPhone)
                ->orderByDesc('created_at')
                ->limit(50) // cap at 50 to stay within token budget
                ->get();

            // Build library inventory for Claude
            $libraryText = "Bibliothèque de {$total} bookmark(s) (50 max analysés) :\n\n";
            foreach ($articles as $i => $article) {
                $title  = $article->title ?: 'Sans titre';
                $source = $article->source ?: 'source inconnue';
                $date   = $article->created_at->format('d/m/Y');
                $libraryText .= ($i + 1) . ". [{$source}] {$title} ({$date})\n";
            }

            // Also include followed categories and keywords
            $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
            $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            $customPref = $prefs->where('category', 'custom')->first();
            $keywords   = $customPref ? ($customPref->keywords ?? []) : [];

            if (!empty($categories)) {
                $libraryText .= "\nCatégories suivies : " . implode(', ', $categories);
            }
            if (!empty($keywords)) {
                $libraryText .= "\nMots-clés personnalisés : " . implode(', ', array_slice($keywords, 0, 10));
            }

            $currentDate  = now()->translatedFormat('d F Y');
            $systemPrompt = <<<PROMPT
Tu es un expert en intelligence de lecture et curation de contenu. Analyse la bibliothèque de bookmarks d'un utilisateur et génère un rapport de profil de lecture personnalisé (date d'analyse : {$currentDate}).

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

🎯 *Thèmes dominants :*
• [thème 1] — [brève justification avec exemples de sources/titres]
• [thème 2] — [brève justification]
• [thème 3 si pertinent]

📈 *Tendances de lecture :*
[2-3 phrases sur l'évolution des lectures : sources préférées, rythme de sauvegarde, diversité des sujets]

🔍 *Angles peu explorés :*
• [domaine sous-représenté 1 qui pourrait enrichir le profil]
• [domaine sous-représenté 2]

💡 *Recommandation personnalisée :*
[1 conseil actionnable précis pour enrichir les lectures — ex: "Explore davantage X car tu lis beaucoup Y mais peu Z"]

RÈGLES STRICTES :
- Base-toi UNIQUEMENT sur les données fournies — zéro invention
- Si des tendances chronologiques se dégagent (ex: lectures récentes vs anciennes), mentionne-les
- Mentionne des titres ou sources spécifiques pour justifier les thèmes
- Sois direct et factuel, style analytique concis
- En français, max 250 mots au total
- Retourne UNIQUEMENT le texte formaté, rien d'autre
PROMPT;

            $analysis = $this->claude->chat($libraryText, ModelResolver::fast(), $systemPrompt);

            if (!$analysis) {
                return AgentResult::reply(
                    "❌ Impossible de générer l'analyse pour le moment.\n"
                    . "_Réessaie dans quelques instants._"
                );
            }

            // Compute quick stats
            $topSource = SavedArticle::where('user_phone', $userPhone)
                ->whereNotNull('source')
                ->where('source', '!=', '')
                ->selectRaw('source, COUNT(*) as cnt')
                ->groupBy('source')
                ->orderByDesc('cnt')
                ->first();

            $oldestDate = SavedArticle::where('user_phone', $userPhone)->orderBy('created_at')->value('created_at');
            $newestDate = SavedArticle::where('user_phone', $userPhone)->orderByDesc('created_at')->value('created_at');

            $output  = "*🧠 PROFIL DE LECTURE*\n";
            $output .= "_Analyse de {$total} bookmark(s)";
            if ($oldestDate && $newestDate) {
                $output .= " · du " . \Carbon\Carbon::parse($oldestDate)->format('d/m/Y') . " au " . \Carbon\Carbon::parse($newestDate)->format('d/m/Y');
            }
            $output .= "_\n\n";

            if ($topSource) {
                $output .= "📡 *Source #1 :* {$topSource->source} ({$topSource->cnt} article(s))\n";
            }
            if (!empty($categories)) {
                $output .= "🏷️ *Catégories suivies :* " . implode(', ', $categories) . "\n";
            }
            $output .= "\n";

            $output .= $analysis . "\n\n";
            $output .= "---\n";
            $output .= "_💡 *recommande* pour des sujets IA · *article du jour* pour la sélection du jour · *bilan semaine* pour tes stats_";

            Cache::put($cacheKey, $output, 1800); // 30 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] AnalyseLibrary failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'analyse de ta bibliothèque. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ARTICLE DU JOUR — SÉLECTION IA PERSONNALISÉE (NEW v1.13.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleArticleDuJour(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        // Default to broad categories if no prefs
        $searchCats = !empty($categories)
            ? array_values(array_unique(array_merge($categories, ['technology', 'ai'])))
            : ['technology', 'ai', 'science', 'business'];
        $searchCats = array_slice($searchCats, 0, 5);

        // Cache 1h per user + category fingerprint — one selection per hour
        $today    = now()->format('Y-m-d-H');
        $cacheKey = "content_curator:article_du_jour:{$userPhone}:{$today}:" . md5(implode(',', $searchCats));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Article du jour requested', ['categories' => $searchCats]);

        try {
            // Fetch a broad pool of trending articles
            $pool = [];
            $seen = [];
            foreach ($searchCats as $cat) {
                $trending = $this->aggregator->getTrending($cat, 8);
                foreach ($trending as $article) {
                    $url = $article['url'] ?? '';
                    if ($url && isset($seen[$url])) continue;
                    if ($url) $seen[$url] = true;
                    $article['_cat'] = $cat;
                    $pool[]          = $article;
                }
            }

            if (empty($pool)) {
                return AgentResult::reply(
                    "📖 Aucun article disponible pour le moment.\n"
                    . "_Réessaie dans quelques minutes ou utilise *digest* pour ton feed complet._"
                );
            }

            // Score pool by popularity (score) then take top 15 candidates for Claude to pick from
            usort($pool, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $candidates = array_slice($pool, 0, 15);

            // Build candidate list for Claude
            $candidatesText = "Profil utilisateur :\n";
            if (!empty($categories)) {
                $candidatesText .= "- Catégories d'intérêt : " . implode(', ', $categories) . "\n";
            }
            if (!empty($keywords)) {
                $candidatesText .= "- Mots-clés : " . implode(', ', array_slice($keywords, 0, 8)) . "\n";
            }
            $candidatesText .= "\nCandidats articles (populaires du moment) :\n\n";
            foreach ($candidates as $i => $article) {
                $num   = $i + 1;
                $title = $article['title'] ?? 'Sans titre';
                $src   = $article['source'] ?? '';
                $desc  = mb_strimwidth($article['description'] ?? '', 0, 150, '...');
                $cat   = $article['_cat'] ?? '';
                $score = $article['score'] ?? 0;

                $candidatesText .= "#{$num} [{$cat}] {$title}";
                if ($src) $candidatesText .= " ({$src})";
                if ($score) $candidatesText .= " score:{$score}";
                $candidatesText .= "\n";
                if ($desc) $candidatesText .= "  → {$desc}\n";
                $candidatesText .= "\n";
            }

            $currentDate  = now()->translatedFormat('l d F Y');
            $systemPrompt = <<<PROMPT
Tu es un éditeur expert en curation de contenu. Parmi les articles candidats, sélectionne L'UN SEUL article qui sera "l'article du jour" pour cet utilisateur (date : {$currentDate}).

FORMAT DE RÉPONSE (JSON strict) :
{"index": <numéro>, "raison": "<pourquoi cet article est le meilleur choix aujourd'hui — 1 phrase directe et personnalisée>", "accroche": "<phrase d'accroche captivante pour donner envie de lire — max 80 caractères>"}

CRITÈRES DE SÉLECTION (par ordre de priorité) :
1. Alignement avec les intérêts de l'utilisateur
2. Actualité et impact (score de popularité élevé)
3. Richesse du contenu (description détaillée disponible)
4. Diversité par rapport aux autres contenus vus aujourd'hui

RÈGLES STRICTES :
- Choisis EXACTEMENT 1 article, retourne UNIQUEMENT le JSON, rien d'autre
- La raison doit mentionner pourquoi C'EST LE BON MOMENT de lire cet article
- L'accroche doit être engageante, style "teaser" journalistique, max 80 caractères
PROMPT;

            $response = $this->claude->chat($candidatesText, ModelResolver::fast(), $systemPrompt);

            $selected = null;
            $raison   = '';
            $accroche = '';

            $decoded = $this->parseJsonResponse($response);
            if (is_array($decoded) && isset($decoded['index'])) {
                $idx      = (int) $decoded['index'] - 1;
                $selected = $candidates[$idx] ?? null;
                $raison   = $decoded['raison'] ?? '';
                $accroche = $decoded['accroche'] ?? '';
            }

            // Fallback: pick highest-scored article
            if (!$selected) {
                $selected = $candidates[0];
            }

            $title  = $selected['title'] ?? 'Sans titre';
            $source = $selected['source'] ?? '';
            $url    = $selected['url'] ?? '';
            $cat    = $selected['_cat'] ?? '';
            $score  = $selected['score'] ?? 0;
            $catIcon = self::CATEGORY_ICONS[$cat] ?? '📰';

            $heatIcon = match(true) {
                $score >= 500 => '🔥',
                $score >= 100 => '⬆️',
                default       => '',
            };

            $output  = "*📖 ARTICLE DU JOUR*\n";
            $output .= "_Sélection IA · " . now()->format('d/m/Y') . "_\n\n";
            $output .= "{$catIcon} *{$title}*";
            if ($source) $output .= " _{$source}_";
            if ($heatIcon) $output .= " {$heatIcon}";
            $output .= "\n";
            if ($url) $output .= "🔗 {$url}\n";
            $output .= "\n";

            if ($accroche) {
                $output .= "_{$accroche}_\n\n";
            }

            if ($raison) {
                $output .= "🎯 *Pourquoi aujourd'hui :* {$raison}\n\n";
            }

            // Try to get a quick TLDR of the selected article
            try {
                $content = $this->extractHtmlContent($url);
                if ($content['ok'] && mb_strlen($content['text']) >= 100) {
                    $text    = ($content['desc'] && mb_strlen($content['desc']) > 50 ? $content['desc'] . "\n\n" : '') . $content['text'];
                    $excerpt = mb_substr($text, 0, 2000);

                    $tldrPrompt = <<<PROMPT
Tu es un assistant de résumé ultra-rapide. Fournis un TLDR en exactement 3 points.

FORMAT STRICT (texte brut WhatsApp):
⚡ *TLDR*
• [point 1 — fait principal, max 70 caractères]
• [point 2 — détail clé ou chiffre, max 70 caractères]
• [point 3 — impact ou conclusion, max 70 caractères]

RÈGLES : Exactement 3 points · Chaque point commence par un verbe ou chiffre · Factuel uniquement · En français · Retourne UNIQUEMENT les 4 lignes
PROMPT;
                    $tldr = $this->claude->chat(
                        "Titre: {$title}\nURL: {$url}\n\nContenu:\n{$excerpt}",
                        ModelResolver::fast(),
                        $tldrPrompt
                    );
                    if ($tldr) {
                        $output .= $tldr . "\n\n";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[content_curator] ArticleDuJour TLDR failed (non-blocking): " . $e->getMessage());
            }

            $output .= "---\n";
            $output .= "_💡 *résume {$url}* pour le résumé complet · *save {$url}* pour bookmarker · *best of* pour le top 5 · *recommande* pour plus_";

            Cache::put($cacheKey, $output, 3600); // 1h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ArticleDuJour failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la sélection de l'article du jour. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PARTAGER BOOKMARK (NEW v1.14.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleShareBookmark(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $this->log($context, 'Share bookmark requested', [
            'position' => $position,
            'url'      => $article->url,
        ]);

        $title  = $article->title ?: 'Article intéressant';
        $source = $article->source ?: '';
        $url    = $article->url;

        // Try to get a quick summary for the share message
        $summaryLine = '';
        try {
            $content = $this->extractHtmlContent($url);
            if ($content['ok'] && $content['desc'] && mb_strlen($content['desc']) >= 30) {
                $summaryLine = "\n\n_" . mb_strimwidth($content['desc'], 0, 150, '...') . "_";
            }
        } catch (\Throwable $e) {
            // Non-blocking — share without summary
        }

        $output  = "*📤 PARTAGER CET ARTICLE*\n\n";
        $output .= "_Copie le message ci-dessous et transfère-le :_\n\n";
        $output .= "───────────────\n";
        $output .= "📰 *{$title}*";
        if ($source) $output .= "\n_{$source}_";
        $output .= $summaryLine;
        $output .= "\n\n🔗 {$url}";
        $output .= "\n───────────────\n\n";
        $output .= "_💡 *résume {$url}* pour un résumé complet · *mes bookmarks* pour ta liste_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ARTICLES SIMILAIRES (NEW v1.14.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSimilar(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $title = $article->title ?: 'Sans titre';

        // Cache 20 min per article URL
        $cacheKey = "content_curator:similar:{$userPhone}:" . md5($article->url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Similar articles requested', [
            'position' => $position,
            'title'    => mb_substr($title, 0, 60),
        ]);

        try {
            // Extract keywords from the bookmark title using Claude
            $systemPrompt = <<<PROMPT
Tu es un expert en recherche d'articles. À partir du titre d'un article, extrais 2-3 mots-clés de recherche en anglais pour trouver des articles similaires.

FORMAT DE RÉPONSE (JSON strict) :
{"keywords": ["keyword1", "keyword2", "keyword3"]}

RÈGLES :
- Mots-clés en anglais (pour la recherche via API)
- Termes précis et spécifiques (pas de mots trop génériques comme "news", "article")
- 2 à 3 mots-clés maximum
- Retourne UNIQUEMENT le JSON
PROMPT;

            $keywordsResponse = $this->claude->chat(
                "Titre de l'article : {$title}\nSource : {$article->source}",
                ModelResolver::fast(),
                $systemPrompt
            );

            $decoded  = $this->parseJsonResponse($keywordsResponse);
            $keywords = $decoded['keywords'] ?? [$title];

            // Determine categories to search in
            $prefs    = UserContentPreference::where('user_phone', $userPhone)->get();
            $userCats = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            $cats     = !empty($userCats)
                ? array_slice($userCats, 0, 4)
                : ['technology', 'science', 'business', 'ai'];

            $results = $this->aggregator->aggregate($cats, $keywords, 10);

            // Remove the original article itself
            $results = array_values(array_filter($results, fn($a) => ($a['url'] ?? '') !== $article->url));

            if (empty($results)) {
                return AgentResult::reply(
                    "🔗 Aucun article similaire trouvé pour *{$title}*.\n\n"
                    . "_Essaie *cherche " . implode(' ', array_slice($keywords, 0, 2)) . "* pour une recherche plus large._"
                );
            }

            $results = array_slice($results, 0, 5);

            $output  = "*🔗 ARTICLES SIMILAIRES*\n";
            $output .= "_Basé sur : " . mb_strimwidth($title, 0, 50, '...') . "_\n\n";

            foreach ($results as $i => $similar) {
                $num    = $i + 1;
                $sTitle = $similar['title'] ?? 'Sans titre';
                $source = $similar['source'] ?? '';
                $url    = $similar['url'] ?? '';
                $desc   = mb_strimwidth($similar['description'] ?? '', 0, 100, '...');

                $output .= "*{$num}. {$sTitle}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($desc) $output .= "  ↳ {$desc}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour lire · *tldr [url]* pour un résumé rapide_";

            Cache::put($cacheKey, $output, 1200); // 20 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Similar failed for position {$position}: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la recherche d'articles similaires. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUIZ SUR UN BOOKMARK (NEW v1.15.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleQuizArticle(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $title = $article->title ?: 'Sans titre';
        $url   = $article->url;

        $cacheKey = "content_curator:quiz:{$userPhone}:" . md5($url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Quiz requested', ['position' => $position, 'title' => mb_substr($title, 0, 60)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok'] || mb_strlen($content['text']) < 100) {
                // Fallback: generate quiz from title + source only
                $excerpt = "Titre : {$title}\nSource : {$article->source}";
            } else {
                $excerpt = mb_substr($content['text'], 0, 3000);
            }

            $systemPrompt = <<<PROMPT
Tu es un générateur de quiz éducatif. À partir du contenu d'un article, crée un mini-quiz de 3 questions pour tester la compréhension du lecteur.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

*Q1.* [Question]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

*Q2.* [Question]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

*Q3.* [Question]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

RÈGLES :
- Questions factuelles basées uniquement sur le contenu de l'article
- 3 choix par question (a, b, c)
- Réponses visibles directement (pas de spoiler caché)
- Explications courtes (1 phrase max)
- En français même si l'article est en anglais
- N'invente aucune information absente du texte
PROMPT;

            $userMessage = "Titre : {$title}\nSource : {$article->source}\n\nContenu :\n{$excerpt}";

            $quiz = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt);

            if (!$quiz) {
                return AgentResult::reply("❌ Impossible de générer le quiz. Réessaie dans quelques instants.");
            }

            $output  = "*🧠 QUIZ — Teste ta compréhension*\n";
            $output .= "_" . mb_strimwidth($title, 0, 60, '...') . "_\n\n";
            $output .= $quiz . "\n\n";
            $output .= "_💡 *résume {$url}* pour relire le résumé · *similaire #{$position}* pour des articles proches_";

            Cache::put($cacheKey, $output, 3600); // 1 heure

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Quiz failed for position {$position}: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération du quiz. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MOOD DIGEST (NEW v1.15.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleMoodDigest(AgentContext $context, string $mood): AgentResult
    {
        $userPhone = $context->from;

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        if (empty($categories)) {
            $categories = ['technology', 'science', 'ai', 'health'];
        }

        $this->log($context, 'Mood digest requested', ['mood' => $mood, 'categories' => $categories]);

        try {
            $articles = $this->aggregator->aggregate($categories, $keywords, 15);

            if (empty($articles)) {
                return AgentResult::reply(
                    "😕 Aucun article trouvé pour le moment.\n"
                    . "_Essaie *digest* ou *flash* pour du contenu classique._"
                );
            }

            // Build article list for the LLM to filter by mood
            $articleList = '';
            foreach (array_slice($articles, 0, 15) as $i => $a) {
                $articleList .= ($i + 1) . ". " . ($a['title'] ?? 'Sans titre')
                    . " | " . ($a['source'] ?? '')
                    . " | " . mb_strimwidth($a['description'] ?? '', 0, 120, '...')
                    . "\n";
            }

            $moodNormalized = mb_strtolower($mood);
            $moodLabel = match (true) {
                str_contains($moodNormalized, 'inspire')   => 'inspirant et motivant',
                str_contains($moodNormalized, 'détend') || str_contains($moodNormalized, 'detend') => 'léger et divertissant',
                str_contains($moodNormalized, 'positif') || str_contains($moodNormalized, 'bonne') || str_contains($moodNormalized, 'feel') => 'positif et optimiste',
                str_contains($moodNormalized, 'motivant')  => 'motivant et énergisant',
                default => $mood,
            };

            $moodEmoji = match (true) {
                str_contains($moodNormalized, 'inspire')   => '✨',
                str_contains($moodNormalized, 'détend') || str_contains($moodNormalized, 'detend') => '😌',
                str_contains($moodNormalized, 'positif') || str_contains($moodNormalized, 'bonne') || str_contains($moodNormalized, 'feel') => '☀️',
                str_contains($moodNormalized, 'motivant')  => '💪',
                default => '🎭',
            };

            $systemPrompt = <<<PROMPT
Tu es un curateur de contenu empathique. L'utilisateur veut lire du contenu qui soit : {$moodLabel}.

À partir de la liste d'articles ci-dessous, sélectionne les 5 articles (maximum) qui correspondent le mieux à cette ambiance. Si moins de 5 correspondent, n'en sélectionne que ceux qui correspondent vraiment.

FORMAT DE RÉPONSE (JSON strict) :
{"selected": [1, 5, 8], "reason": "courte phrase expliquant la sélection"}

RÈGLES :
- Les numéros correspondent aux positions dans la liste
- Ne sélectionne QUE les articles qui correspondent au mood demandé
- Si aucun article ne correspond, retourne {"selected": [], "reason": "explication"}
- Retourne UNIQUEMENT le JSON
PROMPT;

            $response = $this->claude->chat($articleList, ModelResolver::fast(), $systemPrompt);
            $decoded  = $this->parseJsonResponse($response);

            $selected = $decoded['selected'] ?? [];
            $reason   = $decoded['reason'] ?? '';

            if (empty($selected)) {
                return AgentResult::reply(
                    "{$moodEmoji} Pas d'articles correspondant à ton mood *{$moodLabel}* en ce moment.\n\n"
                    . "_Essaie *digest* pour du contenu classique ou *recommande* pour des suggestions IA._"
                );
            }

            $output  = "*{$moodEmoji} MOOD DIGEST — {$moodLabel}*\n";
            $output .= "_Sélection personnalisée selon ton humeur_\n\n";

            $count = 0;
            foreach ($selected as $idx) {
                $idx = (int) $idx;
                if ($idx < 1 || $idx > count($articles)) continue;
                $a = $articles[$idx - 1];
                $count++;

                $aTitle  = $a['title'] ?? 'Sans titre';
                $source  = $a['source'] ?? '';
                $url     = $a['url'] ?? '';
                $desc    = mb_strimwidth($a['description'] ?? '', 0, 100, '...');

                $output .= "*{$count}.* {$aTitle}";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($desc) $output .= "  ↳ {$desc}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            if ($reason) {
                $output .= "_{$moodEmoji} {$reason}_\n\n";
            }

            $output .= "_💡 *résume [url]* pour lire · *save [url]* pour bookmarker · *inspire moi* / *positif* / *détends moi* pour changer de mood_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] MoodDigest failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du mood digest. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HIGHLIGHTS / POINTS CLÉS (NEW v1.16.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleHighlights(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;

        if ($position < 1) {
            return AgentResult::reply("Numéro invalide. Dis *mes bookmarks* pour voir ta liste.");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $article = $articles->get($position - 1);

        if (!$article) {
            $max = $articles->count();
            return AgentResult::reply(
                "Bookmark n°{$position} introuvable. Tu as {$max} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        $title = $article->title ?: 'Sans titre';
        $url   = $article->url;

        $cacheKey = "content_curator:highlights:{$userPhone}:" . md5($url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Highlights requested', ['position' => $position, 'title' => mb_substr($title, 0, 60)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok'] || mb_strlen($content['text']) < 100) {
                $excerpt = "Titre : {$title}\nSource : {$article->source}";
                if ($content['desc'] ?? '') {
                    $excerpt .= "\nDescription : {$content['desc']}";
                }
            } else {
                $excerpt = mb_substr($content['text'], 0, 4000);
                if ($content['desc'] && mb_strlen($content['desc']) > 50) {
                    $excerpt = $content['desc'] . "\n\n" . $excerpt;
                }
            }

            $systemPrompt = <<<PROMPT
Tu es un analyste de contenu expert. Extrais les points clés (highlights) d'un article sous forme de takeaways actionnables.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

🔑 *Takeaway 1* : [phrase concise — fait ou insight principal]

🔑 *Takeaway 2* : [phrase concise]

🔑 *Takeaway 3* : [phrase concise]

🔑 *Takeaway 4* : [phrase concise, si pertinent]

🔑 *Takeaway 5* : [phrase concise, si pertinent]

💬 *En une phrase* : [résumé actionnable en 1 phrase — ce que le lecteur devrait retenir et appliquer]

RÈGLES :
- 3 à 5 takeaways selon la richesse du contenu
- Chaque takeaway = 1 fait concret, chiffre, ou insight actionnable
- Pas de généralités ni de paraphrases vagues
- La phrase finale doit être pratique et orientée action
- Factuel à 100% : n'invente rien d'absent du texte source
- Réponds en français même si l'article est en anglais
PROMPT;

            $userMessage = "Titre : {$title}\nSource : {$article->source}\n\nContenu :\n{$excerpt}";

            $highlights = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt);

            if (!$highlights) {
                return AgentResult::reply("❌ Impossible d'extraire les points clés. Réessaie dans quelques instants.");
            }

            $output  = "*📋 POINTS CLÉS — #{$position}*\n";
            $output .= "_" . mb_strimwidth($title, 0, 60, '...') . "_\n\n";
            $output .= $highlights . "\n\n";
            $output .= "_💡 *résume {$url}* pour le résumé complet · *quiz #{$position}* pour tester ta compréhension_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] Highlights failed for position {$position}: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'extraction des points clés. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RELATED NEWS (NEW v1.16.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRelatedNews(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $recentBookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($recentBookmarks->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Sauvegarde quelques articles avec *save [url]* puis reviens ici pour des news liées._"
            );
        }

        $this->log($context, 'Related news requested', ['bookmark_count' => $recentBookmarks->count()]);

        try {
            // Extract keywords from recent bookmark titles using LLM
            $titleList = $recentBookmarks->map(fn($b, $i) => ($i + 1) . ". " . ($b->title ?: 'Sans titre'))->implode("\n");

            $systemPrompt = <<<PROMPT
À partir de cette liste de titres d'articles bookmarkés par l'utilisateur, extrais les 5 mots-clés ou sujets principaux qui représentent ses centres d'intérêt actuels.

FORMAT DE RÉPONSE (JSON strict) :
{"keywords": ["mot1", "mot2", "mot3", "mot4", "mot5"], "theme": "description en 1 phrase du profil de lecture"}

RÈGLES :
- Mots-clés en anglais pour maximiser les résultats de recherche
- Spécifiques (pas de termes génériques comme "technology" ou "news")
- Retourne UNIQUEMENT le JSON
PROMPT;

            $kwResponse = $this->claude->chat($titleList, ModelResolver::fast(), $systemPrompt);
            $decoded    = $this->parseJsonResponse($kwResponse);
            $keywords   = $decoded['keywords'] ?? [];
            $theme      = $decoded['theme'] ?? '';

            if (empty($keywords)) {
                // Fallback: extract words from titles directly
                $allTitles = $recentBookmarks->pluck('title')->implode(' ');
                $words = array_filter(
                    preg_split('/\s+/', mb_strtolower($allTitles)),
                    fn($w) => mb_strlen($w) >= 4
                );
                $freq = array_count_values($words);
                arsort($freq);
                $keywords = array_slice(array_keys($freq), 0, 5);
            }

            if (empty($keywords)) {
                return AgentResult::reply(
                    "Impossible d'extraire des sujets de tes bookmarks.\n"
                    . "_Essaie *digest* ou *cherche [sujet]* à la place._"
                );
            }

            // Get user's followed categories, fallback to broad set
            $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
            $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            if (empty($categories)) {
                $categories = ['technology', 'science', 'business', 'ai'];
            }

            $articles = $this->aggregator->aggregate($categories, $keywords, 12);

            if (empty($articles)) {
                return AgentResult::reply(
                    "🔗 Aucun article récent lié à tes bookmarks.\n"
                    . "_Essaie *digest* ou *trending* pour du contenu frais._"
                );
            }

            // Deduplicate against existing bookmarks
            $existingUrls = $recentBookmarks->pluck('url')->map(fn($u) => mb_strtolower($u))->toArray();
            $articles = array_values(array_filter($articles, function ($a) use ($existingUrls) {
                return !in_array(mb_strtolower($a['url'] ?? ''), $existingUrls);
            }));

            if (empty($articles)) {
                return AgentResult::reply(
                    "🔗 Tu as déjà bookmarké tous les articles liés !\n"
                    . "_Essaie *trending* ou *digest* pour découvrir du nouveau contenu._"
                );
            }

            $articles = array_slice($articles, 0, 6);

            $output  = "*🔗 NEWS LIÉES À TES BOOKMARKS*\n";
            if ($theme) {
                $output .= "_Thème détecté : {$theme}_\n";
            }
            $output .= "_Basé sur tes " . $recentBookmarks->count() . " bookmarks récents · Mots-clés : " . implode(', ', array_slice($keywords, 0, 4)) . "_\n\n";

            foreach ($articles as $i => $article) {
                $num    = $i + 1;
                $aTitle = $article['title'] ?? 'Sans titre';
                $source = $article['source'] ?? '';
                $url    = $article['url'] ?? '';
                $desc   = mb_strimwidth($article['description'] ?? '', 0, 100, '...');

                $output .= "*{$num}. {$aTitle}*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($desc) $output .= "  ↳ {$desc}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour résumer · *digest* pour ton digest habituel_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] RelatedNews failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la recherche de news liées. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DÉFI LECTURE QUOTIDIEN (NEW v1.17.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingChallenge(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        // One challenge per day per user
        $today    = now()->format('Y-m-d');
        $cacheKey = "content_curator:challenge:{$userPhone}:{$today}";
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();

        // Pick 3 diverse categories (user prefs + random fill)
        $allCats     = self::VALID_CATEGORIES;
        $challengeCats = [];
        if (!empty($categories)) {
            $challengeCats = array_slice($categories, 0, 2);
        }
        $remaining = array_diff($allCats, $challengeCats);
        shuffle($remaining);
        while (count($challengeCats) < 3 && !empty($remaining)) {
            $challengeCats[] = array_shift($remaining);
        }

        $this->log($context, 'Reading challenge requested', ['categories' => $challengeCats]);

        try {
            $articles = [];
            foreach ($challengeCats as $cat) {
                $trending = $this->aggregator->getTrending($cat, 5);
                if (!empty($trending)) {
                    // Pick a random one from top 5
                    $pick = $trending[array_rand($trending)];
                    $pick['_cat'] = $cat;
                    $articles[] = $pick;
                }
            }

            if (count($articles) < 2) {
                return AgentResult::reply(
                    "📚 Pas assez d'articles disponibles pour le défi du jour.\n"
                    . "_Réessaie dans quelques heures ou utilise *digest* pour ton feed._"
                );
            }

            $streak     = $this->computeStreak($userPhone);
            $dayNumber  = (int) Cache::get("content_curator:challenge_count:{$userPhone}", 0) + 1;
            Cache::put("content_curator:challenge_count:{$userPhone}", $dayNumber, 86400 * 30);

            $output  = "*🏆 DÉFI LECTURE DU JOUR — Jour #{$dayNumber}*\n";
            if ($streak > 1) {
                $output .= "🔥 Streak actuel : {$streak} jour(s)\n";
            }
            $output .= "\n";
            $output .= "_Lis au moins 1 de ces 3 articles et sauvegarde-le !_\n\n";

            foreach ($articles as $i => $article) {
                $num    = $i + 1;
                $title  = $article['title'] ?? 'Sans titre';
                $source = $article['source'] ?? '';
                $url    = $article['url'] ?? '';
                $cat    = $article['_cat'] ?? '';
                $icon   = self::CATEGORY_ICONS[$cat] ?? '📄';

                $output .= "*{$num}. {$icon} {$title}*\n";
                if ($source) $output .= "_{$source}_ · {$cat}\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            $output .= "───────────────\n";
            $output .= "✅ *save [url]* quand tu as lu un article\n";
            $output .= "📖 *résume [url]* ou *tldr [url]* pour un aperçu rapide\n";
            $output .= "🧠 Après, essaie *quiz #N* pour tester ta compréhension !\n\n";
            $output .= "_Nouveau défi demain · *best of* pour le top du moment_";

            Cache::put($cacheKey, $output, 3600 * 6); // 6h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ReadingChallenge failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération du défi. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATS AVANCÉES BOOKMARKS (NEW v1.17.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkAnalytics(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark pour analyser.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $total = $articles->count();

        // Sources breakdown
        $sourceCounts = $articles->groupBy('source')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(5);

        // Weekly pace (last 4 weeks)
        $weeklyPace = [];
        for ($w = 0; $w < 4; $w++) {
            $start = now()->subWeeks($w + 1)->startOfWeek();
            $end   = now()->subWeeks($w)->startOfWeek();
            $count = $articles->filter(fn($a) => $a->created_at >= $start && $a->created_at < $end)->count();
            $weeklyPace[] = $count;
        }
        $avgPace = count($weeklyPace) > 0 ? round(array_sum($weeklyPace) / count($weeklyPace), 1) : 0;

        // Activity by day of week
        $dayActivity = $articles->groupBy(fn($a) => $a->created_at->locale('fr')->dayName)
            ->map(fn($group) => $group->count())
            ->sortDesc();
        $bestDay = $dayActivity->keys()->first() ?? '—';

        // Most recent and oldest
        $newest  = $articles->first();
        $oldest  = $articles->last();
        $spanDays = $newest && $oldest ? $oldest->created_at->diffInDays($newest->created_at) : 0;

        // Monthly trend (last 3 months)
        $monthlyTrend = [];
        for ($m = 0; $m < 3; $m++) {
            $start = now()->subMonths($m + 1)->startOfMonth();
            $end   = now()->subMonths($m)->startOfMonth();
            $count = $articles->filter(fn($a) => $a->created_at >= $start && $a->created_at < $end)->count();
            $label = $start->translatedFormat('M');
            $monthlyTrend[$label] = $count;
        }

        // Build output
        $output  = "*📊 ANALYTICS BOOKMARKS*\n";
        $output .= "_{$total} bookmark(s) · {$spanDays} jours d'activité_\n\n";

        // Top sources
        $output .= "*🏠 Top sources :*\n";
        foreach ($sourceCounts as $source => $count) {
            $pct     = round(($count / $total) * 100);
            $bar     = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));
            $output .= "  {$bar} {$source} ({$count} · {$pct}%)\n";
        }
        $output .= "\n";

        // Pace
        $output .= "*📈 Rythme de lecture :*\n";
        $output .= "  • Moyenne : *{$avgPace} bookmarks/semaine*\n";
        $output .= "  • Jour le plus actif : *{$bestDay}*\n";

        // Monthly trend
        if (!empty($monthlyTrend)) {
            $output .= "  • Tendance : ";
            $parts = [];
            foreach (array_reverse($monthlyTrend) as $label => $count) {
                $parts[] = "{$label} ({$count})";
            }
            $output .= implode(' → ', $parts) . "\n";
        }
        $output .= "\n";

        // Diversity score (0-100 based on source distribution)
        $uniqueSources = $articles->pluck('source')->unique()->count();
        $diversityScore = $total > 0 ? min(100, (int) round(($uniqueSources / max($total, 1)) * 100 * 2)) : 0;
        $diversityBar   = str_repeat('▓', (int) round($diversityScore / 10)) . str_repeat('░', 10 - (int) round($diversityScore / 10));
        $diversityLabel = match (true) {
            $diversityScore >= 70 => 'Excellent ! 🌈',
            $diversityScore >= 40 => 'Correct 👍',
            default               => 'À diversifier 🔄',
        };

        $output .= "*🌐 Diversité des sources :*\n";
        $output .= "  {$diversityBar} {$diversityScore}/100 — {$diversityLabel}\n";
        $output .= "  _{$uniqueSources} source(s) unique(s) sur {$total} bookmark(s)_\n\n";

        // Quick insights
        $output .= "*💡 En bref :*\n";
        if ($total >= 10 && $sourceCounts->count() <= 2) {
            $output .= "  • 🔄 Tu lis surtout 1-2 sources. Essaie *recommande* pour diversifier !\n";
        }
        if ($avgPace >= 5) {
            $output .= "  • 🔥 Lecteur assidu ! Plus de {$avgPace} saves/semaine.\n";
        } elseif ($avgPace <= 1) {
            $output .= "  • 💤 Rythme calme. Essaie *défi lecture* pour te motiver !\n";
        }
        $streak = $this->computeStreak($userPhone);
        if ($streak > 0) {
            $output .= "  • 🔥 Streak digest : {$streak} jour(s) consécutif(s)\n";
        }

        // Reading goal progress (v1.18.0)
        $goalKey  = "content_curator:reading_goal:{$userPhone}";
        $goal     = Cache::get($goalKey);
        if ($goal) {
            $thisWeekCount = $articles->filter(fn($a) => $a->created_at >= now()->startOfWeek())->count();
            $goalPct       = min(100, (int) round(($thisWeekCount / max($goal, 1)) * 100));
            $goalBar       = str_repeat('▓', (int) round($goalPct / 10)) . str_repeat('░', 10 - (int) round($goalPct / 10));
            $output .= "  • 🎯 Objectif : {$goalBar} {$thisWeekCount}/{$goal} cette semaine ({$goalPct}%)\n";
        }
        $output .= "\n";

        $output .= "_*profil lecture* pour une analyse IA · *objectif lecture 5* pour fixer un goal_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NOTES SUR BOOKMARK (NEW v1.18.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkNote(AgentContext $context, int $position, string $note): AgentResult
    {
        $userPhone = $context->from;

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        if ($position < 1 || $position > $articles->count()) {
            return AgentResult::reply(
                "❌ Position #{$position} invalide. Tu as {$articles->count()} bookmark(s).\n"
                . "_Dis *mes bookmarks* pour voir la liste._"
            );
        }

        $article = $articles[$position - 1];
        $title   = $article->title ?: $article->url;

        if (mb_strlen($note) > 500) {
            return AgentResult::reply("❌ Note trop longue (max 500 caractères). Raccourcis ta note et réessaie.");
        }

        // Store note in cache (keyed per bookmark ID, 90 days TTL)
        $noteKey = "content_curator:note:{$article->id}";

        if (mb_strtolower(trim($note)) === 'supprimer') {
            Cache::forget($noteKey);
            $this->log($context, 'Bookmark note deleted', ['bookmark_id' => $article->id]);
            return AgentResult::reply(
                "🗑️ Note supprimée pour *" . mb_strimwidth($title, 0, 60, '...') . "*"
            );
        }

        Cache::put($noteKey, $note, 86400 * 90);
        $this->log($context, 'Bookmark note saved', ['bookmark_id' => $article->id, 'note_len' => mb_strlen($note)]);

        return AgentResult::reply(
            "📝 Note ajoutée au bookmark #{$position} !\n\n"
            . "*" . mb_strimwidth($title, 0, 60, '...') . "*\n"
            . "💬 _{$note}_\n\n"
            . "_*note #{$position} supprimer* pour effacer · *lire #{$position}* pour résumer_"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OBJECTIF LECTURE HEBDOMADAIRE (NEW v1.18.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingGoal(AgentContext $context, ?int $target): AgentResult
    {
        $userPhone = $context->from;
        $goalKey   = "content_curator:reading_goal:{$userPhone}";

        // Show current goal status if no target given
        if ($target === null) {
            $goal = Cache::get($goalKey);
            if (!$goal) {
                return AgentResult::reply(
                    "🎯 Tu n'as pas d'objectif de lecture.\n\n"
                    . "_Fixe un objectif : *objectif lecture 5* (5 bookmarks/semaine)_\n"
                    . "_Ou : *objectif lecture 3* pour commencer doucement_"
                );
            }

            $thisWeekCount = SavedArticle::where('user_phone', $userPhone)
                ->where('created_at', '>=', now()->startOfWeek())
                ->count();

            $pct    = min(100, (int) round(($thisWeekCount / max($goal, 1)) * 100));
            $bar    = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));
            $status = $thisWeekCount >= $goal ? '✅ Objectif atteint !' : "⏳ Continue, il reste " . ($goal - $thisWeekCount) . " article(s) !";

            $daysLeft = 7 - (int) now()->dayOfWeek;

            return AgentResult::reply(
                "*🎯 OBJECTIF LECTURE*\n\n"
                . "Objectif : *{$goal} bookmark(s)/semaine*\n"
                . "Progression : {$bar} {$thisWeekCount}/{$goal} ({$pct}%)\n"
                . "{$status}\n\n"
                . "_{$daysLeft} jour(s) restant(s) cette semaine_\n"
                . "_*objectif lecture 0* pour supprimer · *objectif lecture 7* pour changer_"
            );
        }

        // Remove goal
        if ($target === 0) {
            Cache::forget($goalKey);
            $this->log($context, 'Reading goal removed');
            return AgentResult::reply("🎯 Objectif de lecture supprimé. Tu peux en fixer un nouveau quand tu veux.");
        }

        // Validate
        if ($target < 1 || $target > 50) {
            return AgentResult::reply("❌ Objectif invalide. Choisis entre 1 et 50 bookmarks par semaine.");
        }

        // Store goal (no expiry — persistent)
        Cache::put($goalKey, $target, 86400 * 365);
        $this->log($context, 'Reading goal set', ['target' => $target]);

        $thisWeekCount = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        $remaining = max(0, $target - $thisWeekCount);
        $pct       = min(100, (int) round(($thisWeekCount / max($target, 1)) * 100));
        $bar       = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));

        $output  = "*🎯 Objectif fixé : {$target} bookmark(s)/semaine !*\n\n";
        $output .= "Progression cette semaine : {$bar} {$thisWeekCount}/{$target} ({$pct}%)\n";
        if ($remaining > 0) {
            $output .= "📖 Plus que {$remaining} article(s) pour cette semaine !\n";
        } else {
            $output .= "✅ Tu as déjà atteint ton objectif cette semaine ! 🎉\n";
        }
        $output .= "\n_*objectif lecture* pour voir ta progression · *défi lecture* pour des suggestions_";

        return AgentResult::reply($output);
    }

    private function showHelp(): AgentResult
    {
        $v = $this->version();
        return AgentResult::reply(
            "*📰 Content Curator v{$v} — Veille personnalisée*\n\n"
            . "*⚡ Rapide :*\n"
            . "  • *flash* — Top 5 du moment (toutes tes catégories)\n"
            . "  • *flash ai* — Flash sur une catégorie\n"
            . "  • *best of* — Top 5 inter-catégories du jour\n\n"
            . "*📥 Digest & Trending :*\n"
            . "  • *digest express* — 5 articles ultra-compacts en 1 min\n"
            . "  • *digest* — Ton digest personnalisé complet\n"
            . "  • *digest tech* — Digest d'une catégorie\n"
            . "  • *digest tech + ai* — Digest multi-catégories\n"
            . "  • *digest rafraichir* — Forcer le rechargement\n"
            . "  • *digest sur React 19* — Digest sur n'importe quel sujet libre _(nouveau v1.11)_\n"
            . "  • *trending ai* — Contenu trending (tech, ai, science...)\n\n"
            . "*🔍 Recherche & Lecture :*\n"
            . "  • *cherche laravel 12* — Rechercher des articles\n"
            . "  • *tldr [url]* — Résumé ultracompact en 3 points\n"
            . "  • *résume [url]* — Résumé complet + temps de lecture\n"
            . "  • *cite [url]* — Extraire la meilleure citation\n"
            . "  • *compare [url1] [url2]* — Comparer deux articles IA\n"
            . "  • *lire #3* — Résumer le bookmark n°3\n\n"
            . "*🤖 IA :*\n"
            . "  • *article du jour* — L'article IA du jour (sélection personnalisée + TLDR)\n"
            . "  • *profil lecture* — Analyse IA de ta bibliothèque de bookmarks\n"
            . "  • *recommande* — Recommandations personnalisées de sujets\n"
            . "  • *quiz #3* — Mini-quiz pour tester ta compréhension d'un bookmark\n"
            . "  • *highlights #3* — Takeaways et points clés d'un bookmark _(nouveau v1.16)_\n"
            . "  • *news liées* — Articles frais basés sur tes bookmarks récents _(nouveau v1.16)_\n\n"
            . "*🎭 Mood :*\n"
            . "  • *inspire moi* — Articles inspirants et motivants _(nouveau v1.15)_\n"
            . "  • *positif* — Bonnes nouvelles et contenu optimiste\n"
            . "  • *détends moi* — Contenu léger et divertissant\n"
            . "  • *motivant* — Contenu énergisant\n\n"
            . "*🔖 Bookmarks :*\n"
            . "  • *surprends moi* — Redécouvrir un bookmark aléatoire\n"
            . "  • *save [url]* — Sauvegarder (+ titre optionnel : *save [url] Mon titre*)\n"
            . "  • *mes bookmarks* — Voir ta liste (15 derniers)\n"
            . "  • *mes bookmarks page 2* — Voir la page suivante\n"
            . "  • *exporter bookmarks* — Exporter TOUS les bookmarks\n"
            . "  • *cherche bookmarks laravel* — Filtrer tes bookmarks\n"
            . "  • *renommer #3 Mon titre* — Renommer un bookmark\n"
            . "  • *note #3 Mon commentaire* — Ajouter une note à un bookmark _(nouveau v1.18)_\n"
            . "  • *partager #3* — Générer un message prêt à transférer\n"
            . "  • *similaire #3* — Trouver des articles similaires à un bookmark\n"
            . "  • *supprimer 3* — Effacer le bookmark n°3\n"
            . "  • *vider bookmarks* — Effacer tous (confirmation requise)\n\n"
            . "*📊 Stats & Bilan :*\n"
            . "  • *objectif lecture 5* — Fixer un objectif hebdomadaire _(nouveau v1.18)_\n"
            . "  • *objectif lecture* — Voir ta progression\n"
            . "  • *défi lecture* — Défi quotidien (3 articles variés)\n"
            . "  • *analytics bookmarks* — Dashboard de tes habitudes de lecture\n"
            . "  • *bilan semaine* — Résumé de ta semaine de lecture\n"
            . "  • *stats digest* — Historique, streak et statistiques\n"
            . "  • *top sources* — Tes sources les plus bookmarkées\n\n"
            . "*⚙️ Personnalisation :*\n"
            . "  • *follow ai* — Suivre une catégorie ou mot-clé\n"
            . "  • *unfollow tech* — Arrêter de suivre\n"
            . "  • *preferences* — Voir tes intérêts + catégories disponibles\n\n"
            . "*Catégories :* 💻 technology · 🔬 science · 💼 business · ❤️ health · ⚽ sports\n"
            . "🎬 entertainment · 🎮 gaming · 🤖 ai · 🪙 crypto · 🚀 startup · 🎨 design · 🔒 security\n\n"
            . "*🆕 Nouveau v1.18 :*\n"
            . "  • *note #3 [texte]* — Annoter tes bookmarks avec des notes personnelles\n"
            . "  • *objectif lecture N* — Objectif hebdomadaire avec barre de progression\n"
            . "  • Flash news avec salutation selon l'heure ☀️📰🌙\n"
            . "  • Résumés enrichis (langue détectée, compteur de mots)\n"
            . "  • Save avec milestones (10, 50, 100 bookmarks) 🎉\n"
            . "  • Analytics avec score de diversité des sources\n\n"
            . "*Nouveau v1.17 :*\n"
            . "  • *défi lecture* — Défi quotidien (3 articles variés)\n"
            . "  • *analytics bookmarks* — Dashboard habitudes de lecture"
        );
    }
}
