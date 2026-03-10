<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentSummarizerAgent extends BaseAgent
{
    private const URL_PATTERN = '#https?://[^\s<>\[\]"\']+#i';
    private const YOUTUBE_PATTERN = '#(?:https?://)?(?:www\.)?(?:youtube\.com/(?:watch\?v=|shorts/|live/|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#i';
    private const VIMEO_PATTERN = '#(?:https?://)?(?:www\.)?vimeo\.com/(\d+)#i';
    private const TWITTER_PATTERN = '#(?:https?://)?(?:www\.)?(?:twitter|x)\.com/[a-zA-Z0-9_]{1,15}/status/(\d+)#i';
    private const KEYWORD_PATTERN = '/\b(r[eé]sum[eé]r?|summarize|summary|synth[eè]se|synthetiser|tldr|tl;?dr|de\s+quoi\s+parle|lire\s+pour\s+moi|read\s+for\s+me|compare[rz]?|comparaison|vs\.?|bullet|en\s+points?|mots[- ]cl[eé]s\s+seulement|keywords?\s+only|liste\s+des\s+tags?|extraire\s+les\s+tags?|analyse[rz]?\s+le\s+ton|quel\s+est\s+le\s+ton|tone\s+analysis|analyse[rz]?\s+(le\s+)?sentiment|extraire\s+les?\s+citations?|meilleures?\s+citations?|best\s+quotes?|key\s+quotes?|ax[eé][- ]sur|focus[- ]sur|focalise[rz]?[- ]sur|traduis?|traduction|traduire|translate\b|simplifie[rz]?|eli5|vulgar[ié]s[eé]r?|extraire\s+(les?\s+)?actions?|recommandations?|prochaines?\s+[eé]tapes?|next\s+steps?|plan\s+d.action|actions?\s+items?)\b/iu';
    private const TRANSLATE_PATTERN = '/\b(traduis?|traduction|traduire|translate\b)\b/iu';
    private const SUBSTACK_PATTERN = '#(?:https?://)?[a-z0-9-]+\.substack\.com/p/[^\s<>\[\]"\']+#i';
    private const COMPARE_PATTERN = '/\b(compar[eé]r?|compare|vs\.?|versus|diff[eé]rence|entre\s+ces|between\s+these|lequel|laquelle|meilleur|mieux|pr[eé]f[eé]rer|choisir|which|better|best|prefer|choose)\b/iu';
    private const TONE_PATTERN = '/\b(analyse[rz]?\s+le\s+ton|quel\s+est\s+le\s+ton|ton\s+de|tone\s+analysis|tone\s+of|analyse[rz]?\s+(le\s+)?sentiment|sentiment\s+analysis)\b/iu';
    private const LANG_OVERRIDE_PATTERN = '/\b(en\s+(fran[çc]ais|anglais|espagnol|allemand|italien|portugais)|in\s+(french|english|spanish|german|italian|portuguese))\b/iu';
    private const BULLET_PATTERN = '/\b(bullet|en\s+points?|liste\s+de\s+points?|points?\s+cl[eé]s?|key\s+points?)\b/iu';
    private const KEYWORDS_ONLY_PATTERN = '/\b(mots[- ]cl[eé]s\s+seulement|keywords?\s+only|liste\s+des\s+tags?|tags?\s+seulement|extraire\s+les\s+tags?|just\s+tags?|only\s+tags?)\b/iu';
    private const REDDIT_PATTERN = '#(?:https?://)?(?:www\.)?reddit\.com/r/[a-zA-Z0-9_]+/comments/[a-zA-Z0-9_]+#i';
    private const WORD_COUNT_PATTERN = '/\b(?:en|in)\s+(\d{1,4})\s+(?:mots?|words?)\b/iu';

    // Private IP ranges and dangerous hosts to block
    private const PRIVATE_IP_PATTERN = '/^https?:\/\/(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|0\.0\.0\.0|169\.254\.\d+\.\d+)/i';
    private const ONION_PATTERN = '/\.onion(:\d+)?(\/|$)/i';
    private const WIKIPEDIA_PATTERN = '~(?:https?://)?([a-z]{2,3})\.wikipedia\.org/wiki/([^\s<>\[\]"\'#&?]+)~i';
    private const GITHUB_PATTERN = '#(?:https?://)?(?:www\.)?github\.com/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_.-]+)(?:[/\s]|$)#i';
    private const HACKERNEWS_PATTERN = '#(?:https?://)?news\.ycombinator\.com/item\?id=(\d+)#i';
    private const LINKEDIN_PATTERN = '#(?:https?://)?(?:www\.)?linkedin\.com/(?:pulse|posts)[^\s<>\[\]"\']*#i';
    private const ARXIV_PATTERN = '#(?:https?://)?arxiv\.org/(?:abs|pdf)/([0-9]{4}\.[0-9]{4,5}(?:v\d+)?)#i';
    private const FLASH_PATTERN = '/\b(flash|en\s+une\s+phrase|in\s+one\s+sentence|ultra[- ]?court|ultra\s+bref)\b/iu';
    private const FOCUS_PATTERN = '/\b(?:ax[eé][- ]sur|focus[- ]sur|focalis[eé][rz]?[- ]sur|centr[eé][- ]sur|centered?\s+on|focused?\s+on|accent\s+sur|en\s+mettant\s+l[\'"]?accent\s+sur)\s+([^\n,.!?:]{2,60})/iu';
    private const QUOTES_PATTERN = '/\b(extraire\s+les?\s+citations?|meilleures?\s+citations?|best\s+quotes?|key\s+quotes?|notable\s+quotes?|top\s+citations?|top\s+quotes?|passages?\s+cl[eé]s?|extraire\s+(les?\s+)?quotes?|citations?\s+seulement|quotes?\s+only)\b/iu';
    private const SIMPLE_PATTERN = '/\b(simplifie[rz]?|eli5|vulgar[ié]s[eé]r?|vulgarisation|explain\s+simply|pour\s+les\s+nuls|pour\s+d[eé]butants?|niveau\s+d[eé]butant|en\s+termes?\s+simples?|simple\s+(?:explanation|resume|summary)|accessible[- ]?(?:ment)?)\b/iu';
    private const ACTIONS_PATTERN = '/\b(actions?\s+items?|liste\s+(les?\s+)?actions?|extraire\s+(les?\s+)?actions?|recommandations?|prochaines?\s+[eé]tapes?|next\s+steps?|to[- ]do\s+list|points?\s+d.action|plan\s+d.action|que\s+faire|quoi\s+faire|what\s+to\s+do)\b/iu';

    // Internal marker for text-paste summarization
    private const TEXT_PASTE_URL = '__text__';

    public function __construct()
    {
        parent::__construct();
    }

    public function name(): string
    {
        return 'content_summarizer';
    }

    public function description(): string
    {
        return 'Agent de resume de contenu web. Resume automatiquement les articles, pages web, videos YouTube/Vimeo (avec transcription), tweets Twitter/X, pages Wikipedia, depots GitHub, posts Reddit, articles scientifiques Arxiv, posts HackerNews/LinkedIn et newsletters Substack. Supporte les resumes flash (1 phrase), courts, standards, detailles, en points (bullet), en nombre de mots precis, en mode simplifie/ELI5 pour debutants et en mode extraction d\'actions/recommandations. Peut comparer deux contenus, extraire des mots-cles, detecter le ton, traduire le contenu dans une autre langue, estimer le temps de lecture et resumer du texte colle directement.';
    }

    public function keywords(): array
    {
        return [
            'resume', 'résumé', 'resumer', 'résumer', 'summarize', 'summary',
            'resume article', 'resume lien', 'resume url', 'resume page',
            'resume video', 'resume youtube', 'resume vimeo',
            'tldr', 'tl;dr', 'TL;DR',
            'synthese', 'synthèse', 'synthetiser',
            'resume court', 'resume detaille', 'resume bref',
            'short summary', 'detailed summary', 'quick summary',
            'de quoi parle', 'what is this about',
            'lire pour moi', 'read for me',
            'contenu', 'content', 'article', 'lien', 'link', 'url',
            'youtube', 'video', 'vidéo', 'vimeo',
            'twitter', 'tweet', 'x.com',
            'compare', 'comparer', 'comparaison', 'vs',
            'mots-cles', 'tags', 'keywords', 'ton', 'sentiment',
            'bullet', 'en points', 'liste de points',
            'mots-cles seulement', 'keywords only', 'liste des tags',
            'texte', 'coller', 'paste',
            'ton', 'analyse ton', 'tone', 'sentiment', 'quel est le ton',
            'tone analysis', 'analyse sentiment',
            'en anglais', 'in english', 'en francais', 'en espagnol',
            'wikipedia', 'wiki',
            'github', 'readme', 'depot', 'dépôt', 'repository', 'repo',
            'reddit', 'subreddit', 'post reddit',
            'en mots', 'in words', 'en 50 mots', 'en 100 mots', 'en 200 mots',
            'hackernews', 'hacker news', 'ycombinator', 'hn', 'show hn', 'ask hn',
            'linkedin', 'linkedin article', 'linkedin post', 'pulse linkedin',
            'arxiv', 'arxiv.org', 'article scientifique', 'preprint', 'papier',
            'flash', 'en une phrase', 'in one sentence', 'ultra-court',
            'extraire citations', 'meilleures citations', 'best quotes', 'key quotes',
            'top citations', 'top quotes', 'passages cles', 'extraire quotes',
            'citations seulement', 'quotes only',
            'focus sur', 'axe sur', 'axé sur', 'focalise sur', 'centré sur',
            'traduis', 'traduction', 'traduire', 'translate', 'translate to',
            'substack', 'newsletter', 'post substack',
            'simplifie', 'simplifier', 'eli5', 'vulgarise', 'vulgariser',
            'pour les nuls', 'pour debutants', 'en termes simples', 'explain simply',
            'extraire actions', 'liste les actions', 'recommandations',
            'prochaines etapes', 'next steps', 'plan d action', 'action items',
        ];
    }

    public function version(): string
    {
        return '1.12.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match(self::URL_PATTERN, $context->body)
            || (bool) preg_match(self::KEYWORD_PATTERN, $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp();
        }

        // Extract URLs from message
        $urls = $this->extractUrls($body);

        if (empty($urls)) {
            // Check for text paste summarization (keyword + long body, no URL)
            if ($this->isTextPasteRequest($body)) {
                $mode = $this->detectSummaryMode($body);
                $pasteOutputLang = $this->detectOutputLanguage($body);
                return $this->handleTextPaste($context, $body, $mode, $pasteOutputLang);
            }
            return $this->showHelp();
        }

        // Detect summary mode (short/medium/detailed/bullet/keywords)
        $mode = $this->detectSummaryMode($body);

        // Detect explicit output language override (e.g. "en anglais", "in english")
        $outputLang = $this->detectOutputLanguage($body);

        // Detect comparison mode (2 URLs + compare/choice keyword)
        $compareMode = count($urls) === 2 && (bool) preg_match(self::COMPARE_PATTERN, $body);

        // Detect tone analysis mode
        $toneMode = (bool) preg_match(self::TONE_PATTERN, $body);

        // Detect quotes extraction mode
        $quotesMode = (bool) preg_match(self::QUOTES_PATTERN, $body);

        // Detect translation mode (before other modes to avoid conflicts)
        $translateMode = !$compareMode && !$toneMode && !$quotesMode
            && (bool) preg_match(self::TRANSLATE_PATTERN, $body);

        // Detect focus topic (e.g. "axé sur les chiffres", "focus sur les risques")
        $focusTopic = $this->detectFocusTopic($body);

        $this->log($context, 'Content summarization requested', [
            'urls' => $urls,
            'mode' => $mode,
            'compare_mode' => $compareMode,
            'tone_mode' => $toneMode,
            'quotes_mode' => $quotesMode,
            'translate_mode' => $translateMode,
            'focus_topic' => $focusTopic,
            'output_lang' => $outputLang,
        ]);

        if ($compareMode) {
            return $this->handleComparison($context, $urls, $mode, $outputLang);
        }

        // Tone analysis mode: analyse tone/sentiment without full summary
        if ($toneMode) {
            return $this->handleToneAnalysis($context, $urls, $outputLang);
        }

        // Keywords-only mode: extract only tags without full summary
        if ($mode === 'keywords') {
            return $this->handleKeywordsOnly($context, $urls);
        }

        // Quotes extraction mode: extract notable citations/passages
        if ($quotesMode) {
            return $this->handleQuotesExtraction($context, $urls, $outputLang);
        }

        // Translation mode: translate content to target language
        if ($translateMode) {
            if (!$outputLang) {
                return AgentResult::reply(
                    "🌍 *Traduction* — Precise la langue cible !\n\n"
                    . "Exemples :\n"
                    . "- _traduis en anglais_ https://example.com\n"
                    . "- _traduis en espagnol_ https://example.com\n"
                    . "- _translate to german_ https://example.com\n"
                    . "- _traduis en anglais_ [colle ton texte ici...]"
                );
            }
            return $this->handleTranslation($context, $urls, $outputLang);
        }

        $results = [];

        foreach ($urls as $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'Contenu insuffisant ou inaccessible. Le site est peut-etre protege ou necessite une authentification.';
                    $results[] = $this->formatErrorResult($url, $errorMsg);
                    continue;
                }

                $results[] = $this->summarizeContent($context, $content, $url, $mode, $outputLang, $focusTopic);
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Error processing URL: {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);

        $this->log($context, 'Content summarization completed', [
            'urls_count' => count($urls),
            'mode' => $mode,
        ]);

        return AgentResult::reply($output);
    }

    private function handleComparison(AgentContext $context, array $urls, string $mode, ?string $outputLang = null): AgentResult
    {
        $contents = [];
        $metadataCache = [];

        foreach ($urls as $i => $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'contenu inaccessible';
                    return AgentResult::reply(
                        "⚠ Impossible de comparer : {$errorMsg} pour l'URL " . ($i + 1) . ".\n{$url}"
                    );
                }

                $contents[$url] = $content;
                $metadataCache[$url] = [
                    'reading_time' => $this->estimateReadingTime($content),
                    'lang' => $this->detectContentLanguage($content),
                ];
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Comparison fetch failed for {$url}", ['error' => $e->getMessage()]);
                return AgentResult::reply(
                    "⚠ Impossible de comparer : " . $this->friendlyError($e) . "\n{$url}"
                );
            }
        }

        $urlList = array_keys($contents);
        $rt1 = $metadataCache[$urlList[0]]['reading_time'];
        $rt2 = $metadataCache[$urlList[1]]['reading_time'];
        $rt1Str = $rt1 <= 1 ? '< 1 min' : "{$rt1} min";
        $rt2Str = $rt2 <= 1 ? '< 1 min' : "{$rt2} min";

        $combinedContent = "=== SOURCE 1 (lecture: {$rt1Str}) ===\n{$contents[$urlList[0]]}\n\n=== SOURCE 2 (lecture: {$rt2Str}) ===\n{$contents[$urlList[1]]}";

        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $systemPrompt = <<<PROMPT
