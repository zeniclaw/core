<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentSummarizerAgent extends BaseAgent
{
    private const URL_PATTERN = '#https?://[^\s<>\[\]"\']+#i';
    private const YOUTUBE_PATTERN = '#(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([a-zA-Z0-9_-]{11})#i';
    private const KEYWORD_PATTERN = '/\b(r[eé]sum[eé]r?|summarize|summary|synth[eè]se|synthetiser|tldr|tl;?dr|de\s+quoi\s+parle|lire\s+pour\s+moi|read\s+for\s+me|compare[rz]?|comparaison|vs\.?)\b/iu';
    private const COMPARE_PATTERN = '/\b(compar[eé]r?|compare|vs\.?|versus|diff[eé]rence|entre\s+ces|between\s+these)\b/iu';

    // Private IP ranges to block for URL security
    private const PRIVATE_IP_PATTERN = '/^https?:\/\/(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/i';

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
        return 'Agent de resume de contenu web. Resume automatiquement les articles, pages web et videos YouTube (avec transcription). Supporte les resumes courts, standards et detailles. Peut comparer deux contenus et estimer le temps de lecture.';
    }

    public function keywords(): array
    {
        return [
            'resume', 'résumé', 'resumer', 'résumer', 'summarize', 'summary',
            'resume article', 'resume lien', 'resume url', 'resume page',
            'resume video', 'resume youtube',
            'tldr', 'tl;dr', 'TL;DR',
            'synthese', 'synthèse', 'synthetiser',
            'resume court', 'resume detaille', 'resume bref',
            'short summary', 'detailed summary', 'quick summary',
            'de quoi parle', 'what is this about',
            'lire pour moi', 'read for me',
            'contenu', 'content', 'article', 'lien', 'link', 'url',
            'youtube', 'video', 'vidéo',
            'compare', 'comparer', 'comparaison', 'vs',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        // Match URLs or summarize-related keywords
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
            return $this->showHelp();
        }

        // Detect summary length preference
        $length = $this->detectSummaryLength($body);

        // Detect comparison mode (2 URLs + compare keyword)
        $compareMode = count($urls) === 2 && (bool) preg_match(self::COMPARE_PATTERN, $body);

        $this->log($context, 'Content summarization requested', [
            'urls' => $urls,
            'length' => $length,
            'compare_mode' => $compareMode,
        ]);

        if ($compareMode) {
            return $this->handleComparison($context, $urls, $length);
        }

        $results = [];

        foreach ($urls as $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    $results[] = $this->formatErrorResult($url, 'Contenu insuffisant ou inaccessible. Le site est peut-etre protege ou necessite une authentification.');
                    continue;
                }

                $summary = $this->summarizeContent($context, $content, $url, $length);
                $results[] = $summary;
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Error processing URL: {$url}", ['error' => $e->getMessage()]);
                $results[] = $this->formatErrorResult($url, $this->friendlyError($e));
            }
        }

        $output = implode("\n\n---\n\n", $results);

        $this->log($context, 'Content summarization completed', [
            'urls_count' => count($urls),
            'length' => $length,
        ]);

        return AgentResult::reply($output);
    }

    private function handleComparison(AgentContext $context, array $urls, string $length): AgentResult
    {
        $contents = [];

        foreach ($urls as $i => $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    return AgentResult::reply(
                        "⚠ Impossible de comparer : contenu inaccessible pour l'URL " . ($i + 1) . ".\n{$url}"
                    );
                }

                $contents[$url] = $content;
            } catch (\Throwable $e) {
                Log::warning("[content_summarizer] Comparison fetch failed for {$url}", ['error' => $e->getMessage()]);
                return AgentResult::reply(
                    "⚠ Impossible de comparer : " . $this->friendlyError($e) . "\n{$url}"
                );
            }
        }

        $urlList = array_keys($contents);
        $combinedContent = "=== SOURCE 1 ===\n{$contents[$urlList[0]]}\n\n=== SOURCE 2 ===\n{$contents[$urlList[1]]}";

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
- [Source 1] vs [Source 2]: [difference]

*Quelle source privilegier ?*
[Recommandation courte et objective selon le contexte]

