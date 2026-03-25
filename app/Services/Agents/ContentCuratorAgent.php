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
        return 'Agent de curation de contenu personnalisé. Digest, trending, flash news, newsletter hebdo IA, recherche d\'articles, résumé d\'URL, bookmarking avec notes et favoris, partage, articles similaires, recherche sémantique, recommandations IA, série de lecture gamifiée, timeline chronologique, résumé quotidien, marquage lu en masse, répartition par catégorie, historique de lecture et statistiques selon les intérêts de l\'utilisateur via NewsAPI, HackerNews et Reddit.';
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
            // v1.19.0
            'tag', 'tagger', 'étiquette', 'etiquette', 'mes tags', 'tags',
            'recap mensuel', 'recap mois', 'bilan mensuel', 'monthly recap',
            // v1.20.0
            'bookmarks tag', 'filtrer par tag', 'filtre tag', 'par tag',
            'auto tag', 'tag auto', 'tag automatique', 'tagger auto',
            // v1.21.0
            'auto tag all', 'tagger tout', 'tag tout', 'auto tag tous',
            'résumé bookmarks', 'resume bookmarks', 'bookmark summary', 'vue ensemble',
            'overview bookmarks', 'panorama bookmarks',
            // v1.22.0
            'combien bookmarks', 'count bookmarks', 'nombre bookmarks', 'total bookmarks',
            'combien articles', 'nombre articles',
            'batch save', 'sauvegarder plusieurs', 'save multiple',
            'tldr mes bookmarks', 'batch tldr', 'résumé express bookmarks', 'resume express bookmarks',
            'découvrir sources', 'decouvrir sources', 'discover sources',
            'nouvelles sources', 'sources recommandées', 'sources recommandees',
            // v1.23.0
            'deep dive', 'approfondir', 'plonger dans', 'analyse approfondie',
            'topic deep dive', 'exploration', 'dossier',
            'rappel bookmarks', 'bookmark reminder', 'bookmarks oubliés', 'bookmarks oublies',
            'vieux bookmarks', 'anciens bookmarks', 'redécouvrir', 'redecouvrir',
            // v1.24.0
            'doublons', 'doublons bookmarks', 'duplicates', 'duplicate bookmarks', 'nettoyage bookmarks',
            'comparer bookmarks', 'compare bookmarks', 'comparer #',
            // v1.25.0
            'recherche intelligente', 'smart search', 'cherche ia', 'recherche ia',
            'semantic search', 'trouver intelligemment',
            'focus', 'session lecture', 'focus session', 'reading session',
            'session concentrée', 'session concentree', 'lire sur',
            // v1.26.0
            'newsletter', 'ma newsletter', 'newsletter hebdo', 'weekly newsletter',
            'résumé hebdo', 'resume hebdo',
            'noter', 'rate bookmark', 'rating', 'noter bookmark',
            'top notés', 'top notes', 'best rated', 'mes favoris', 'favoris',
            'mieux notés', 'mieux notes', 'top rated',
            // v1.27.0
            'ma série', 'ma serie', 'reading streak', 'streak', 'série lecture', 'serie lecture',
            'série de lecture', 'serie de lecture', 'mon streak',
            'timeline', 'chronologie', 'frise', 'timeline bookmarks', 'chronologie bookmarks',
            'frise bookmarks', 'historique bookmarks',
            // v1.28.0
            'lu', 'marquer lu', 'mark read', 'read',
            'à lire', 'a lire', 'non lus', 'non lu', 'unread', 'pas lus', 'pas lu',
            'bookmarks non lus', 'mes non lus',
            // v1.29.0
            'lu tout', 'mark all read', 'tout lu', 'marquer tout lu', 'all read',
            'mon jour', 'daily summary', 'résumé du jour', 'resume du jour',
            'aujourd\'hui lecture', 'lu aujourd\'hui', 'read today',
            // v1.30.0
            'stats catégories', 'stats categories', 'category stats', 'répartition', 'repartition',
            'répartition bookmarks', 'repartition bookmarks', 'par catégorie', 'par categorie',
            'historique lecture', 'reading history', 'journal lecture', 'mes lectures semaine',
            'log lecture', 'lecture log',
            // v1.33.0
            'exporter tag', 'export tag', 'exporter par tag', 'export by tag',
            'bookmarks tag export', 'exporter bookmarks tag',
            'semaine en bref', 'week at a glance', 'aperçu semaine', 'apercu semaine',
            'résumé semaine rapide', 'resume semaine rapide', 'quick weekly',
            // v1.32.0
            'facts', 'données', 'donnees', 'data',
            'plan lecture', 'reading plan', 'parcours lecture', 'parcours',
            // v1.34.0
            'radar', 'scan sources', 'veille radar', 'radar topic',
            'supprimer plusieurs', 'bulk delete', 'effacer plusieurs',
            // v1.35.0
            'grouper', 'clusters', 'grouper bookmarks', 'thèmes bookmarks', 'themes bookmarks',
            'regrouper', 'catégoriser', 'categoriser', 'cluster bookmarks',
            'watch', 'surveiller', 'suivi', 'trend watch', 'surveiller sujet',
            'évolution', 'evolution', 'tendance sujet',
            // v1.36.0
            'brief', 'briefing sur', 'résumé exécutif', 'resume executif', 'executive brief',
            'insights', 'insights lecture', 'reading insights', 'analyse tendances',
            'mes tendances', 'blind spots', 'angles morts',
        ];
    }

    public function version(): string
    {
        return '1.36.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        return (bool) preg_match(
            '/\b(digest|trending|tendances?|follow|suivre|unfollow|ne\s+plus\s+suivre|veille|curation|bookmark|sauvegarder|save|daily\s+digest|resume\s+quotidien|newsletter|flux\s+rss|mes\s+inter[eé]ts?|hackernews|reddit|news|actualit[eé]s?|mes\s+bookmarks?|mes\s+articles?|supprimer\s+(bookmark|article)|cherche|recherche|search|stats\s+digest|historique\s+digest|mon\s+historique|pr[eé]f[eé]rences?|mes\s+sources?|r[eé]sum[eé]|resumer|summarize|recommande|recommandation|suggestions?|pour\s+moi|vider\s+bookmarks?|quoi\s+lire|effacer\s+tout|flash|news\s+rapides|quoi\s+de\s+neuf|trouver\s+dans\s+mes\s+articles|exporter?\s+bookmarks?|exporter?\s+mes\s+articles?|confirmer\s+vider|vider\s+confirmer|lire\s+#?\d+|ouvrir\s+#?\d+|read\s+#?\d+|best\s+of|top\s+du\s+jour|meilleurs?\s+articles?|briefing|matin|renommer\s+#?\d+|rename\s+#?\d+|bilan\s+semaine|mes\s+lectures|bilan\s+lecture|r[eé]sum[eé]\s+semaine|tldr|en\s+bref|r[eé]sum[eé]\s+rapide|compare|comparer|comparaison|cite|citation|extrait|top\s+sources?|sources?\s+populaires?|meilleures?\s+sources?|surprends?\s+moi|hasard|al[eé]atoire|article\s+surprise|article\s+al[eé]atoire|digest\s+express|digest\s+rapide|quick\s+digest|aujourd.?hui|cette\s+semaine|ce\s+mois|analyser?\s+mes\s+bookmarks?|profil\s+lecture|analyse\s+biblio|r[eé]sum[eé]\s+biblio|intelligence\s+lecture|article\s+du\s+jour|lecture\s+du\s+jour|deep\s+read|s[eé]lection\s+du\s+jour|que\s+lire\s+aujourd.?hui|partager\s+#?\d+|share\s+#?\d+|envoyer\s+#?\d+|similaire\s+#?\d+|similar\s+#?\d+|comme\s+#?\d+|quiz\s+#?\d+|inspire\s*moi|d[ée]tends?\s*moi|positif|bonne\s+nouvelle|feel\s*good|motivant|mood\s+\S+|highlights?\s+#?\d+|points?\s+cl[eé]s?\s+#?\d+|takeaways?\s+#?\d+|essentiel\s+#?\d+|news\s+li[eé]es?|actualit[eé]s?\s+li[eé]es?|related\s+news|en\s+rapport|li[eé]\s+[àa]\s+mes\s+bookmarks?|d[eé]fi\s+lecture|reading\s+challenge|challenge\s+lecture|analytics?\s+bookmarks?|stats?\s+bookmarks?|dashboard\s+lecture|note\s+#?\d+|objectif\s+lecture|reading\s+goal|goal\s+lecture|tag\s+#?\d+|mes\s+tags?|recap\s+mensuel|recap\s+mois|bilan\s+mensuel|monthly\s+recap|bookmarks?\s+tag\s+\S+|filtrer?\s+par\s+tag|filtre\s+tag|par\s+tag\s+\S+|auto\s+tag\s+#?\d+|tag\s+auto\s+#?\d+|auto\s+tag\s+(all|tous?|tout)|tagger\s+tout|r[eé]sum[eé]\s+bookmarks?|bookmark\s+summary|vue\s+ensemble|overview\s+bookmarks?|panorama\s+bookmarks?|combien\s+(bookmarks?|articles?)|count\s+bookmarks?|nombre\s+(bookmarks?|articles?)|total\s+bookmarks?|batch\s+save|sauvegarder\s+plusieurs|save\s+multiple|deep\s+dive|approfondir|plonger\s+dans|analyse\s+approfondie|dossier\s+\S+|exploration\s+\S+|rappel\s+bookmarks?|bookmark\s+reminder|bookmarks?\s+oubli[eé]s?|vieux\s+bookmarks?|anciens?\s+bookmarks?|red[eé]couvrir|doublons|duplicates?|nettoyage\s+bookmarks?|comparer\s+#?\d+\s+#?\d+|compare\s+#?\d+\s+#?\d+|recherche\s+intelligente|smart\s+search|cherche\s+ia|recherche\s+ia|focus\s+session|session\s+lecture|reading\s+session|session\s+concentr[eé]e|lire\s+sur\s+\S+|ma\s+newsletter|newsletter\s+hebdo|weekly\s+newsletter|r[eé]sum[eé]\s+hebdo|noter\s+#?\d+|rate\s+#?\d+|rating\s+#?\d+|top\s+not[eé]s?|best\s+rated|mes\s+favoris|favoris|mieux\s+not[eé]s?|top\s+rated|ma\s+s[eé]rie|reading\s+streak|streak|s[eé]rie\s+lecture|s[eé]rie\s+de\s+lecture|mon\s+streak|timeline(\s+bookmarks?)?|chronologie(\s+bookmarks?)?|frise(\s+bookmarks?)?|historique\s+bookmarks?|stats?\s+cat[eé]gories?|category\s+stats?|r[eé]partition(\s+bookmarks?)?|par\s+cat[eé]gorie|historique\s+lecture|reading\s+history|journal\s+lecture|mes\s+lectures?\s+semaine|log\s+lecture|lecture\s+log|exporter?\s+(?:par\s+)?tag|export\s+(?:by\s+)?tag|semaine\s+en\s+bref|week\s+at\s+a\s+glance|aper[çc]u\s+semaine|quick\s+weekly|facts?\s+#?\d+|donn[eé]es?\s+#?\d+|data\s+#?\d+|plan\s+lecture|reading\s+plan|parcours\s+lecture|parcours\s+\S+|radar\s+\S+|scan\s+sources?|veille\s+radar|supprimer\s+#?\d+\s+#?\d+|delete\s+#?\d+\s+#?\d+|grouper(\s+bookmarks?)?|clusters?|regrouper|cat[eé]goriser|watch\s+\S+|surveiller\s+\S+|trend\s+watch|suivi\s+\S+|[eé]volution\s+\S+|brief\s+\S+|briefing\s+sur|r[eé]sum[eé]\s+ex[eé]cutif|executive\s+brief|insights(\s+lecture)?|reading\s+insights|analyse\s+tendances|mes\s+tendances|blind\s+spots?|angles?\s+morts?)\b/iu',
            $context->body
        );
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            $errMsg = mb_strtolower($e->getMessage());

            // Track consecutive errors per user for smarter guidance
            $errorCountKey = "content_curator:error_count:{$context->from}";
            $errorCount = (int) Cache::get($errorCountKey, 0) + 1;
            Cache::put($errorCountKey, $errorCount, 300); // 5 min window

            Log::error('[content_curator] handle() exception', [
                'from'        => $context->from,
                'body'        => mb_substr($context->body ?? '', 0, 300),
                'error'       => $e->getMessage(),
                'file'        => $e->getFile() . ':' . $e->getLine(),
                'trace'       => mb_substr($e->getTraceAsString(), 0, 1500),
                'error_count' => $errorCount,
            ]);

            $isDbError    = $e instanceof \Illuminate\Database\QueryException;
            $isRateLimit  = str_contains($errMsg, 'rate_limit') || str_contains($errMsg, '429');
            $isTimeout    = str_contains($errMsg, 'timed out') || str_contains($errMsg, 'timeout');
            $isOverload   = str_contains($errMsg, 'overloaded') || str_contains($errMsg, '529');
            $isConnection = str_contains($errMsg, 'connection refused') || str_contains($errMsg, 'could not resolve')
                || str_contains($errMsg, 'failed to connect') || str_contains($errMsg, 'ssl') || str_contains($errMsg, 'curl error');
            $isAuth       = str_contains($errMsg, 'authentication') || str_contains($errMsg, '401') || str_contains($errMsg, 'unauthorized')
                || str_contains($errMsg, 'invalid api key') || str_contains($errMsg, 'permission denied');
            $isMemory     = str_contains($errMsg, 'allowed memory') || str_contains($errMsg, 'out of memory');

            $reply = match (true) {
                $isDbError    => "⚠ Erreur temporaire de base de données. Réessaie dans quelques instants.\n_💡 Commandes hors-ligne : *flash* · *aide contenu*_",
                $isRateLimit  => "⚠ Trop de requêtes en cours. Attends 10-15 secondes et réessaie.\n_💡 Astuce : espace tes commandes de quelques secondes_",
                $isTimeout    => "⚠ Le traitement a pris trop de temps. Essaie une commande plus simple.\n_💡 Alternatives rapides : *flash* · *digest express* · *tldr [url]*_",
                $isOverload   => "⚠ Le service IA est surchargé. Réessaie dans 1-2 minutes.\n_💡 Les commandes légères comme *flash* ou *mes bookmarks* restent disponibles_",
                $isConnection => "⚠ Service externe inaccessible. Vérifie ta connexion et réessaie dans quelques instants.\n_💡 Tes bookmarks locaux restent accessibles : *mes bookmarks* · *mes tags*_",
                $isAuth       => "⚠ Erreur d'authentification avec un service externe. Contacte l'administrateur.",
                $isMemory     => "⚠ Requête trop volumineuse. Essaie avec moins de données.\n_💡 Alternatives : *mes bookmarks page 1* · *combien bookmarks* · *stats bookmarks*_",
                default       => "⚠ Erreur inattendue du Content Curator. Réessaie dans quelques secondes.\n_💡 Dis *aide contenu* pour voir les commandes disponibles_",
            };

            // After 3+ consecutive errors, suggest help
            if ($errorCount >= 3) {
                $reply .= "\n\n_💡 Plusieurs erreurs détectées. Dis *aide contenu* pour voir les commandes disponibles ou essaie *flash* / *digest express* qui sont plus légers._";
            }

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, [
                'error'       => $e->getMessage(),
                'error_type'  => match (true) {
                    $isDbError    => 'database',
                    $isRateLimit  => 'rate_limit',
                    $isTimeout    => 'timeout',
                    $isOverload   => 'overloaded',
                    $isConnection => 'connection',
                    $isAuth       => 'auth',
                    $isMemory     => 'memory',
                    default       => 'unknown',
                },
                'error_count' => $errorCount,
            ]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        // Sanitize: strip control characters (keep newlines and tabs)
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
        $body = trim($body);

        if (empty($body)) {
            return $this->showHelp();
        }

        if (mb_strlen($body) > 2000) {
            return AgentResult::reply("⚠ Message trop long (max 2000 caractères). Raccourcis ta requête et réessaie.");
        }

        // Spam detection: repeated chars, gibberish, only special characters, or only emojis
        if (preg_match('/(.)\1{15,}/u', $body)
            || (mb_strlen($body) > 20 && preg_match_all('/[a-zA-ZÀ-ÿ]/u', $body) < mb_strlen($body) * 0.3)
            || (mb_strlen($body) > 5 && !preg_match('/[a-zA-ZÀ-ÿ0-9]/u', $body))
            || (mb_strlen($body) > 3 && preg_match('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\s]+$/u', $body))
        ) {
            return AgentResult::reply(
                "⚠ Message non reconnu. Dis *aide contenu* pour voir les commandes disponibles.\n"
                . "_Exemples : *flash* · *digest* · *trending ai* · *mes bookmarks*_"
            );
        }

        $this->log($context, 'Content curator request', ['body' => mb_substr($body, 0, 100)]);

        // Count bookmarks (NEW v1.22.0) — quick stat
        if (preg_match('/^(combien\s+(bookmarks?|articles?)|count\s+bookmarks?|nombre\s+(bookmarks?|articles?)|total\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleCountBookmarks($context);
        }

        // Batch save multiple URLs (NEW v1.22.0)
        if (preg_match('/^(batch\s+save|sauvegarder\s+plusieurs|save\s+multiple)\s+(.+)$/iu', $body, $m)) {
            return $this->handleBatchSave($context, trim($m[2]));
        }

        // Batch TLDR of recent bookmarks (NEW v1.22.0) — before single TLDR
        if (preg_match('/^(tldr\s+mes\s+bookmarks?|batch\s+tldr|résumé\s+express\s+bookmarks?|resume\s+express\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleBatchTldr($context);
        }

        // Source discovery (NEW v1.22.0)
        if (preg_match('/^(d[ée]couvrir\s+sources?|discover\s+sources?|nouvelles?\s+sources?|sources?\s+recommand[ée]e?s?)\s*$/iu', $body)) {
            return $this->handleSourceDiscovery($context);
        }

        // Bookmark reminder (NEW v1.23.0) — resurface forgotten bookmarks
        if (preg_match('/^(rappel\s+bookmarks?|bookmark\s+reminder|bookmarks?\s+oubli[eé]s?|vieux\s+bookmarks?|anciens?\s+bookmarks?|red[eé]couvrir(\s+bookmarks?)?)\s*$/iu', $body)) {
            return $this->handleBookmarkReminder($context);
        }

        // Quick facts from a bookmark (NEW v1.32.0) — extract key data points
        if (preg_match('/^(facts?|donn[eé]es?|data)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleQuickFacts($context, (int) $m[2]);
        }

        // Reading plan on a topic (NEW v1.32.0) — AI learning path
        if (preg_match('/^(plan\s+lecture|reading\s+plan|parcours\s+lecture|parcours)\s+(.+)$/iu', $body, $m)) {
            return $this->handleReadingPlan($context, trim($m[2]));
        }

        // Content radar (NEW v1.34.0) — multi-source topic scan
        if (preg_match('/^(radar|scan\s+sources?|veille\s+radar)\s+(.+)$/iu', $body, $m)) {
            return $this->handleContentRadar($context, trim($m[2]));
        }

        // Bookmark clusters (NEW v1.35.0) — AI thematic grouping
        if (preg_match('/^(grouper|clusters?|grouper\s+bookmarks?|th[èe]mes?\s+bookmarks?|regrouper|cat[eé]goriser|cluster\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleBookmarkCluster($context);
        }

        // Trend watch (NEW v1.35.0) — topic evolution tracker
        if (preg_match('/^(watch|surveiller|suivi|trend\s+watch|surveiller\s+sujet|[eé]volution)\s+(.+)$/iu', $body, $m)) {
            return $this->handleTrendWatch($context, trim($m[2]));
        }

        // Executive brief (NEW v1.36.0) — structured topic briefing
        if (preg_match('/^(brief|briefing\s+sur|r[eé]sum[eé]\s+ex[eé]cutif|executive\s+brief)\s+(.+)$/iu', $body, $m)) {
            return $this->handleExecutiveBrief($context, trim($m[2]));
        }

        // Reading insights (NEW v1.36.0) — AI analysis of reading patterns
        if (preg_match('/^(insights?|insights?\s+lecture|reading\s+insights?|analyse\s+tendances|mes\s+tendances|blind\s+spots?|angles?\s+morts?)\s*$/iu', $body)) {
            return $this->handleReadingInsights($context);
        }

        // Topic deep dive (NEW v1.23.0) — comprehensive analysis of a topic
        if (preg_match('/^(deep\s+dive|approfondir|plonger\s+dans|analyse\s+approfondie|dossier|exploration)\s+(.+)$/iu', $body, $m)) {
            return $this->handleTopicDeepDive($context, trim($m[2]));
        }

        // Smart search bookmarks (NEW v1.25.0) — AI semantic search, must be before keyword search
        if (preg_match('/^(recherche\s+intelligente|smart\s+search|cherche\s+ia|recherche\s+ia)\s+(.+)$/iu', $body, $m)) {
            return $this->handleSmartSearch($context, trim($m[2]));
        }

        // Focus reading session (NEW v1.25.0) — curated session on a topic
        if (preg_match('/^(focus|session\s+lecture|focus\s+session|reading\s+session|session\s+concentr[eé]e|lire\s+sur)\s+(.+)$/iu', $body, $m)) {
            return $this->handleFocusSession($context, trim($m[2]));
        }

        // Compare two bookmarks by position (NEW v1.24.0) — must be before URL compare
        if (preg_match('/^(comparer?|compare)\s+#?(\d+)\s+(?:et|and|vs|#)\s*#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleCompareBookmarks($context, (int) $m[2], (int) $m[3]);
        }

        // Duplicate bookmarks detection (NEW v1.24.0)
        if (preg_match('/^(doublons|doublons?\s+bookmarks?|duplicates?|duplicate\s+bookmarks?|nettoyage\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleBookmarkDuplicates($context);
        }

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

        // Bulk delete bookmarks (NEW v1.34.0) — must be before single delete
        if (preg_match('/^(supprimer|delete|effacer|remove)\s+(#?\d+[\s,]+)+#?\d+\s*$/iu', $body)) {
            preg_match_all('/#?(\d+)/', $body, $nums);
            if (count($nums[1]) >= 2) {
                return $this->handleBulkDelete($context, $nums[1]);
            }
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

        // Tag un bookmark (NEW v1.19.0)
        if (preg_match('/^tag\s+#?(\d+)\s+(.+)$/iu', $body, $m)) {
            return $this->handleTagBookmark($context, (int) $m[1], trim($m[2]));
        }

        // Auto-tag ALL untagged bookmarks (NEW v1.21.0) — must be before single auto-tag
        if (preg_match('/^(auto\s+tag\s+(all|tous?|tout)|tagger\s+tout|tag\s+tout)\s*$/iu', $body)) {
            return $this->handleAutoTagAll($context);
        }

        // Bookmark summary / overview (NEW v1.21.0)
        if (preg_match('/^(r[eé]sum[eé]\s+bookmarks?|bookmark\s+summary|vue\s+ensemble|overview\s+bookmarks?|panorama\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleBookmarkOverview($context);
        }

        // Auto-tag IA (NEW v1.20.0) — must be before generic tag match
        if (preg_match('/^(auto\s+tag|tag\s+auto|tag\s+automatique|tagger\s+auto)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleAutoTag($context, (int) $m[2]);
        }

        // Filtrer bookmarks par tag (NEW v1.20.0) — must be before generic tag list
        if (preg_match('/^(bookmarks?\s+tag|filtrer?\s+par\s+tag|filtre\s+tag|par\s+tag)\s+(.+)$/iu', $body, $m)) {
            return $this->handleBookmarksByTag($context, trim($m[2]));
        }

        // Voir mes tags (NEW v1.19.0)
        if (preg_match('/^(mes\s+tags?|tags?)\s*$/iu', $body)) {
            return $this->handleListTags($context);
        }

        // Récap mensuel (NEW v1.19.0)
        if (preg_match('/^(recap\s+mensuel|recap\s+mois|bilan\s+mensuel|monthly\s+recap)\s*$/iu', $body)) {
            return $this->handleRecapMensuel($context);
        }

        // Weekly newsletter (NEW v1.26.0) — AI-curated weekly summary
        if (preg_match('/^(ma\s+newsletter|newsletter|newsletter\s+hebdo|weekly\s+newsletter|résumé\s+hebdo|resume\s+hebdo)\s*$/iu', $body)) {
            return $this->handleWeeklyNewsletter($context);
        }

        // Rate a bookmark (NEW v1.26.0) — score 1-5 stars
        if (preg_match('/^(noter|rate|rating)\s+#?(\d+)\s+(\d)\s*$/iu', $body, $m)) {
            return $this->handleRateBookmark($context, (int) $m[2], (int) $m[3]);
        }

        // View top rated bookmarks (NEW v1.26.0)
        if (preg_match('/^(top\s+not[eé]s?|best\s+rated|mes\s+favoris|favoris|top\s+rated|mieux\s+not[eé]s?)\s*$/iu', $body)) {
            return $this->handleTopRated($context);
        }

        // Mark bookmark as read (NEW v1.28.0) — toggle read status
        if (preg_match('/^(lu|read|marquer?\s+lu|mark\s+read)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleMarkRead($context, (int) $m[2]);
        }

        // Bulk mark all as read (NEW v1.29.0) — must be before single unread match
        if (preg_match('/^(lu\s+tout|tout\s+lu|mark\s+all\s+read|marquer?\s+tout\s+lu|all\s+read)\s*$/iu', $body)) {
            return $this->handleBulkMarkRead($context);
        }

        // Category stats breakdown (NEW v1.30.0) — visual distribution by category/tag
        if (preg_match('/^(stats?\s+cat[eé]gories?|category\s+stats?|r[eé]partition(\s+bookmarks?)?|par\s+cat[eé]gorie)\s*$/iu', $body)) {
            return $this->handleCategoryStats($context);
        }

        // Reading history log (NEW v1.30.0) — 7-day reading journal
        if (preg_match('/^(historique\s+lecture|reading\s+history|journal\s+lecture|mes\s+lectures?\s+semaine|log\s+lecture|lecture\s+log)\s*$/iu', $body)) {
            return $this->handleReadingHistory($context);
        }

        // Export bookmarks by tag (NEW v1.33.0)
        if (preg_match('/^(exporter?\s+(?:par\s+)?tag|export\s+(?:by\s+)?tag|bookmarks?\s+tag\s+export|exporter?\s+bookmarks?\s+tag)\s+(.+)$/iu', $body, $m)) {
            return $this->handleExportByTag($context, trim($m[2]));
        }

        // Week at a glance (NEW v1.33.0) — quick visual weekly overview
        if (preg_match('/^(semaine\s+en\s+bref|week\s+at\s+a\s+glance|aper[çc]u\s+semaine|r[eé]sum[eé]\s+semaine\s+rapide|resume\s+semaine\s+rapide|quick\s+weekly)\s*$/iu', $body)) {
            return $this->handleWeekAtGlance($context);
        }

        // Daily reading summary (NEW v1.29.0) — what you read today + suggestions
        if (preg_match('/^(mon\s+jour|daily\s+summary|r[eé]sum[eé]\s+du\s+jour|lu\s+aujourd.?hui|read\s+today|aujourd.?hui\s+lecture)\s*$/iu', $body)) {
            return $this->handleDailySummary($context);
        }

        // Unread bookmarks list (NEW v1.28.0) — show unread items
        if (preg_match('/^([àa]\s+lire|non\s+lus?|unread|pas\s+lus?|bookmarks?\s+non\s+lus?|mes\s+non\s+lus?)\s*$/iu', $body)) {
            return $this->handleUnreadBookmarks($context);
        }

        // Reading streak (NEW v1.27.0) — gamified streak tracking
        if (preg_match('/^(ma\s+s[eé]rie|reading\s+streak|streak|s[eé]rie\s+lecture|s[eé]rie\s+de\s+lecture|mon\s+streak)\s*$/iu', $body)) {
            return $this->handleReadingStreak($context);
        }

        // Bookmark timeline (NEW v1.27.0) — chronological view
        if (preg_match('/^(timeline|chronologie|frise|timeline\s+bookmarks?|chronologie\s+bookmarks?|frise\s+bookmarks?|historique\s+bookmarks?)\s*$/iu', $body)) {
            return $this->handleBookmarkTimeline($context);
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
        // Per-user rate limit: max 1 flash per 30s (v1.26.0)
        $throttleKey = "content_curator:flash_throttle:{$context->from}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends quelques secondes avant de relancer *flash*.");
        }
        Cache::put($throttleKey, true, 30);

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
                $catLabel = $normalized ? "*{$normalized}*" : 'tes catégories';
                return AgentResult::reply(
                    "⚡ Aucun flash disponible pour {$catLabel} en ce moment.\n\n"
                    . "_💡 Essaie :_\n"
                    . "• *flash ai* ou *flash tech* — changer de catégorie\n"
                    . "• *digest* — résumé complet\n"
                    . "• *trending* — contenu populaire"
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

            $output .= "_💡 *résume [url]* pour lire en détail · *save [url]* pour bookmarker · *digest* pour plus_";

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

            $streak  = $this->computeStreak($userPhone);
            $header  = "*{$icon} DIGEST{$catLabel}*\n";
            $header .= "_" . now()->format('d/m/Y H:i') . " · " . count($summaries) . " articles_";
            if ($streak >= 2) $header .= " 🔥 _{$streak}j consécutifs_";
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

        // Sanitize query: strip characters that could break LIKE or cause issues
        $query = preg_replace('/[%_\\\\]/', ' ', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if (mb_strlen($query) < 2) {
            return AgentResult::reply("Précise un sujet de recherche. Exemple: *cherche laravel 12*");
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
                $noResult = "🔍 Aucun article trouvé pour *{$queryDisplay}*.\n\n"
                    . "_Essaie un terme plus général ou vérifie l'orthographe._\n"
                    . "_Ou utilise *trending tech* pour voir les tendances du moment._";
                // Cache empty results briefly to avoid API spam (v1.23.0)
                Cache::put($cacheKey, $noResult, 60);
                return AgentResult::reply(
                    $noResult
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

            $output .= "_💡 *save [url]* pour bookmarker · *résume [url]* pour résumer · *tldr [url]* pour un résumé express_";

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

        if (mb_strlen($url) > 2048) {
            return AgentResult::reply("❌ L'URL est trop longue (max 2048 caractères).");
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
        }

        // Rate limit: max 1 summary per 15s per user
        $throttleKey = "content_curator:summarize_throttle:{$context->from}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends quelques secondes avant de relancer un résumé.");
        }
        Cache::put($throttleKey, true, 15);

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

🏷️ *Tags* : [2-3 mots-clés séparés par des virgules pour catégoriser l'article]

RÈGLES STRICTES:
- Factuel à 100% : n'invente AUCUNE information absente du texte source
- Style journalistique direct : chiffres, noms propres, faits vérifiables
- Si le texte est trop court ou peu informatif, résume ce qui est disponible sans compléter
- Si le contenu semble être un paywall ou une page de login, indique-le clairement
- Adapte le nombre de points clés au contenu réel (3 minimum, 5 maximum)
- Réponds en français même si l'article est en anglais
- N'utilise pas de guillemets ni de markdown lourd (*, _, etc. sont autorisés pour le formatage WhatsApp)
- Si tu détectes un paywall, page de login, cookie wall, message d'erreur, contenu tronqué, ou texte de type "subscribe to continue", commence par ⚠️ et indique clairement que le contenu est incomplet
- Si l'article contient des données chiffrées (stats, pourcentages, montants, dates), mets-les en avant dans les points clés
- Si le texte contient principalement du code HTML/JS ou des menus de navigation, signale que l'extraction a échoué
- Si l'article mentionne des personnes, entreprises ou organisations, cite-les nommément dans les points clés
- Si l'article est une opinion ou un éditorial, précise-le dès le sujet principal
PROMPT;

            $userMessage = "Titre: " . ($title ?? 'Inconnu') . "\nURL: {$url}\n\nContenu:\n{$excerpt}";

            $summary = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt);

            if (!$summary) {
                return AgentResult::reply("❌ Impossible de générer le résumé. Réessaie dans quelques instants.");
            }

            // Detect article language from content (check common FR words for better accuracy)
            $frenchScore = preg_match_all('/\b(les|des|une|dans|pour|est|sur|avec|que|sont|cette|aussi|leur|mais|comme|très|même|faire|entre|sans|tous|après|bien|plus|sous|quand|alors|depuis|encore|chez|donc|enfin)\b/iu', mb_substr($text, 0, 2000));
            $langHint = $frenchScore >= 5 ? '🇫🇷' : '🇬🇧';

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

        // Cache recommendations for 30 min per user (v1.23.0 — avoid repeated LLM calls)
        $recoCacheKey = "content_curator:reco:{$userPhone}:" . md5(implode(',', $categories) . implode(',', $keywords));
        $cachedReco = Cache::get($recoCacheKey);
        if ($cachedReco) {
            return AgentResult::reply($cachedReco);
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

            $topics = $topicsJson ? $this->parseJsonResponse($topicsJson) : null;

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

            Cache::put($recoCacheKey, $output, 1800); // 30 min

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

        // Strip trailing punctuation that's not part of the URL (v1.23.0)
        $url = rtrim($m[0], '.,;:!?)>}');

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

        // Check exact URL and normalized variant (v1.22.0 — catch near-duplicates)
        $normalizedUrl = $this->normalizeUrl($url);
        $existing = SavedArticle::where('user_phone', $userPhone)
            ->where(function ($q) use ($url, $normalizedUrl) {
                $q->where('url', $url)->orWhere('url', $normalizedUrl);
            })
            ->first();

        if ($existing) {
            $pos = SavedArticle::where('user_phone', $userPhone)
                ->orderByDesc('created_at')
                ->pluck('id')
                ->search($existing->id);
            $posLabel = $pos !== false ? " (#" . ($pos + 1) . ")" : '';
            return AgentResult::reply(
                "📌 Cet article est déjà dans tes bookmarks{$posLabel} (ajouté le " . $existing->created_at->format('d/m/Y') . ").\n\n"
                . "_💡 Actions possibles :_\n"
                . "• *lire " . ($pos !== false ? '#' . ($pos + 1) : '[url]') . "* — Résumer\n"
                . "• *similaire " . ($pos !== false ? '#' . ($pos + 1) : '') . "* — Articles proches\n"
                . "• *note " . ($pos !== false ? '#' . ($pos + 1) . ' [texte]' : '') . "* — Ajouter une note"
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
            $output .= " ({$date})";
            // Show tags inline if present (v1.22.0)
            $tags = Cache::get("content_curator:tags:{$article->id}", []);
            if (!empty($tags)) {
                $output .= " " . implode(' ', array_map(fn($t) => "#{$t}", array_slice($tags, 0, 3)));
            }
            $output .= "\n";
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

    // ─────────────────────────────────────────────────────────────────────────
    // BULK DELETE (NEW v1.34.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBulkDelete(AgentContext $context, array $positions): AgentResult
    {
        $userPhone = $context->from;

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply("Tu n'as aucun bookmark à supprimer.");
        }

        $max = $articles->count();
        $positions = array_unique(array_map('intval', $positions));
        sort($positions);

        // Validate all positions first
        $invalid = array_filter($positions, fn($p) => $p < 1 || $p > $max);
        if (!empty($invalid)) {
            return AgentResult::reply(
                "❌ Position(s) invalide(s) : " . implode(', ', $invalid) . "\n"
                . "_Tu as {$max} bookmark(s). Dis *mes bookmarks* pour voir les numéros._"
            );
        }

        if (count($positions) > 20) {
            return AgentResult::reply("❌ Maximum 20 bookmarks par suppression groupée. Pour tout effacer, utilise *vider bookmarks*.");
        }

        $deleted = [];
        $toDelete = [];
        foreach ($positions as $pos) {
            $article = $articles->get($pos - 1);
            if ($article) {
                $toDelete[] = $article;
                $deleted[] = "#{$pos} — " . ($article->title ?: mb_strimwidth($article->url, 0, 50, '...'));
            }
        }

        foreach ($toDelete as $article) {
            $article->delete();
        }

        $this->log($context, 'Bulk delete', ['positions' => $positions, 'count' => count($deleted)]);

        $remaining = SavedArticle::where('user_phone', $userPhone)->count();
        $output = "🗑️ *" . count($deleted) . " bookmark(s) supprimé(s) :*\n\n";
        foreach ($deleted as $entry) {
            $output .= "  • {$entry}\n";
        }
        $output .= "\n_Il te reste {$remaining} bookmark(s). Dis *mes bookmarks* pour voir ta liste._";

        return AgentResult::reply($output);
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

        if (mb_strlen($url) > 2048) {
            return AgentResult::reply("❌ L'URL est trop longue (max 2048 caractères).");
        }

        if ($this->isPrivateUrl($url)) {
            return AgentResult::reply("❌ URL privée non autorisée. Utilise une URL publique.");
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
            $decoded   = $response ? $this->parseJsonResponse($response) : null;
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

        // Extract JSON array or object
        if (str_starts_with($clean, '[') || str_starts_with($clean, '{')) {
            // already looks like JSON
        } elseif (preg_match('/(\[.*\])/s', $clean, $m)) {
            $clean = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fix trailing commas before ] or } (common LLM mistake)
        $fixed = preg_replace('/,\s*([\]\}])/s', '$1', $clean);
        $decoded = json_decode($fixed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fix unescaped newlines inside JSON string values (common LLM mistake v1.23.0)
        $nlFixed = preg_replace('/(?<="[^"]*)\n(?=[^"]*")/', '\\n', $fixed);
        if ($nlFixed !== $fixed) {
            $decoded = json_decode($nlFixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fix single-quoted strings → double-quoted (common LLM mistake v1.22.0)
        $singleFixed = preg_replace("/(?<=[\[{,:])\s*'([^']*?)'\s*(?=[\]},:])/" , '"$1"', $fixed);
        if ($singleFixed !== $fixed) {
            $decoded = json_decode($singleFixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Attempt to fix truncated JSON by closing brackets
        $openBraces   = substr_count($clean, '{') - substr_count($clean, '}');
        $openBrackets = substr_count($clean, '[') - substr_count($clean, ']');
        if ($openBraces > 0 || $openBrackets > 0) {
            $attempt = $clean;
            // Remove last incomplete key-value pair before closing (v1.22.0)
            $attempt = preg_replace('/,\s*"[^"]*"?\s*:?\s*$/', '', $attempt);
            $attempt .= str_repeat('}', max(0, $openBraces));
            $attempt .= str_repeat(']', max(0, $openBrackets));
            // Also fix trailing commas in the attempt
            $attempt = preg_replace('/,\s*([\]\}])/s', '$1', $attempt);
            $decoded = json_decode($attempt, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fix control characters inside JSON string values (v1.26.0)
        $ctrlFixed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);
        if ($ctrlFixed !== $clean) {
            $decoded = json_decode($ctrlFixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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

            // Body: <article> > <main> > div[class*=content/post/entry] > <p> aggregation > <body> (v1.22.0: added <p> fallback)
            $text = '';
            if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $html, $m)) {
                $text = $m[1];
            } elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $html, $m)) {
                $text = $m[1];
            } elseif (preg_match('/<div[^>]+class=["\'][^"\']*\b(post|entry|content|article-body|story|text)\b[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $m)) {
                $text = $m[2];
            } else {
                // Try aggregating all <p> tags (v1.22.0 — better than raw <body> for many sites)
                $stripped = preg_replace('/<(script|style|nav|header|footer|aside|noscript|form|iframe)[^>]*>.*?<\/\1>/si', '', $html);
                if (preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $stripped, $pm)) {
                    $paragraphs = array_filter(array_map(fn($p) => trim(strip_tags($p)), $pm[1]), fn($p) => mb_strlen($p) > 30);
                    if (count($paragraphs) >= 2) {
                        $text = implode(' ', $paragraphs);
                    }
                }
                // Fallback to full <body>
                if ($text === '' && preg_match('/<body[^>]*>(.*?)<\/body>/si', $stripped, $m)) {
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

            $decoded = $response ? $this->parseJsonResponse($response) : null;
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

            $decoded  = $keywordsResponse ? $this->parseJsonResponse($keywordsResponse) : null;
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

*Q1.* 🟢 [Question facile — fait principal]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

*Q2.* 🟡 [Question intermédiaire — détail ou chiffre]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

*Q3.* 🔴 [Question avancée — implication ou analyse]
  a) [Option A]
  b) [Option B]
  c) [Option C]
✅ Réponse : [lettre] — [explication courte]

RÈGLES :
- Difficulté progressive : facile → intermédiaire → avancée
- Questions factuelles basées uniquement sur le contenu de l'article
- 3 choix par question (a, b, c), les mauvaises réponses doivent être plausibles
- Réponses visibles directement (pas de spoiler caché)
- Explications courtes (1 phrase max)
- En français même si l'article est en anglais
- N'invente aucune information absente du texte
- La Q3 peut demander une déduction logique à partir des faits de l'article
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
            $decoded  = $response ? $this->parseJsonResponse($response) : null;

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
            $decoded    = $kwResponse ? $this->parseJsonResponse($kwResponse) : null;
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

    // ─────────────────────────────────────────────────────────────────────────
    // TAG BOOKMARK (NEW v1.19.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTagBookmark(AgentContext $context, int $position, string $tag): AgentResult
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

        $tag = mb_strtolower(trim($tag));

        if (mb_strlen($tag) > 50) {
            return AgentResult::reply("❌ Tag trop long (max 50 caractères).");
        }

        if ($tag === 'supprimer' || $tag === 'effacer') {
            $article  = $articles[$position - 1];
            $tagKey   = "content_curator:tags:{$article->id}";
            Cache::forget($tagKey);
            $this->log($context, 'Bookmark tags cleared', ['bookmark_id' => $article->id]);
            $title = $article->title ?: $article->url;
            return AgentResult::reply(
                "🗑️ Tags supprimés pour *" . mb_strimwidth($title, 0, 60, '...') . "*"
            );
        }

        $article = $articles[$position - 1];
        $tagKey  = "content_curator:tags:{$article->id}";

        $existingTags = Cache::get($tagKey, []);
        $newTags = array_map('trim', explode(',', $tag));
        $newTags = array_filter($newTags, fn($t) => $t !== '');

        $merged = array_values(array_unique(array_merge($existingTags, $newTags)));

        if (count($merged) > 10) {
            return AgentResult::reply("❌ Maximum 10 tags par bookmark. Ce bookmark en a déjà " . count($existingTags) . ".");
        }

        Cache::put($tagKey, $merged, 86400 * 365);
        $this->log($context, 'Bookmark tagged', ['bookmark_id' => $article->id, 'tags' => $merged]);

        $title   = $article->title ?: $article->url;
        $tagList = implode(', ', array_map(fn($t) => "#{$t}", $merged));

        return AgentResult::reply(
            "🏷️ Bookmark #{$position} tagué !\n\n"
            . "*" . mb_strimwidth($title, 0, 60, '...') . "*\n"
            . "Tags : {$tagList}\n\n"
            . "_*mes tags* pour voir tous tes tags · *tag #{$position} supprimer* pour effacer_"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LISTER TAGS (NEW v1.19.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleListTags(AgentContext $context): AgentResult
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

        $tagMap = []; // tag => [bookmark titles]
        foreach ($articles as $i => $article) {
            $tagKey = "content_curator:tags:{$article->id}";
            $tags   = Cache::get($tagKey, []);
            foreach ($tags as $tag) {
                $tagMap[$tag][] = [
                    'pos'   => $i + 1,
                    'title' => mb_strimwidth($article->title ?: $article->url, 0, 40, '...'),
                ];
            }
        }

        if (empty($tagMap)) {
            return AgentResult::reply(
                "🏷️ Aucun tag trouvé.\n\n"
                . "_Utilise *tag #3 tech, ia* pour taguer un bookmark._"
            );
        }

        ksort($tagMap);
        $output = "*🏷️ Tes tags (" . count($tagMap) . ")*\n\n";

        foreach ($tagMap as $tag => $bookmarks) {
            $output .= "*#{$tag}* — " . count($bookmarks) . " bookmark(s)\n";
            foreach (array_slice($bookmarks, 0, 3) as $bm) {
                $output .= "  ↳ #{$bm['pos']} {$bm['title']}\n";
            }
            if (count($bookmarks) > 3) {
                $output .= "  ↳ _+" . (count($bookmarks) - 3) . " autres…_\n";
            }
            $output .= "\n";
        }

        $output .= "_*tag #N [tag]* pour ajouter · *tag #N supprimer* pour effacer_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECAP MENSUEL (NEW v1.19.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRecapMensuel(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $startOfMonth = now()->startOfMonth();
        $endOfMonth   = now()->endOfMonth();

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->orderByDesc('created_at')
            ->get();

        $totalAll = SavedArticle::where('user_phone', $userPhone)->count();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "📅 Aucun bookmark ce mois-ci.\n\n"
                . "Total bookmarks : {$totalAll}\n"
                . "_Utilise *save [url]* pour commencer à sauvegarder !_"
            );
        }

        $monthName = now()->translatedFormat('F Y');

        // Stats de base
        $count    = $articles->count();
        $sources  = [];
        $titles   = [];
        foreach ($articles as $a) {
            $titles[] = $a->title ?: $a->url;
            if ($a->url) {
                $host = parse_url($a->url, PHP_URL_HOST);
                if ($host) {
                    $host = preg_replace('/^www\./', '', $host);
                    $sources[$host] = ($sources[$host] ?? 0) + 1;
                }
            }
        }

        arsort($sources);
        $topSources = array_slice($sources, 0, 5, true);

        // Digest logs pour le mois
        $digestCount = ContentDigestLog::where('user_phone', $userPhone)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        // Jours actifs (jours avec au moins un bookmark)
        $activeDays = $articles->groupBy(fn($a) => $a->created_at->format('Y-m-d'))->count();
        $totalDays  = (int) now()->day;

        // Semaine la plus active
        $weekGroups = $articles->groupBy(fn($a) => $a->created_at->format('W'));
        $bestWeek   = $weekGroups->sortByDesc(fn($g) => $g->count())->keys()->first();
        $bestWeekCount = $weekGroups->max(fn($g) => $g->count());

        // AI analysis du profil lecture du mois
        $titleList = implode("\n", array_slice($titles, 0, 20));

        $systemPrompt = <<<PROMPT
Tu es un analyste de lecture personnel. Analyse les titres d'articles sauvegardés ce mois-ci et génère un profil concis.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :
📊 *Tendance du mois* : [1 phrase — le thème principal qui se dégage]
🔎 *Centres d'intérêt* : [3-5 mots-clés séparés par des virgules]
💡 *Insight* : [1 phrase — observation surprenante ou pattern de lecture]

RÈGLES STRICTES :
- Factuel : base-toi UNIQUEMENT sur les titres fournis
- Concis : chaque section en une seule phrase ou ligne
- En français
- N'invente aucun titre ou article
PROMPT;

        try {
            $aiAnalysis = $this->claude->chat(
                "Titres des bookmarks de ce mois :\n{$titleList}",
                ModelResolver::fast(),
                $systemPrompt,
            );
        } catch (\Throwable $e) {
            Log::warning('[content_curator] recap mensuel AI failed', ['error' => $e->getMessage()]);
            $aiAnalysis = null;
        }

        $output  = "*📅 RÉCAP MENSUEL — {$monthName}*\n\n";
        $output .= "*📚 Bookmarks :* {$count} ce mois · {$totalAll} au total\n";
        $output .= "*📰 Digests consultés :* {$digestCount}\n";
        $output .= "*📆 Jours actifs :* {$activeDays}/{$totalDays}\n";

        if ($bestWeek) {
            $output .= "*🏆 Semaine la plus active :* S{$bestWeek} ({$bestWeekCount} bookmarks)\n";
        }

        if (!empty($topSources)) {
            $output .= "\n*🌐 Top sources du mois :*\n";
            $rank = 0;
            foreach ($topSources as $host => $cnt) {
                $rank++;
                $output .= "  {$rank}. {$host} ({$cnt})\n";
            }
        }

        if ($aiAnalysis) {
            $output .= "\n{$aiAnalysis}\n";
        }

        // Progression par rapport au mois dernier
        $lastMonthCount = SavedArticle::where('user_phone', $userPhone)
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()])
            ->count();

        if ($lastMonthCount > 0) {
            $diff = $count - $lastMonthCount;
            $pct  = (int) round(abs($diff) / max($lastMonthCount, 1) * 100);
            if ($diff > 0) {
                $output .= "\n📈 +{$diff} bookmarks vs le mois dernier (+{$pct}%) — en progression !\n";
            } elseif ($diff < 0) {
                $output .= "\n📉 {$diff} bookmarks vs le mois dernier (-{$pct}%)\n";
            } else {
                $output .= "\n📊 Même rythme que le mois dernier\n";
            }
        }

        $output .= "\n_*bilan semaine* pour le détail hebdo · *analytics bookmarks* pour le dashboard complet_";

        $this->log($context, 'Monthly recap generated', ['month' => $monthName, 'count' => $count]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FILTRER BOOKMARKS PAR TAG (NEW v1.20.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarksByTag(AgentContext $context, string $tag): AgentResult
    {
        $userPhone = $context->from;
        $tag = mb_strtolower(trim($tag));

        if (mb_strlen($tag) > 50) {
            return AgentResult::reply("❌ Tag trop long (max 50 caractères).");
        }

        $articles = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($articles->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        $matched = [];
        foreach ($articles as $i => $article) {
            $tagKey = "content_curator:tags:{$article->id}";
            $tags   = Cache::get($tagKey, []);
            if (in_array($tag, $tags, true)) {
                $matched[] = [
                    'pos'     => $i + 1,
                    'title'   => $article->title ?: $article->url,
                    'url'     => $article->url,
                    'tags'    => $tags,
                    'created' => $article->created_at,
                ];
            }
        }

        if (empty($matched)) {
            return AgentResult::reply(
                "🏷️ Aucun bookmark avec le tag *#{$tag}*.\n\n"
                . "_Utilise *mes tags* pour voir tes tags existants._"
            );
        }

        $output = "*🏷️ Bookmarks tagués #{$tag}* — " . count($matched) . " résultat(s)\n\n";

        foreach (array_slice($matched, 0, 15) as $idx => $bm) {
            $num   = $idx + 1;
            $title = mb_strimwidth($bm['title'], 0, 65, '...');
            $pos   = $bm['pos'];
            $ago   = $bm['created'] ? $bm['created']->diffForHumans() : '';
            $otherTags = array_filter($bm['tags'], fn($t) => $t !== $tag);
            $tagStr = !empty($otherTags) ? ' · ' . implode(' ', array_map(fn($t) => "#{$t}", array_slice($otherTags, 0, 3))) : '';

            $output .= "{$num}. *{$title}*\n";
            $output .= "   📍 #{$pos}{$tagStr}";
            if ($ago) $output .= " · _{$ago}_";
            $output .= "\n";
            $output .= "   🔗 {$bm['url']}\n\n";
        }

        if (count($matched) > 15) {
            $output .= "_+" . (count($matched) - 15) . " autres bookmarks avec ce tag…_\n\n";
        }

        $output .= "_*lire #N* pour résumer · *similaire #N* pour trouver des articles proches_";

        $this->log($context, 'Bookmarks filtered by tag', ['tag' => $tag, 'count' => count($matched)]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTO-TAG IA (NEW v1.20.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleAutoTag(AgentContext $context, int $position): AgentResult
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
        $source  = $article->source ?? '';
        $url     = $article->url;

        $tagKey       = "content_curator:tags:{$article->id}";
        $existingTags = Cache::get($tagKey, []);

        if (count($existingTags) >= 10) {
            return AgentResult::reply(
                "❌ Ce bookmark a déjà 10 tags (maximum). Supprime des tags avec *tag #{$position} supprimer* avant d'en ajouter."
            );
        }

        try {
            $existingStr = !empty($existingTags) ? "\nTags existants (à ne PAS répéter) : " . implode(', ', $existingTags) : '';

            $systemPrompt = <<<PROMPT
Tu es un expert en classification de contenu. Analyse le titre et la source d'un article et propose 3 tags pertinents.

FORMAT DE RÉPONSE (JSON uniquement) :
{"tags": ["tag1", "tag2", "tag3"]}

RÈGLES STRICTES :
- Exactement 3 tags, en minuscules, sans espaces (utilise des tirets si nécessaire)
- Tags courts (1-2 mots max, ex: "ia", "react", "cybersécurité", "open-source")
- Pertinents au contenu réel de l'article
- En français de préférence, anglais accepté pour les termes techniques courants
- Ne répète jamais un tag existant{$existingStr}
- Retourne UNIQUEMENT le JSON
PROMPT;

            $response = $this->claude->chat(
                "Titre : {$title}\nSource : {$source}\nURL : {$url}",
                ModelResolver::fast(),
                $systemPrompt
            );

            if (!$response) {
                return AgentResult::reply("❌ L'IA n'a pas pu analyser cet article. Réessaie dans quelques instants.");
            }

            $decoded = $this->parseJsonResponse($response);
            $newTags = $decoded['tags'] ?? [];

            if (empty($newTags)) {
                return AgentResult::reply("❌ Impossible de déterminer des tags pour cet article. Utilise *tag #{$position} [tag]* pour taguer manuellement.");
            }

            // Clean and validate tags
            $newTags = array_map(fn($t) => mb_strtolower(trim($t)), $newTags);
            $newTags = array_filter($newTags, fn($t) => $t !== '' && mb_strlen($t) <= 50);
            $newTags = array_values(array_filter($newTags, fn($t) => !in_array($t, $existingTags, true)));

            if (empty($newTags)) {
                return AgentResult::reply("🏷️ L'IA n'a trouvé que des tags déjà existants pour ce bookmark. Il est bien tagué !");
            }

            $merged = array_values(array_unique(array_merge($existingTags, $newTags)));
            $merged = array_slice($merged, 0, 10); // Enforce max 10

            Cache::put($tagKey, $merged, 86400 * 365);
            $this->log($context, 'Bookmark auto-tagged', ['bookmark_id' => $article->id, 'new_tags' => $newTags, 'all_tags' => $merged]);

            $titleShort = mb_strimwidth($title, 0, 60, '...');
            $newTagStr  = implode(', ', array_map(fn($t) => "#{$t}", $newTags));
            $allTagStr  = implode(', ', array_map(fn($t) => "#{$t}", $merged));

            return AgentResult::reply(
                "🤖🏷️ Auto-tag bookmark #{$position} !\n\n"
                . "*{$titleShort}*\n"
                . "Tags ajoutés : {$newTagStr}\n"
                . "Tous les tags : {$allTagStr}\n\n"
                . "_*bookmarks tag {$newTags[0]}* pour filtrer · *tag #{$position} supprimer* pour effacer_"
            );

        } catch (\Throwable $e) {
            Log::error('[content_curator] Auto-tag failed', [
                'bookmark_id' => $article->id,
                'error'       => $e->getMessage(),
            ]);
            return AgentResult::reply("❌ Erreur lors de l'auto-tagging. Réessaie ou utilise *tag #{$position} [tag]* manuellement.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTO-TAG ALL (NEW v1.21.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleAutoTagAll(AgentContext $context): AgentResult
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

        // Find untagged bookmarks
        $untagged = [];
        foreach ($articles as $i => $article) {
            $tagKey = "content_curator:tags:{$article->id}";
            $tags   = Cache::get($tagKey, []);
            if (empty($tags)) {
                $untagged[] = ['index' => $i, 'article' => $article, 'pos' => $i + 1];
            }
        }

        if (empty($untagged)) {
            $totalTags = 0;
            foreach ($articles as $article) {
                $totalTags += count(Cache::get("content_curator:tags:{$article->id}", []));
            }
            return AgentResult::reply(
                "✅ Tous tes bookmarks sont déjà tagués !\n\n"
                . "📊 {$articles->count()} bookmark(s) · {$totalTags} tag(s) au total\n\n"
                . "_*mes tags* pour voir tes tags · *bookmarks tag [tag]* pour filtrer_"
            );
        }

        // Limit batch to 10 to avoid rate limiting / timeout
        $batch    = array_slice($untagged, 0, 10);
        $tagged   = 0;
        $failed   = 0;
        $results  = [];

        $this->log($context, 'Auto-tag all started', [
            'total_bookmarks' => $articles->count(),
            'untagged'        => count($untagged),
            'batch_size'      => count($batch),
        ]);

        foreach ($batch as $item) {
            $article = $item['article'];
            $title   = $article->title ?: $article->url;
            $source  = $article->source ?? '';
            $url     = $article->url;

            try {
                $systemPrompt = <<<PROMPT
Tu es un expert en classification de contenu. Analyse le titre et la source d'un article et propose 3 tags pertinents.

FORMAT DE RÉPONSE (JSON uniquement) :
{"tags": ["tag1", "tag2", "tag3"]}

RÈGLES STRICTES :
- Exactement 3 tags, en minuscules, sans espaces (utilise des tirets si nécessaire)
- Tags courts (1-2 mots max, ex: "ia", "react", "cybersécurité", "open-source")
- Pertinents au contenu réel de l'article
- En français de préférence, anglais accepté pour les termes techniques courants
- Retourne UNIQUEMENT le JSON
PROMPT;

                $response = $this->claude->chat(
                    "Titre : {$title}\nSource : {$source}\nURL : {$url}",
                    ModelResolver::fast(),
                    $systemPrompt
                );

                if (!$response) {
                    $failed++;
                    continue;
                }

                $decoded = $this->parseJsonResponse($response);
                $newTags = $decoded['tags'] ?? [];

                if (empty($newTags)) {
                    $failed++;
                    continue;
                }

                $newTags = array_map(fn($t) => mb_strtolower(trim($t)), $newTags);
                $newTags = array_filter($newTags, fn($t) => $t !== '' && mb_strlen($t) <= 50);
                $newTags = array_values($newTags);

                if (empty($newTags)) {
                    $failed++;
                    continue;
                }

                $tagKey = "content_curator:tags:{$article->id}";
                Cache::put($tagKey, array_slice($newTags, 0, 10), 86400 * 365);
                $tagged++;

                $titleShort = mb_strimwidth($title, 0, 45, '...');
                $tagStr     = implode(', ', array_map(fn($t) => "#{$t}", $newTags));
                $results[]  = "#{$item['pos']} {$titleShort} → {$tagStr}";

            } catch (\Throwable $e) {
                Log::warning('[content_curator] Auto-tag batch item failed', [
                    'bookmark_id' => $article->id,
                    'error'       => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $remaining = count($untagged) - count($batch);

        $output  = "*🤖🏷️ Auto-tag en masse — Résultat*\n\n";
        $output .= "✅ {$tagged} bookmark(s) tagué(s)";
        if ($failed > 0) $output .= " · ⚠️ {$failed} échec(s)";
        $output .= "\n\n";

        if (!empty($results)) {
            foreach ($results as $line) {
                $output .= "  • {$line}\n";
            }
            $output .= "\n";
        }

        if ($remaining > 0) {
            $output .= "📋 {$remaining} bookmark(s) non tagué(s) restant(s).\n";
            $output .= "_Relance *auto tag all* pour continuer le batch suivant._\n\n";
        } else {
            $output .= "🎉 Tous tes bookmarks sont maintenant tagués !\n\n";
        }

        $output .= "_*mes tags* pour voir tes tags · *bookmarks tag [tag]* pour filtrer_";

        $this->log($context, 'Auto-tag all completed', ['tagged' => $tagged, 'failed' => $failed, 'remaining' => $remaining]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK OVERVIEW (NEW v1.21.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkOverview(AgentContext $context): AgentResult
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

        $total     = $articles->count();
        $tagCounts = [];
        $untagged  = 0;
        $sourceCounts = [];
        $weekCount = 0;
        $monthCount = 0;
        $noteCount = 0;
        $weekAgo   = now()->subWeek();
        $monthAgo  = now()->subMonth();

        foreach ($articles as $article) {
            // Tag stats
            $tagKey = "content_curator:tags:{$article->id}";
            $tags   = Cache::get($tagKey, []);
            if (empty($tags)) {
                $untagged++;
            } else {
                foreach ($tags as $t) {
                    $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
                }
            }

            // Source stats
            $source = $article->source ?? parse_url($article->url, PHP_URL_HOST) ?? 'inconnu';
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;

            // Time stats
            if ($article->created_at >= $weekAgo) $weekCount++;
            if ($article->created_at >= $monthAgo) $monthCount++;

            // Note stats
            $noteKey = "content_curator:note:{$article->id}";
            if (Cache::has($noteKey)) $noteCount++;
        }

        // Sort tags by frequency
        arsort($tagCounts);
        arsort($sourceCounts);

        $output  = "*📊 Panorama de ta bibliothèque*\n\n";
        $output .= "📚 *{$total}* bookmark(s) au total\n";
        $output .= "📅 {$weekCount} cette semaine · {$monthCount} ce mois\n";
        if ($noteCount > 0) $output .= "📝 {$noteCount} bookmark(s) annotés\n";
        $output .= "\n";

        // Top tags
        if (!empty($tagCounts)) {
            $output .= "*🏷️ Top tags :*\n";
            $displayTags = array_slice($tagCounts, 0, 8, true);
            foreach ($displayTags as $tag => $count) {
                $bar = str_repeat('█', min($count, 10));
                $output .= "  #{$tag} {$bar} {$count}\n";
            }
            if (count($tagCounts) > 8) {
                $output .= "  _+" . (count($tagCounts) - 8) . " autres tags_\n";
            }
            $output .= "\n";
        }

        if ($untagged > 0) {
            $pct = round(($untagged / $total) * 100);
            $output .= "⚠️ {$untagged} bookmark(s) sans tag ({$pct}%)\n";
            $output .= "_*auto tag all* pour les taguer automatiquement_\n\n";
        }

        // Top sources
        if (!empty($sourceCounts)) {
            $output .= "*📰 Top sources :*\n";
            $displaySources = array_slice($sourceCounts, 0, 5, true);
            foreach ($displaySources as $source => $count) {
                $output .= "  • {$source} ({$count})\n";
            }
            $output .= "\n";
        }

        // Reading streak
        $streak = $this->computeStreak($userPhone);
        if ($streak > 0) {
            $output .= "🔥 Streak : {$streak} jour(s) consécutifs\n\n";
        }

        // Reading goal progress
        $goalKey  = "content_curator:reading_goal:{$userPhone}";
        $goal     = Cache::get($goalKey);
        if ($goal) {
            $output .= "🎯 Objectif : {$weekCount}/{$goal} cette semaine\n\n";
        }

        $output .= "_*mes tags* · *analytics bookmarks* · *profil lecture* · *auto tag all*_";

        $this->log($context, 'Bookmark overview', ['total' => $total, 'tags' => count($tagCounts), 'untagged' => $untagged]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COUNT BOOKMARKS (NEW v1.22.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCountBookmarks(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "📚 Tu n'as aucun bookmark pour le moment.\n"
                . "_Utilise *save [url]* pour commencer ta collection._"
            );
        }

        $thisWeek = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();
        $thisMonth = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $streak = $this->computeStreak($userPhone);

        $output = "*📚 TES BOOKMARKS EN CHIFFRES*\n\n";
        $output .= "📖 Total : *{$total}* bookmark" . ($total > 1 ? 's' : '') . "\n";
        $output .= "📅 Cette semaine : *{$thisWeek}*\n";
        $output .= "🗓️ Ce mois : *{$thisMonth}*\n";
        if ($streak > 0) {
            $output .= "🔥 Streak digest : *{$streak} jour" . ($streak > 1 ? 's' : '') . "*\n";
        }
        $output .= "\n_💡 *mes bookmarks* · *analytics bookmarks* · *profil lecture*_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BATCH SAVE (NEW v1.22.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBatchSave(AgentContext $context, string $input): AgentResult
    {
        $userPhone = $context->from;

        // Rate limit: max 1 batch save per 60s per user
        $throttleKey = "content_curator:batch_save_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends une minute avant de relancer *batch save*.");
        }
        Cache::put($throttleKey, true, 60);

        preg_match_all('#https?://[^\s<>\[\]"\']+#i', $input, $matches);
        $urls = array_values(array_unique($matches[0] ?? []));

        if (count($urls) < 2) {
            return AgentResult::reply(
                "❌ Envoie au moins 2 URLs pour un batch save.\n"
                . "_Exemple : *batch save https://url1.com https://url2.com*_\n"
                . "_Pour une seule URL, utilise *save [url]*._"
            );
        }

        if (count($urls) > 10) {
            return AgentResult::reply("❌ Maximum 10 URLs par batch (tu en as envoyé " . count($urls) . ").");
        }

        $saved    = [];
        $skipped  = [];
        $errors   = [];

        foreach ($urls as $url) {
            if (mb_strlen($url) > 2048) {
                $errors[] = mb_strimwidth($url, 0, 40, '...') . ' (trop longue)';
                continue;
            }

            if ($this->isPrivateUrl($url)) {
                $errors[] = mb_strimwidth($url, 0, 40, '...') . ' (URL privée)';
                continue;
            }

            $normalizedUrl = $this->normalizeUrl($url);
            $existing = SavedArticle::where('user_phone', $userPhone)
                ->where(function ($q) use ($url, $normalizedUrl) {
                    $q->where('url', $url)->orWhere('url', $normalizedUrl);
                })
                ->first();

            if ($existing) {
                $skipped[] = $existing->title ?: mb_strimwidth($url, 0, 40, '...');
                continue;
            }

            $title  = $this->fetchPageTitle($url);
            $source = parse_url($url, PHP_URL_HOST) ?: 'unknown';
            $source = preg_replace('/^www\./i', '', $source);

            SavedArticle::create([
                'user_phone' => $userPhone,
                'url'        => $url,
                'title'      => $title,
                'source'     => $source,
            ]);

            $saved[] = $title ?: mb_strimwidth($url, 0, 50, '...');
        }

        $this->log($context, 'Batch save', [
            'saved' => count($saved), 'skipped' => count($skipped), 'errors' => count($errors),
        ]);

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        $output = "*🔖 BATCH SAVE — Résultat*\n\n";

        if (!empty($saved)) {
            $output .= "✅ *" . count($saved) . " sauvegardé" . (count($saved) > 1 ? 's' : '') . " :*\n";
            foreach ($saved as $i => $t) {
                $output .= "  " . ($i + 1) . ". {$t}\n";
            }
            $output .= "\n";
        }

        if (!empty($skipped)) {
            $output .= "⏭️ *" . count($skipped) . " déjà existant" . (count($skipped) > 1 ? 's' : '') . " :*\n";
            foreach ($skipped as $t) {
                $output .= "  • _{$t}_\n";
            }
            $output .= "\n";
        }

        if (!empty($errors)) {
            $output .= "❌ *" . count($errors) . " erreur" . (count($errors) > 1 ? 's' : '') . " :*\n";
            foreach ($errors as $e) {
                $output .= "  • _{$e}_\n";
            }
            $output .= "\n";
        }

        $output .= "📚 Total : {$total} bookmark" . ($total > 1 ? 's' : '') . "\n";
        $output .= "_💡 *mes bookmarks* pour voir ta liste_";

        if (empty($saved)) {
            $output = "⏭️ Aucun nouveau bookmark sauvegardé — tous existent déjà ou sont invalides.\n"
                . "_Dis *mes bookmarks* pour voir ta liste._";
        }

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // URL NORMALIZATION HELPER (NEW v1.22.0)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalize a URL for duplicate detection: strip trailing slash, www, tracking params.
     */
    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        $host = mb_strtolower(preg_replace('/^www\./i', '', $parsed['host']));
        $path = rtrim($parsed['path'] ?? '', '/');
        $scheme = $parsed['scheme'] ?? 'https';

        // Strip common tracking query params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ref', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];
            foreach ($trackingParams as $tp) {
                unset($params[$tp]);
            }
            if (!empty($params)) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        $fragment = '';
        // Keep fragments only if they look content-relevant (not tracking)
        if (!empty($parsed['fragment']) && !preg_match('/^(utm_|ref=|comment-)/i', $parsed['fragment'])) {
            $fragment = '#' . $parsed['fragment'];
        }

        return "{$scheme}://{$host}{$path}{$query}{$fragment}";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BATCH TLDR — Résumé express de bookmarks récents (NEW v1.22.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBatchTldr(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour commencer ta collection._"
            );
        }

        $this->log($context, 'Batch TLDR requested', ['count' => $bookmarks->count()]);

        // Check cache first
        $cacheFingerprint = $bookmarks->pluck('id')->implode('-');
        $cacheKey = "content_curator:batch_tldr:{$userPhone}:" . md5($cacheFingerprint);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        try {
            $output = "*⚡ BATCH TLDR — Tes " . $bookmarks->count() . " derniers bookmarks*\n\n";
            $processed = 0;

            foreach ($bookmarks as $i => $bookmark) {
                $url   = $bookmark->url;
                $title = $bookmark->title ?: 'Sans titre';
                $num   = $i + 1;

                // Check if individual TLDR is already cached
                $individualCacheKey = "content_curator:tldr:" . md5($url);
                $individualCached = Cache::get($individualCacheKey);

                if ($individualCached) {
                    // Extract just the TLDR points from cached version
                    $output .= "*{$num}. {$title}*\n";
                    if (preg_match('/⚡ \*TLDR\*\n((?:• .+\n)+)/s', $individualCached, $tldrMatch)) {
                        $output .= trim($tldrMatch[1]) . "\n\n";
                    } else {
                        $output .= "_(résumé disponible : *tldr {$url}*)_\n\n";
                    }
                    $processed++;
                    continue;
                }

                // Fetch and summarize
                $content = $this->extractHtmlContent($url);
                if (!$content['ok'] || mb_strlen($content['text']) < 80) {
                    $output .= "*{$num}. {$title}*\n";
                    $output .= "  ⚠ _Contenu inaccessible_\n";
                    $output .= "  🔗 {$url}\n\n";
                    continue;
                }

                $text = $content['text'];
                if ($content['desc'] && mb_strlen($content['desc']) > 30) {
                    $text = $content['desc'] . "\n\n" . $text;
                }
                $excerpt = mb_substr($text, 0, 1500);

                $systemPrompt = <<<PROMPT
Résume cet article en exactement 2 points ultra-concis (max 60 caractères chacun).
FORMAT: un point par ligne, préfixé par "• ", en français. RIEN D'AUTRE.
PROMPT;

                $userMsg = "Titre: {$title}\nContenu:\n{$excerpt}";
                $tldr = $this->claude->chat($userMsg, ModelResolver::fast(), $systemPrompt);

                $output .= "*{$num}. {$title}*\n";
                if ($tldr) {
                    $lines = array_filter(explode("\n", trim($tldr)), fn($l) => str_starts_with(trim($l), '•'));
                    $output .= implode("\n", array_slice($lines, 0, 2)) . "\n";
                } else {
                    $output .= "  ⚠ _Résumé indisponible_\n";
                }
                $output .= "  🔗 {$url}\n\n";
                $processed++;
            }

            $output .= "_💡 *tldr [url]* pour un résumé détaillé · *résume [url]* pour l'analyse complète_";

            Cache::put($cacheKey, $output, 1800); // 30 min

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] BatchTldr failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du batch TLDR. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SOURCE DISCOVERY — Découverte IA de nouvelles sources (NEW v1.22.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSourceDiscovery(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        // Gather reading profile
        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        $keywords   = $prefs->pluck('keywords')->flatten()->filter()->values()->toArray();

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Compute current sources from bookmarks
        $currentSources = [];
        foreach ($bookmarks as $b) {
            $host = $b->source ?? parse_url($b->url, PHP_URL_HOST) ?? '';
            $host = preg_replace('/^www\./i', '', mb_strtolower($host));
            if ($host) {
                $currentSources[$host] = ($currentSources[$host] ?? 0) + 1;
            }
        }
        arsort($currentSources);

        if (empty($categories) && empty($keywords) && empty($currentSources)) {
            return AgentResult::reply(
                "*🔎 DÉCOUVRIR DES SOURCES*\n\n"
                . "Je n'ai pas assez de données pour te recommander des sources.\n\n"
                . "_Pour commencer :_\n"
                . "• *follow tech* — Suis une catégorie\n"
                . "• *save [url]* — Sauvegarde quelques articles\n"
                . "• *digest* — Génère un premier digest\n\n"
                . "_Plus tu utilises le curator, meilleures sont les recommandations._"
            );
        }

        // Cache 6h per user
        $cacheKey = "content_curator:source_discovery:{$userPhone}:" . now()->format('Y-m-d-H');
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Source discovery requested', [
            'categories' => $categories,
            'current_sources' => array_slice(array_keys($currentSources), 0, 5),
        ]);

        try {
            $profileText = "Profil de lecture :\n";
            if (!empty($categories)) {
                $profileText .= "- Catégories : " . implode(', ', $categories) . "\n";
            }
            if (!empty($keywords)) {
                $profileText .= "- Mots-clés : " . implode(', ', array_slice($keywords, 0, 8)) . "\n";
            }
            if (!empty($currentSources)) {
                $profileText .= "- Sources actuelles (déjà connues) : " . implode(', ', array_slice(array_keys($currentSources), 0, 10)) . "\n";
            }
            $recentTitles = $bookmarks->pluck('title')->filter()->take(8)->implode(' | ');
            if ($recentTitles) {
                $profileText .= "- Articles récents : {$recentTitles}\n";
            }

            $systemPrompt = <<<PROMPT
Tu es un expert en veille informationnelle. À partir du profil de lecture, recommande 5 NOUVELLES sources (sites, blogs, newsletters) que l'utilisateur ne connaît probablement PAS encore.

FORMAT JSON strict :
[
  {"name": "Nom du site", "url": "https://...", "description": "1 phrase — pourquoi cette source est pertinente", "category": "catégorie principale", "type": "blog|newsletter|média|communauté|agrégateur"},
  ...
]

RÈGLES :
- 5 sources exactement, toutes DIFFÉRENTES des sources actuelles de l'utilisateur
- Privilégier des sources de qualité (contenu original, experts reconnus, pas du clickbait)
- Mélanger les types (pas que des blogs ou que des médias)
- URLs réelles et vérifiables de sites existants connus
- Descriptions en français, concises (max 80 caractères)
- Retourne UNIQUEMENT le JSON
PROMPT;

            $response = $this->claude->chat($profileText, ModelResolver::fast(), $systemPrompt);
            $sources  = $response ? $this->parseJsonResponse($response) : null;

            if (!$sources || empty($sources)) {
                return AgentResult::reply(
                    "❌ Impossible de générer des recommandations de sources.\n"
                    . "_Essaie *recommande* pour des sujets, ou *trending* pour du contenu populaire._"
                );
            }

            $sources = array_slice($sources, 0, 5);
            $typeIcons = [
                'blog' => '✍️', 'newsletter' => '📧', 'média' => '📰',
                'communauté' => '👥', 'agrégateur' => '🔗', 'media' => '📰',
            ];

            $output  = "*🔎 SOURCES RECOMMANDÉES POUR TOI*\n";
            $output .= "_Basé sur tes " . count($currentSources) . " sources actuelles et tes intérêts_\n\n";

            foreach ($sources as $i => $src) {
                $num  = $i + 1;
                $name = $src['name'] ?? 'Source';
                $url  = $src['url'] ?? '';
                $desc = $src['description'] ?? '';
                $type = mb_strtolower($src['type'] ?? '');
                $icon = $typeIcons[$type] ?? '🌐';

                $output .= "*{$num}. {$icon} {$name}*";
                if ($type) $output .= " _{$type}_";
                $output .= "\n";
                if ($desc) $output .= "  ↳ {$desc}\n";
                if ($url) $output .= "  🔗 {$url}\n";
                $output .= "\n";
            }

            if (!empty($currentSources)) {
                $topCurrent = array_slice(array_keys($currentSources), 0, 3);
                $output .= "_Tes sources préférées actuelles : " . implode(', ', $topCurrent) . "_\n";
            }
            $output .= "_💡 *top sources* pour voir tes stats · *follow [mot-clé]* pour affiner ton profil_";

            Cache::put($cacheKey, $output, 21600); // 6h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] SourceDiscovery failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la découverte de sources. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOPIC DEEP DIVE (NEW v1.23.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTopicDeepDive(AgentContext $context, string $topic): AgentResult
    {
        $userPhone = $context->from;

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply(
                "Précise un sujet pour le deep dive.\n"
                . "_Exemple : *deep dive React Server Components* ou *approfondir cybersécurité 2026*_"
            );
        }

        if (mb_strlen($topic) > 150) {
            return AgentResult::reply("Sujet trop long. Utilise quelques mots-clés (max 150 caractères).");
        }

        // Cache 2h per user+topic
        $cacheKey = "content_curator:deepdive:{$userPhone}:" . md5(mb_strtolower($topic));
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Topic deep dive requested', ['topic' => mb_substr($topic, 0, 80)]);

        try {
            // Fetch articles from multiple angles
            $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
            $userCats = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            $searchCats = !empty($userCats) ? array_slice($userCats, 0, 4) : ['technology', 'science', 'business'];

            $articles = $this->aggregator->aggregate($searchCats, [mb_strtolower($topic)], 12);

            // Build context from articles
            $articlesContext = '';
            $articleLinks = [];
            foreach (array_slice($articles, 0, 8) as $i => $a) {
                $title = $a['title'] ?? 'Sans titre';
                $desc = mb_strimwidth($a['description'] ?? '', 0, 200, '...');
                $url = $a['url'] ?? '';
                $articlesContext .= "Article " . ($i + 1) . ": {$title}\n{$desc}\n\n";
                if ($url) {
                    $articleLinks[] = ['title' => $title, 'url' => $url, 'source' => $a['source'] ?? ''];
                }
            }

            // Also check user bookmarks for related content
            $relatedBookmarks = SavedArticle::where('user_phone', $userPhone)
                ->where(function ($q) use ($topic) {
                    $words = array_filter(preg_split('/\s+/', mb_strtolower($topic)), fn($w) => mb_strlen($w) >= 3);
                    foreach ($words as $word) {
                        $q->orWhere('title', 'LIKE', "%{$word}%");
                    }
                })
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            $bookmarkContext = '';
            if ($relatedBookmarks->isNotEmpty()) {
                $bookmarkContext = "\n\nBookmarks de l'utilisateur sur ce sujet :\n";
                foreach ($relatedBookmarks as $b) {
                    $bookmarkContext .= "- {$b->title} ({$b->source})\n";
                }
            }

            $currentDate = now()->translatedFormat('F Y');
            $systemPrompt = <<<PROMPT
Tu es un analyste expert en veille stratégique (date actuelle : {$currentDate}). L'utilisateur demande un DEEP DIVE sur un sujet. Génère une analyse structurée et approfondie.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

📌 *Contexte* : [2-3 phrases — de quoi parle-t-on, pourquoi c'est important maintenant]

🔑 *Points essentiels* :
• [fait/tendance majeur 1 avec chiffres si disponibles]
• [fait/tendance majeur 2]
• [fait/tendance majeur 3]
• [fait/tendance majeur 4]
• [fait/tendance majeur 5]

⚡ *Tendances actuelles* :
• [ce qui émerge ou change en ce moment]
• [ce qui est controversé ou débattu]

🎯 *Pour aller plus loin* :
• [angle ou sous-sujet à explorer]
• [compétence ou concept à approfondir]

RÈGLES STRICTES :
- Base-toi UNIQUEMENT sur les articles fournis, ne fabrique pas d'informations
- Style journalistique, chiffres concrets quand disponibles
- En français, même si les sources sont en anglais
- Max 400 mots au total
- Si les articles sont insuffisants sur ce sujet, indique-le honnêtement
PROMPT;

            $userMessage = "Sujet du deep dive : {$topic}\n\nArticles trouvés :\n{$articlesContext}{$bookmarkContext}";

            $analysis = $this->claude->chat($userMessage, ModelResolver::balanced(), $systemPrompt);

            if (!$analysis) {
                return AgentResult::reply("❌ Impossible de générer le deep dive. Réessaie dans quelques instants.");
            }

            $output = "*🔬 DEEP DIVE — " . mb_strimwidth($topic, 0, 50, '...') . "*\n";
            $output .= "_Analyse approfondie · " . now()->format('d/m H:i') . "_\n\n";
            $output .= $analysis . "\n\n";

            // Add source links
            if (!empty($articleLinks)) {
                $output .= "*📎 Sources consultées :*\n";
                foreach (array_slice($articleLinks, 0, 4) as $i => $link) {
                    $output .= ($i + 1) . ". " . mb_strimwidth($link['title'], 0, 60, '...') . "\n";
                    $output .= "   🔗 {$link['url']}\n";
                }
                $output .= "\n";
            }

            if ($relatedBookmarks->isNotEmpty()) {
                $output .= "_📚 Tu as " . $relatedBookmarks->count() . " bookmark(s) sur ce sujet._\n";
            }

            $output .= "_💡 *save [url]* pour bookmarker · *cherche {$topic}* pour plus d'articles · *digest sur {$topic}*_";

            Cache::put($cacheKey, $output, 7200); // 2h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] TopicDeepDive failed for '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du deep dive. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK REMINDER — Resurface forgotten bookmarks (NEW v1.23.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkReminder(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        // Get bookmarks older than 7 days
        $oldBookmarks = SavedArticle::where('user_phone', $userPhone)
            ->where('created_at', '<', now()->subDays(7))
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        if ($oldBookmarks->isEmpty()) {
            return AgentResult::reply(
                "*🔔 RAPPEL BOOKMARKS*\n\n"
                . "Tu n'as aucun bookmark de plus d'une semaine.\n"
                . "_Sauvegarde des articles avec *save [url]* et je te les rappellerai plus tard !_"
            );
        }

        $totalOld = $oldBookmarks->count();

        // Pick 3 random old bookmarks to suggest
        $suggestions = $oldBookmarks->random(min(3, $totalOld));

        $this->log($context, 'Bookmark reminder', ['total_old' => $totalOld, 'suggested' => $suggestions->count()]);

        $output = "*🔔 RAPPEL — Bookmarks oubliés*\n";
        $output .= "_Tu as {$totalOld} bookmark(s) de plus d'une semaine_\n\n";
        $output .= "*Voici " . $suggestions->count() . " article(s) à (re)découvrir :*\n\n";

        foreach ($suggestions->values() as $i => $b) {
            $num = $i + 1;
            $title = $b->title ?: 'Sans titre';
            $source = $b->source ?? '';
            $age = $b->created_at->diffForHumans();
            $tags = is_array($b->tags) ? $b->tags : (is_string($b->tags) ? json_decode($b->tags, true) ?? [] : []);
            $tagStr = !empty($tags) ? ' ' . implode(' ', array_map(fn($t) => "#{$t}", array_slice($tags, 0, 3))) : '';

            $output .= "*{$num}. {$title}*";
            if ($source) $output .= " _{$source}_";
            $output .= "\n";
            $output .= "   ⏰ Sauvegardé {$age}{$tagStr}\n";
            if ($b->url) $output .= "   🔗 {$b->url}\n";
            $output .= "\n";
        }

        // Age breakdown
        $weekOld = $oldBookmarks->filter(fn($b) => $b->created_at->diffInDays(now()) <= 14)->count();
        $monthOld = $oldBookmarks->filter(fn($b) => $b->created_at->diffInDays(now()) <= 30)->count();
        $ancientCount = $totalOld - $monthOld;

        $output .= "*📊 Ancienneté :*\n";
        $output .= "  • 1-2 semaines : {$weekOld}\n";
        $output .= "  • 2-4 semaines : " . ($monthOld - $weekOld) . "\n";
        if ($ancientCount > 0) {
            $output .= "  • > 1 mois : {$ancientCount} _(pense à faire le tri !)_\n";
        }
        $output .= "\n";

        $output .= "_💡 *lire #N* pour résumer · *supprimer N* pour nettoyer · *mes bookmarks* pour tout voir_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK DUPLICATES — Detect and list duplicate bookmarks (NEW v1.24.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkDuplicates(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->count() < 2) {
            return AgentResult::reply(
                "Tu as moins de 2 bookmarks — pas de doublons possibles.\n"
                . "_Utilise *save [url]* pour commencer ta collection._"
            );
        }

        $this->log($context, 'Duplicate bookmarks scan', ['total' => $bookmarks->count()]);

        // Group 1: exact URL duplicates (normalized)
        $urlGroups = [];
        foreach ($bookmarks as $bm) {
            $normalizedUrl = $this->normalizeUrl($bm->url);
            $urlGroups[$normalizedUrl][] = $bm;
        }

        $exactDuplicates = array_filter($urlGroups, fn($group) => count($group) > 1);

        // Group 2: same domain + very similar titles (Levenshtein)
        $similarPairs = [];
        $bmArray = $bookmarks->values()->all();
        $totalBm = count($bmArray);
        $checkedPairs = 0;
        $maxChecks = 5000; // prevent O(n^2) explosion on large libraries

        for ($i = 0; $i < $totalBm && $checkedPairs < $maxChecks; $i++) {
            for ($j = $i + 1; $j < $totalBm && $checkedPairs < $maxChecks; $j++) {
                $checkedPairs++;
                $a = $bmArray[$i];
                $b = $bmArray[$j];

                // Skip if already caught as exact URL duplicate
                $normA = $this->normalizeUrl($a->url);
                $normB = $this->normalizeUrl($b->url);
                if ($normA === $normB) continue;

                // Check same domain
                $hostA = parse_url($a->url, PHP_URL_HOST);
                $hostB = parse_url($b->url, PHP_URL_HOST);
                if (!$hostA || !$hostB) continue;
                $hostA = preg_replace('/^www\./i', '', mb_strtolower($hostA));
                $hostB = preg_replace('/^www\./i', '', mb_strtolower($hostB));
                if ($hostA !== $hostB) continue;

                // Compare titles
                $titleA = mb_strtolower(trim($a->title ?? ''));
                $titleB = mb_strtolower(trim($b->title ?? ''));
                if (empty($titleA) || empty($titleB)) continue;

                $maxLen = max(mb_strlen($titleA), mb_strlen($titleB));
                if ($maxLen === 0) continue;

                $distance = levenshtein(mb_substr($titleA, 0, 255), mb_substr($titleB, 0, 255));
                $similarity = 1 - ($distance / $maxLen);

                if ($similarity >= 0.75) {
                    $similarPairs[] = ['a' => $a, 'b' => $b, 'similarity' => round($similarity * 100)];
                }
            }
        }

        $totalDuplicates = count($exactDuplicates);
        $totalSimilar    = count($similarPairs);

        if ($totalDuplicates === 0 && $totalSimilar === 0) {
            return AgentResult::reply(
                "✅ *Aucun doublon détecté !*\n"
                . "_Tes " . $bookmarks->count() . " bookmarks sont tous uniques._\n\n"
                . "_💡 *mes bookmarks* pour voir ta collection_"
            );
        }

        $output = "*🔍 DOUBLONS BOOKMARKS*\n";
        $output .= "_Analyse de " . $bookmarks->count() . " bookmarks_\n\n";

        // Show exact duplicates
        if ($totalDuplicates > 0) {
            $output .= "*📋 URLs identiques (" . $totalDuplicates . " groupe" . ($totalDuplicates > 1 ? 's' : '') . ") :*\n\n";
            $shown = 0;
            foreach ($exactDuplicates as $normalizedUrl => $group) {
                if ($shown >= 5) {
                    $output .= "_... et " . ($totalDuplicates - 5) . " autre(s) groupe(s)_\n";
                    break;
                }
                $first = $group[0];
                $output .= "*" . mb_strimwidth($first->title ?? 'Sans titre', 0, 60, '...') . "*\n";
                $output .= "🔗 " . mb_strimwidth($first->url, 0, 60, '...') . "\n";
                $output .= "📅 Sauvegardé " . count($group) . " fois : ";
                $dates = array_map(fn($bm) => $bm->created_at->format('d/m'), $group);
                $output .= implode(', ', $dates) . "\n";

                // Show position numbers for deletion
                foreach (array_slice($group, 1) as $dup) {
                    $pos = $bookmarks->search(fn($b) => $b->id === $dup->id) + 1;
                    $output .= "  _→ *supprimer {$pos}* pour retirer le doublon_\n";
                }
                $output .= "\n";
                $shown++;
            }
        }

        // Show similar bookmarks
        if ($totalSimilar > 0) {
            $output .= "*🔄 Bookmarks similaires (" . $totalSimilar . " paire" . ($totalSimilar > 1 ? 's' : '') . ") :*\n\n";
            $shown = 0;
            foreach (array_slice($similarPairs, 0, 5) as $pair) {
                $posA = $bookmarks->search(fn($b) => $b->id === $pair['a']->id) + 1;
                $posB = $bookmarks->search(fn($b) => $b->id === $pair['b']->id) + 1;
                $output .= "• #{$posA} *" . mb_strimwidth($pair['a']->title ?? 'Sans titre', 0, 45, '...') . "*\n";
                $output .= "  #{$posB} *" . mb_strimwidth($pair['b']->title ?? 'Sans titre', 0, 45, '...') . "*\n";
                $output .= "  _Similarité : {$pair['similarity']}% · *comparer {$posA} et {$posB}* pour voir_\n\n";
                $shown++;
            }
            if ($totalSimilar > 5) {
                $output .= "_... et " . ($totalSimilar - 5) . " autre(s) paire(s) similaire(s)_\n\n";
            }
        }

        $output .= "_💡 *supprimer X* pour retirer un doublon · *comparer X et Y* pour comparer_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPARE BOOKMARKS BY POSITION — Side-by-side comparison (NEW v1.24.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCompareBookmarks(AgentContext $context, int $posA, int $posB): AgentResult
    {
        $userPhone = $context->from;

        if ($posA === $posB) {
            return AgentResult::reply("⚠ Tu ne peux pas comparer un bookmark avec lui-même !");
        }

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour commencer ta collection._"
            );
        }

        $maxPos = $bookmarks->count();
        if ($posA < 1 || $posA > $maxPos || $posB < 1 || $posB > $maxPos) {
            return AgentResult::reply(
                "⚠ Position invalide. Tu as {$maxPos} bookmark" . ($maxPos > 1 ? 's' : '') . " (1 à {$maxPos}).\n"
                . "_Utilise *mes bookmarks* pour voir la liste._"
            );
        }

        $bmA = $bookmarks[$posA - 1];
        $bmB = $bookmarks[$posB - 1];

        $this->log($context, 'Compare bookmarks by position', ['posA' => $posA, 'posB' => $posB]);

        // Check cache
        $cacheKey = "content_curator:compare_bm:" . md5($bmA->url . '|' . $bmB->url);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        try {
            // Extract content from both articles
            $contentA = $this->extractHtmlContent($bmA->url);
            $contentB = $this->extractHtmlContent($bmB->url);

            $textA = ($contentA['ok'] ? mb_substr($contentA['text'], 0, 2000) : '');
            $textB = ($contentB['ok'] ? mb_substr($contentB['text'], 0, 2000) : '');

            // If both fail to extract, provide a basic comparison
            if (empty($textA) && empty($textB)) {
                $output = "*⚖️ COMPARAISON BOOKMARKS*\n\n";
                $output .= "*#$posA :* " . ($bmA->title ?: 'Sans titre') . "\n";
                $output .= "🔗 {$bmA->url}\n";
                $output .= "📅 " . $bmA->created_at->format('d/m/Y') . "\n\n";
                $output .= "*#$posB :* " . ($bmB->title ?: 'Sans titre') . "\n";
                $output .= "🔗 {$bmB->url}\n";
                $output .= "📅 " . $bmB->created_at->format('d/m/Y') . "\n\n";
                $output .= "_⚠ Contenu inaccessible pour les deux articles — comparaison IA impossible._\n";
                $output .= "_💡 Essaie *compare {$bmA->url} {$bmB->url}* directement si les URLs ont changé._";
                return AgentResult::reply($output);
            }

            $systemPrompt = <<<PROMPT
Tu es un analyste de contenu expert. Compare les deux articles ci-dessous et fournis une analyse structurée.

FORMAT (texte WhatsApp) :
📌 *Sujet commun* : [ce qui relie les 2 articles en 1 phrase]

*Article A* : [1 phrase — thèse ou angle principal]
*Article B* : [1 phrase — thèse ou angle principal]

⚖️ *Différences clés* :
• [différence 1 — factuelle et précise]
• [différence 2]
• [différence 3 si pertinent]

🤝 *Points communs* :
• [point commun 1]
• [point commun 2 si pertinent]

🏆 *Verdict* : [lequel est plus complet/récent/pertinent et pourquoi, en 1-2 phrases]

RÈGLES :
- Factuel, pas d'invention
- Si un article est inaccessible, analyse uniquement ce qui est disponible
- Réponds en français
- Max 300 mots
PROMPT;

            $userMessage = "ARTICLE A (#{$posA}) — Titre : " . ($bmA->title ?: 'Inconnu') . "\nURL : {$bmA->url}\n"
                . ($textA ? "Contenu :\n{$textA}" : "(contenu inaccessible)")
                . "\n\n---\n\n"
                . "ARTICLE B (#{$posB}) — Titre : " . ($bmB->title ?: 'Inconnu') . "\nURL : {$bmB->url}\n"
                . ($textB ? "Contenu :\n{$textB}" : "(contenu inaccessible)");

            $comparison = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt, 1024);

            if (!$comparison) {
                return AgentResult::reply("❌ Impossible de générer la comparaison. Réessaie dans quelques instants.");
            }

            $output = "*⚖️ COMPARAISON BOOKMARKS #{$posA} vs #{$posB}*\n\n";
            $output .= "*A :* " . mb_strimwidth($bmA->title ?: 'Sans titre', 0, 60, '...') . "\n";
            $output .= "*B :* " . mb_strimwidth($bmB->title ?: 'Sans titre', 0, 60, '...') . "\n\n";
            $output .= $comparison . "\n\n";
            $output .= "_💡 *lire {$posA}* ou *lire {$posB}* pour le résumé complet_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] CompareBookmarks failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la comparaison. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SMART SEARCH — AI semantic search in bookmarks (NEW v1.25.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSmartSearch(AgentContext $context, string $query): AgentResult
    {
        $userPhone = $context->from;

        if (mb_strlen($query) < 3) {
            return AgentResult::reply(
                "Précise ta recherche (min 3 caractères).\n"
                . "_Exemple : *recherche intelligente articles sur le futur de l'IA*_"
            );
        }

        if (mb_strlen($query) > 200) {
            return AgentResult::reply("Requête trop longue. Reformule en quelques mots (max 200 caractères).");
        }

        $total = SavedArticle::where('user_phone', $userPhone)->count();
        if ($total === 0) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark sauvegardé.\n"
                . "_Utilise *save [url]* pour en ajouter, puis recherche avec l'IA._"
            );
        }

        $this->log($context, 'Smart search requested', ['query' => mb_substr($query, 0, 80)]);

        // Fetch all bookmarks (limit to 100 most recent for performance)
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply("Aucun bookmark trouvé. Sauvegarde des articles d'abord avec *save [url]*.");
        }

        // Build bookmark catalog for LLM
        $catalog = '';
        foreach ($bookmarks as $i => $b) {
            $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 80, '...');
            $source = $b->source ?: '';
            $tags = $b->tags ?? '';
            $note = $b->note ? mb_strimwidth($b->note, 0, 60, '...') : '';
            $date = $b->created_at->format('d/m/Y');
            $catalog .= ($i + 1) . ". [{$date}] {$title}";
            if ($source) $catalog .= " ({$source})";
            if ($tags) $catalog .= " [tags: {$tags}]";
            if ($note) $catalog .= " — note: {$note}";
            $catalog .= "\n";
        }

        $systemPrompt = <<<PROMPT
Tu es un moteur de recherche sémantique intelligent. L'utilisateur recherche dans ses bookmarks sauvegardés.

Ta mission : comprendre L'INTENTION de la recherche et trouver les bookmarks les plus pertinents, même si les mots-clés ne correspondent pas exactement.

Par exemple :
- "articles sur comment coder mieux" → trouver des bookmarks sur clean code, bonnes pratiques, refactoring
- "trucs sur l'argent" → trouver des bookmarks finance, business, investissement
- "ce que j'ai lu sur React" → trouver des bookmarks React, frontend, JavaScript

RÉPONDS UNIQUEMENT en JSON valide, un tableau de numéros de bookmarks pertinents (max 8), du plus pertinent au moins pertinent :
{"matches": [3, 7, 12], "reason": "Explication courte de pourquoi ces résultats"}

Si aucun bookmark ne correspond, retourne : {"matches": [], "reason": "Aucun bookmark pertinent trouvé"}
PROMPT;

        try {
            $response = $this->claude->chat(
                "Recherche : \"{$query}\"\n\nBookmarks disponibles :\n{$catalog}",
                ModelResolver::fast(),
                $systemPrompt,
                1024
            );

            $parsed = $this->parseJsonResponse($response);

            if (!$parsed || !isset($parsed['matches']) || !is_array($parsed['matches']) || empty($parsed['matches'])) {
                return AgentResult::reply(
                    "🧠 Aucun bookmark ne correspond à *{$query}*.\n\n"
                    . "_Ta recherche porte sur {$total} bookmark(s)._\n"
                    . "_Essaie *cherche bookmarks [mot-clé]* pour une recherche par mot-clé classique._"
                );
            }

            $matchIndices = array_filter($parsed['matches'], fn($idx) => $idx >= 1 && $idx <= $bookmarks->count());
            $matchIndices = array_slice($matchIndices, 0, 8);

            if (empty($matchIndices)) {
                return AgentResult::reply(
                    "🧠 Aucun résultat pertinent pour *{$query}*.\n"
                    . "_Essaie une formulation différente ou *cherche bookmarks [mot]* pour une recherche classique._"
                );
            }

            $reason = $parsed['reason'] ?? '';
            $output = "*🧠 RECHERCHE IA — {$query}*\n";
            $output .= "_" . count($matchIndices) . " résultat(s) sur {$total} bookmark(s)_\n";
            if ($reason) {
                $output .= "_💬 {$reason}_\n";
            }
            $output .= "\n";

            foreach ($matchIndices as $rank => $idx) {
                $article = $bookmarks[$idx - 1] ?? null;
                if (!$article) continue;

                $num = $rank + 1;
                $title = $article->title ?: 'Sans titre';
                $source = $article->source ?: '';
                $date = $article->created_at->format('d/m');
                $tags = $article->tags ?? '';

                $output .= "*{$num}. {$title}*";
                if ($source) $output .= " _{$source}_";
                $output .= " ({$date})";
                if ($tags) $output .= " 🏷 {$tags}";
                $output .= "\n🔗 {$article->url}\n\n";
            }

            $output .= "_💡 *lire #N* pour résumer · *résume [url]* pour le détail · *cherche bookmarks [mot]* pour recherche classique_";

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] SmartSearch failed: " . $e->getMessage());
            return AgentResult::reply(
                "❌ Erreur lors de la recherche intelligente. Réessaie ou utilise *cherche bookmarks {$query}* pour une recherche classique."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FOCUS SESSION — Curated reading session on a topic (NEW v1.25.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleFocusSession(AgentContext $context, string $topic): AgentResult
    {
        $userPhone = $context->from;

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply(
                "Précise un sujet pour ta session focus.\n"
                . "_Exemple : *focus Laravel 12* ou *session lecture cybersécurité*_"
            );
        }

        if (mb_strlen($topic) > 100) {
            return AgentResult::reply("Sujet trop long. Utilise quelques mots-clés (max 100 caractères).");
        }

        // Cache 1h per user+topic
        $cacheKey = "content_curator:focus:{$userPhone}:" . md5(mb_strtolower($topic));
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Focus session requested', ['topic' => mb_substr($topic, 0, 80)]);

        try {
            // 1. Find related bookmarks
            $topicWords = array_filter(
                preg_split('/\s+/', mb_strtolower($topic)),
                fn($w) => mb_strlen($w) >= 3
            );

            $relatedBookmarks = collect();
            if (!empty($topicWords)) {
                $relatedBookmarks = SavedArticle::where('user_phone', $userPhone)
                    ->where(function ($q) use ($topicWords) {
                        foreach ($topicWords as $word) {
                            $q->orWhere('title', 'LIKE', "%{$word}%");
                            $q->orWhere('tags', 'LIKE', "%{$word}%");
                        }
                    })
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get();
            }

            // 2. Fetch fresh articles on the topic
            $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
            $userCats = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            $searchCats = !empty($userCats) ? array_slice($userCats, 0, 3) : ['technology', 'science'];

            $freshArticles = $this->aggregator->aggregate($searchCats, $topicWords, 8);

            if (empty($freshArticles) && $relatedBookmarks->isEmpty()) {
                return AgentResult::reply(
                    "📖 Aucun contenu trouvé sur *{$topic}*.\n\n"
                    . "_Essaie un sujet plus large ou *digest sur {$topic}* pour chercher autrement._"
                );
            }

            // 3. Build article context for LLM curation
            $articlesForLlm = '';
            $freshLinks = [];
            foreach (array_slice($freshArticles, 0, 6) as $i => $a) {
                $title = $a['title'] ?? 'Sans titre';
                $desc = mb_strimwidth($a['description'] ?? '', 0, 200, '...');
                $source = $a['source'] ?? '';
                $url = $a['url'] ?? '';
                $articlesForLlm .= "FRESH-" . ($i + 1) . ": {$title} ({$source})\n{$desc}\n\n";
                if ($url) {
                    $freshLinks[] = ['title' => $title, 'url' => $url, 'source' => $source];
                }
            }

            $bookmarkContext = '';
            foreach ($relatedBookmarks as $i => $b) {
                $bookmarkContext .= "BOOKMARK-" . ($i + 1) . ": {$b->title} ({$b->source})\n";
            }

            $systemPrompt = <<<PROMPT
Tu es un curateur expert qui prépare une SESSION DE LECTURE FOCALISÉE pour l'utilisateur. Le but : sélectionner exactement 3 articles qui forment un parcours de lecture cohérent sur le sujet.

Choisis 3 articles parmi ceux fournis (FRESH = articles frais, BOOKMARK = bookmarks existants) qui forment une progression logique :
1. Un article d'INTRODUCTION ou de contexte (facile, rapide)
2. Un article d'APPROFONDISSEMENT (plus technique ou détaillé)
3. Un article de PERSPECTIVE (vision, tendance, ou angle original)

RÉPONDS en JSON valide :
{
  "articles": [
    {"id": "FRESH-1", "role": "intro", "why": "explication courte", "reading_min": 3},
    {"id": "BOOKMARK-2", "role": "deep", "why": "explication courte", "reading_min": 7},
    {"id": "FRESH-3", "role": "perspective", "why": "explication courte", "reading_min": 5}
  ],
  "session_title": "Titre engageant de la session (max 50 chars)",
  "total_reading_min": 15
}

RÈGLES :
- Exactement 3 articles, pas plus
- Estime le temps de lecture réaliste (2-15 min par article)
- Les IDs doivent correspondre exactement aux articles fournis
- Si tu ne trouves pas 3 bons articles, utilise ceux disponibles
- En français
PROMPT;

            $userMessage = "Sujet de la session : {$topic}\n\nArticles disponibles :\n{$articlesForLlm}";
            if ($bookmarkContext) {
                $userMessage .= "\nBookmarks de l'utilisateur :\n{$bookmarkContext}";
            }

            $response = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt, 1024);
            $parsed = $this->parseJsonResponse($response);

            if (!$parsed || !isset($parsed['articles']) || !is_array($parsed['articles'])) {
                // Fallback: simple listing without LLM curation
                $output = "*📖 SESSION FOCUS — " . mb_strimwidth($topic, 0, 40, '...') . "*\n";
                $output .= "_Pas assez de contenu pour une session structurée._\n\n";

                if (!empty($freshLinks)) {
                    $output .= "*Articles frais :*\n";
                    foreach (array_slice($freshLinks, 0, 3) as $i => $link) {
                        $output .= ($i + 1) . ". " . mb_strimwidth($link['title'], 0, 60, '...') . "\n";
                        $output .= "   🔗 {$link['url']}\n";
                    }
                }

                if ($relatedBookmarks->isNotEmpty()) {
                    $output .= "\n*Tes bookmarks liés :*\n";
                    foreach ($relatedBookmarks as $i => $b) {
                        $output .= "📌 " . mb_strimwidth($b->title ?: 'Sans titre', 0, 60, '...') . "\n";
                    }
                }

                $output .= "\n_💡 *deep dive {$topic}* pour une analyse approfondie_";
                return AgentResult::reply($output);
            }

            $sessionTitle = $parsed['session_title'] ?? $topic;
            $totalMin = $parsed['total_reading_min'] ?? '?';

            $output = "*📖 SESSION FOCUS — {$sessionTitle}*\n";
            $output .= "_⏱ ~{$totalMin} min de lecture · " . now()->format('d/m H:i') . "_\n\n";

            $roleEmojis = ['intro' => '🟢', 'deep' => '🔵', 'perspective' => '🟣'];
            $roleLabels = ['intro' => 'Introduction', 'deep' => 'Approfondissement', 'perspective' => 'Perspective'];

            foreach ($parsed['articles'] as $i => $item) {
                $id = $item['id'] ?? '';
                $role = $item['role'] ?? 'intro';
                $why = $item['why'] ?? '';
                $readingMin = $item['reading_min'] ?? '?';
                $emoji = $roleEmojis[$role] ?? '⚪';
                $label = $roleLabels[$role] ?? $role;

                // Resolve article from id
                $title = '';
                $url = '';
                $source = '';

                if (preg_match('/^FRESH-(\d+)$/', $id, $idm)) {
                    $idx = (int) $idm[1] - 1;
                    if (isset($freshLinks[$idx])) {
                        $title = $freshLinks[$idx]['title'];
                        $url = $freshLinks[$idx]['url'];
                        $source = $freshLinks[$idx]['source'];
                    }
                } elseif (preg_match('/^BOOKMARK-(\d+)$/', $id, $idm)) {
                    $idx = (int) $idm[1] - 1;
                    $bm = $relatedBookmarks[$idx] ?? null;
                    if ($bm) {
                        $title = $bm->title ?: 'Sans titre';
                        $url = $bm->url;
                        $source = $bm->source ?: '';
                    }
                }

                if (!$title) continue;

                $num = $i + 1;
                $output .= "{$emoji} *{$num}. {$label}* (~{$readingMin} min)\n";
                $output .= "*" . mb_strimwidth($title, 0, 65, '...') . "*";
                if ($source) $output .= " _{$source}_";
                $output .= "\n";
                if ($why) $output .= "_{$why}_\n";
                if ($url) $output .= "🔗 {$url}\n";
                $output .= "\n";
            }

            if ($relatedBookmarks->isNotEmpty() && $relatedBookmarks->count() > 1) {
                $extraCount = max(0, $relatedBookmarks->count() - 1);
                if ($extraCount > 0) {
                    $output .= "_📚 Tu as aussi {$extraCount} autre(s) bookmark(s) sur ce sujet._\n";
                }
            }

            $output .= "_💡 *save [url]* pour bookmarker · *deep dive {$topic}* pour une analyse IA complète_";

            Cache::put($cacheKey, $output, 3600); // 1h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] FocusSession failed for '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la préparation de la session. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEEKLY NEWSLETTER (NEW v1.26.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleWeeklyNewsletter(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        // Rate limit: 1 newsletter per 6h per user
        $throttleKey = "content_curator:newsletter_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply(
                "⏳ Ta newsletter a déjà été générée récemment.\n"
                . "_Disponible à nouveau dans quelques heures. En attendant, essaie *digest express* ou *flash*._"
            );
        }

        // Cache result for 3h
        $cacheKey = "content_curator:newsletter:{$userPhone}:" . now()->format('Y-W');
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Weekly newsletter requested');

        try {
            // 1. Get user preferences
            $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
            $userCats = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
            if (empty($userCats)) {
                $userCats = ['technology', 'ai'];
            }

            // 2. Get bookmarks from last 7 days
            $weekBookmarks = SavedArticle::where('user_phone', $userPhone)
                ->where('created_at', '>=', now()->subDays(7))
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            // 3. Get trending articles from user categories
            $trendingArticles = [];
            $seen = [];
            foreach (array_slice($userCats, 0, 3) as $cat) {
                $fetched = $this->aggregator->getTrending($cat, 5);
                foreach ($fetched as $art) {
                    $url = $art['url'] ?? '';
                    if ($url && isset($seen[$url])) continue;
                    if ($url) $seen[$url] = true;
                    $art['_category'] = $cat;
                    $trendingArticles[] = $art;
                }
            }
            $trendingArticles = array_slice($trendingArticles, 0, 10);

            // 4. Build context for LLM
            $bookmarkSummary = '';
            if ($weekBookmarks->isNotEmpty()) {
                foreach ($weekBookmarks->take(10) as $i => $b) {
                    $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 80, '...');
                    $tags = $b->tags ?? '';
                    $rating = $b->rating ?? null;
                    $bookmarkSummary .= ($i + 1) . ". {$title}";
                    if ($tags) $bookmarkSummary .= " [tags: {$tags}]";
                    if ($rating) $bookmarkSummary .= " [★{$rating}]";
                    $bookmarkSummary .= "\n";
                }
            }

            $trendingForLlm = '';
            foreach ($trendingArticles as $i => $a) {
                $title = $a['title'] ?? 'Sans titre';
                $desc = mb_strimwidth($a['description'] ?? '', 0, 150, '...');
                $source = $a['source'] ?? '';
                $cat = $a['_category'] ?? '';
                $icon = self::CATEGORY_ICONS[$cat] ?? '📰';
                $trendingForLlm .= ($i + 1) . ". [{$icon} {$cat}] {$title} ({$source})\n{$desc}\n\n";
            }

            $systemPrompt = <<<PROMPT
Tu es un éditeur de newsletter personnalisée. Crée une newsletter hebdomadaire engageante et concise.

La newsletter doit contenir :
1. Un titre accrocheur pour cette semaine (max 50 chars)
2. "Le fait marquant" : le sujet le plus important de la semaine (2-3 phrases)
3. "Top 3 à ne pas manquer" : les 3 articles les plus pertinents parmi les trending (numéro de l'article)
4. "Ton activité" : un court bilan de lecture de la semaine si des bookmarks sont fournis (1-2 phrases)
5. "Le conseil de la semaine" : un conseil ou insight lié aux intérêts de l'utilisateur (1 phrase)

RÉPONDS en JSON valide :
{
  "title": "Titre de la newsletter",
  "highlight": "Le fait marquant de la semaine en 2-3 phrases",
  "top3": [
    {"num": 1, "why": "Pourquoi cet article est important (1 phrase)"},
    {"num": 3, "why": "Raison"},
    {"num": 7, "why": "Raison"}
  ],
  "activity_summary": "Bilan de l'activité lecture ou null si pas de bookmarks",
  "tip": "Conseil ou insight de la semaine"
}

RÈGLES :
- En français, ton conversationnel mais informatif
- Les nums dans top3 doivent correspondre aux articles trending fournis (1-indexé)
- Sois concis et engageant
- Ne référence PAS d'articles qui n'existent pas dans la liste
- Si aucun bookmark n'est fourni, activity_summary doit être null
- Le JSON doit être valide et complet, sans commentaire
PROMPT;

            $userMessage = "Catégories suivies : " . implode(', ', $userCats) . "\n\n";
            $userMessage .= "Articles trending de la semaine :\n{$trendingForLlm}\n";
            if ($bookmarkSummary) {
                $userMessage .= "Bookmarks sauvegardés cette semaine :\n{$bookmarkSummary}";
            } else {
                $userMessage .= "Aucun bookmark cette semaine.";
            }

            $response = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt, 1024);
            $parsed = $this->parseJsonResponse($response);

            // Build output
            $weekNum = now()->format('W');
            $dateRange = now()->subDays(6)->format('d/m') . ' — ' . now()->format('d/m/Y');

            if (!$parsed || !isset($parsed['title'])) {
                // Fallback without LLM
                $output = "*📬 TA NEWSLETTER — Semaine {$weekNum}*\n";
                $output .= "_{$dateRange}_\n\n";

                if (!empty($trendingArticles)) {
                    $output .= "*📈 Trending cette semaine :*\n";
                    foreach (array_slice($trendingArticles, 0, 5) as $i => $a) {
                        $cat = $a['_category'] ?? '';
                        $icon = self::CATEGORY_ICONS[$cat] ?? '📰';
                        $output .= "{$icon} " . mb_strimwidth($a['title'] ?? '', 0, 60, '...') . "\n";
                        if (!empty($a['url'])) $output .= "   🔗 {$a['url']}\n";
                    }
                }

                if ($weekBookmarks->isNotEmpty()) {
                    $output .= "\n*📖 Tes lectures de la semaine :* {$weekBookmarks->count()} bookmark(s)\n";
                }

                $output .= "\n_💡 *digest express* pour un résumé rapide · *recommande* pour des suggestions IA_";
                Cache::put($cacheKey, $output, 10800);
                Cache::put($throttleKey, true, 21600);
                return AgentResult::reply($output);
            }

            $output = "*📬 {$parsed['title']}*\n";
            $output .= "_Semaine {$weekNum} · {$dateRange}_\n\n";

            // Highlight
            if (!empty($parsed['highlight'])) {
                $output .= "*🔥 Le fait marquant :*\n{$parsed['highlight']}\n\n";
            }

            // Top 3
            if (!empty($parsed['top3']) && is_array($parsed['top3'])) {
                $output .= "*🏆 Top 3 à ne pas manquer :*\n";
                foreach ($parsed['top3'] as $rank => $item) {
                    $num = ($item['num'] ?? 0) - 1;
                    if ($num < 0 || !isset($trendingArticles[$num])) continue;
                    $art = $trendingArticles[$num];
                    $cat = $art['_category'] ?? '';
                    $icon = self::CATEGORY_ICONS[$cat] ?? '📰';
                    $title = mb_strimwidth($art['title'] ?? '', 0, 55, '...');
                    $why = $item['why'] ?? '';
                    $output .= "\n" . ($rank + 1) . ". {$icon} *{$title}*\n";
                    if ($why) $output .= "   _{$why}_\n";
                    if (!empty($art['url'])) $output .= "   🔗 {$art['url']}\n";
                }
                $output .= "\n";
            }

            // Activity
            if (!empty($parsed['activity_summary'])) {
                $output .= "*📖 Ton activité :* {$parsed['activity_summary']}\n\n";
            } elseif ($weekBookmarks->isNotEmpty()) {
                $output .= "*📖 Ton activité :* {$weekBookmarks->count()} bookmark(s) cette semaine\n\n";
            }

            // Tip
            if (!empty($parsed['tip'])) {
                $output .= "*💡 Conseil :* _{$parsed['tip']}_\n\n";
            }

            $output .= "_📬 *newsletter* chaque semaine · *focus [sujet]* pour approfondir_";

            Cache::put($cacheKey, $output, 10800); // 3h
            Cache::put($throttleKey, true, 21600); // 6h

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error('[content_curator] WeeklyNewsletter failed: ' . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération de ta newsletter. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK RATING (NEW v1.26.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleRateBookmark(AgentContext $context, int $position, int $rating): AgentResult
    {
        if ($rating < 1 || $rating > 5) {
            return AgentResult::reply(
                "⚠ La note doit être entre *1* et *5*.\n"
                . "_Exemple : *noter #3 4* pour donner 4 étoiles au bookmark n°3._\n"
                . "_1 = passable · 3 = bien · 5 = excellent_"
            );
        }

        if ($position < 1) {
            return AgentResult::reply("⚠ Numéro de bookmark invalide. Utilise *mes bookmarks* pour voir la liste.");
        }

        $userPhone = $context->from;
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply("Tu n'as aucun bookmark. Utilise *save [url]* pour en ajouter.");
        }

        if ($position < 1 || $position > $bookmarks->count()) {
            return AgentResult::reply(
                "⚠ Bookmark #{$position} introuvable. Tu as {$bookmarks->count()} bookmark(s).\n"
                . "_Utilise *mes bookmarks* pour voir la liste._"
            );
        }

        $bookmark = $bookmarks[$position - 1];
        $bookmark->rating = $rating;
        $bookmark->save();

        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
        $title = mb_strimwidth($bookmark->title ?: 'Sans titre', 0, 50, '...');

        $this->log($context, 'Bookmark rated', ['position' => $position, 'rating' => $rating]);

        return AgentResult::reply(
            "✅ *{$title}*\n"
            . "Note : {$stars} ({$rating}/5)\n\n"
            . "_💡 *top notés* pour voir tes bookmarks les mieux notés_"
        );
    }

    private function handleTopRated(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $rated = SavedArticle::where('user_phone', $userPhone)
            ->whereNotNull('rating')
            ->where('rating', '>', 0)
            ->orderByDesc('rating')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($rated->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as pas encore noté de bookmarks.\n\n"
                . "_Utilise *noter #3 4* pour donner une note (1 à 5) à un bookmark._\n"
                . "_Tes favoris apparaîtront ici._"
            );
        }

        $total = SavedArticle::where('user_phone', $userPhone)
            ->whereNotNull('rating')
            ->where('rating', '>', 0)
            ->count();

        $avgRating = SavedArticle::where('user_phone', $userPhone)
            ->whereNotNull('rating')
            ->where('rating', '>', 0)
            ->avg('rating');

        $output = "*⭐ TES BOOKMARKS LES MIEUX NOTÉS*\n";
        $output .= "_{$total} bookmark(s) noté(s) · Moyenne : " . number_format($avgRating, 1) . "/5_\n\n";

        foreach ($rated as $i => $b) {
            $stars = str_repeat('⭐', $b->rating);
            $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 50, '...');
            $source = $b->source ? " _{$b->source}_" : '';
            $tags = $b->tags ? " [{$b->tags}]" : '';

            $output .= ($i + 1) . ". {$stars} *{$title}*{$source}{$tags}\n";
            if ($b->url) {
                $output .= "   🔗 {$b->url}\n";
            }
        }

        if ($total > 10) {
            $output .= "\n_... et " . ($total - 10) . " autre(s) bookmark(s) noté(s)._\n";
        }

        $output .= "\n_💡 *noter #N 5* pour noter · *similaire #N* pour trouver du contenu similaire_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READING STREAK (NEW v1.27.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingStreak(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        // Gather all bookmark dates (created_at) + digest log dates to track activity
        $bookmarkDates = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->pluck('created_at')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        $digestDates = ContentDigestLog::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->pluck('created_at')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        $allDates = collect(array_merge($bookmarkDates, $digestDates))
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        if (empty($allDates)) {
            return AgentResult::reply(
                "*🔥 MA SÉRIE DE LECTURE*\n\n"
                . "Tu n'as pas encore d'activité de lecture.\n\n"
                . "_Commence par :_\n"
                . "• *digest* — Lire ton premier digest\n"
                . "• *save [url]* — Sauvegarder un article\n\n"
                . "_Chaque jour actif compte pour ta série !_"
            );
        }

        // Calculate current streak (consecutive days ending today or yesterday)
        $today     = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');
        $dateSet   = array_flip($allDates);

        $currentStreak = 0;
        $checkDate = isset($dateSet[$today]) ? now() : (isset($dateSet[$yesterday]) ? now()->subDay() : null);

        if ($checkDate) {
            $d = $checkDate->copy();
            while (isset($dateSet[$d->format('Y-m-d')])) {
                $currentStreak++;
                $d->subDay();
            }
        }

        // Calculate best streak ever
        $bestStreak  = 0;
        $tempStreak  = 1;
        $sortedDates = array_values(array_unique($allDates));
        sort($sortedDates);

        for ($i = 1; $i < count($sortedDates); $i++) {
            $prev = \Carbon\Carbon::parse($sortedDates[$i - 1]);
            $curr = \Carbon\Carbon::parse($sortedDates[$i]);
            if ($prev->diffInDays($curr) === 1) {
                $tempStreak++;
            } else {
                $bestStreak = max($bestStreak, $tempStreak);
                $tempStreak = 1;
            }
        }
        $bestStreak = max($bestStreak, $tempStreak);

        // Stats
        $totalActiveDays = count(array_unique($allDates));
        $firstDate       = \Carbon\Carbon::parse($sortedDates[0]);
        $daysSinceStart  = max(1, $firstDate->diffInDays(now()) + 1);
        $consistency     = round(($totalActiveDays / $daysSinceStart) * 100);

        // Milestones
        $milestones = [
            3   => ['🌱', 'Graine de lecteur'],
            7   => ['🌿', 'Lecteur régulier'],
            14  => ['🌳', 'Lecteur assidu'],
            30  => ['🔥', 'Flamme de lecture'],
            60  => ['⚡', 'Lecteur infatigable'],
            100 => ['🏆', 'Centurion de la lecture'],
            365 => ['👑', 'Légende de la veille'],
        ];

        $currentMilestone = null;
        $nextMilestone    = null;
        foreach ($milestones as $days => $info) {
            if ($currentStreak >= $days) {
                $currentMilestone = [$days, $info[0], $info[1]];
            } elseif (!$nextMilestone) {
                $nextMilestone = [$days, $info[0], $info[1]];
            }
        }

        // Activity heatmap (last 7 days)
        $heatmap = '';
        for ($i = 6; $i >= 0; $i--) {
            $d   = now()->subDays($i)->format('Y-m-d');
            $day = now()->subDays($i)->translatedFormat('D');
            $heatmap .= isset($dateSet[$d]) ? "🟩" : "⬜";
        }

        // Build output
        $streakEmoji = $currentStreak >= 7 ? '🔥' : ($currentStreak >= 3 ? '🌿' : '📖');
        $output = "*{$streakEmoji} MA SÉRIE DE LECTURE*\n\n";

        $output .= "*Série actuelle :* {$currentStreak} jour(s) consécutif(s)\n";
        $output .= "*Meilleure série :* {$bestStreak} jour(s)\n";
        $output .= "*Jours actifs :* {$totalActiveDays} / {$daysSinceStart} jours ({$consistency}%)\n\n";

        $output .= "*7 derniers jours :*\n";
        $output .= "{$heatmap}\n";
        $output .= "_Lun → Dim · 🟩 actif · ⬜ inactif_\n\n";

        if ($currentMilestone) {
            $output .= "*Badge actuel :* {$currentMilestone[1]} {$currentMilestone[2]} ({$currentMilestone[0]}j)\n";
        }
        if ($nextMilestone) {
            $remaining = $nextMilestone[0] - $currentStreak;
            $output .= "*Prochain badge :* {$nextMilestone[1]} {$nextMilestone[2]} dans {$remaining} jour(s)\n";
        }
        $output .= "\n";

        if ($currentStreak === 0) {
            $output .= "_💡 Lance un *digest* ou *save [url]* pour démarrer ta série !_";
        } else {
            $output .= "_💡 Continue ta série ! *flash* · *digest* · *article du jour*_";
        }

        $this->log($context, 'Reading streak viewed', [
            'current_streak' => $currentStreak,
            'best_streak'    => $bestStreak,
        ]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK TIMELINE (NEW v1.27.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkTimeline(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "*📅 TIMELINE BOOKMARKS*\n\n"
                . "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        // Group by period (this week, last week, this month, older months)
        $now       = now();
        $groups    = [];
        $thisWeek  = [];
        $lastWeek  = [];
        $thisMonth = [];
        $older     = [];

        foreach ($bookmarks as $b) {
            $created = $b->created_at;
            $title   = mb_strimwidth($b->title ?: 'Sans titre', 0, 45, '...');
            $source  = $b->source ? " _{$b->source}_" : '';
            $rating  = ($b->rating ?? 0) > 0 ? ' ' . str_repeat('⭐', $b->rating) : '';
            $tags    = $b->tags ? " [{$b->tags}]" : '';
            $entry   = "• *{$title}*{$source}{$rating}{$tags}";

            if ($created->isCurrentWeek()) {
                $dayLabel = $created->translatedFormat('l');
                $thisWeek[$dayLabel][] = $entry;
            } elseif ($created->copy()->addWeek()->isCurrentWeek()) {
                $lastWeek[] = $entry;
            } elseif ($created->isCurrentMonth()) {
                $thisMonth[] = $entry;
            } else {
                $monthLabel = $created->translatedFormat('F Y');
                $older[$monthLabel][] = $entry;
            }
        }

        $output = "*📅 TIMELINE DE TES BOOKMARKS*\n";
        $output .= "_{$bookmarks->count()} bookmark(s) au total_\n\n";

        $charCount = mb_strlen($output);
        $maxChars  = 3500; // WhatsApp message limit safety

        if (!empty($thisWeek)) {
            $output .= "*📌 Cette semaine :*\n";
            foreach ($thisWeek as $day => $entries) {
                $output .= "  __{$day}__\n";
                foreach (array_slice($entries, 0, 5) as $e) {
                    $output .= "  {$e}\n";
                }
                if (count($entries) > 5) {
                    $output .= "  _... +" . (count($entries) - 5) . " autre(s)_\n";
                }
            }
            $output .= "\n";
        }

        if (!empty($lastWeek) && mb_strlen($output) < $maxChars) {
            $output .= "*📎 Semaine dernière :* _(" . count($lastWeek) . " bookmark(s))_\n";
            foreach (array_slice($lastWeek, 0, 5) as $e) {
                $output .= "  {$e}\n";
            }
            if (count($lastWeek) > 5) {
                $output .= "  _... +" . (count($lastWeek) - 5) . " autre(s)_\n";
            }
            $output .= "\n";
        }

        if (!empty($thisMonth) && mb_strlen($output) < $maxChars) {
            $output .= "*📅 Ce mois :* _(" . count($thisMonth) . " bookmark(s))_\n";
            foreach (array_slice($thisMonth, 0, 3) as $e) {
                $output .= "  {$e}\n";
            }
            if (count($thisMonth) > 3) {
                $output .= "  _... +" . (count($thisMonth) - 3) . " autre(s)_\n";
            }
            $output .= "\n";
        }

        if (!empty($older) && mb_strlen($output) < $maxChars) {
            $output .= "*📦 Mois précédents :*\n";
            foreach (array_slice($older, 0, 3, true) as $month => $entries) {
                $output .= "  __{$month}__ — " . count($entries) . " bookmark(s)\n";
            }
            if (count($older) > 3) {
                $output .= "  _... +" . (count($older) - 3) . " autre(s) mois_\n";
            }
            $output .= "\n";
        }

        // Weekly save rate
        $firstBookmark = $bookmarks->last()->created_at;
        $weeksSince    = max(1, (int) ceil($firstBookmark->diffInDays($now) / 7));
        $weeklyRate    = round($bookmarks->count() / $weeksSince, 1);
        $output .= "_📊 Rythme : ~{$weeklyRate} bookmark(s)/semaine_\n\n";

        $output .= "_💡 *mes bookmarks* · *analytics bookmarks* · *ma série*_";

        $this->log($context, 'Bookmark timeline viewed', ['total' => $bookmarks->count()]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MARK AS READ (NEW v1.28.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleMarkRead(AgentContext $context, int $position): AgentResult
    {
        $userPhone = $context->from;
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply("Tu n'as aucun bookmark. Utilise *save [url]* pour en ajouter.");
        }

        if ($position < 1 || $position > $bookmarks->count()) {
            return AgentResult::reply(
                "⚠ Bookmark #{$position} introuvable. Tu as {$bookmarks->count()} bookmark(s).\n"
                . "_Utilise *mes bookmarks* pour voir la liste._"
            );
        }

        $bookmark = $bookmarks[$position - 1];
        $readKey = "content_curator:read_status:{$bookmark->id}";
        $alreadyRead = Cache::has($readKey);

        if ($alreadyRead) {
            // Toggle: unmark as read
            Cache::forget($readKey);
            $title = mb_strimwidth($bookmark->title ?: 'Sans titre', 0, 55, '...');

            $this->log($context, 'Bookmark unmarked as read', ['position' => $position]);

            return AgentResult::reply(
                "📖 *{$title}*\n"
                . "Statut : non lu\n\n"
                . "_💡 *lu #{$position}* pour re-marquer comme lu_"
            );
        }

        // Mark as read with timestamp — persist for 365 days
        Cache::put($readKey, now()->toIso8601String(), 60 * 60 * 24 * 365);

        // Also track in daily reading log for streak computation
        $dailyKey = "content_curator:daily_reads:{$userPhone}:" . now()->format('Y-m-d');
        $dailyReads = Cache::get($dailyKey, []);
        if (!in_array($bookmark->id, $dailyReads)) {
            $dailyReads[] = $bookmark->id;
            Cache::put($dailyKey, $dailyReads, 60 * 60 * 24 * 7);
        }

        $title = mb_strimwidth($bookmark->title ?: 'Sans titre', 0, 55, '...');
        $readCount = 0;
        foreach ($bookmarks as $b) {
            if (Cache::has("content_curator:read_status:{$b->id}")) {
                $readCount++;
            }
        }

        $total = $bookmarks->count();
        $pct = $total > 0 ? round(($readCount / $total) * 100) : 0;
        $bar = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));

        $this->log($context, 'Bookmark marked as read', ['position' => $position, 'read_total' => $readCount]);

        $output = "✅ *{$title}*\n"
            . "Marqué comme lu !\n\n"
            . "📊 Progression : {$bar} {$pct}%\n"
            . "_{$readCount}/{$total} bookmark(s) lus_\n\n";

        // Milestone messages
        if ($readCount === $total) {
            $output .= "🏆 *Bravo ! Tu as lu TOUS tes bookmarks !*\n\n";
        } elseif ($pct >= 75 && $pct < 100) {
            $output .= "🔥 Plus que " . ($total - $readCount) . " à lire, tu y es presque !\n\n";
        } elseif (count($dailyReads) === 3) {
            $output .= "⭐ 3 articles lus aujourd'hui, bien joué !\n\n";
        }

        $output .= "_💡 *à lire* pour voir tes bookmarks non lus · *lu #{$position}* pour démarquer_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UNREAD BOOKMARKS (NEW v1.28.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleUnreadBookmarks(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "Tu n'as aucun bookmark.\n"
                . "_Utilise *save [url]* pour en ajouter._"
            );
        }

        // Separate read/unread
        $unread = [];
        $readCount = 0;
        foreach ($bookmarks->values() as $i => $b) {
            $readKey = "content_curator:read_status:{$b->id}";
            if (Cache::has($readKey)) {
                $readCount++;
            } else {
                $unread[] = ['bookmark' => $b, 'position' => $i + 1];
            }
        }

        $total = $bookmarks->count();
        $unreadCount = count($unread);

        if ($unreadCount === 0) {
            return AgentResult::reply(
                "🏆 *Tous tes bookmarks sont lus !*\n\n"
                . "📚 {$total} bookmark(s) · 100% lus\n\n"
                . "_💡 *save [url]* pour ajouter de nouveaux articles · *recommande* pour des suggestions_"
            );
        }

        $pct = round(($readCount / $total) * 100);
        $bar = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));

        $output = "*📖 TES BOOKMARKS À LIRE*\n";
        $output .= "_{$unreadCount} non lu(s) sur {$total} · Progression : {$bar} {$pct}%_\n\n";

        // Show up to 15 unread bookmarks
        $displayed = array_slice($unread, 0, 15);
        foreach ($displayed as $item) {
            $b = $item['bookmark'];
            $pos = $item['position'];
            $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 55, '...');
            $source = $b->source ? " _{$b->source}_" : '';
            $age = $b->created_at->diffForHumans(short: true);

            $output .= "📌 *#{$pos}* {$title}{$source}\n";
            $output .= "   _{$age}_";

            // Show tags if present
            $tagKey = "content_curator:tags:{$b->id}";
            $tags = Cache::get($tagKey, []);
            if (!empty($tags)) {
                $output .= " · " . implode(', ', array_map(fn($t) => "#{$t}", array_slice($tags, 0, 3)));
            }
            $output .= "\n";
        }

        if ($unreadCount > 15) {
            $output .= "\n_... et " . ($unreadCount - 15) . " autre(s) non lu(s)_\n";
        }

        $output .= "\n_💡 *lu #N* pour marquer comme lu · *lire #N* pour résumer · *highlights #N* pour les points clés_";

        $this->log($context, 'Unread bookmarks listed', ['unread' => $unreadCount, 'total' => $total]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BULK MARK READ (NEW v1.29.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBulkMarkRead(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply("Tu n'as aucun bookmark. Utilise *save [url]* pour en ajouter.");
        }

        // Safety limit to avoid cache overload on very large libraries
        $maxBatch = 500;
        if ($bookmarks->count() > $maxBatch) {
            $bookmarks = $bookmarks->take($maxBatch);
        }

        $alreadyRead = 0;
        $newlyMarked = 0;
        $today = now()->format('Y-m-d');
        $dailyKey = "content_curator:daily_reads:{$userPhone}:{$today}";
        $dailyReads = Cache::get($dailyKey, []);

        foreach ($bookmarks as $b) {
            $readKey = "content_curator:read_status:{$b->id}";
            if (Cache::has($readKey)) {
                $alreadyRead++;
            } else {
                Cache::put($readKey, now()->toIso8601String(), 60 * 60 * 24 * 365);
                $newlyMarked++;
                if (!in_array($b->id, $dailyReads)) {
                    $dailyReads[] = $b->id;
                }
            }
        }

        if ($newlyMarked > 0) {
            Cache::put($dailyKey, $dailyReads, 60 * 60 * 24 * 7);
        }

        $total = $bookmarks->count();

        $this->log($context, 'Bulk mark read', ['newly_marked' => $newlyMarked, 'already_read' => $alreadyRead, 'total' => $total]);

        if ($newlyMarked === 0) {
            return AgentResult::reply(
                "🏆 *Tous tes bookmarks sont déjà lus !*\n\n"
                . "📚 {$total} bookmark(s) · 100% lus\n\n"
                . "_💡 *save [url]* pour ajouter de nouveaux articles_"
            );
        }

        $output = "✅ *{$newlyMarked} bookmark(s) marqué(s) comme lu(s) !*\n\n"
            . "📊 Progression : ▓▓▓▓▓▓▓▓▓▓ 100%\n"
            . "_{$total}/{$total} bookmark(s) lus_\n\n";

        if ($newlyMarked >= 10) {
            $output .= "📚 *Inbox zero atteint !* Ta liste de lecture est à jour.\n\n";
        }

        $output .= "_💡 *à lire* pour vérifier · *recommande* pour de nouvelles suggestions_";

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DAILY READING SUMMARY (NEW v1.29.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleDailySummary(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;
        $today = now()->format('Y-m-d');
        $dailyKey = "content_curator:daily_reads:{$userPhone}:{$today}";
        $dailyReads = Cache::get($dailyKey, []);

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        $total = $bookmarks->count();

        // Count global read status
        $readCount = 0;
        foreach ($bookmarks as $b) {
            if (Cache::has("content_curator:read_status:{$b->id}")) {
                $readCount++;
            }
        }

        $hour = (int) now()->format('H');
        $greet = match (true) {
            $hour < 12  => '☀️ Bonjour',
            $hour < 18  => '📖 Cet après-midi',
            default     => '🌙 Ce soir',
        };

        $output = "*{$greet} — Résumé du jour*\n";
        $output .= "_" . now()->format('d/m/Y H:i') . "_\n\n";

        // Today's reads
        if (empty($dailyReads)) {
            $output .= "📭 *Aucune lecture aujourd'hui.*\n";
            $output .= "_Commence par *à lire* pour voir tes bookmarks en attente._\n\n";
        } else {
            $output .= "📗 *Lu aujourd'hui : " . count($dailyReads) . " article(s)*\n";

            // Show titles of today's reads
            $todayArticles = $bookmarks->filter(fn($b) => in_array($b->id, $dailyReads));
            foreach ($todayArticles->take(5) as $b) {
                $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 50, '...');
                $output .= "  ✅ {$title}\n";
            }
            if ($todayArticles->count() > 5) {
                $output .= "  _... et " . ($todayArticles->count() - 5) . " autre(s)_\n";
            }
            $output .= "\n";

            // Daily milestone
            $count = count($dailyReads);
            if ($count >= 5) {
                $output .= "🏆 *Lecteur assidu !* 5+ articles en une journée.\n\n";
            } elseif ($count >= 3) {
                $output .= "⭐ *Beau rythme !* 3+ articles aujourd'hui.\n\n";
            }
        }

        // Overall progress
        if ($total > 0) {
            $pct = round(($readCount / $total) * 100);
            $bar = str_repeat('▓', (int) round($pct / 10)) . str_repeat('░', 10 - (int) round($pct / 10));
            $unread = $total - $readCount;
            $output .= "📊 *Bibliothèque :* {$bar} {$pct}%\n";
            $output .= "_{$readCount} lu(s) · {$unread} à lire · {$total} total_\n\n";
        }

        // Streak info
        $streak = $this->computeStreak($userPhone);
        if ($streak > 1) {
            $output .= "🔥 *Série en cours : {$streak} jour(s)*\n\n";
        } elseif ($streak === 1) {
            $output .= "✨ *Début de série !* Reviens demain pour enchaîner.\n\n";
        }

        // Suggest next reads (unread bookmarks)
        $unreadBookmarks = $bookmarks->filter(fn($b) => !Cache::has("content_curator:read_status:{$b->id}"));
        if ($unreadBookmarks->isNotEmpty()) {
            $suggestions = $unreadBookmarks->take(3);
            $output .= "*📌 Suggestions pour la suite :*\n";
            foreach ($suggestions as $b) {
                $pos = $bookmarks->search($b) + 1;
                $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 50, '...');
                $source = $b->source ? " _{$b->source}_" : '';
                $output .= "  • *#{$pos}* {$title}{$source}\n";
            }
            $output .= "\n";
        }

        $output .= "_💡 *lire #N* pour résumer · *lu #N* pour marquer lu · *flash* pour du frais_";

        $this->log($context, 'Daily summary', ['today_reads' => count($dailyReads), 'total' => $total, 'streak' => $streak ?? 0]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CATEGORY STATS BREAKDOWN (NEW v1.30.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleCategoryStats(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "📊 *Répartition par catégorie*\n\n"
                . "Tu n'as aucun bookmark pour l'instant.\n\n"
                . "_Commence par *save [url]* ou *follow ai* pour personnaliser ta veille._"
            );
        }

        $total = $bookmarks->count();

        // Count by tag (primary tag = first tag)
        $catCounts = [];
        $untagged = 0;
        foreach ($bookmarks as $b) {
            $tags = trim($b->tags ?? '');
            if ($tags === '') {
                $untagged++;
                continue;
            }
            $primaryTag = mb_strtolower(trim(explode(',', $tags)[0]));
            // Try to resolve to a known category
            $resolved = $this->resolveCategory($primaryTag);
            $label = $resolved ?: $primaryTag;
            $catCounts[$label] = ($catCounts[$label] ?? 0) + 1;
        }

        // Sort by count descending
        arsort($catCounts);

        // Build visual bar chart
        $output = "*📊 RÉPARTITION PAR CATÉGORIE*\n";
        $output .= "_{$total} bookmark(s) au total_\n\n";

        if (!empty($catCounts)) {
            $maxCount = max($catCounts);
            $barWidth = 10;

            foreach ($catCounts as $cat => $count) {
                $icon = self::CATEGORY_ICONS[$cat] ?? '📄';
                $pct = round(($count / $total) * 100);
                $filled = (int) round(($count / $maxCount) * $barWidth);
                $bar = str_repeat('▓', $filled) . str_repeat('░', $barWidth - $filled);
                $label = ucfirst($cat);
                $output .= "{$icon} *{$label}* {$bar} {$count} ({$pct}%)\n";
            }
        }

        if ($untagged > 0) {
            $pct = round(($untagged / $total) * 100);
            $output .= "\n📭 *Non tagués :* {$untagged} ({$pct}%)\n";
            $output .= "_💡 *auto tag all* pour taguer automatiquement_\n";
        }

        // Read vs unread breakdown
        $readCount = 0;
        foreach ($bookmarks as $b) {
            if (Cache::has("content_curator:read_status:{$b->id}")) {
                $readCount++;
            }
        }
        $unreadCount = $total - $readCount;
        $readPct = round(($readCount / $total) * 100);

        $output .= "\n*📖 Progression lecture :*\n";
        $readBar = str_repeat('▓', (int) round($readPct / 10)) . str_repeat('░', 10 - (int) round($readPct / 10));
        $output .= "{$readBar} {$readPct}% ({$readCount} lus · {$unreadCount} à lire)\n\n";

        // Top 3 most saved categories suggestion
        $topCats = array_slice(array_keys($catCounts), 0, 3);
        if (!empty($topCats)) {
            $output .= "_Tes domaines favoris : *" . implode(', ', array_map('ucfirst', $topCats)) . "*_\n";
        }

        $output .= "_💡 *bookmarks tag tech* pour filtrer · *analytics bookmarks* pour plus de stats_";

        $this->log($context, 'Category stats viewed', ['total' => $total, 'categories' => count($catCounts), 'untagged' => $untagged]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READING HISTORY (NEW v1.30.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingHistory(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->get();

        if ($bookmarks->isEmpty()) {
            return AgentResult::reply(
                "📅 *Historique de lecture*\n\n"
                . "Tu n'as aucun bookmark pour l'instant.\n\n"
                . "_Commence par *save [url]* pour sauvegarder un article._"
            );
        }

        // Build per-day reading log for last 7 days
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $days[$date] = [
                'label' => $i === 0 ? "Aujourd'hui" : ($i === 1 ? 'Hier' : now()->subDays($i)->translatedFormat('l d/m')),
                'saved' => [],
                'read' => [],
            ];
        }

        // Saved per day
        foreach ($bookmarks as $b) {
            $date = $b->created_at->format('Y-m-d');
            if (isset($days[$date])) {
                $days[$date]['saved'][] = $b;
            }
        }

        // Read per day (from daily_reads cache)
        foreach (array_keys($days) as $date) {
            $dailyKey = "content_curator:daily_reads:{$userPhone}:{$date}";
            $dailyReads = Cache::get($dailyKey, []);
            if (!empty($dailyReads)) {
                $readArticles = $bookmarks->filter(fn($b) => in_array($b->id, $dailyReads));
                $days[$date]['read'] = $readArticles->values()->all();
            }
        }

        $output = "*📅 HISTORIQUE DE LECTURE — 7 derniers jours*\n\n";

        $totalRead = 0;
        $totalSaved = 0;
        $activeDays = 0;

        foreach ($days as $date => $day) {
            $savedCount = count($day['saved']);
            $readCount = count($day['read']);
            $totalRead += $readCount;
            $totalSaved += $savedCount;

            $hasActivity = $savedCount > 0 || $readCount > 0;
            if ($hasActivity) $activeDays++;

            $dayIcon = $hasActivity ? '🟩' : '⬜';
            $output .= "{$dayIcon} *{$day['label']}*";

            if (!$hasActivity) {
                $output .= " — _aucune activité_\n";
                continue;
            }

            $parts = [];
            if ($readCount > 0) $parts[] = "📗 {$readCount} lu(s)";
            if ($savedCount > 0) $parts[] = "💾 {$savedCount} sauvegardé(s)";
            $output .= " — " . implode(' · ', $parts) . "\n";

            // Show up to 2 article titles per day
            $shown = [];
            foreach (array_slice($day['read'], 0, 2) as $b) {
                $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 45, '...');
                $shown[] = "  ✅ {$title}";
            }
            foreach (array_slice($day['saved'], 0, max(0, 2 - count($shown))) as $b) {
                $title = mb_strimwidth($b->title ?: 'Sans titre', 0, 45, '...');
                $shown[] = "  💾 {$title}";
            }
            $dayTotal = $savedCount + $readCount;
            $shownCount = count($shown);
            foreach ($shown as $line) {
                $output .= "{$line}\n";
            }
            if ($dayTotal > $shownCount) {
                $output .= "  _... et " . ($dayTotal - $shownCount) . " autre(s)_\n";
            }
        }

        $output .= "\n*📊 Bilan 7 jours :*\n";
        $output .= "  📗 {$totalRead} article(s) lu(s)\n";
        $output .= "  💾 {$totalSaved} article(s) sauvegardé(s)\n";
        $output .= "  📅 {$activeDays}/7 jours actifs\n\n";

        // Streak
        $streak = $this->computeStreak($userPhone);
        if ($streak > 0) {
            $output .= "🔥 *Série en cours : {$streak} jour(s)*\n\n";
        }

        $output .= "_💡 *mon jour* pour le détail du jour · *ma série* pour tes badges_";

        $this->log($context, 'Reading history viewed', ['active_days' => $activeDays, 'total_read' => $totalRead, 'total_saved' => $totalSaved]);

        return AgentResult::reply($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXPORT BY TAG (NEW v1.33.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleExportByTag(AgentContext $context, string $tagName): AgentResult
    {
        $userPhone = $context->from;
        $tagName = mb_strtolower(trim($tagName));

        if (mb_strlen($tagName) > 50) {
            return AgentResult::reply("⚠ Nom de tag trop long (max 50 caractères).");
        }

        try {
            $articles = SavedArticle::where('user_phone', $userPhone)
                ->orderByDesc('created_at')
                ->get();

            if ($articles->isEmpty()) {
                return AgentResult::reply(
                    "Tu n'as aucun bookmark sauvegardé.\n"
                    . "_Utilise *save [url]* pour commencer._"
                );
            }

            // Filter by tag
            $filtered = $articles->filter(function ($article) use ($tagName) {
                $tags = Cache::get("content_curator:tags:{$article->id}", []);
                return in_array($tagName, array_map('mb_strtolower', $tags));
            });

            if ($filtered->isEmpty()) {
                return AgentResult::reply(
                    "❌ Aucun bookmark trouvé avec le tag *#{$tagName}*.\n"
                    . "_Utilise *mes tags* pour voir tes tags disponibles._"
                );
            }

            $output = "*📤 EXPORT — Bookmarks #{$tagName}*\n";
            $output .= "_{$filtered->count()} article(s) trouvé(s)_\n\n";

            foreach ($filtered->values() as $i => $article) {
                $title = $article->title ?: 'Sans titre';
                $date  = $article->created_at->format('d/m/Y');
                $note  = Cache::get("content_curator:note:{$article->id}", '');
                $rating = Cache::get("content_curator:rating:{$article->id}");

                $output .= "*" . ($i + 1) . ". {$title}*\n";
                $output .= "🔗 {$article->url}\n";
                $output .= "📅 {$date}";
                if ($rating) {
                    $output .= " · " . str_repeat('⭐', $rating);
                }
                $output .= "\n";
                if ($note) {
                    $output .= "📝 _{$note}_\n";
                }
                $output .= "\n";
            }

            $output .= "_💡 *exporter bookmarks* pour tout exporter · *bookmarks tag {$tagName}* pour filtrer_";

            $this->log($context, 'Export by tag', ['tag' => $tagName, 'count' => $filtered->count()]);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ExportByTag failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'export par tag. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEEK AT A GLANCE (NEW v1.33.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleWeekAtGlance(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        try {
            $weekStart = now()->subDays(6)->startOfDay();

            // Bookmarks saved this week
            $savedThisWeek = SavedArticle::where('user_phone', $userPhone)
                ->where('created_at', '>=', $weekStart)
                ->orderByDesc('created_at')
                ->get();

            // Read articles this week
            $readKeys = [];
            for ($d = 0; $d < 7; $d++) {
                $date = now()->subDays($d)->format('Y-m-d');
                $readKeys[] = "content_curator:read_log:{$userPhone}:{$date}";
            }

            $totalRead = 0;
            $dailyActivity = [];
            $dayLabels = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

            for ($d = 6; $d >= 0; $d--) {
                $date = now()->subDays($d);
                $dateStr = $date->format('Y-m-d');
                $dayLabel = $dayLabels[(int) $date->format('w')];

                $readCount = count(Cache::get("content_curator:read_log:{$userPhone}:{$dateStr}", []));
                $savedCount = $savedThisWeek->filter(fn($a) => $a->created_at->format('Y-m-d') === $dateStr)->count();

                $totalRead += $readCount;
                $total = $readCount + $savedCount;

                // Visual bar: each block = 1 article activity
                $bar = match (true) {
                    $total === 0 => '░░░',
                    $total <= 2  => '▓░░',
                    $total <= 5  => '▓▓░',
                    default      => '▓▓▓',
                };

                $dailyActivity[] = [
                    'label' => $dayLabel,
                    'bar' => $bar,
                    'read' => $readCount,
                    'saved' => $savedCount,
                    'total' => $total,
                    'isToday' => $d === 0,
                ];
            }

            $totalSaved = $savedThisWeek->count();
            $activeDays = count(array_filter($dailyActivity, fn($d) => $d['total'] > 0));
            $streak = $this->computeStreak($userPhone);

            // Collect top tags of the week
            $weekTags = [];
            foreach ($savedThisWeek as $article) {
                $tags = Cache::get("content_curator:tags:{$article->id}", []);
                foreach ($tags as $t) {
                    $weekTags[$t] = ($weekTags[$t] ?? 0) + 1;
                }
            }
            arsort($weekTags);

            $output = "*📅 SEMAINE EN BREF*\n";
            $output .= "_" . now()->subDays(6)->format('d/m') . " → " . now()->format('d/m') . "_\n\n";

            // Activity heatmap
            foreach ($dailyActivity as $day) {
                $marker = $day['isToday'] ? ' ← aujourd\'hui' : '';
                $output .= "  {$day['label']} {$day['bar']} {$day['total']} article(s){$marker}\n";
            }

            $output .= "\n*📊 Totaux :*\n";
            $output .= "  📗 {$totalRead} lu(s) · 💾 {$totalSaved} sauvegardé(s)\n";
            $output .= "  📅 {$activeDays}/7 jours actifs\n";

            if ($streak > 0) {
                $output .= "  🔥 Série en cours : {$streak} jour(s)\n";
            }

            // Top tags of the week
            if (!empty($weekTags)) {
                $topTags = array_slice($weekTags, 0, 5, true);
                $tagParts = [];
                foreach ($topTags as $tag => $count) {
                    $tagParts[] = "#{$tag} ({$count})";
                }
                $output .= "\n*🏷️ Top tags :* " . implode(' · ', $tagParts) . "\n";
            }

            // Best rated this week
            $bestRated = null;
            foreach ($savedThisWeek as $article) {
                $rating = Cache::get("content_curator:rating:{$article->id}");
                if ($rating && (!$bestRated || $rating > $bestRated['rating'])) {
                    $bestRated = ['title' => $article->title ?: 'Sans titre', 'rating' => $rating];
                }
            }
            if ($bestRated) {
                $output .= "\n*⭐ Meilleur article :* {$bestRated['title']} " . str_repeat('⭐', $bestRated['rating']) . "\n";
            }

            $output .= "\n_💡 *bilan semaine* pour le détail complet · *historique lecture* pour le journal_";

            $this->log($context, 'Week at a glance', ['active_days' => $activeDays, 'read' => $totalRead, 'saved' => $totalSaved]);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] WeekAtGlance failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du résumé hebdomadaire. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUICK FACTS — extract key data points from a bookmark (NEW v1.32.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleQuickFacts(AgentContext $context, int $position): AgentResult
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

        $cacheKey = "content_curator:facts:{$userPhone}:" . md5($url);
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Quick facts requested', ['position' => $position, 'title' => mb_substr($title, 0, 60)]);

        try {
            $content = $this->extractHtmlContent($url);

            if (!$content['ok'] || mb_strlen($content['text']) < 100) {
                return AgentResult::reply(
                    "❌ Impossible d'extraire le contenu de cet article.\n"
                    . "_Le site bloque peut-être la lecture automatique._"
                );
            }

            $excerpt = mb_substr($content['text'], 0, 3500);

            $systemPrompt = <<<PROMPT
Tu es un extracteur de données factuelles. À partir du contenu d'un article, extrais les faits clés structurés.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

📊 *Chiffres & données* :
• [chiffre ou stat 1 avec contexte]
• [chiffre ou stat 2 avec contexte]
• [chiffre ou stat 3 si disponible]

👤 *Acteurs clés* :
• [personne/entreprise/organisation 1 — rôle ou action]
• [personne/entreprise/organisation 2 — rôle ou action]

📅 *Dates & échéances* :
• [date ou période 1 — événement associé]
• [date ou période 2 si disponible]

🔗 *Liens & références* :
• [étude, rapport ou source citée dans l'article, si disponible]

RÈGLES :
- Extrais UNIQUEMENT les données présentes dans le texte source
- N'invente aucune donnée, chiffre ou nom
- Si une section n'a aucune donnée (ex: pas de chiffres), omets-la entièrement
- Privilégie les données précises : montants exacts, pourcentages, dates complètes
- En français même si l'article est en anglais
- Maximum 3-4 items par section pour rester concis
- Si le contenu est trop pauvre en données, indique-le honnêtement
PROMPT;

            $userMessage = "Titre : {$title}\nSource : {$article->source}\n\nContenu :\n{$excerpt}";

            $facts = $this->claude->chat($userMessage, ModelResolver::fast(), $systemPrompt, 1024);

            if (!$facts) {
                return AgentResult::reply("❌ Impossible d'extraire les données. Réessaie dans quelques instants.");
            }

            $output  = "*📋 DONNÉES CLÉS*\n";
            $output .= "_" . mb_strimwidth($title, 0, 60, '...') . "_\n\n";
            $output .= $facts . "\n\n";
            $output .= "_💡 *résume {$url}* pour le résumé complet · *quiz #{$position}* pour tester ta compréhension_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] QuickFacts failed for position {$position}: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'extraction des données. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READING PLAN — AI-generated learning path on a topic (NEW v1.32.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingPlan(AgentContext $context, string $topic): AgentResult
    {
        $userPhone = $context->from;
        $topic     = mb_substr(trim($topic), 0, 100);

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply("Précise un sujet. Exemple : *plan lecture machine learning*");
        }

        // Rate limit: 1 plan per 60s per user
        $throttleKey = "content_curator:plan_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends un moment avant de générer un nouveau plan de lecture.");
        }
        Cache::put($throttleKey, true, 60);

        $cacheKey = "content_curator:plan:{$userPhone}:" . md5(mb_strtolower($topic));
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Reading plan requested', ['topic' => $topic]);

        // Gather user's bookmarks related to this topic
        $bookmarks = SavedArticle::where('user_phone', $userPhone)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $topicLower    = mb_strtolower($topic);
        $topicWords    = array_filter(preg_split('/\s+/', $topicLower), fn($w) => mb_strlen($w) >= 3);
        $relatedTitles = [];

        foreach ($bookmarks as $bm) {
            $titleLower = mb_strtolower($bm->title ?? '');
            foreach ($topicWords as $word) {
                if (str_contains($titleLower, $word)) {
                    $relatedTitles[] = $bm->title . ' (' . $bm->source . ')';
                    break;
                }
            }
            if (count($relatedTitles) >= 10) break;
        }

        // Fetch fresh articles on the topic
        $prefs      = UserContentPreference::where('user_phone', $userPhone)->get();
        $categories = $prefs->where('category', '!=', 'custom')->pluck('category')->toArray();
        if (empty($categories)) {
            $categories = ['technology', 'science', 'ai'];
        }

        try {
            $freshArticles = $this->aggregator->aggregate($categories, [$topicLower], 10);
            $freshTitles   = array_map(
                fn($a) => ($a['title'] ?? 'Sans titre') . ' — ' . ($a['url'] ?? ''),
                array_slice($freshArticles, 0, 8)
            );
        } catch (\Throwable $e) {
            $freshTitles = [];
        }

        $bookmarkContext = !empty($relatedTitles)
            ? "Bookmarks existants de l'utilisateur sur ce sujet :\n" . implode("\n", array_map(fn($t) => "- {$t}", $relatedTitles))
            : "L'utilisateur n'a pas encore de bookmarks sur ce sujet.";

        $freshContext = !empty($freshTitles)
            ? "\n\nArticles récents disponibles :\n" . implode("\n", array_map(fn($t) => "- {$t}", $freshTitles))
            : "";

        $systemPrompt = <<<PROMPT
Tu es un expert en curation de contenu et en parcours d'apprentissage. Crée un plan de lecture progressif sur un sujet donné.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

*📖 Niveau 1 — Découverte* (débutant)
• [Article/concept 1 avec brève description — pourquoi commencer par là]
• [Article/concept 2]

*📗 Niveau 2 — Approfondissement* (intermédiaire)
• [Article/concept 3 — ce que ça apporte de plus]
• [Article/concept 4]

*📘 Niveau 3 — Expertise* (avancé)
• [Article/concept 5 — perspective avancée]
• [Article/concept 6]

*🎯 Objectif final* : [1 phrase résumant ce que le lecteur saura après ce parcours]

RÈGLES :
- Parcours progressif : du plus accessible au plus technique
- Si des bookmarks existants correspondent, intègre-les dans le parcours
- Si des articles récents sont disponibles, inclus leurs URLs
- 2-3 items par niveau, pas plus
- En français
- Chaque item doit avoir une brève justification de sa place dans le parcours
- Si le sujet est très niche, élargis légèrement pour proposer un parcours viable
PROMPT;

        $userMessage = "Sujet : {$topic}\n\n{$bookmarkContext}{$freshContext}";

        try {
            $plan = $this->claude->chat($userMessage, ModelResolver::balanced(), $systemPrompt, 1500);

            if (!$plan) {
                return AgentResult::reply("❌ Impossible de générer le plan de lecture. Réessaie dans quelques instants.");
            }

            $output  = "*🗺️ PLAN DE LECTURE — " . mb_strtoupper($topic) . "*\n";
            $output .= "_Parcours personnalisé en 3 niveaux_\n\n";
            $output .= $plan . "\n\n";

            if (!empty($relatedTitles)) {
                $output .= "_📚 " . count($relatedTitles) . " bookmark(s) déjà dans ta bibliothèque sur ce sujet_\n";
            }

            $output .= "_💡 *deep dive {$topic}* pour une analyse approfondie · *focus {$topic}* pour une session guidée_";

            Cache::put($cacheKey, $output, 7200); // 2 hours

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ReadingPlan failed for topic '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération du plan de lecture. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONTENT RADAR (NEW v1.34.0) — multi-source topic scan
    // ─────────────────────────────────────────────────────────────────────────

    private function handleContentRadar(AgentContext $context, string $topic): AgentResult
    {
        $userPhone = $context->from;
        $topic = mb_substr(trim($topic), 0, 100);

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply("❌ Précise un sujet. Exemple : *radar kubernetes* ou *radar IA generative*");
        }

        // Rate limit: 1 radar per 60s per user
        $throttleKey = "content_curator:radar_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends une minute entre chaque *radar*.");
        }
        Cache::put($throttleKey, true, 60);

        // Cache: 1 hour
        $cacheKey = "content_curator:radar:" . md5($userPhone . ':' . mb_strtolower($topic));
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Content radar', ['topic' => $topic]);

        try {
            // Fetch articles from all followed categories + general
            $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
            $categories = $prefs->pluck('category')->filter()->unique()->values()->toArray();
            if (empty($categories)) {
                $categories = ['technology', 'science', 'business'];
            }

            $keywords = array_merge(
                $prefs->pluck('keywords')->flatten()->filter()->values()->toArray(),
                [mb_strtolower($topic)]
            );

            $articles = $this->aggregator->aggregate($categories, $keywords, 20);

            // Filter articles relevant to the topic
            $topicLower = mb_strtolower($topic);
            $topicWords = array_filter(preg_split('/\s+/', $topicLower), fn($w) => mb_strlen($w) > 2);

            $relevant = array_filter($articles, function ($a) use ($topicLower, $topicWords) {
                $text = mb_strtolower(($a['title'] ?? '') . ' ' . ($a['description'] ?? ''));
                if (str_contains($text, $topicLower)) return true;
                $matches = 0;
                foreach ($topicWords as $w) {
                    if (str_contains($text, $w)) $matches++;
                }
                return count($topicWords) > 0 && ($matches / count($topicWords)) >= 0.5;
            });

            $relevant = array_values($relevant);

            // Also check bookmarks for existing knowledge
            $bookmarkCount = SavedArticle::where('user_phone', $userPhone)
                ->where(function ($q) use ($topicLower, $topicWords) {
                    $q->whereRaw('LOWER(title) LIKE ?', ["%{$topicLower}%"]);
                    foreach ($topicWords as $w) {
                        $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$w}%"]);
                    }
                })
                ->count();

            // Group by source domain
            $bySource = [];
            foreach ($relevant as $a) {
                $domain = parse_url($a['url'] ?? '', PHP_URL_HOST) ?: 'inconnu';
                $domain = preg_replace('/^www\./', '', $domain);
                $bySource[$domain] = ($bySource[$domain] ?? 0) + 1;
            }
            arsort($bySource);

            // Build radar output
            $model = $this->resolveModel($context);

            if (count($relevant) < 2) {
                $output = "*📡 RADAR — " . mb_strtoupper($topic) . "*\n\n";
                $output .= "🔇 Peu de couverture détectée sur ce sujet dans tes sources actuelles.\n\n";
                $output .= "_💡 Essaie *cherche {$topic}* pour une recherche web · *follow {$topic}* pour suivre ce sujet_";
                return AgentResult::reply($output);
            }

            // Use LLM to analyze coverage
            $articlesText = implode("\n", array_map(function ($a, $i) {
                $domain = parse_url($a['url'] ?? '', PHP_URL_HOST) ?: '?';
                return ($i + 1) . ". [{$domain}] " . ($a['title'] ?? 'Sans titre') . " — " . mb_substr($a['description'] ?? '', 0, 120);
            }, array_slice($relevant, 0, 12), array_keys(array_slice($relevant, 0, 12))));

            $sourcesText = implode(', ', array_map(
                fn($d, $c) => "{$d} ({$c})",
                array_keys(array_slice($bySource, 0, 8)),
                array_slice($bySource, 0, 8)
            ));

            $systemPrompt = <<<PROMPT
Tu es un analyste de veille. Analyse la couverture d'un sujet à travers plusieurs sources.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp uniquement) :

*🔥 Tendance :* [une phrase — le sujet est-il chaud/émergent/stable/en déclin ?]

*📊 Couverture :*
• [Source 1] — [angle principal couvert]
• [Source 2] — [angle principal couvert]
• [Source 3] — [angle principal couvert]

*💡 Synthèse :* [2-3 phrases résumant les points de convergence et divergence entre les sources]

*🔮 À surveiller :* [1-2 angles peu couverts ou tendances émergentes]

RÈGLES :
- Sois concis, factuel, max 200 mots
- Base-toi UNIQUEMENT sur les articles fournis — zéro invention
- Pas de markdown ## ni **
- En français
PROMPT;

            $analysis = $this->claude->chat(
                "Sujet : {$topic}\n\nSources détectées : {$sourcesText}\n\nArticles :\n{$articlesText}",
                $model,
                $systemPrompt,
                1200
            );

            if (!$analysis) {
                return AgentResult::reply("❌ Impossible de générer le radar. Réessaie dans quelques instants.");
            }

            $output  = "*📡 RADAR — " . mb_strtoupper($topic) . "*\n";
            $output .= "_" . count($relevant) . " article(s) · " . count($bySource) . " source(s) détectée(s)_\n\n";
            $output .= $analysis . "\n\n";

            if ($bookmarkCount > 0) {
                $output .= "📚 _{$bookmarkCount} bookmark(s) existant(s) sur ce sujet_\n";
            }

            $output .= "_💡 *deep dive {$topic}* · *focus {$topic}* · *cherche {$topic}*_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ContentRadar failed for topic '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du scan radar. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BOOKMARK CLUSTERS — AI THEMATIC GROUPING (NEW v1.35.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleBookmarkCluster(AgentContext $context): AgentResult
    {
        $userPhone = $context->from;

        $total = SavedArticle::where('user_phone', $userPhone)->count();

        if ($total === 0) {
            return AgentResult::reply(
                "*🧩 CLUSTERS THÉMATIQUES*\n\n"
                . "Tu n'as aucun bookmark sauvegardé.\n\n"
                . "_Sauvegarde des articles avec *save [url]* puis reviens ici._"
            );
        }

        if ($total < 5) {
            return AgentResult::reply(
                "*🧩 CLUSTERS THÉMATIQUES*\n\n"
                . "Tu n'as que *{$total} bookmark(s)* — il en faut au moins 5 pour un regroupement pertinent.\n\n"
                . "_Utilise *save [url]* ou *batch save [url1] [url2]...* pour en ajouter._"
            );
        }

        // Rate limit: 1 cluster per 90s per user
        $throttleKey = "content_curator:cluster_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends un peu avant de relancer *grouper*.");
        }
        Cache::put($throttleKey, true, 90);

        // Cache 20 min — clustering is expensive
        $cacheKey = "content_curator:clusters:{$userPhone}:" . md5((string) $total);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Bookmark clustering requested', ['total' => $total]);

        try {
            $articles = SavedArticle::where('user_phone', $userPhone)
                ->orderByDesc('created_at')
                ->limit(60)
                ->get();

            $listText = '';
            foreach ($articles as $i => $article) {
                $title  = $article->title ?: 'Sans titre';
                $source = $article->source ?: '?';
                $tags   = '';
                if (!empty($article->tags)) {
                    $tagsArr = is_array($article->tags) ? $article->tags : json_decode($article->tags, true);
                    if (!empty($tagsArr)) {
                        $tags = ' [' . implode(', ', array_slice($tagsArr, 0, 3)) . ']';
                    }
                }
                $listText .= ($i + 1) . ". [{$source}] {$title}{$tags}\n";
            }

            $systemPrompt = <<<PROMPT
Tu es un expert en organisation de contenu. Analyse une liste de bookmarks et regroupe-les en clusters thématiques cohérents.

FORMAT DE RÉPONSE (JSON strict) :
[
  {
    "name": "Nom du cluster (court, 2-4 mots)",
    "icon": "un emoji représentatif",
    "articles": [1, 5, 12],
    "summary": "1 phrase décrivant le fil conducteur"
  }
]

RÈGLES :
- Crée entre 3 et 7 clusters maximum
- Chaque article doit apparaître dans exactement 1 cluster
- Les numéros font référence à la position dans la liste fournie
- Trie les clusters du plus grand au plus petit
- Si un article ne rentre dans aucun thème, mets-le dans un cluster "Divers"
- Les noms de clusters doivent être en français, concis et descriptifs
- Retourne UNIQUEMENT le JSON, aucun texte avant ou après
PROMPT;

            $model = $this->resolveModel($context);
            $response = $this->claude->chat(
                "Voici {$total} bookmarks (max 60 analysés) :\n\n{$listText}",
                $model,
                $systemPrompt,
                2000
            );

            $clusters = $this->parseJsonResponse($response);

            if (!is_array($clusters) || empty($clusters)) {
                return AgentResult::reply("❌ Impossible de générer les clusters. Réessaie dans quelques instants.");
            }

            $output = "*🧩 CLUSTERS THÉMATIQUES*\n";
            $output .= "_" . min(count($articles), 60) . " bookmark(s) analysés · " . count($clusters) . " groupe(s) détectés_\n\n";

            foreach ($clusters as $ci => $cluster) {
                $icon = $cluster['icon'] ?? '📁';
                $name = $cluster['name'] ?? 'Groupe ' . ($ci + 1);
                $artIds = $cluster['articles'] ?? [];
                $summary = $cluster['summary'] ?? '';
                $count = count($artIds);

                $output .= "*{$icon} {$name}* ({$count} article" . ($count > 1 ? 's' : '') . ")\n";
                if ($summary) {
                    $output .= "_{$summary}_\n";
                }

                // Show first 3 article titles from the cluster
                $shown = 0;
                foreach (array_slice($artIds, 0, 3) as $artIdx) {
                    $idx = ((int) $artIdx) - 1;
                    if (isset($articles[$idx])) {
                        $title = mb_strimwidth($articles[$idx]->title ?: 'Sans titre', 0, 55, '...');
                        $output .= "  • #{$artIdx} {$title}\n";
                        $shown++;
                    }
                }
                if ($count > 3) {
                    $output .= "  _+ " . ($count - 3) . " autre(s)_\n";
                }
                $output .= "\n";
            }

            $output .= "_💡 *lire #N* pour résumer · *similaire #N* pour explorer · *profil lecture* pour ton analyse IA_";

            Cache::put($cacheKey, $output, 1200);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] BookmarkCluster failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors du regroupement. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TREND WATCH — TOPIC EVOLUTION TRACKER (NEW v1.35.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleTrendWatch(AgentContext $context, string $topic): AgentResult
    {
        $userPhone = $context->from;
        $topic = mb_substr(trim($topic), 0, 100);

        if (mb_strlen($topic) < 2) {
            return AgentResult::reply("❌ Précise un sujet. Exemple : *watch kubernetes* ou *surveiller IA generative*");
        }

        // Rate limit: 1 watch per 60s per user
        $throttleKey = "content_curator:watch_throttle:{$userPhone}";
        if (Cache::has($throttleKey)) {
            return AgentResult::reply("⏳ Attends une minute entre chaque *watch*.");
        }
        Cache::put($throttleKey, true, 60);

        // Cache 2 hours
        $cacheKey = "content_curator:watch:" . md5($userPhone . ':' . mb_strtolower($topic));
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return AgentResult::reply($cached);
        }

        $this->log($context, 'Trend watch', ['topic' => $topic]);

        try {
            $topicLower = mb_strtolower($topic);
            $topicWords = array_filter(preg_split('/\s+/', $topicLower), fn($w) => mb_strlen($w) > 2);

            // 1. Analyze user's bookmarks related to this topic
            $relatedBookmarks = SavedArticle::where('user_phone', $userPhone)
                ->where(function ($q) use ($topicLower, $topicWords) {
                    $q->whereRaw('LOWER(title) LIKE ?', ["%{$topicLower}%"]);
                    foreach ($topicWords as $w) {
                        $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$w}%"]);
                    }
                })
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            // 2. Fetch fresh articles on the topic
            $prefs = UserContentPreference::where('user_phone', $userPhone)->get();
            $categories = $prefs->pluck('category')->filter()->unique()->values()->toArray();
            if (empty($categories)) {
                $categories = ['technology', 'science', 'business'];
            }

            $freshArticles = $this->aggregator->aggregate(
                $categories,
                array_merge([$topicLower], $topicWords),
                15
            );

            // Filter fresh articles by relevance
            $relevantFresh = array_filter($freshArticles, function ($a) use ($topicLower, $topicWords) {
                $text = mb_strtolower(($a['title'] ?? '') . ' ' . ($a['description'] ?? ''));
                if (str_contains($text, $topicLower)) return true;
                $matches = 0;
                foreach ($topicWords as $w) {
                    if (str_contains($text, $w)) $matches++;
                }
                return count($topicWords) > 0 && ($matches / count($topicWords)) >= 0.5;
            });
            $relevantFresh = array_values(array_slice($relevantFresh, 0, 8));

            // Build context for LLM
            $bookmarksText = '';
            if ($relatedBookmarks->isNotEmpty()) {
                $bookmarksText = "BOOKMARKS DE L'UTILISATEUR sur ce sujet (" . $relatedBookmarks->count() . ") :\n";
                foreach ($relatedBookmarks as $i => $bm) {
                    $date = $bm->created_at->format('d/m/Y');
                    $bookmarksText .= ($i + 1) . ". [{$date}] " . ($bm->title ?: 'Sans titre') . "\n";
                }
            }

            $freshText = '';
            if (!empty($relevantFresh)) {
                $freshText = "\nARTICLES FRAIS AUJOURD'HUI (" . count($relevantFresh) . ") :\n";
                foreach ($relevantFresh as $i => $a) {
                    $domain = parse_url($a['url'] ?? '', PHP_URL_HOST) ?: '?';
                    $domain = preg_replace('/^www\./', '', $domain);
                    $freshText .= ($i + 1) . ". [{$domain}] " . ($a['title'] ?? 'Sans titre') . "\n";
                }
            }

            if ($relatedBookmarks->isEmpty() && empty($relevantFresh)) {
                $output = "*📈 TREND WATCH — " . mb_strtoupper($topic) . "*\n\n";
                $output .= "🔇 Aucune donnée trouvée sur ce sujet dans tes bookmarks ni dans les sources actuelles.\n\n";
                $output .= "_💡 *follow {$topic}* pour suivre ce sujet · *cherche {$topic}* pour une recherche_";
                return AgentResult::reply($output);
            }

            $model = $this->resolveModel($context);
            $systemPrompt = <<<PROMPT
Tu es un analyste de tendances. Analyse l'évolution d'un sujet en croisant les bookmarks sauvegardés par l'utilisateur et les articles frais du jour.

FORMAT DE RÉPONSE (texte brut, formatage WhatsApp) :

*📊 Évolution :* [le sujet est-il en croissance, stable, en déclin dans les lectures de l'utilisateur ? Base-toi sur les dates des bookmarks]

*🔄 Ce qui a changé :*
• [changement ou développement récent 1]
• [changement ou développement récent 2]
• [changement 3 si pertinent]

*🆕 Nouveautés du jour :*
• [article frais notable 1 — résumé en 1 ligne]
• [article frais notable 2 — résumé en 1 ligne]

*🎯 À surveiller :* [1-2 angles émergents ou signaux faibles à suivre]

RÈGLES :
- Base-toi UNIQUEMENT sur les données fournies
- Si pas de bookmarks, concentre-toi sur les articles frais
- Si pas d'articles frais, concentre-toi sur l'analyse des bookmarks
- Sois concis, max 200 mots
- En français
PROMPT;

            $userMessage = "Sujet surveillé : {$topic}\n\n{$bookmarksText}\n{$freshText}";

            $analysis = $this->claude->chat($userMessage, $model, $systemPrompt, 1200);

            if (!$analysis) {
                return AgentResult::reply("❌ Impossible de générer l'analyse. Réessaie dans quelques instants.");
            }

            $output  = "*📈 TREND WATCH — " . mb_strtoupper($topic) . "*\n";
            $output .= "_" . $relatedBookmarks->count() . " bookmark(s) · " . count($relevantFresh) . " article(s) frais_\n\n";
            $output .= $analysis . "\n\n";

            // Show first 2 fresh article URLs for quick access
            if (!empty($relevantFresh)) {
                $output .= "*🔗 Liens rapides :*\n";
                foreach (array_slice($relevantFresh, 0, 2) as $a) {
                    if (!empty($a['url'])) {
                        $title = mb_strimwidth($a['title'] ?? 'Article', 0, 50, '...');
                        $output .= "• {$title}\n  {$a['url']}\n";
                    }
                }
                $output .= "\n";
            }

            $output .= "_💡 *radar {$topic}* · *deep dive {$topic}* · *focus {$topic}*_";

            Cache::put($cacheKey, $output, 7200);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] TrendWatch failed for topic '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'analyse de tendance. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXECUTIVE BRIEF (NEW v1.36.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleExecutiveBrief(AgentContext $context, string $topic): AgentResult
    {
        try {
            $topic = mb_substr(trim($topic), 0, 100);

            if (mb_strlen($topic) < 2) {
                return AgentResult::reply(
                    "⚠ Précise un sujet pour le brief.\n"
                    . "_Exemple : *brief intelligence artificielle* · *brief laravel 12* · *brief cybersécurité*_"
                );
            }

            $cacheKey = "content_curator:brief:{$context->from}:" . md5(mb_strtolower($topic));
            if ($cached = Cache::get($cacheKey)) {
                return AgentResult::reply($cached);
            }

            $userPhone = preg_replace('/@.*/', '', $context->from);

            // Gather bookmarks related to the topic
            $bookmarks = \App\Models\SavedArticle::where('user_phone', $userPhone)
                ->where(function ($q) use ($topic) {
                    $q->where('title', 'LIKE', "%{$topic}%")
                      ->orWhere('url', 'LIKE', "%{$topic}%")
                      ->orWhere('tags', 'LIKE', "%{$topic}%")
                      ->orWhere('notes', 'LIKE', "%{$topic}%");
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            // Fetch fresh articles
            $freshArticles = [];
            try {
                $freshArticles = $this->aggregator->search($topic, 5);
            } catch (\Throwable $e) {
                Log::warning("[content_curator] ExecutiveBrief aggregator search failed: " . $e->getMessage());
            }

            $bookmarksText = '';
            if ($bookmarks->isNotEmpty()) {
                $bookmarksText = "BOOKMARKS DE L'UTILISATEUR SUR CE SUJET :\n";
                foreach ($bookmarks as $i => $b) {
                    $date = $b->created_at?->format('d/m') ?? '?';
                    $bookmarksText .= ($i + 1) . ". [{$date}] " . ($b->title ?? 'Sans titre') . "\n";
                }
            }

            $freshText = '';
            if (!empty($freshArticles)) {
                $freshText = "ARTICLES FRAIS DU JOUR :\n";
                foreach (array_slice($freshArticles, 0, 5) as $i => $a) {
                    $freshText .= ($i + 1) . ". " . ($a['title'] ?? 'Sans titre') . "\n";
                }
            }

            if ($bookmarks->isEmpty() && empty($freshArticles)) {
                $output = "*📋 BRIEF — " . mb_strtoupper($topic) . "*\n\n";
                $output .= "🔇 Aucune donnée trouvée sur ce sujet.\n\n";
                $output .= "_💡 *cherche {$topic}* pour une recherche d'articles · *flash {$topic}* pour les news_";
                return AgentResult::reply($output);
            }

            $currentDate = now()->translatedFormat('l j F Y');
            $model = $this->resolveModel($context);
            $systemPrompt = <<<PROMPT
Tu es un analyste stratégique produisant un brief exécutif concis sur un sujet. Date : {$currentDate}.

FORMAT STRICT (texte brut WhatsApp) :

*📌 Contexte :*
[2-3 phrases résumant la situation actuelle du sujet — chiffres concrets si disponibles]

*📊 Faits clés :*
• [fait important 1 avec source/chiffre]
• [fait important 2]
• [fait important 3]

*⚡ Impact :*
[1-2 phrases : pourquoi c'est important MAINTENANT, conséquences concrètes]

*🎯 Actions recommandées :*
• [action concrète 1 — ce que le lecteur devrait faire/surveiller]
• [action concrète 2]

RÈGLES :
- Base-toi UNIQUEMENT sur les données fournies
- Sois factuel, pas d'opinion ni de spéculation
- Max 180 mots au total
- En français
- Pas de markdown autre que * pour le gras WhatsApp
PROMPT;

            $userMessage = "Sujet du brief : {$topic}\n\n{$bookmarksText}\n{$freshText}";

            $analysis = $this->claude->chat($userMessage, $model, $systemPrompt, 1200);

            if (!$analysis) {
                return AgentResult::reply("❌ Impossible de générer le brief. Réessaie dans quelques instants.");
            }

            $output  = "*📋 BRIEF — " . mb_strtoupper($topic) . "*\n";
            $output .= "_" . $bookmarks->count() . " bookmark(s) · " . count($freshArticles) . " source(s) fraîche(s) · " . $currentDate . "_\n\n";
            $output .= $analysis . "\n\n";

            // Quick access links
            if (!empty($freshArticles)) {
                $output .= "*🔗 Sources :*\n";
                foreach (array_slice($freshArticles, 0, 3) as $a) {
                    if (!empty($a['url'])) {
                        $title = mb_strimwidth($a['title'] ?? 'Article', 0, 50, '...');
                        $output .= "• {$title}\n  {$a['url']}\n";
                    }
                }
                $output .= "\n";
            }

            $output .= "_💡 *deep dive {$topic}* pour aller plus loin · *watch {$topic}* pour suivre_";

            Cache::put($cacheKey, $output, 3600);

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ExecutiveBrief failed for topic '{$topic}': " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de la génération du brief. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READING INSIGHTS (NEW v1.36.0)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleReadingInsights(AgentContext $context): AgentResult
    {
        try {
            $cacheKey = "content_curator:insights:{$context->from}";
            if ($cached = Cache::get($cacheKey)) {
                return AgentResult::reply($cached);
            }

            $userPhone = preg_replace('/@.*/', '', $context->from);

            // Get bookmarks from last 30 days
            $recentBookmarks = \App\Models\SavedArticle::where('user_phone', $userPhone)
                ->where('created_at', '>=', now()->subDays(30))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            if ($recentBookmarks->count() < 3) {
                return AgentResult::reply(
                    "*🔍 INSIGHTS LECTURE*\n\n"
                    . "📭 Pas assez de bookmarks pour générer des insights (minimum 3, tu en as {$recentBookmarks->count()}).\n\n"
                    . "_💡 Sauvegarde des articles avec *save [url]* puis reviens voir tes insights !_"
                );
            }

            // Get older bookmarks for comparison
            $olderBookmarks = \App\Models\SavedArticle::where('user_phone', $userPhone)
                ->where('created_at', '<', now()->subDays(30))
                ->where('created_at', '>=', now()->subDays(90))
                ->orderByDesc('created_at')
                ->limit(30)
                ->get();

            // Build profile text
            $recentText = "BOOKMARKS DES 30 DERNIERS JOURS ({$recentBookmarks->count()}) :\n";
            foreach ($recentBookmarks as $i => $b) {
                $date = $b->created_at?->format('d/m') ?? '?';
                $tags = $b->tags ?? '';
                $rating = $b->rating ? " ★{$b->rating}" : '';
                $readStatus = $b->is_read ? ' ✓lu' : '';
                $recentText .= ($i + 1) . ". [{$date}] " . ($b->title ?? 'Sans titre') . " [{$tags}]{$rating}{$readStatus}\n";
            }

            $olderText = '';
            if ($olderBookmarks->isNotEmpty()) {
                $olderText = "\nBOOKMARKS PLUS ANCIENS (30-90 jours, {$olderBookmarks->count()}) :\n";
                foreach ($olderBookmarks as $i => $b) {
                    $date = $b->created_at?->format('d/m') ?? '?';
                    $tags = $b->tags ?? '';
                    $olderText .= ($i + 1) . ". [{$date}] " . ($b->title ?? 'Sans titre') . " [{$tags}]\n";
                }
            }

            // Reading stats
            $totalRecent = $recentBookmarks->count();
            $readCount = $recentBookmarks->where('is_read', true)->count();
            $readRate = $totalRecent > 0 ? round(($readCount / $totalRecent) * 100) : 0;
            $avgRating = $recentBookmarks->where('rating', '>', 0)->avg('rating');
            $avgRatingStr = $avgRating ? number_format($avgRating, 1) : 'N/A';

            $statsText = "\nSTATISTIQUES :\n";
            $statsText .= "- Articles récents : {$totalRecent}\n";
            $statsText .= "- Taux de lecture : {$readRate}%\n";
            $statsText .= "- Note moyenne : {$avgRatingStr}/5\n";

            $model = $this->resolveModel($context);
            $systemPrompt = <<<PROMPT
Tu es un analyste de comportement de lecture. Analyse les habitudes de lecture de l'utilisateur et produis des insights actionnables.

FORMAT STRICT (texte brut WhatsApp) :

*📈 Tendances émergentes :*
• [sujet/thème dont la fréquence augmente récemment — avec preuves]
• [2ème tendance si visible]

*🔇 Angles morts :*
• [sujet que l'utilisateur suivait avant mais a abandonné — ou domaine absent mais lié à ses intérêts]
• [2ème angle mort si pertinent]

*🔗 Connexions cachées :*
• [lien inattendu entre deux sujets/articles que l'utilisateur n'a peut-être pas remarqué]

*💡 Recommandation :*
[1 suggestion personnalisée et actionnable basée sur l'analyse — un sujet à explorer, une habitude à changer, ou un croisement de domaines à investiguer]

RÈGLES :
- Base-toi UNIQUEMENT sur les données fournies
- Chaque insight doit citer des articles spécifiques comme preuves
- Sois concret et actionnable, pas vague
- Max 200 mots
- En français
PROMPT;

            $userMessage = "{$recentText}\n{$olderText}\n{$statsText}";

            $analysis = $this->claude->chat($userMessage, $model, $systemPrompt, 1500);

            if (!$analysis) {
                return AgentResult::reply("❌ Impossible de générer les insights. Réessaie dans quelques instants.");
            }

            $currentDate = now()->translatedFormat('j F Y');
            $output  = "*🔍 INSIGHTS LECTURE*\n";
            $output .= "_{$totalRecent} articles · {$readRate}% lus · note moy. {$avgRatingStr}/5 · 30 derniers jours_\n\n";
            $output .= $analysis . "\n\n";
            $output .= "_💡 *profil lecture* · *recommande* · *analytics bookmarks*_";

            Cache::put($cacheKey, $output, 7200); // 2h cache

            return AgentResult::reply($output);

        } catch (\Throwable $e) {
            Log::error("[content_curator] ReadingInsights failed: " . $e->getMessage());
            return AgentResult::reply("❌ Erreur lors de l'analyse des insights. Réessaie dans quelques instants.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELP
    // ─────────────────────────────────────────────────────────────────────────

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
            . "  • *comparer #3 et #5* — Comparer deux bookmarks _(nouveau v1.24)_\n"
            . "  • *lire #3* — Résumer le bookmark n°3\n\n"
            . "*🤖 IA :*\n"
            . "  • *article du jour* — L'article IA du jour (sélection personnalisée + TLDR)\n"
            . "  • *profil lecture* — Analyse IA de ta bibliothèque de bookmarks\n"
            . "  • *recommande* — Recommandations personnalisées de sujets\n"
            . "  • *quiz #3* — Mini-quiz pour tester ta compréhension d'un bookmark\n"
            . "  • *highlights #3* — Takeaways et points clés d'un bookmark\n"
            . "  • *news liées* — Articles frais basés sur tes bookmarks récents\n"
            . "  • *deep dive [sujet]* — Analyse approfondie d'un sujet avec sources\n"
            . "  • *recherche intelligente [question]* — Recherche sémantique IA dans tes bookmarks _(nouveau v1.25)_\n"
            . "  • *focus [sujet]* — Session de lecture guidée : 3 articles, intro→expert _(nouveau v1.25)_\n"
            . "  • *facts #3* — Chiffres, acteurs et dates clés d'un bookmark _(nouveau v1.32)_\n"
            . "  • *plan lecture [sujet]* — Parcours d'apprentissage progressif IA _(nouveau v1.32)_\n"
            . "  • *radar [sujet]* — Scan multi-sources : couverture, tendance, angles _(nouveau v1.34)_\n"
            . "  • *watch [sujet]* — Suivi d'un sujet : évolution dans tes bookmarks + articles frais _(nouveau v1.35)_\n"
            . "  • *brief [sujet]* — Brief exécutif : contexte, faits clés, impact, actions _(nouveau v1.36)_\n"
            . "  • *insights* — Analyse de tes habitudes de lecture : tendances, angles morts _(nouveau v1.36)_\n\n"
            . "*🎭 Mood :*\n"
            . "  • *inspire moi* — Articles inspirants et motivants _(nouveau v1.15)_\n"
            . "  • *positif* — Bonnes nouvelles et contenu optimiste\n"
            . "  • *détends moi* — Contenu léger et divertissant\n"
            . "  • *motivant* — Contenu énergisant\n\n"
            . "*🔖 Bookmarks :*\n"
            . "  • *rappel bookmarks* — Redécouvrir des bookmarks oubliés _(nouveau v1.23)_\n"
            . "  • *surprends moi* — Redécouvrir un bookmark aléatoire\n"
            . "  • *save [url]* — Sauvegarder (+ titre optionnel : *save [url] Mon titre*)\n"
            . "  • *batch save [url1] [url2]...* — Sauvegarder plusieurs URLs d'un coup _(nouveau v1.22)_\n"
            . "  • *mes bookmarks* — Voir ta liste (15 derniers)\n"
            . "  • *mes bookmarks page 2* — Voir la page suivante\n"
            . "  • *exporter bookmarks* — Exporter TOUS les bookmarks\n"
            . "  • *cherche bookmarks laravel* — Filtrer tes bookmarks\n"
            . "  • *renommer #3 Mon titre* — Renommer un bookmark\n"
            . "  • *note #3 Mon commentaire* — Ajouter une note à un bookmark\n"
            . "  • *tag #3 tech, ia* — Taguer un bookmark\n"
            . "  • *auto tag #3* — Tagging IA automatique\n"
            . "  • *auto tag all* — Taguer tous les bookmarks non tagués _(nouveau v1.21)_\n"
            . "  • *bookmarks tag tech* — Filtrer par tag\n"
            . "  • *mes tags* — Voir tous tes tags\n"
            . "  • *partager #3* — Générer un message prêt à transférer\n"
            . "  • *similaire #3* — Trouver des articles similaires à un bookmark\n"
            . "  • *grouper* — Regrouper tes bookmarks par thème avec l'IA _(nouveau v1.35)_\n"
            . "  • *doublons* — Détecter et nettoyer les bookmarks en double _(nouveau v1.24)_\n"
            . "  • *supprimer 3* — Effacer le bookmark n°3\n"
            . "  • *supprimer #1 #3 #5* — Supprimer plusieurs bookmarks d'un coup _(nouveau v1.34)_\n"
            . "  • *vider bookmarks* — Effacer tous (confirmation requise)\n\n"
            . "*📊 Stats & Bilan :*\n"
            . "  • *combien bookmarks* — Compteur rapide _(nouveau v1.22)_\n"
            . "  • *objectif lecture 5* — Fixer un objectif hebdomadaire _(nouveau v1.18)_\n"
            . "  • *objectif lecture* — Voir ta progression\n"
            . "  • *défi lecture* — Défi quotidien (3 articles variés)\n"
            . "  • *analytics bookmarks* — Dashboard de tes habitudes de lecture\n"
            . "  • *résumé bookmarks* — Panorama complet de ta bibliothèque _(nouveau v1.21)_\n"
            . "  • *recap mensuel* — Bilan du mois avec analyse IA\n"
            . "  • *bilan semaine* — Résumé de ta semaine de lecture\n"
            . "  • *stats digest* — Historique, streak et statistiques\n"
            . "  • *top sources* — Tes sources les plus bookmarkées\n"
            . "  • *newsletter* — Ta newsletter hebdo personnalisée IA\n"
            . "  • *ma série* — Streak de lecture gamifié avec badges\n"
            . "  • *timeline* — Vue chronologique des bookmarks\n"
            . "  • *lu #3* — Marquer un bookmark comme lu (toggle) _(nouveau v1.28)_\n"
            . "  • *à lire* — Voir tes bookmarks non lus avec progression _(nouveau v1.28)_\n"
            . "  • *lu tout* — Marquer tous les bookmarks comme lus d'un coup _(nouveau v1.29)_\n"
            . "  • *mon jour* — Récap du jour : articles lus, progression, suggestions _(nouveau v1.29)_\n"
            . "  • *stats catégories* — Répartition visuelle par catégorie _(nouveau v1.30)_\n"
            . "  • *historique lecture* — Journal de lecture des 7 derniers jours _(nouveau v1.30)_\n\n"
            . "*⭐ Notes & Favoris :*\n"
            . "  • *noter #3 4* — Donner une note de 1 à 5 à un bookmark\n"
            . "  • *top notés* — Voir tes bookmarks les mieux notés\n"
            . "  • *mes favoris* — Idem, tes coups de cœur\n\n"
            . "*⚙️ Personnalisation :*\n"
            . "  • *follow ai* — Suivre une catégorie ou mot-clé\n"
            . "  • *unfollow tech* — Arrêter de suivre\n"
            . "  • *preferences* — Voir tes intérêts + catégories disponibles\n\n"
            . "*Catégories :* 💻 technology · 🔬 science · 💼 business · ❤️ health · ⚽ sports\n"
            . "🎬 entertainment · 🎮 gaming · 🤖 ai · 🪙 crypto · 🚀 startup · 🎨 design · 🔒 security\n\n"
            . "  • *exporter tag tech* — Exporter tes bookmarks filtrés par tag _(nouveau v1.33)_\n"
            . "  • *semaine en bref* — Aperçu visuel rapide de ta semaine _(nouveau v1.33)_\n\n"
            . "*🆕 Nouveau v1.32 :*\n"
            . "  • *facts #3* — Extraire chiffres, acteurs et dates clés d'un bookmark\n"
            . "  • *plan lecture [sujet]* — Parcours d'apprentissage IA en 3 niveaux\n\n"
            . "*🆕 Nouveau v1.36 :*\n"
            . "  • *brief [sujet]* — Brief exécutif structuré : contexte, faits, impact, actions\n"
            . "  • *insights* — Analyse IA de tes habitudes : tendances, angles morts, connexions cachées\n\n"
            . "*Nouveau v1.35 :*\n"
            . "  • *grouper* — Regroupement IA de tes bookmarks par thème\n"
            . "  • *watch [sujet]* — Suivi de tendance : bookmarks + articles frais\n\n"
            . "*Nouveau v1.34 :*\n"
            . "  • *radar [sujet]* · *supprimer #1 #3 #5*\n\n"
            . "*Nouveau v1.33 :*\n"
            . "  • *exporter tag [tag]* · *semaine en bref*\n\n"
            . "*Nouveau v1.30 :*\n"
            . "  • *stats catégories* · *historique lecture*\n\n"
            . "*Nouveau v1.29 :*\n"
            . "  • *lu tout* · *mon jour*\n\n"
            . "*Nouveau v1.28 :*\n"
            . "  • *lu #3* · *à lire*"
        );
    }
}