Tu es un expert en analyse comparative de contenus. Compare deux sources de maniere objective et structuree.

FORMAT DE REPONSE (pour WhatsApp):
*🔍 COMPARAISON DE CONTENUS*

*Source 1 :* [titre/nom bref]
*Source 2 :* [titre/nom bref]

*Points communs :*
- [point commun 1]
- [point commun 2]

*Differences cles :*
- *Sujet/Angle :* [Source 1] vs [Source 2]
- *Ton :* [ton Source 1] vs [ton Source 2]
- *Public cible :* [audience Source 1] vs [audience Source 2]
- *Profondeur :* [niveau detail Source 1] vs [niveau detail Source 2]

*Quelle source privilegier ?*
[Recommandation courte et objective selon le contexte]

*Mots-cles communs :* #[tag1] #[tag2] #[tag3]

REGLES:
- Sois factuel et neutre
- Mets en avant les differences les plus importantes
- Compare le ton (informatif, critique, alarmiste, technique, educatif, etc.)
- Indique le public cible apparent (debutants, experts, grand public, etc.)
- Si une date de publication est disponible dans les metadonnees, mentionne-la pour chaque source
- N'invente rien, base-toi uniquement sur les contenus fournis
PROMPT;

        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        if ($outputLang && isset($langNames[$outputLang])) {
            $systemPrompt .= "\n\nReponds OBLIGATOIREMENT en {$langNames[$outputLang]}.";
        } else {
            $systemPrompt .= "\n\nReponds en francais sauf si l'utilisateur semble anglophone.";
        }

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $response = $this->claude->chat($combinedContent, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply("⚠ Impossible de generer la comparaison. Reessaie dans quelques instants.");
        }

        $output = trim($response)
            . "\n\n🔗 Source 1: {$urlList[0]}"
            . "\n🔗 Source 2: {$urlList[1]}";

        $this->log($context, 'Content comparison completed', ['urls' => $urlList]);

        return AgentResult::reply($output);
    }

    /**
     * Extract only keyword tags from content without a full summary.
     */
    private function handleKeywordsOnly(AgentContext $context, array $urls): AgentResult
    {
        $results = [];
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
        $systemPrompt = $this->buildKeywordsSystemPrompt();
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        foreach ($urls as $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'Contenu insuffisant ou inaccessible.';
                    $results[] = $this->formatErrorResult($url, $errorMsg);
                    continue;
                }

                $messages = [['role' => 'user', 'content' => $content]];
                $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 512);

                if (!$response) {
                    $results[] = $this->formatErrorResult($url, 'Impossible de generer les mots-cles.');
                    continue;
                }

                $shortUrl = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '...' : $url;
                $results[] = trim($response) . "\n\n🔗 _{$shortUrl}_";
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Keywords extraction failed for {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);
        $this->log($context, 'Keywords extraction completed', ['urls_count' => count($urls)]);

        return AgentResult::reply($output);
    }

    /**
     * Analyse uniquement le ton/sentiment d'un ou plusieurs contenus (sans résumé complet).
     */
    private function handleToneAnalysis(AgentContext $context, array $urls, ?string $outputLang): AgentResult
    {
        $results = [];
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        $forcedLangName = $outputLang ? ($langNames[$outputLang] ?? $outputLang) : null;
        $langInstruction = $forcedLangName
            ? "Reponds OBLIGATOIREMENT en {$forcedLangName}, quelle que soit la langue du contenu."
            : "Reponds en francais. Si le contenu est en anglais et que l'utilisateur semble anglophone, tu peux repondre en anglais.";

        $systemPrompt = $this->buildToneSystemPrompt($langInstruction);
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        foreach ($urls as $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'Contenu insuffisant ou inaccessible.';
                    $results[] = $this->formatErrorResult($url, $errorMsg);
                    continue;
                }

                $messages = [['role' => 'user', 'content' => $content]];
                $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 768);

                if (!$response) {
                    $results[] = $this->formatErrorResult($url, 'Impossible de generer l\'analyse de ton.');
                    continue;
                }

                $shortUrl = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '...' : $url;
                $results[] = trim($response) . "\n\n🔗 _{$shortUrl}_";
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Tone analysis failed for {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);
        $this->log($context, 'Tone analysis completed', ['urls_count' => count($urls)]);

        return AgentResult::reply($output);
    }

    /**
     * Analyse le ton d'un contenu déjà récupéré (texte collé directement, pas d'URL à fetcher).
     */
    private function handleToneAnalysisForContent(AgentContext $context, string $content, ?string $outputLang): AgentResult
    {
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        $forcedLangName = $outputLang ? ($langNames[$outputLang] ?? $outputLang) : null;
        $langInstruction = $forcedLangName
            ? "Reponds OBLIGATOIREMENT en {$forcedLangName}, quelle que soit la langue du contenu."
            : "Reponds en francais. Si le contenu est en anglais et que l'utilisateur semble anglophone, tu peux repondre en anglais.";

        $systemPrompt = $this->buildToneSystemPrompt($langInstruction);
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 768);

        if (!$response) {
            return AgentResult::reply("⚠ Impossible de generer l'analyse de ton. Reessaie dans quelques instants.");
        }

        $this->log($context, 'Tone analysis on text paste completed');
        return AgentResult::reply("📝 " . trim($response));
    }

    /**
     * Construit le prompt système pour l'analyse de ton/sentiment.
     */
    private function buildToneSystemPrompt(string $langInstruction): string
    {
        return <<<PROMPT
Tu es un expert en analyse de ton, style et sentiment pour des contenus web. Analyse le ton du contenu fourni de maniere precise et structuree.

FORMAT DE REPONSE (pour WhatsApp):
*🎭 ANALYSE DU TON*

*Ton principal :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif | Satirique | Polemique | Enthousiaste | Pessimiste]

*Registre :* [Academique | Journalistique | Marketing | Conversationnel | Scientifique | Politique | Humoristique | Formel | Informel]

*Emotions detectees :* [liste les 2-3 principales emotions ou attitudes perceptibles]

*Public cible :* [Grand public | Experts | Debutants | Journalistes | Investisseurs | Etudiants | Professionnels | etc.]

*Objectif apparent :* [Informer | Convaincre | Vendre | Divertir | Alerter | Former | Debattre | etc.]

*Exemples du ton :* [1-2 extraits courts illustrant le ton dominant]

*Score de biais :* [Neutre ✓ | Legerement oriente | Clairement partisan | Tres engage] — [breve explication]