REGLES:
- Sois factuel et neutre
- Mets en avant les differences les plus importantes
- Reponds en francais sauf si l'utilisateur semble anglophone
- N'invente rien, base-toi uniquement sur les contenus fournis
PROMPT;

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
        // Block private IPs and localhost
        if (preg_match(self::PRIVATE_IP_PATTERN, $url)) {
            Log::info("[content_summarizer] Blocked private/local URL: {$url}");
            return false;
        }

        // Must have a valid TLD-like structure
        $parsed = parse_url($url);
        if (!isset($parsed['host']) || empty($parsed['host'])) {
            return false;
        }

        return true;
    }

    private function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match(self::YOUTUBE_PATTERN, $url);
    }

    private function extractYouTubeVideoId(string $url): ?string
    {
        if (preg_match(self::YOUTUBE_PATTERN, $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function detectSummaryLength(string $body): string
    {
        $bodyLower = mb_strtolower($body);

        if (preg_match('/\b(detaille|detailed|complet|full|long|approfondi|in[- ]?depth|exhaustif|complet)\b/iu', $bodyLower)) {
            return 'detailed';
        }

        if (preg_match('/\b(court|short|bref|brief|rapide|quick|tldr|tl;?dr|resume\s+court|en\s+bref|en\s+quelques\s+mots)\b/iu', $bodyLower)) {
            return 'short';
        }

        return 'medium';
    }

    private function estimateReadingTime(string $content): int
    {
        // Average reading speed: 200 words/min in French/English
        $wordCount = str_word_count(strip_tags($content));
        return (int) ceil($wordCount / 200);
    }

    private function detectContentLanguage(string $content): string
    {
        // Simple heuristic: count French vs English common words
        $frenchWords = ['le', 'la', 'les', 'de', 'du', 'des', 'est', 'une', 'pour', 'dans', 'que', 'qui', 'sur', 'avec', 'par', 'pas', 'plus', 'mais'];
        $englishWords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him'];

        $contentLower = mb_strtolower($content);
        $words = preg_split('/\s+/', $contentLower, -1, PREG_SPLIT_NO_EMPTY);

        $frenchScore = 0;
        $englishScore = 0;

        foreach ($words as $word) {
            $word = trim($word, '.,;:!?');
            if (in_array($word, $frenchWords)) $frenchScore++;
            if (in_array($word, $englishWords)) $englishScore++;
        }

        return $englishScore > $frenchScore ? 'en' : 'fr';
    }

    private function extractYouTubeContent(string $url): ?string
    {
        $videoId = $this->extractYouTubeVideoId($url);
        if (!$videoId) return null;

        // Try to get transcript via yt-dlp (subtitles)
        $transcript = $this->getYouTubeTranscript($videoId);

        if ($transcript) {
            return $transcript;
        }

        // Fallback: get video metadata via oEmbed
        $metadata = $this->getYouTubeMetadata($videoId);
        if ($metadata) {
            return $metadata;
        }

        return null;
    }

    private function getYouTubeTranscript(string $videoId): ?string
    {
        $url = "https://www.youtube.com/watch?v={$videoId}";
        $outputDir = storage_path('app/yt-transcripts');

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        $outputFile = "{$outputDir}/{$videoId}";

        // Try yt-dlp to extract subtitles with timeout
        $command = sprintf(
            'timeout 30 yt-dlp --skip-download --write-auto-sub --sub-lang fr,en --sub-format vtt --convert-subs srt -o %s %s 2>&1',
            escapeshellarg($outputFile),
            escapeshellarg($url)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        // Check for generated subtitle files
        $srtFiles = glob("{$outputFile}*.srt");
        if (!empty($srtFiles)) {
            $srtContent = file_get_contents($srtFiles[0]);
            // Clean up files
            foreach (glob("{$outputFile}*") as $file) {
                @unlink($file);
            }
            return $this->cleanSrtTranscript($srtContent);
        }

        // Clean up any partial files
        foreach (glob("{$outputFile}*") as $file) {
            @unlink($file);
        }

        return null;
    }

    private function cleanSrtTranscript(string $srt): string
    {
        // Remove SRT numbers and timestamps, keep only text
        $lines = explode("\n", $srt);
        $text = [];
        $previousLine = '';

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines, sequence numbers, and timestamps
            if (empty($line) || is_numeric($line) || preg_match('/^\d{2}:\d{2}/', $line)) {
                continue;
            }
            // Remove HTML-like tags and VTT positioning tags from subtitles
            $line = strip_tags($line);
            $line = preg_replace('/<[^>]+>/', '', $line);
            $line = preg_replace('/\{[^}]+\}/', '', $line); // Remove VTT style blocks
            $line = trim($line);

            if (empty($line)) continue;

            // Skip duplicate consecutive lines
            if ($line !== $previousLine) {
                $text[] = $line;
                $previousLine = $line;
            }
        }

        $transcript = implode(' ', $text);
        // Limit to ~10000 chars to stay within context
        return mb_substr($transcript, 0, 10000);
    }

    private function getYouTubeMetadata(string $videoId): ?string
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
                return "[VIDEO YOUTUBE] Titre: {$title} | Auteur/Chaine: {$author}\n(Transcription non disponible - resume base sur le titre et les metadonnees)";
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] YouTube oEmbed failed: " . $e->getMessage());
        }

        return null;
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

            if ($response->status() === 404) {
                return null;
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

        // Remove non-content tags
        $cleanHtml = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript|form|button|select|input|textarea|svg|canvas)[^>]*>.*?<\/\1>/si', '', $html);
        // Remove comments
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

        // Fallback to body
        if (!$articleContent) {
            if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $cleanHtml, $m)) {
                $articleContent = $m[1];
            }
        }

        // Strip remaining HTML tags and clean up whitespace
        $text = strip_tags($articleContent);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (mb_strlen($text) < 50 && $metaDesc) {
            $text = $metaDesc;
        }

        // Limit content length
        $text = mb_substr($text, 0, 10000);

        $header = "[PAGE WEB] URL: {$url}";
        if ($title) $header .= "\nTitre: {$title}";
        if ($metaDesc) $header .= "\nDescription: {$metaDesc}";

        return "{$header}\n\nCONTENU:\n{$text}";
    }

    private function summarizeContent(AgentContext $context, string $content, string $url, string $length): string
    {
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        // Detect content language for response language guidance
        $contentLang = $this->detectContentLanguage($content);
        $langInstruction = $contentLang === 'en'
            ? "Le contenu est en anglais. Reponds en francais sauf si l'utilisateur a ecrit en anglais, dans ce cas reponds en anglais."
            : "Reponds en francais.";

        // Estimate reading time from raw content (before truncation)
        $readingMinutes = $this->estimateReadingTime($content);
        $readingTimeStr = $readingMinutes <= 1 ? '< 1 min de lecture' : "{$readingMinutes} min de lecture";

        $lengthInstructions = match ($length) {
            'short' => "RESUME COURT: 2-3 phrases maximum. Va droit a l'essentiel. Pas de liste de points.",
            'detailed' => "RESUME DETAILLE: Resume complet de 10-15 lignes couvrant tous les arguments, points importants, exemples cles et conclusions. Inclus une liste de 5-8 points cles.",
            default => "RESUME STANDARD: 3-5 lignes de resume concis + liste de 3-5 points cles essentiels.",
        };

        $systemPrompt = <<<PROMPT
Tu es un expert en synthese de contenu pour WhatsApp. Resume le contenu fourni de maniere claire et structuree.

{$lengthInstructions}

FORMAT DE REPONSE (optimise WhatsApp):
*[Titre ou source]* — [type: Article / Video / Page web]
[Ton resume ici]

*Points cles :*
- [point 1]
- [point 2]
- [point 3]

REGLES:
- {$langInstruction}
- Sois factuel et objectif — n'invente RIEN
- Base-toi uniquement sur le contenu fourni
- Si c'est une video YouTube, mentionne la chaine/auteur
- Si le contenu est insuffisant (metadonnees seulement), precise-le
- Pour les articles techniques, mets en avant les concepts cles
- Utilise *gras* pour mettre en valeur les elements importants
- Pas de #hashtags, pas de mentions @
PROMPT;

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        // Use higher max_tokens for detailed summaries via chatWithMessages
        $maxTokens = match ($length) {
            'detailed' => 2048,
            'short' => 512,
            default => 1024,
        };

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

        if (!$response) {
            return $this->formatErrorResult($url, 'Impossible de generer le resume. Verifie ta connexion et reessaie.');
        }

        $icon = $this->isYouTubeUrl($url) ? '🎬' : '📰';
        $lengthLabel = match ($length) {
            'short' => 'court',
            'detailed' => 'detaille',
            default => 'standard',
        };

        return "{$icon} *RESUME ({$lengthLabel})* — _{$readingTimeStr}_\n\n"
            . trim($response)
            . "\n\n🔗 {$url}";
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

        if (str_contains($message, '429') || str_contains($message, 'Too Many Requests')) {
            return 'Le site limite les acces automatiques (rate limit). Reessaie dans quelques minutes.';
        }

        if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503')) {
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

        return 'Impossible de recuperer le contenu. Verifie que le lien est valide et accessible publiquement.';
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*📰 Resume de Contenu — Articles, Videos & Liens*\n\n"
            . "*Comment utiliser :*\n"
            . "Envoie simplement un lien et je le resumerai !\n\n"
            . "*Exemples :*\n"
            . "- https://example.com/article\n"
            . "- https://youtube.com/watch?v=xxx\n"
            . "- _resume court_ https://example.com/article\n"
            . "- _resume detaille_ https://youtube.com/watch?v=xxx\n"
            . "- _compare_ https://site1.com https://site2.com\n\n"
            . "*Options de longueur :*\n"
            . "- _court/bref/rapide_ → 2-3 phrases\n"
            . "- _standard_ (defaut) → resume + points cles\n"
            . "- _detaille/complet_ → resume approfondi\n\n"
            . "*Contenus supportes :*\n"
            . "🌐 Articles web & blogs\n"
            . "🎬 Videos YouTube (avec transcription si disponible)\n"
            . "📄 Pages web generales\n\n"
            . "*Nouvelles fonctionnalites :*\n"
            . "⏱ Estimation du temps de lecture\n"
            . "🔍 Comparaison de 2 liens (_compare_ + 2 URLs)\n"
            . "🌍 Detection automatique de la langue"
        );
    }
}