REGLES:
- {$langInstruction}
- Base-toi uniquement sur le contenu fourni, n'invente rien
- Sois precis et objectif dans ton analyse
- Si le contenu est insuffisant (metadonnees seulement), precise-le
PROMPT;
    }

    /**
     * Extract the focus topic from the message body (e.g. "axé sur les chiffres").
     * Returns the topic string or null if no focus trigger is found.
     */
    private function detectFocusTopic(string $body): ?string
    {
        if (!preg_match(self::FOCUS_PATTERN, $body, $m)) {
            return null;
        }
        $topic = preg_replace(self::URL_PATTERN, '', $m[1] ?? '');
        $topic = trim(preg_replace('/\s+/', ' ', $topic));
        return mb_strlen($topic) >= 2 ? mb_substr($topic, 0, 60) : null;
    }

    /**
     * Extract notable quotes/citations from one or several URLs.
     */
    private function handleQuotesExtraction(AgentContext $context, array $urls, ?string $outputLang): AgentResult
    {
        $results = [];
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        $forcedLangName = $outputLang ? ($langNames[$outputLang] ?? $outputLang) : null;
        $langInstruction = $forcedLangName
            ? "Reponds OBLIGATOIREMENT en {$forcedLangName}, quelle que soit la langue du contenu."
            : "Reponds en francais. Si le contenu est en anglais et que l'utilisateur semble anglophone, tu peux repondre en anglais.";

        $systemPrompt = $this->buildQuotesSystemPrompt($langInstruction);
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        foreach ($urls as $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'Contenu insuffisant ou inaccessible.';
                    $results[] = $this->formatErrorResult($url, $errorMsg);
                    continue;
                }

                $messages = [['role' => 'user', 'content' => $content]];
                $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 1024);

                if (!$response) {
                    $results[] = $this->formatErrorResult($url, "Impossible d'extraire les citations.");
                    continue;
                }

                $shortUrl = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '...' : $url;
                $results[] = trim($response) . "\n\n🔗 _{$shortUrl}_";
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Quotes extraction failed for {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);
        $this->log($context, 'Quotes extraction completed', ['urls_count' => count($urls)]);

        return AgentResult::reply($output);
    }

    /**
     * Translate the content of one or several URLs to a target language.
     */
    private function handleTranslation(AgentContext $context, array $urls, string $targetLang): AgentResult
    {
        $results = [];
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        $targetLangName = $langNames[$targetLang] ?? $targetLang;
        $systemPrompt = $this->buildTranslationSystemPrompt($targetLangName);
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        foreach ($urls as $url) {
            try {
                $content = $this->fetchContentForUrl($url);

                if (!$content || mb_strlen($content) < 50 || $this->isErrorContent($content)) {
                    $errorMsg = ($content && $this->isErrorContent($content))
                        ? $this->extractErrorMessage($content)
                        : 'Contenu insuffisant ou inaccessible.';
                    $results[] = $this->formatErrorResult($url, $errorMsg);
                    continue;
                }

                $messages = [['role' => 'user', 'content' => $content]];
                $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 2048);

                if (!$response) {
                    $results[] = $this->formatErrorResult($url, 'Impossible de generer la traduction.');
                    continue;
                }

                $shortUrl = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '...' : $url;
                $results[] = trim($response) . "\n\n🔗 _{$shortUrl}_";
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Translation failed for {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);
        $this->log($context, 'Translation completed', ['urls_count' => count($urls), 'target_lang' => $targetLang]);

        return AgentResult::reply($output);
    }

    /**
     * Translate directly pasted text (no URL) to a target language.
     */
    private function handleTranslationForContent(AgentContext $context, string $content, string $targetLang): AgentResult
    {
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        $targetLangName = $langNames[$targetLang] ?? $targetLang;
        $systemPrompt = $this->buildTranslationSystemPrompt($targetLangName);
        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 2048);

        if (!$response) {
            return AgentResult::reply("⚠ Impossible de generer la traduction. Reessaie dans quelques instants.");
        }

        $this->log($context, 'Translation on text paste completed', ['target_lang' => $targetLang]);
        return AgentResult::reply("🌍 " . trim($response));
    }

    /**
     * Build the system prompt for translation mode.
     */
    private function buildTranslationSystemPrompt(string $targetLangName): string
    {
        return <<<PROMPT
Tu es un traducteur expert. Traduis le contenu fourni en {$targetLangName} de maniere fidele, naturelle et fluide.

FORMAT DE REPONSE (pour WhatsApp):
*🌍 TRADUCTION EN {$targetLangName}*

*Titre :* [titre traduit si disponible]

[Traduction complete et fidele du contenu principal]

*Source :* [langue detectee du contenu original]

REGLES:
- Traduis OBLIGATOIREMENT en {$targetLangName}, quelle que soit la langue source
- Conserve le sens, le ton et le style de l'original
- Traduis uniquement le contenu textuel principal (titre + corps), pas les metadonnees techniques
- Si le contenu est deja dans la langue cible, precise-le et fournis quand meme le texte
- Garde la mise en forme avec *gras* pour les elements importants si pertinent
- Ne resumes pas : traduis fidelement, en conservant toute la richesse du texte original
- Si le contenu est trop court ou ne contient que des metadonnees, indique-le
PROMPT;
    }

    /**
     * Build the system prompt for quotes/citations extraction.
     */
    private function buildQuotesSystemPrompt(string $langInstruction): string
    {
        return <<<PROMPT
Tu es un expert en extraction de citations et passages marquants. Identifie et extrait les meilleures citations et passages notables du contenu fourni.

FORMAT DE REPONSE (pour WhatsApp):
*💬 CITATIONS NOTABLES*

*Citation 1 :*
_"[texte exact ou passage representatif]"_
→ Contexte : [pourquoi cette citation est importante ou representative]

*Citation 2 :*
_"[texte exact ou passage representatif]"_
→ Contexte : [pourquoi cette citation est importante ou representative]

[...entre 3 et 7 citations au total]

*Themes :* #[theme1] #[theme2] #[theme3]

REGLES:
- {$langInstruction}
- Extrait les citations les plus marquantes, percutantes ou révélatrices (3 a 7 maximum)
- Les citations doivent etre des extraits verbatim ou tres proches du texte original, entre guillemets
- Si le contenu ne contient pas de citations directes, extrait les passages les plus significatifs ou idees cles
- Indique brievement pourquoi chaque citation est notable (impact, originalite, cle de comprehension)
- Si le contenu est insuffisant (metadonnees seulement), extrait les elements les plus informatifs disponibles et precise-le
- Base-toi uniquement sur le contenu fourni, n'invente rien
PROMPT;
    }

    /**
     * Summarize text pasted directly (no URL), with keyword + body length > 300 chars.
     */
    private function handleTextPaste(AgentContext $context, string $body, string $mode, ?string $outputLang = null): AgentResult
    {
        $content = "[TEXTE DIRECT]\n\n" . mb_substr(trim($body), 0, 10000);
        $this->log($context, 'Text paste summarization', ['length' => mb_strlen($body), 'mode' => $mode]);

        if ($mode === 'keywords') {
            $model = $this->resolveModel($context);
            $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
            $systemPrompt = $this->buildKeywordsSystemPrompt();
            if ($memoryPrompt) $systemPrompt .= "\n\n" . $memoryPrompt;

            $messages = [['role' => 'user', 'content' => $content]];
            $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 512);

            if (!$response) {
                return AgentResult::reply("⚠ Impossible d'extraire les mots-cles. Reessaie dans quelques instants.");
            }
            return AgentResult::reply("📝 " . trim($response));
        }

        // Tone analysis on pasted text (no URL to fetch, analyse content directly)
        if ((bool) preg_match(self::TONE_PATTERN, $body)) {
            return $this->handleToneAnalysisForContent($context, $content, $outputLang);
        }

        // Translation mode on pasted text
        if ((bool) preg_match(self::TRANSLATE_PATTERN, $body)) {
            if (!$outputLang) {
                return AgentResult::reply(
                    "🌍 *Traduction* — Precise la langue cible !\n\n"
                    . "Exemples :\n"
                    . "- _traduis en anglais_ [colle ton texte ici...]\n"
                    . "- _traduis en espagnol_ [colle ton texte ici...]"
                );
            }
            return $this->handleTranslationForContent($context, $content, $outputLang);
        }

        // Quotes extraction on pasted text
        if ((bool) preg_match(self::QUOTES_PATTERN, $body)) {
            $model = $this->resolveModel($context);
            $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
            $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
            $forcedLangName = $outputLang ? ($langNames[$outputLang] ?? $outputLang) : null;
            $langInstruction = $forcedLangName
                ? "Reponds OBLIGATOIREMENT en {$forcedLangName}."
                : "Reponds en francais.";
            $systemPrompt = $this->buildQuotesSystemPrompt($langInstruction);
            if ($memoryPrompt) $systemPrompt .= "\n\n" . $memoryPrompt;

            $messages = [['role' => 'user', 'content' => $content]];
            $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, 1024);
            if (!$response) {
                return AgentResult::reply("⚠ Impossible d'extraire les citations. Reessaie dans quelques instants.");
            }
            $this->log($context, 'Quotes extraction on text paste completed');
            return AgentResult::reply("📝 " . trim($response));
        }

        $focusTopic = $this->detectFocusTopic($body);
        $summary = $this->summarizeContent($context, $content, self::TEXT_PASTE_URL, $mode, $outputLang, $focusTopic);
        return AgentResult::reply($summary);
    }

    /**
     * Shared helper: fetch and return content for any URL type (YouTube, Vimeo, Twitter, web).
     */
    private function fetchContentForUrl(string $url): ?string
    {
        if ($this->isYouTubeUrl($url)) {
            return $this->extractYouTubeContent($url);
        }
        if ($this->isVimeoUrl($url)) {
            return $this->extractVimeoContent($url);
        }
        if ($this->isTwitterUrl($url)) {
            return $this->extractTwitterContent($url);
        }
        if ($this->isWikipediaUrl($url)) {
            return $this->extractWikipediaContent($url);
        }
        if ($this->isGithubUrl($url)) {
            return $this->extractGithubContent($url);
        }
        if ($this->isRedditUrl($url)) {
            return $this->extractRedditContent($url);
        }
        if ($this->isHackerNewsUrl($url)) {
            return $this->extractHackerNewsContent($url);
        }
        if ($this->isLinkedInUrl($url)) {
            return $this->extractLinkedInContent($url);
        }
        if ($this->isArxivUrl($url)) {
            return $this->extractArxivContent($url);
        }
        if ($this->isSubstackUrl($url)) {
            return $this->extractSubstackContent($url);
        }
        return $this->extractWebContent($url);
    }

    private function extractUrls(string $body): array
    {
        preg_match_all(self::URL_PATTERN, $body, $matches);
        $urls = $matches[0] ?? [];

        // Filter out insecure/private URLs
        $urls = array_filter($urls, fn($url) => $this->isSecureUrl($url));

        // Deduplicate and limit to 3 URLs
        return array_slice(array_unique(array_values($urls)), 0, 3);
    }

    private function isSecureUrl(string $url): bool
    {
        // Block non-HTTP schemes (file://, ftp://, data://, etc.)
        if (!preg_match('#^https?://#i', $url)) {
            Log::info("[content_summarizer] Blocked non-HTTP URL scheme: {$url}");
            return false;
        }

        // Block private IPs, localhost, link-local and 0.0.0.0
        if (preg_match(self::PRIVATE_IP_PATTERN, $url)) {
            Log::info("[content_summarizer] Blocked private/local URL: {$url}");
            return false;
        }

        // Block .onion domains (Tor hidden services)
        if (preg_match(self::ONION_PATTERN, $url)) {
            Log::info("[content_summarizer] Blocked .onion URL: {$url}");
            return false;
        }

        // Must have a valid host
        $parsed = parse_url($url);
        if (!isset($parsed['host']) || empty($parsed['host'])) {
            return false;
        }

        return true;
    }

    /**
     * Detect content strings that represent HTTP errors, not actual content.
     * These should never be sent to Claude for summarization.
     */
    private function isErrorContent(string $content): bool
    {
        return str_starts_with($content, '[ACCES REFUSE]')
            || str_starts_with($content, '[RATE LIMIT]')
            || str_starts_with($content, '[ERREUR SERVEUR]')
            || str_starts_with($content, '[CONTENU BLOQUE]');
    }

    /**
     * Extract a human-readable error message from an error content marker string.
     */
    private function extractErrorMessage(string $content): string
    {
        if (preg_match('/\(([^)]+)\)/', $content, $m)) {
            return $m[1];
        }
        return 'Acces refuse ou contenu inaccessible. Le site bloque peut-etre les acces automatiques.';
    }

    /**
     * Returns true if the body is a text-paste summarization request:
     * must contain a summarize keyword AND be >= 300 chars (but have no extractable URLs).
     */
    private function isTextPasteRequest(string $body): bool
    {
        return mb_strlen($body) >= 200 && (bool) preg_match(self::KEYWORD_PATTERN, $body);
    }

    private function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match(self::YOUTUBE_PATTERN, $url);
    }

    private function isVimeoUrl(string $url): bool
    {
        return (bool) preg_match(self::VIMEO_PATTERN, $url);
    }

    private function isTwitterUrl(string $url): bool
    {
        return (bool) preg_match(self::TWITTER_PATTERN, $url);
    }

    private function isWikipediaUrl(string $url): bool
    {
        return (bool) preg_match(self::WIKIPEDIA_PATTERN, $url);
    }

    private function isGithubUrl(string $url): bool
    {
        return (bool) preg_match(self::GITHUB_PATTERN, $url);
    }

    private function isRedditUrl(string $url): bool
    {
        return (bool) preg_match(self::REDDIT_PATTERN, $url);
    }

    private function isHackerNewsUrl(string $url): bool
    {
        return (bool) preg_match(self::HACKERNEWS_PATTERN, $url);
    }

    private function isLinkedInUrl(string $url): bool
    {
        return (bool) preg_match(self::LINKEDIN_PATTERN, $url);
    }

    private function isArxivUrl(string $url): bool
    {
        return (bool) preg_match(self::ARXIV_PATTERN, $url);
    }

    private function isSubstackUrl(string $url): bool
    {
        return (bool) preg_match(self::SUBSTACK_PATTERN, $url);
    }

    private function extractArxivId(string $url): ?string
    {
        if (preg_match(self::ARXIV_PATTERN, $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractYouTubeVideoId(string $url): ?string
    {
        if (preg_match(self::YOUTUBE_PATTERN, $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractVimeoVideoId(string $url): ?string
    {
        if (preg_match(self::VIMEO_PATTERN, $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Detect summary mode from message body.
     * Returns: 'keywords' | 'bullet' | 'detailed' | 'short' | 'medium'
     */
    private function detectSummaryMode(string $body): string
    {
        // Keywords-only extraction mode (check before others to avoid conflicts)
        if (preg_match(self::KEYWORDS_ONLY_PATTERN, $body)) {
            return 'keywords';
        }

        // Word count mode: "en 150 mots", "in 200 words"
        if (preg_match(self::WORD_COUNT_PATTERN, $body, $wm)) {
            $count = max(20, min(2000, (int) $wm[1]));
            return "wordcount:{$count}";
        }

        // Flash mode: exactly 1 sentence (check before bullet/short)
        if (preg_match(self::FLASH_PATTERN, $body)) {
            return 'flash';
        }

        // Bullet points mode
        if (preg_match(self::BULLET_PATTERN, $body)) {
            return 'bullet';
        }

        if (preg_match('/\b(detaille|detailed|complet|full|long|approfondi|in[- ]?depth|exhaustif)\b/iu', $body)) {
            return 'detailed';
        }

        if (preg_match('/\b(court|short|bref|brief|rapide|quick|tldr|tl;?dr|resume\s+court|en\s+bref|en\s+quelques\s+mots)\b/iu', $body)) {
            return 'short';
        }

        // Simplified/ELI5 mode: explain for a layman
        if (preg_match(self::SIMPLE_PATTERN, $body)) {
            return 'simple';
        }

        // Actions/recommendations extraction mode
        if (preg_match(self::ACTIONS_PATTERN, $body)) {
            return 'actions';
        }

        return 'medium';
    }

    /**
     * Detect an explicit output language requested by the user.
     * Returns a BCP-47 language code or null if no override detected.
     */
    private function detectOutputLanguage(string $body): ?string
    {
        if (preg_match('/\b(en\s+anglais|in\s+english)\b/iu', $body)) return 'en';
        if (preg_match('/\b(en\s+fran[çc]ais|in\s+french)\b/iu', $body)) return 'fr';
        if (preg_match('/\b(en\s+espagnol|in\s+spanish)\b/iu', $body)) return 'es';
        if (preg_match('/\b(en\s+allemand|in\s+german)\b/iu', $body)) return 'de';
        if (preg_match('/\b(en\s+italien|in\s+italian)\b/iu', $body)) return 'it';
        if (preg_match('/\b(en\s+portugais|in\s+portuguese)\b/iu', $body)) return 'pt';
        return null;
    }

    /**
     * @deprecated Use detectSummaryMode() — kept for backward-compatible test reflection calls.
     */
    private function detectSummaryLength(string $body): string
    {
        return $this->detectSummaryMode($body);
    }

    private function estimateReadingTime(string $content): int
    {
        $text = strip_tags($content);
        $wordCount = preg_match_all('/\S+/', $text, $matches);
        return (int) ceil(($wordCount ?: 1) / 200);
    }

    private function detectContentLanguage(string $content): string
    {
        $indicators = [
            'fr' => ['le', 'la', 'les', 'de', 'du', 'des', 'est', 'une', 'pour', 'dans', 'que', 'qui', 'sur', 'avec', 'par', 'pas', 'plus', 'mais', 'ce', 'au'],
            'en' => ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'its'],
            'es' => ['el', 'la', 'los', 'las', 'del', 'que', 'una', 'con', 'por', 'para', 'como', 'pero', 'este', 'esta', 'son', 'sus', 'más', 'todo'],
            'de' => ['der', 'die', 'das', 'und', 'ist', 'von', 'den', 'dem', 'mit', 'für', 'auf', 'ein', 'eine', 'nicht', 'auch', 'sich', 'als', 'sind'],
            'it' => ['il', 'lo', 'gli', 'dei', 'del', 'che', 'una', 'per', 'con', 'sono', 'non', 'questo', 'dalla', 'nella', 'anche', 'come'],
        ];

        $contentLower = mb_strtolower($content);
        $words = preg_split('/\s+/', $contentLower, -1, PREG_SPLIT_NO_EMPTY);

        $scores = array_fill_keys(array_keys($indicators), 0);
        foreach ($words as $word) {
            $word = trim($word, '.,;:!?-"\'()[]');
            foreach ($indicators as $lang => $wordList) {
                if (in_array($word, $wordList, true)) $scores[$lang]++;
            }
        }

        arsort($scores);
        $detected = array_key_first($scores);
        return $detected ?: 'fr';
    }

    private function extractYouTubeContent(string $url): ?string
    {
        $videoId = $this->extractYouTubeVideoId($url);
        if (!$videoId) return null;

        $isLive = str_contains($url, '/live/');

        $transcript = $this->getYouTubeTranscript($videoId);
        $metadata = $this->getYouTubeMetadata($videoId, $isLive);

        if ($transcript) {
            $prefix = $isLive ? '[VIDEO YOUTUBE LIVE]' : '[VIDEO YOUTUBE]';
            if ($metadata) {
                return "{$metadata}\n\nTRANSCRIPTION:\n{$transcript}";
            }
            return "{$prefix}\n{$transcript}";
        }

        if ($metadata) {
            return $metadata;
        }

        return null;
    }

    private function extractVimeoContent(string $url): ?string
    {
        $videoId = $this->extractVimeoVideoId($url);
        if (!$videoId) return null;

        try {
            $response = Http::timeout(10)->get('https://vimeo.com/api/oembed.json', [
                'url' => $url,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $title = $data['title'] ?? 'Titre inconnu';
                $author = $data['author_name'] ?? 'Auteur inconnu';
                $description = $data['description'] ?? '';
                $duration = $data['duration'] ?? 0;
                $durationStr = $duration > 0 ? gmdate('H:i:s', $duration) : 'duree inconnue';
                $width = $data['width'] ?? null;
                $height = $data['height'] ?? null;
                $resolution = ($width && $height) ? " | Resolution: {$width}x{$height}" : '';

                $content = "[VIDEO VIMEO] Titre: {$title} | Auteur/Chaine: {$author} | Duree: {$durationStr}{$resolution}";
                if ($description) {
                    $content .= "\nDescription: " . mb_substr($description, 0, 1000);
                }
                $content .= "\n(Transcription non disponible - resume base sur les metadonnees)";
                return $content;
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] Vimeo oEmbed failed for {$videoId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Attempt to extract content from a Twitter/X tweet URL.
     * Twitter heavily rate-limits bots, so we try OG metadata and fall back gracefully.
     */
    private function extractTwitterContent(string $url): ?string
    {
        preg_match(self::TWITTER_PATTERN, $url, $matches);
        $tweetId = $matches[1] ?? null;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->get($url);

            if ($response->successful()) {
                $parsed = $this->parseHtmlContent($response->body(), $url);
                if ($parsed && mb_strlen($parsed) > 100) {
                    return preg_replace('/^\[PAGE WEB\]/', '[TWEET / POST X]', $parsed);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] Twitter/X fetch failed for {$url}: " . $e->getMessage());
        }

        // Graceful fallback: return a metadata-only marker for Claude
        $tweetInfo = $tweetId ? " | Tweet ID: {$tweetId}" : '';
        return "[TWEET / POST X]{$tweetInfo} URL: {$url}\n(Acces limite par Twitter/X. Resume base sur les metadonnees disponibles — le contenu complet du tweet peut etre inaccessible.)";
    }

    /**
     * Extract content from a Wikipedia article via the official REST API.
     * Falls back to regular web scraping if the API fails.
     */
    private function extractWikipediaContent(string $url): ?string
    {
        if (!preg_match(self::WIKIPEDIA_PATTERN, $url, $m)) {
            return $this->extractWebContent($url);
        }

        $lang = strtolower($m[1]);
        $rawTitle = $m[2];
        $encodedTitle = urlencode(str_replace(' ', '_', $rawTitle));

        try {
            $summaryResponse = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ZeniClaw/1.6 (content summarizer; contact@zeniclaw.app)'])
                ->get("https://{$lang}.wikipedia.org/api/rest_v1/page/summary/{$encodedTitle}");

            if ($summaryResponse->successful()) {
                $data = $summaryResponse->json();
                $pageTitle = $data['title'] ?? $rawTitle;
                $description = $data['description'] ?? '';
                $extract = $data['extract'] ?? '';
                $lastModified = isset($data['timestamp']) ? substr($data['timestamp'], 0, 10) : '';

                $content = "[PAGE WIKIPEDIA] Titre: {$pageTitle}";
                if ($description) $content .= "\nDescription: {$description}";
                if ($lastModified) $content .= "\nDerniere modification: {$lastModified}";
                $content .= "\nURL: {$url}";

                if ($extract) {
                    $content .= "\n\nRESUME WIKIPEDIA:\n{$extract}";
                }

                // If extract is short, fetch the lead section for more detail
                if (mb_strlen($extract) < 600) {
                    try {
                        $sectionsResponse = Http::timeout(10)
                            ->withHeaders(['User-Agent' => 'ZeniClaw/1.6 (content summarizer; contact@zeniclaw.app)'])
                            ->get("https://{$lang}.wikipedia.org/api/rest_v1/page/mobile-sections/{$encodedTitle}");

                        if ($sectionsResponse->successful()) {
                            $sectionsData = $sectionsResponse->json();
                            $leadHtml = $sectionsData['lead']['sections'][0]['text'] ?? '';
                            if ($leadHtml) {
                                $leadText = strip_tags($leadHtml);
                                $leadText = html_entity_decode($leadText, ENT_QUOTES, 'UTF-8');
                                $leadText = preg_replace('/\s+/', ' ', trim($leadText));
                                $content .= "\n\nCONTENU DETAILLE:\n" . mb_substr($leadText, 0, 5000);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::debug("[content_summarizer] Wikipedia sections API failed: " . $e->getMessage());
                    }
                }

                return $content;
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] Wikipedia API failed for {$url}: " . $e->getMessage());
        }

        // Fallback to regular web scraping
        return $this->extractWebContent($url);
    }

    /**
     * Extract metadata, description and README from a GitHub repository via the GitHub API.
     * Falls back to regular web scraping if the API fails.
     */
    private function extractGithubContent(string $url): ?string
    {
        if (!preg_match('#github\.com/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_.-]+)#i', $url, $m)) {
            return $this->extractWebContent($url);
        }

        $owner = $m[1];
        $repo = preg_replace('/\.git$/', '', rtrim($m[2], '/'));

        try {
            $apiHeaders = [
                'User-Agent' => 'ZeniClaw/1.6 (content summarizer; contact@zeniclaw.app)',
                'Accept'     => 'application/vnd.github.v3+json',
            ];

            $repoResponse = Http::timeout(10)->withHeaders($apiHeaders)
                ->get("https://api.github.com/repos/{$owner}/{$repo}");

            if (!$repoResponse->successful()) {
                return $this->extractWebContent($url);
            }

            $data = $repoResponse->json();

            $fullName    = $data['full_name']         ?? "{$owner}/{$repo}";
            $description = $data['description']       ?? '';
            $language    = $data['language']          ?? '';
            $stars       = $data['stargazers_count']  ?? 0;
            $forks       = $data['forks_count']       ?? 0;
            $openIssues  = $data['open_issues_count'] ?? 0;
            $topics      = implode(', ', $data['topics'] ?? []);
            $license     = $data['license']['name']   ?? '';
            $homepage    = $data['homepage']          ?? '';
            $updatedAt   = isset($data['pushed_at'])  ? substr($data['pushed_at'], 0, 10) : '';
            $isArchived  = $data['archived']          ?? false;
            $isForked    = $data['fork']              ?? false;

            $starsStr = $stars >= 1000 ? round($stars / 1000, 1) . 'k' : (string) $stars;
            $forksStr = $forks >= 1000 ? round($forks / 1000, 1) . 'k' : (string) $forks;

            $content = "[DEPOT GITHUB] {$fullName}";
            if ($description)  $content .= "\nDescription: {$description}";
            if ($language)     $content .= "\nLangage principal: {$language}";
            $content .= "\nEtoiles: {$starsStr} | Forks: {$forksStr} | Issues ouvertes: {$openIssues}";
            if ($topics)       $content .= "\nTopics: {$topics}";
            if ($license)      $content .= "\nLicence: {$license}";
            if ($homepage)     $content .= "\nSite web: {$homepage}";
            if ($updatedAt)    $content .= "\nDernier commit: {$updatedAt}";
            if ($isArchived)   $content .= "\n(Ce depot est archive — plus activement maintenu)";
            if ($isForked)     $content .= "\n(Ce depot est un fork)";

            // Fetch README
            try {
                $readmeResponse = Http::timeout(10)
                    ->withHeaders(array_merge($apiHeaders, ['Accept' => 'application/vnd.github.raw']))
                    ->get("https://api.github.com/repos/{$owner}/{$repo}/readme");

                if ($readmeResponse->successful()) {
                    $readmeData = $readmeResponse->json();
                    if (!empty($readmeData['content'])) {
                        $readmeRaw = base64_decode(str_replace("\n", '', $readmeData['content']));
                        // Strip common markdown syntax to plain text
                        $readmeText = preg_replace('/#{1,6}\s+/', '', $readmeRaw);
                        $readmeText = preg_replace('/!\[.*?\]\(.*?\)/', '', $readmeText);
                        $readmeText = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $readmeText);
                        $readmeText = preg_replace('/`{3}[^`]*`{3}/s', '', $readmeText);
                        $readmeText = preg_replace('/`([^`]+)`/', '$1', $readmeText);
                        $readmeText = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $readmeText);
                        $readmeText = preg_replace('/^>\s+.*$/m', '', $readmeText);
                        $readmeText = preg_replace('/[ \t]+/', ' ', $readmeText);
                        $readmeText = preg_replace('/\n{3,}/', "\n\n", trim($readmeText));
                        $content .= "\n\nREADME:\n" . mb_substr($readmeText, 0, 5000);
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("[content_summarizer] GitHub README fetch failed for {$owner}/{$repo}: " . $e->getMessage());
            }

            return $content;
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] GitHub API failed for {$url}: " . $e->getMessage());
        }

        return $this->extractWebContent($url);
    }

    /**
     * Extract content from a Reddit post via the Reddit JSON API.
     * Falls back to regular web scraping if the API fails.
     */
    private function extractRedditContent(string $url): ?string
    {
        // Strip query/fragment and append .json for the API
        $apiUrl = preg_replace('/[?#].*$/', '', rtrim($url, '/')) . '.json';

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'ZeniClaw/1.7 (content summarizer; contact@zeniclaw.app)',
                    'Accept' => 'application/json',
                ])
                ->get($apiUrl);

            if (!$response->successful()) {
                return $this->extractWebContent($url);
            }

            $data = $response->json();

            if (!is_array($data) || empty($data[0])) {
                return $this->extractWebContent($url);
            }

            $post = $data[0]['data']['children'][0]['data'] ?? null;
            if (!$post) {
                return $this->extractWebContent($url);
            }

            $title       = $post['title'] ?? 'Titre inconnu';
            $author      = $post['author'] ?? 'Auteur inconnu';
            $subreddit   = $post['subreddit_name_prefixed'] ?? ('r/' . ($post['subreddit'] ?? '?'));
            $score       = $post['score'] ?? 0;
            $numComments = $post['num_comments'] ?? 0;
            $selftext    = $post['selftext'] ?? '';
            $isVideo     = $post['is_video'] ?? false;
            $postUrl     = $post['url'] ?? $url;
            $flair       = $post['link_flair_text'] ?? '';
            $createdAt   = isset($post['created_utc']) ? date('Y-m-d', (int) $post['created_utc']) : '';
            $upvoteRatio = isset($post['upvote_ratio']) ? round($post['upvote_ratio'] * 100) . '%' : '';

            $scoreStr = $score >= 1000 ? round($score / 1000, 1) . 'k' : (string) $score;

            $content = "[POST REDDIT] {$subreddit} — Titre: {$title}";
            $content .= "\nAuteur: u/{$author}";
            $content .= "\nScore: {$scoreStr} points" . ($upvoteRatio ? " ({$upvoteRatio} upvotes)" : '') . " | Commentaires: {$numComments}";
            if ($createdAt) $content .= "\nDate: {$createdAt}";
            if ($flair) $content .= "\nFlair: {$flair}";
            if ($isVideo) $content .= "\n(Post avec video)";

            if ($selftext && mb_strlen(trim($selftext)) > 20 && $selftext !== '[deleted]' && $selftext !== '[removed]') {
                $content .= "\n\nCONTENU DU POST:\n" . mb_substr(trim($selftext), 0, 5000);
            } elseif (!empty($postUrl) && $postUrl !== $url && str_starts_with($postUrl, 'http')) {
                $content .= "\nLien partage: {$postUrl}";
            }

            // Include top comments for richer context
            $topComments = $this->extractRedditTopComments($data);
            if ($topComments) {
                $content .= "\n\nTOP COMMENTAIRES:\n{$topComments}";
            }

            return $content;
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] Reddit API failed for {$url}: " . $e->getMessage());
            return $this->extractWebContent($url);
        }
    }

    /**
     * Extract the top-voted comments from a Reddit JSON API response.
     */
    private function extractRedditTopComments(array $data): string
    {
        $comments = $data[1]['data']['children'] ?? [];
        $result   = [];
        $limit    = 5;

        foreach ($comments as $child) {
            if (count($result) >= $limit) break;

            $kind    = $child['kind'] ?? '';
            $comment = $child['data'] ?? [];

            if ($kind !== 't1') continue;

            $author = $comment['author'] ?? '';
            $body   = trim($comment['body'] ?? '');
            $score  = $comment['score'] ?? 0;

            if (in_array($author, ['', '[deleted]', 'AutoModerator'], true)) continue;
            if (in_array($body, ['', '[deleted]', '[removed]'], true)) continue;
            if (mb_strlen($body) < 10) continue;

            $shortBody = mb_strlen($body) > 300 ? mb_substr($body, 0, 300) . '...' : $body;
            $scoreStr  = $score >= 1000 ? round($score / 1000, 1) . 'k' : (string) $score;

            $result[] = "• u/{$author} ({$scoreStr} pts): {$shortBody}";
        }

        return implode("\n", $result);
    }

    /**
     * Extract content from a HackerNews post via the HN Firebase API.
     * Includes title, author, score, linked URL, post text and top comments.
     */
    private function extractHackerNewsContent(string $url): ?string
    {
        if (!preg_match(self::HACKERNEWS_PATTERN, $url, $m)) {
            return $this->extractWebContent($url);
        }

        $itemId = $m[1];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ZeniClaw/1.8 (content summarizer; contact@zeniclaw.app)'])
                ->get("https://hacker-news.firebaseio.com/v0/item/{$itemId}.json");

            if (!$response->successful()) {
                return $this->extractWebContent($url);
            }

            $data = $response->json();
            if (!$data || !isset($data['type'])) {
                return $this->extractWebContent($url);
            }

            $title       = $data['title']       ?? 'Titre inconnu';
            $author      = $data['by']           ?? 'Auteur inconnu';
            $score       = $data['score']        ?? 0;
            $numComments = $data['descendants']  ?? 0;
            $time        = isset($data['time'])  ? date('Y-m-d', (int) $data['time']) : '';
            $linkedUrl   = $data['url']          ?? '';
            $text        = isset($data['text'])  ? strip_tags(html_entity_decode($data['text'], ENT_QUOTES, 'UTF-8')) : '';

            $content = "[POST HACKERNEWS] {$title}";
            $content .= "\nAuteur: {$author}";
            $content .= "\nScore: {$score} points | Commentaires: {$numComments}";
            if ($time)       $content .= "\nDate: {$time}";
            if ($linkedUrl)  $content .= "\nLien: {$linkedUrl}";
            if ($text)       $content .= "\n\nCONTENU:\n" . mb_substr(trim($text), 0, 5000);

            // Fetch top 5 comments
            $kids = array_slice($data['kids'] ?? [], 0, 5);
            if (!empty($kids)) {
                $comments = [];
                foreach ($kids as $kidId) {
                    try {
                        $commentResp = Http::timeout(5)
                            ->withHeaders(['User-Agent' => 'ZeniClaw/1.8 (content summarizer; contact@zeniclaw.app)'])
                            ->get("https://hacker-news.firebaseio.com/v0/item/{$kidId}.json");

                        if (!$commentResp->successful()) continue;

                        $c = $commentResp->json();
                        if (!$c || ($c['dead'] ?? false) || ($c['deleted'] ?? false) || empty($c['text'])) continue;

                        $commentText = strip_tags(html_entity_decode($c['text'], ENT_QUOTES, 'UTF-8'));
                        $commentText = mb_substr(trim($commentText), 0, 300);
                        if (mb_strlen($commentText) < 10) continue;

                        $commentAuthor = $c['by'] ?? '?';
                        $commentScore  = $c['score'] ?? '';
                        $scoreStr      = $commentScore !== '' ? " ({$commentScore} pts)" : '';
                        $comments[] = "• {$commentAuthor}{$scoreStr}: {$commentText}";
                    } catch (\Throwable $e) {
                        // Skip failed comment fetch
                    }
                }
                if (!empty($comments)) {
                    $content .= "\n\nTOP COMMENTAIRES:\n" . implode("\n", $comments);
                }
            }

            return $content;
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] HackerNews API failed for {$url}: " . $e->getMessage());
            return $this->extractWebContent($url);
        }
    }

    /**
     * Attempt to extract content from a LinkedIn article/post URL.
     * LinkedIn heavily restricts scraping, so a graceful fallback is always provided.
     */
    private function extractLinkedInContent(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->get($url);

            if ($response->status() === 401 || $response->status() === 403) {
                return "[ACCES REFUSE] URL: {$url}\n(LinkedIn requiert une authentification pour acceder aux articles. Contenu inaccessible sans connexion.)";
            }

            if ($response->successful()) {
                $parsed = $this->parseHtmlContent($response->body(), $url);
                if ($parsed && mb_strlen($parsed) > 100) {
                    return preg_replace('/^\[PAGE WEB\]/', '[ARTICLE LINKEDIN]', $parsed);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] LinkedIn fetch failed for {$url}: " . $e->getMessage());
        }

        // Graceful fallback — let Claude summarize from the URL context
        return "[ARTICLE LINKEDIN] URL: {$url}\n(LinkedIn limite l'acces aux contenus sans authentification. Le resume sera base sur les metadonnees publiques disponibles.)";
    }

    /**
     * Extract content from a Substack newsletter post.
     * Substack posts are publicly accessible; we use web scraping with a newsletter-specific marker.
     */
    private function extractSubstackContent(string $url): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->get($url);

            if ($response->status() === 403 || $response->status() === 401) {
                return "[ACCES REFUSE] URL: {$url}\n(Ce post Substack est reserve aux abonnes payants.)";
            }

            if ($response->successful()) {
                $parsed = $this->parseHtmlContent($response->body(), $url);
                if ($parsed && mb_strlen($parsed) > 100) {
                    // Replace the generic [PAGE WEB] marker with [NEWSLETTER SUBSTACK]
                    return preg_replace('/^\[PAGE WEB\]/', '[NEWSLETTER SUBSTACK]', $parsed);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] Substack fetch failed for {$url}: " . $e->getMessage());
        }

        return "[NEWSLETTER SUBSTACK] URL: {$url}\n(Impossible de recuperer le contenu Substack. Le post est peut-etre reserve aux abonnes.)";
    }

    private function getYouTubeTranscript(string $videoId): ?string
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";
        $outputDir = storage_path('app/yt-transcripts');

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        $outputFile = "{$outputDir}/{$videoId}";

        $command = sprintf(
            'timeout 30 yt-dlp --skip-download --write-auto-sub --sub-lang fr,en --sub-format vtt --convert-subs srt -o %s %s 2>&1',
            escapeshellarg($outputFile),
            escapeshellarg($url)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $srtFiles = glob("{$outputFile}*.srt");
        if (!empty($srtFiles)) {
            $srtContent = file_get_contents($srtFiles[0]);
            foreach (glob("{$outputFile}*") as $file) {
                @unlink($file);
            }
            return $this->cleanSrtTranscript($srtContent);
        }

        foreach (glob("{$outputFile}*") as $file) {
            @unlink($file);
        }

        return null;
    }

    private function cleanSrtTranscript(string $srt): string
    {
        $lines = explode("\n", $srt);
        $text = [];
        $previousLine = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || is_numeric($line) || preg_match('/^\d{2}:\d{2}/', $line)) {
                continue;
            }
            $line = strip_tags($line);
            $line = preg_replace('/<[^>]+>/', '', $line);
            $line = preg_replace('/\{[^}]+\}/', '', $line);
            $line = preg_replace('/^NOTE\s.*$/m', '', $line);
            $line = preg_replace('/^WEBVTT.*$/m', '', $line);
            $line = preg_replace('/^align:.*$/m', '', $line);
            $line = preg_replace('/^position:.*$/m', '', $line);
            $line = trim($line);

            if (empty($line)) continue;

            if ($line !== $previousLine) {
                $text[] = $line;
                $previousLine = $line;
            }
        }

        $transcript = implode(' ', $text);
        return mb_substr($transcript, 0, 10000);
    }

    private function getYouTubeMetadata(string $videoId, bool $isLive = false): ?string
    {
        try {
            $response = Http::timeout(10)->get("https://www.youtube.com/oembed", [
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'format' => 'json',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $title = $data['title'] ?? 'Titre inconnu';
                $author = $data['author_name'] ?? 'Auteur inconnu';
                $type = $isLive ? 'VIDEO YOUTUBE LIVE' : 'VIDEO YOUTUBE';

                $content = "[{$type}] Titre: {$title} | Auteur/Chaine: {$author}";
                $content .= "\n(Transcription non disponible - resume base sur le titre et les metadonnees)";
                return $content;
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] YouTube oEmbed failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract content from an arXiv paper via the official Atom API.
     * Falls back to regular web scraping if the API fails.
     */
    private function extractArxivContent(string $url): ?string
    {
        $id = $this->extractArxivId($url);
        if (!$id) return $this->extractWebContent($url);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ZeniClaw/1.9 (content summarizer; contact@zeniclaw.app)'])
                ->get("https://export.arxiv.org/api/query?id_list={$id}&max_results=1");

            if (!$response->successful()) {
                return $this->extractWebContent($url);
            }

            $xml = $response->body();

            // Parse all <title> tags — first is feed title, second is paper title
            $title = '';
            preg_match_all('/<title>(.*?)<\/title>/si', $xml, $titleMatches);
            if (!empty($titleMatches[1][1])) {
                $title = html_entity_decode(trim($titleMatches[1][1]), ENT_QUOTES, 'UTF-8');
            } elseif (!empty($titleMatches[1][0])) {
                $title = html_entity_decode(trim($titleMatches[1][0]), ENT_QUOTES, 'UTF-8');
            }

            // Parse abstract/summary
            $abstract = '';
            if (preg_match('/<summary>(.*?)<\/summary>/si', $xml, $m)) {
                $abstract = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                $abstract = preg_replace('/\s+/', ' ', $abstract);
            }

            if (!$title && !$abstract) {
                return $this->extractWebContent($url);
            }

            // Parse authors
            $authors = [];
            preg_match_all('/<author>.*?<name>(.*?)<\/name>.*?<\/author>/si', $xml, $authorMatches);
            foreach ($authorMatches[1] ?? [] as $a) {
                $authors[] = html_entity_decode(trim($a), ENT_QUOTES, 'UTF-8');
            }
            $authorStr = implode(', ', array_slice($authors, 0, 5));
            if (count($authors) > 5) $authorStr .= ' et al.';

            // Parse submission date
            $published = '';
            if (preg_match('/<published>(.*?)<\/published>/si', $xml, $m)) {
                $published = substr(trim($m[1]), 0, 10);
            }

            // Parse categories/subjects
            preg_match_all('/<category[^>]*term="([^"]+)"/si', $xml, $catMatches);
            $catStr = implode(', ', array_slice($catMatches[1] ?? [], 0, 3));

            $content = "[ARTICLE ARXIV] ID: {$id}";
            if ($title)      $content .= "\nTitre: {$title}";
            if ($authorStr)  $content .= "\nAuteurs: {$authorStr}";
            if ($published)  $content .= "\nDate de soumission: {$published}";
            if ($catStr)     $content .= "\nCategories: {$catStr}";
            $content .= "\nURL: {$url}";
            if ($abstract)   $content .= "\n\nRESUME (ABSTRACT):\n{$abstract}";

            return $content;
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] arXiv API failed for {$url}: " . $e->getMessage());
            return $this->extractWebContent($url);
        }
    }

    private function extractWebContent(string $url): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->get($url);

            if ($response->status() === 403 || $response->status() === 401) {
                return "[ACCES REFUSE] URL: {$url}\n(Le site requiert une authentification ou bloque les bots)";
            }

            if ($response->status() === 404 || $response->status() === 410) {
                return null;
            }

            if ($response->status() === 429) {
                return "[RATE LIMIT] URL: {$url}\n(Le site limite les acces automatiques. Reessaie dans quelques minutes.)";
            }

            if ($response->status() === 451) {
                return "[CONTENU BLOQUE] URL: {$url}\n(Ce contenu est bloque pour des raisons legales dans votre region.)";
            }

            if ($response->status() >= 500) {
                Log::warning("[content_summarizer] HTTP {$response->status()} for {$url}");
                return "[ERREUR SERVEUR] URL: {$url}\n(Le serveur rencontre une erreur interne ({$response->status()}). Reessaie plus tard.)";
            }

            if (!$response->successful()) {
                Log::warning("[content_summarizer] HTTP {$response->status()} for {$url}");
                return null;
            }

            $html = $response->body();
            return $this->parseHtmlContent($html, $url);
        } catch (\Throwable $e) {
            Log::warning("[content_summarizer] Web fetch failed for {$url}: " . $e->getMessage());
            return null;
        }
    }

    private function parseHtmlContent(string $html, string $url): ?string
    {
        // Extract title
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
        }

        // Extract meta description
        $metaDesc = '';
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $metaDesc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract og:description as fallback
        if (!$metaDesc && preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $metaDesc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract og:title as fallback title
        if (!$title && preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract publication date from article:published_time og tag
        $pubDate = '';
        if (preg_match('/<meta[^>]*property=["\']article:published_time["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $rawDate = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $rawDate, $dateMatch)) {
                $pubDate = $dateMatch[1];
            }
        }

        // Extract author from meta name="author" or article:author
        $author = '';
        if (preg_match('/<meta[^>]*name=["\']author["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $author = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<meta[^>]*property=["\']article:author["\'][^>]*content=["\'](.*?)["\']/si', $html, $m)) {
            $author = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Extract JSON-LD structured data (Article, NewsArticle, BlogPosting)
        $jsonLdBody = '';
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $ldMatches)) {
            foreach ($ldMatches[1] as $ldJson) {
                $ldDecoded = json_decode(trim($ldJson), true);
                if (!$ldDecoded) continue;

                $ldType = $ldDecoded['@type'] ?? '';
                if (in_array($ldType, ['Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'TechArticle'])) {
                    if (!$title && isset($ldDecoded['headline'])) {
                        $title = $ldDecoded['headline'];
                    }
                    if (!$metaDesc && isset($ldDecoded['description'])) {
                        $metaDesc = $ldDecoded['description'];
                    }
                    if (!$author && isset($ldDecoded['author'])) {
                        $ldAuthor = $ldDecoded['author'];
                        if (is_array($ldAuthor)) {
                            $author = $ldAuthor['name'] ?? (is_string($ldAuthor[0] ?? null) ? $ldAuthor[0] : ($ldAuthor[0]['name'] ?? ''));
                        } else {
                            $author = (string) $ldAuthor;
                        }
                    }
                    if (!$pubDate && isset($ldDecoded['datePublished'])) {
                        $rawDate = $ldDecoded['datePublished'];
                        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $rawDate, $dateMatch)) {
                            $pubDate = $dateMatch[1];
                        }
                    }
                    if (isset($ldDecoded['articleBody']) && empty($jsonLdBody)) {
                        $jsonLdBody = mb_substr($ldDecoded['articleBody'], 0, 5000);
                    }
                }
            }
        }

        // Remove non-content tags
        $cleanHtml = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript|form|button|select|input|textarea|svg|canvas)[^>]*>.*?<\/\1>/si', '', $html);
        $cleanHtml = preg_replace('/<!--.*?-->/si', '', $cleanHtml);

        // Try to find article content — ordered by specificity
        $articleContent = '';
        $selectors = [
            '/<article[^>]*>(.*?)<\/article>/si',
            '/<main[^>]*>(.*?)<\/main>/si',
            '/<div[^>]*\b(?:id|class)=["\'][^"\']*\b(?:article|post|entry|content|story|text)[^"\']*["\'][^>]*>(.*?)<\/div>/si',
            '/<section[^>]*\b(?:id|class)=["\'][^"\']*\b(?:content|article|post)[^"\']*["\'][^>]*>(.*?)<\/section>/si',
            '/<div[^>]*\b(?:id|class)=["\'][^"\']*\b(?:body|prose|reader)[^"\']*["\'][^>]*>(.*?)<\/div>/si',
        ];

        foreach ($selectors as $selector) {
            if (preg_match($selector, $cleanHtml, $m)) {
                $articleContent = $m[1];
                break;
            }
        }

        if (!$articleContent) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $cleanHtml, $m)) {
                $articleContent = $m[1];
            }
        }

        $text = strip_tags($articleContent);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (mb_strlen($text) < 200 && !empty($jsonLdBody)) {
            $text = $jsonLdBody;
        } elseif (mb_strlen($text) < 50 && $metaDesc) {
            $text = $metaDesc;
        }

        $text = mb_substr($text, 0, 10000);

        $header = "[PAGE WEB] URL: {$url}";
        if ($title) $header .= "\nTitre: {$title}";
        if ($author) $header .= "\nAuteur: {$author}";
        if ($pubDate) $header .= "\nDate de publication: {$pubDate}";
        if ($metaDesc) $header .= "\nDescription: {$metaDesc}";

        return "{$header}\n\nCONTENU:\n{$text}";
    }

    private function summarizeContent(AgentContext $context, string $content, string $url, string $mode, ?string $outputLang = null, ?string $focusTopic = null): string
    {
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        // Resolve wordcount mode
        $wordCount = null;
        if (str_starts_with($mode, 'wordcount:')) {
            $wordCount = (int) substr($mode, 10);
            $mode = 'wordcount';
        }

        $langNames = ['en' => 'anglais', 'fr' => 'français', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];
        if ($outputLang && isset($langNames[$outputLang])) {
            $langInstruction = "Reponds OBLIGATOIREMENT en {$langNames[$outputLang]}, quelle que soit la langue du contenu.";
        } else {
            $contentLang = $this->detectContentLanguage($content);
            $langInstruction = $contentLang === 'en'
                ? "Le contenu est en anglais. Reponds en francais sauf si l'utilisateur a ecrit en anglais, dans ce cas reponds en anglais."
                : "Reponds en francais.";
        }

        $readingMinutes = $this->estimateReadingTime($content);
        $readingTimeStr = $readingMinutes <= 1 ? '< 1 min de lecture' : "{$readingMinutes} min de lecture";

        $lengthInstructions = match ($mode) {
            'flash'      => "RESUME FLASH: Exactement 1 phrase unique, la plus concise possible. N'ecris rien d'autre — ni liste, ni ton, ni mots-cles, ni source. Juste une phrase qui capture l'essentiel.",
            'short'      => "RESUME COURT: 2-3 phrases maximum. Va droit a l'essentiel. Pas de liste de points. Inclure quand meme le ton et 2-3 mots-cles.",
            'detailed'   => "RESUME DETAILLE: Resume complet de 10-15 lignes couvrant tous les arguments, points importants, exemples cles et conclusions. Inclus une liste de 5-8 points cles.",
            'bullet'     => "RESUME EN POINTS: Presente UNIQUEMENT une liste de 5 a 10 bullets. Chaque point est une phrase courte et autonome. Pas de texte de resume avant la liste.",
            'wordcount'  => "RESUME EN {$wordCount} MOTS: Ecris un resume en exactement {$wordCount} mots (+/- 10%). Respecte scrupuleusement cette contrainte de longueur. Inclus les points cles, le ton et 2-3 mots-cles a la fin.",
            'simple'     => "EXPLICATION SIMPLIFIEE (ELI5): Explique le contenu comme si tu parlais a un debutant complet — aucune connaissance prealable supposee. Utilise un vocabulaire courant, des analogies concretes et des exemples de la vie quotidienne. Evite absolument le jargon technique et les acronymes non expliques. 3-5 lignes maximum.",
            'actions'    => "EXTRACTION D'ACTIONS ET RECOMMANDATIONS: Identifie et liste UNIQUEMENT les actions concretes, decisions, recommandations ou prochaines etapes mentionnees ou impliquees dans le contenu. Format: liste numerotee, chaque item en 1 ligne courte et directement actionnable. 3 a 7 items maximum. Si le contenu ne contient pas d'actions explicites, extrait les implications pratiques ou ce que le lecteur devrait faire/retenir.",
            default      => "RESUME STANDARD: 3-5 lignes de resume concis + liste de 3-5 points cles essentiels.",
        };

        $bodyFormat = match(true) {
            $mode === 'flash'   => "[Une seule phrase resumant l'essentiel.]",
            $mode === 'bullet'  => "*Points cles :*\n- [point 1 — sujet principal]\n- [point 2 — argument/donnee cle]\n- [point 3 — exemple ou contexte]\n- [...]\n\n*Ton :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif]\n*Mots-cles :* #[tag1] #[tag2] #[tag3]",
            $mode === 'simple'  => "[Explication simple et accessible — aucun jargon]\n\n*En bref :*\n- [concept de base explique simplement]\n- [analogie ou exemple concret du quotidien]\n- [conclusion accessible]\n\n*Mots-cles :* #[tag1] #[tag2] #[tag3]",
            $mode === 'actions' => "*Actions / Recommandations :*\n1. [action concrete 1]\n2. [action concrete 2]\n3. [action concrete 3]\n[...jusqu'a 7 items max]\n\n*Contexte :* [1 phrase sur le contenu source]\n*Mots-cles :* #[tag1] #[tag2]",
            default             => "[Ton resume ici]\n\n*Points cles :*\n- [point 1]\n- [point 2]\n- [point 3]\n\n*Ton :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif]\n\n*Mots-cles :* #[tag1] #[tag2] #[tag3]",
        };

        $systemPrompt = <<<PROMPT
Tu es un expert en synthese de contenu pour WhatsApp. Resume le contenu fourni de maniere claire et structuree.

{$lengthInstructions}

FORMAT DE REPONSE (optimise WhatsApp):
*[Titre ou source]* — [type: Article / Video YouTube / Video Vimeo / Tweet / Texte direct / Page web]
{$bodyFormat}

REGLES:
- {$langInstruction}
- Sois factuel et objectif — n'invente RIEN
- Base-toi uniquement sur le contenu fourni
- Si c'est une video YouTube ou Vimeo, mentionne la chaine/auteur
- Si c'est un tweet Twitter/X, mentionne le contexte du post si disponible
- Si c'est un texte colle directement, traite-le comme un document a resumer
- Si une date de publication ou un auteur est disponible dans les metadonnees, cite-les
- Si le contenu est insuffisant (metadonnees seulement), precise-le
- Pour les articles techniques, mets en avant les concepts cles
- Utilise *gras* pour mettre en valeur les elements importants
- Les mots-cles doivent etre en minuscules, sans espaces, pertinents pour la recherche
- Pas de #hashtags parasites, pas de mentions @
PROMPT;

        if ($focusTopic) {
            $systemPrompt .= "\n\nFOCUS THEMATIQUE: L'utilisateur souhaite que tu mettes particulierement l'accent sur cet aspect dans le resume : *{$focusTopic}*. Insiste sur ce sujet dans le resume et les points cles. Si le contenu n'aborde pas directement ce theme, precise-le.";
        }

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $maxTokens = match ($mode) {
            'flash'     => 80,
            'detailed'  => 2048,
            'wordcount' => $wordCount ? min(2048, max(256, (int) ($wordCount * 3))) : 1024,
            'short', 'bullet', 'simple', 'actions' => 512,
            default => 1024,
        };

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

        if (!$response) {
            return $this->formatErrorResult($url, 'Impossible de generer le resume. Verifie ta connexion et reessaie.');
        }

        $isTextPaste = $url === self::TEXT_PASTE_URL;
        $icon = match (true) {
            $isTextPaste => '📝',
            $this->isYouTubeUrl($url) => '🎬',
            $this->isVimeoUrl($url) => '🎥',
            $this->isTwitterUrl($url) => '🐦',
            $this->isWikipediaUrl($url) => '📖',
            $this->isGithubUrl($url) => '🐙',
            $this->isRedditUrl($url) => '🤖',
            $this->isHackerNewsUrl($url) => '🔶',
            $this->isLinkedInUrl($url) => '💼',
            $this->isArxivUrl($url) => '🎓',
            $this->isSubstackUrl($url) => '📨',
            default => '📰',
        };

        $modeLabel = match ($mode) {
            'flash'     => 'flash',
            'short'     => 'court',
            'detailed'  => 'detaille',
            'bullet'    => 'en points',
            'wordcount' => "{$wordCount} mots",
            'simple'    => 'simplifie',
            'actions'   => 'actions',
            default     => 'standard',
        };

        $urlSuffix = $isTextPaste ? '' : "\n\n🔗 {$url}";

        return "{$icon} *RESUME ({$modeLabel})* — _{$readingTimeStr}_\n\n"
            . trim($response)
            . $urlSuffix;
    }

    /**
     * Shared system prompt for keywords extraction (used by handleKeywordsOnly and handleTextPaste).
     */
    private function buildKeywordsSystemPrompt(): string
    {
        return <<<PROMPT
Tu es un expert en extraction de mots-cles et tags. Analyse le contenu fourni et extrait les tags les plus pertinents.

FORMAT DE REPONSE (pour WhatsApp):
*🏷 MOTS-CLES*

*Tags principaux :* #[tag1] #[tag2] #[tag3] #[tag4] #[tag5]

*Tags secondaires :* #[tag6] #[tag7] #[tag8] #[tag9] #[tag10]

*Categorie :* [technologie | science | politique | economie | culture | sport | sante | education | divers]

*Ton :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif]

REGLES:
- Extrait entre 8 et 15 tags au total
- Les tags principaux sont les concepts cles et entites nommees les plus importants
- Les tags secondaires sont les themes secondaires et contextuels
- Les tags sont en minuscules, sans espaces (utilise des tirets), preferablement sans accents
- Ne genere pas de hashtags trop generiques (#article, #lien, #web, etc.)
- Reponds toujours dans cette structure, quelle que soit la langue du contenu
PROMPT;
    }

    private function formatErrorResult(string $url, string $error): string
    {
        $shortUrl = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '...' : $url;
        return "⚠ *Erreur :* {$error}\n_{$shortUrl}_";
    }

    private function friendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'cURL error 28')) {
            return 'Le site met trop de temps a repondre (timeout). Reessaie plus tard ou verifie ta connexion.';
        }

        if (str_contains($message, '403') || str_contains($message, 'Forbidden')) {
            return 'Acces refuse (403). Le contenu est peut-etre protege ou reserve aux abonnes.';
        }

        if (str_contains($message, '404') || str_contains($message, 'Not Found')) {
            return 'Page introuvable (404). Verifie que le lien est correct.';
        }

        if (str_contains($message, '410') || str_contains($message, 'Gone')) {
            return 'Cette page n\'existe plus (410). Le contenu a ete supprime definitivement.';
        }

        if (str_contains($message, '429') || str_contains($message, 'Too Many Requests')) {
            return 'Le site limite les acces automatiques (rate limit). Reessaie dans quelques minutes.';
        }

        if (str_contains($message, '451')) {
            return 'Ce contenu est bloque pour des raisons legales dans votre region (451).';
        }

        if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503') || str_contains($message, '504')) {
            return 'Le site rencontre une erreur interne. Reessaie plus tard.';
        }

        if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
            return 'Erreur de certificat SSL. Le site pourrait avoir un probleme de securite.';
        }

        if (str_contains($message, 'Could not resolve host') || str_contains($message, 'cURL error 6')) {
            return 'Impossible de resoudre le nom de domaine. Verifie que le lien est valide.';
        }

        if (str_contains($message, 'Connection refused') || str_contains($message, 'cURL error 7')) {
            return 'Connexion refusee par le serveur. Le site est peut-etre hors ligne.';
        }

        if (str_contains($message, 'Too many redirects') || str_contains($message, 'cURL error 47')) {
            return 'Le site effectue trop de redirections. Le lien pourrait etre invalide ou en boucle.';
        }

        return 'Impossible de recuperer le contenu. Verifie que le lien est valide et accessible publiquement.';
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*📰 Resume de Contenu — Articles, Videos, Reddit, Wikipedia, GitHub, Arxiv & plus*\n\n"
            . "*Comment utiliser :*\n"
            . "Envoie simplement un lien et je le resumerai !\n\n"
            . "*Exemples :*\n"
            . "- https://example.com/article\n"
            . "- https://youtube.com/watch?v=xxx\n"
            . "- https://vimeo.com/123456789\n"
            . "- https://x.com/user/status/123\n"
            . "- https://fr.wikipedia.org/wiki/PHP\n"
            . "- https://github.com/laravel/laravel\n"
            . "- https://reddit.com/r/tech/comments/abc123\n"
            . "- https://news.ycombinator.com/item?id=12345\n"
            . "- https://linkedin.com/pulse/article-titre\n"
            . "- https://arxiv.org/abs/2301.01234\n"
            . "- _resume court_ https://example.com/article\n"
            . "- _resume detaille_ https://youtube.com/watch?v=xxx\n"
            . "- _flash_ https://example.com/article\n"
            . "- _en points_ https://example.com/article\n"
            . "- _resume en 100 mots_ https://example.com/article\n"
            . "- _mots-cles seulement_ https://example.com/article\n"
            . "- _compare_ https://site1.com https://site2.com\n"
            . "- _analyse le ton_ https://example.com/article\n"
            . "- _resume en anglais_ https://example.com/article\n"
            . "- _extraire les citations_ https://example.com/article\n"
            . "- _resume axé sur les chiffres_ https://example.com/article\n"
            . "- _focus sur les risques_ https://example.com/article\n"
            . "- _traduis en anglais_ https://example.com/article\n"
            . "- _traduis en espagnol_ [colle ton texte ici...]\n"
            . "- _analyse le ton_ [colle ton texte ici...]\n"
            . "- _simplifie_ https://example.com/article\n"
            . "- _eli5_ https://arxiv.org/abs/2301.01234\n"
            . "- _extraire les actions_ https://example.com/article\n"
            . "- _recommandations_ https://example.com/article\n"
            . "- _resume_ [colle ton texte directement ici...]\n\n"
            . "*Options de longueur :*\n"
            . "- _flash / en une phrase_ → 1 phrase ultra-concise\n"
            . "- _court/bref/rapide_ → 2-3 phrases\n"
            . "- _standard_ (defaut) → resume + points cles\n"
            . "- _detaille/complet_ → resume approfondi\n"
            . "- _en points/bullet_ → liste de points cles uniquement\n"
            . "- _en X mots / in X words_ → resume en nombre de mots precis\n"
            . "- _simplifie / eli5_ → explication accessible pour debutants\n"
            . "- _extraire les actions_ → liste d'actions et recommandations\n\n"
            . "*Langue de reponse :*\n"
            . "- _en anglais / in english_ → reponse en anglais\n"
            . "- _en espagnol / in spanish_ → reponse en espagnol\n"
            . "- (defaut : francais, adaptatif selon contenu)\n\n"
            . "*Contenus supportes :*\n"
            . "🌐 Articles web & blogs\n"
            . "🎬 Videos YouTube (avec transcription si disponible)\n"
            . "🎥 Videos Vimeo (metadonnees + description)\n"
            . "🐦 Tweets Twitter / Posts X\n"
            . "📖 Pages Wikipedia (via API officielle, toutes langues)\n"
            . "🐙 Depots GitHub (README, stats, description)\n"
            . "🤖 Posts Reddit (contenu + top commentaires)\n"
            . "🔶 Posts HackerNews (score, commentaires, lien)\n"
            . "💼 Articles LinkedIn Pulse (best-effort, acces limite)\n"
            . "🎓 Articles scientifiques Arxiv (titre, auteurs, abstract)\n"
            . "📨 Newsletters Substack (contenu complet si public)\n"
            . "📝 Texte colle directement\n"
            . "📄 Pages web generales\n\n"
            . "*Fonctionnalites :*\n"
            . "⏱ Estimation du temps de lecture\n"
            . "🔍 Comparaison de 2 liens (_compare_ + 2 URLs)\n"
            . "🎭 Analyse du ton et sentiment (_analyse le ton_ + URL ou texte)\n"
            . "🌍 Detection automatique de la langue (FR/EN/ES/DE/IT)\n"
            . "🏷 Extraction de mots-cles et detection du ton\n"
            . "📌 Extraction des tags uniquement (_mots-cles seulement_)\n"
            . "📝 Resume de texte colle directement (sans URL)\n"
            . "📏 Resume en nombre de mots precis (_en 150 mots_ + URL)\n"
            . "👤 Auteur et date de publication si disponibles\n"
            . "🌐 Langue de reponse personnalisable (_en anglais_, _in english_, etc.)\n"
            . "💬 Extraction de citations notables (_extraire les citations_ + URL ou texte)\n"
            . "🎯 Focus thematique (_axe sur X_ / _focus sur X_ + URL ou texte)\n"
            . "🌍 Traduction de contenu (_traduis en anglais_ + URL ou texte)\n"
            . "🧠 Explication simplifiee/ELI5 (_simplifie_ ou _eli5_ + URL ou texte)\n"
            . "✅ Extraction d'actions et recommandations (_extraire les actions_ + URL ou texte)"
        );
    }
}
