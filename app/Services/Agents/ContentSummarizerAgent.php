<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentSummarizerAgent extends BaseAgent
{
    private const URL_PATTERN = '#https?://[^\s<>\[\]"\']+#i';
    private const YOUTUBE_PATTERN = '#(?:https?://)?(?:www\.)?(?:youtube\.com/(?:watch\?v=|shorts/|live/)|youtu\.be/)([a-zA-Z0-9_-]{11})#i';
    private const VIMEO_PATTERN = '#(?:https?://)?(?:www\.)?vimeo\.com/(\d+)#i';
    private const KEYWORD_PATTERN = '/\b(r[eé]sum[eé]r?|summarize|summary|synth[eè]se|synthetiser|tldr|tl;?dr|de\s+quoi\s+parle|lire\s+pour\s+moi|read\s+for\s+me|compare[rz]?|comparaison|vs\.?|bullet|en\s+points?|mots[- ]cl[eé]s\s+seulement|keywords?\s+only|liste\s+des\s+tags?|extraire\s+les\s+tags?)\b/iu';
    private const COMPARE_PATTERN = '/\b(compar[eé]r?|compare|vs\.?|versus|diff[eé]rence|entre\s+ces|between\s+these|lequel|laquelle|meilleur|mieux|pr[eé]f[eé]rer|choisir|which|better|best|prefer|choose)\b/iu';
    private const BULLET_PATTERN = '/\b(bullet|en\s+points?|liste\s+de\s+points?|points?\s+cl[eé]s?|key\s+points?)\b/iu';
    private const KEYWORDS_ONLY_PATTERN = '/\b(mots[- ]cl[eé]s\s+seulement|keywords?\s+only|liste\s+des\s+tags?|tags?\s+seulement|extraire\s+les\s+tags?|just\s+tags?|only\s+tags?)\b/iu';

    // Private IP ranges and dangerous hosts to block
    private const PRIVATE_IP_PATTERN = '/^https?:\/\/(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/i';
    private const ONION_PATTERN = '/\.onion(:\d+)?(\/|$)/i';

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
        return 'Agent de resume de contenu web. Resume automatiquement les articles, pages web et videos YouTube/Vimeo (avec transcription). Supporte les resumes courts, standards, detailles et en points (bullet). Peut comparer deux contenus, extraire des mots-cles, detecter le ton, estimer le temps de lecture et extraire uniquement les tags.';
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
            'compare', 'comparer', 'comparaison', 'vs',
            'mots-cles', 'tags', 'keywords', 'ton', 'sentiment',
            'bullet', 'en points', 'liste de points',
            'mots-cles seulement', 'keywords only', 'liste des tags',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
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

        // Detect summary mode (short/medium/detailed/bullet/keywords)
        $mode = $this->detectSummaryMode($body);

        // Detect comparison mode (2 URLs + compare/choice keyword)
        $compareMode = count($urls) === 2 && (bool) preg_match(self::COMPARE_PATTERN, $body);

        $this->log($context, 'Content summarization requested', [
            'urls' => $urls,
            'mode' => $mode,
            'compare_mode' => $compareMode,
        ]);

        if ($compareMode) {
            return $this->handleComparison($context, $urls, $mode);
        }

        // Keywords-only mode: extract only tags without full summary
        if ($mode === 'keywords') {
            return $this->handleKeywordsOnly($context, $urls);
        }

        $results = [];

        foreach ($urls as $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } elseif ($this->isVimeoUrl($url)) {
                    $content = $this->extractVimeoContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    $results[] = $this->formatErrorResult($url, 'Contenu insuffisant ou inaccessible. Le site est peut-etre protege ou necessite une authentification.');
                    continue;
                }

                $summary = $this->summarizeContent($context, $content, $url, $mode);
                $results[] = $summary;
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

    private function handleComparison(AgentContext $context, array $urls, string $mode): AgentResult
    {
        $contents = [];
        $metadataCache = [];

        foreach ($urls as $i => $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } elseif ($this->isVimeoUrl($url)) {
                    $content = $this->extractVimeoContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    return AgentResult::reply(
                        "⚠ Impossible de comparer : contenu inaccessible pour l'URL " . ($i + 1) . ".\n{$url}"
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

    /**
     * New feature: extract only keyword tags from content without a full summary.
     */
    private function handleKeywordsOnly(AgentContext $context, array $urls): AgentResult
    {
        $results = [];
        $model = $this->resolveModel($context);

        foreach ($urls as $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } elseif ($this->isVimeoUrl($url)) {
                    $content = $this->extractVimeoContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    $results[] = $this->formatErrorResult($url, 'Contenu insuffisant ou inaccessible.');
                    continue;
                }

                $systemPrompt = <<<PROMPT
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

        // Block private IPs and localhost
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

    private function isYouTubeUrl(string $url): bool
    {
        return (bool) preg_match(self::YOUTUBE_PATTERN, $url);
    }

    private function isVimeoUrl(string $url): bool
    {
        return (bool) preg_match(self::VIMEO_PATTERN, $url);
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
        // Keywords-only extraction mode (check before bullet/short to avoid conflicts)
        if (preg_match(self::KEYWORDS_ONLY_PATTERN, $body)) {
            return 'keywords';
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

        return 'medium';
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
        // Unicode-aware word counting (handles French accents properly)
        $text = strip_tags($content);
        $wordCount = preg_match_all('/\S+/', $text, $matches);
        return (int) ceil(($wordCount ?: 1) / 200);
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

        // Detect if it's a live stream URL
        $isLive = str_contains($url, '/live/');

        // Try to get transcript via yt-dlp (subtitles)
        $transcript = $this->getYouTubeTranscript($videoId);

        // Always try metadata to enrich context
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
            // Remove HTML-like tags and VTT positioning/style tags
            $line = strip_tags($line);
            $line = preg_replace('/<[^>]+>/', '', $line);
            $line = preg_replace('/\{[^}]+\}/', '', $line);      // Remove VTT style blocks
            $line = preg_replace('/^NOTE\s.*$/m', '', $line);     // Remove VTT NOTE lines
            $line = preg_replace('/^WEBVTT.*$/m', '', $line);     // Remove WEBVTT header
            $line = preg_replace('/^align:.*$/m', '', $line);     // Remove alignment hints
            $line = preg_replace('/^position:.*$/m', '', $line);  // Remove position hints
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

        // Prefer JSON-LD articleBody if regular extraction is thin
        if (mb_strlen($text) < 200 && !empty($jsonLdBody)) {
            $text = $jsonLdBody;
        } elseif (mb_strlen($text) < 50 && $metaDesc) {
            $text = $metaDesc;
        }

        // Limit content length
        $text = mb_substr($text, 0, 10000);

        $header = "[PAGE WEB] URL: {$url}";
        if ($title) $header .= "\nTitre: {$title}";
        if ($author) $header .= "\nAuteur: {$author}";
        if ($pubDate) $header .= "\nDate de publication: {$pubDate}";
        if ($metaDesc) $header .= "\nDescription: {$metaDesc}";

        return "{$header}\n\nCONTENU:\n{$text}";
    }

    private function summarizeContent(AgentContext $context, string $content, string $url, string $mode): string
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

        $lengthInstructions = match ($mode) {
            'short'    => "RESUME COURT: 2-3 phrases maximum. Va droit a l'essentiel. Pas de liste de points. Inclure quand meme le ton et 2-3 mots-cles.",
            'detailed' => "RESUME DETAILLE: Resume complet de 10-15 lignes couvrant tous les arguments, points importants, exemples cles et conclusions. Inclus une liste de 5-8 points cles.",
            'bullet'   => "RESUME EN POINTS: Presente UNIQUEMENT une liste de 5 a 10 bullets. Chaque point est une phrase courte et autonome. Pas de texte de resume avant la liste.",
            default    => "RESUME STANDARD: 3-5 lignes de resume concis + liste de 3-5 points cles essentiels.",
        };

        $bodyFormat = $mode === 'bullet'
            ? "*Points cles :*\n- [point 1 — sujet principal]\n- [point 2 — argument/donnee cle]\n- [point 3 — exemple ou contexte]\n- [...]\n\n*Ton :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif]\n*Mots-cles :* #[tag1] #[tag2] #[tag3]"
            : "[Ton resume ici]\n\n*Points cles :*\n- [point 1]\n- [point 2]\n- [point 3]\n\n*Ton :* [Informatif | Positif | Critique | Neutre | Alarmiste | Technique | Educatif]\n\n*Mots-cles :* #[tag1] #[tag2] #[tag3]";

        $systemPrompt = <<<PROMPT
Tu es un expert en synthese de contenu pour WhatsApp. Resume le contenu fourni de maniere claire et structuree.

{$lengthInstructions}

FORMAT DE REPONSE (optimise WhatsApp):
*[Titre ou source]* — [type: Article / Video YouTube / Video Vimeo / Page web]
{$bodyFormat}

REGLES:
- {$langInstruction}
- Sois factuel et objectif — n'invente RIEN
- Base-toi uniquement sur le contenu fourni
- Si c'est une video YouTube ou Vimeo, mentionne la chaine/auteur
- Si une date de publication ou un auteur est disponible dans les metadonnees, cite-les
- Si le contenu est insuffisant (metadonnees seulement), precise-le
- Pour les articles techniques, mets en avant les concepts cles
- Utilise *gras* pour mettre en valeur les elements importants
- Les mots-cles doivent etre en minuscules, sans espaces, pertinents pour la recherche
- Pas de #hashtags parasites, pas de mentions @
PROMPT;

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $maxTokens = match ($mode) {
            'detailed' => 2048,
            'short', 'bullet' => 512,
            default => 1024,
        };

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

        if (!$response) {
            return $this->formatErrorResult($url, 'Impossible de generer le resume. Verifie ta connexion et reessaie.');
        }

        $icon = match (true) {
            $this->isYouTubeUrl($url) => '🎬',
            $this->isVimeoUrl($url) => '🎥',
            default => '📰',
        };

        $modeLabel = match ($mode) {
            'short'    => 'court',
            'detailed' => 'detaille',
            'bullet'   => 'en points',
            default    => 'standard',
        };

        return "{$icon} *RESUME ({$modeLabel})* — _{$readingTimeStr}_\n\n"
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

        if (str_contains($message, '410') || str_contains($message, 'Gone')) {
            return 'Cette page n\'existe plus (410). Le contenu a ete supprime definitivement.';
        }

        if (str_contains($message, '429') || str_contains($message, 'Too Many Requests')) {
            return 'Le site limite les acces automatiques (rate limit). Reessaie dans quelques minutes.';
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
            "*📰 Resume de Contenu — Articles, Videos & Liens*\n\n"
            . "*Comment utiliser :*\n"
            . "Envoie simplement un lien et je le resumerai !\n\n"
            . "*Exemples :*\n"
            . "- https://example.com/article\n"
            . "- https://youtube.com/watch?v=xxx\n"
            . "- https://vimeo.com/123456789\n"
            . "- _resume court_ https://example.com/article\n"
            . "- _resume detaille_ https://youtube.com/watch?v=xxx\n"
            . "- _en points_ https://example.com/article\n"
            . "- _mots-cles seulement_ https://example.com/article\n"
            . "- _compare_ https://site1.com https://site2.com\n\n"
            . "*Options de longueur :*\n"
            . "- _court/bref/rapide_ → 2-3 phrases\n"
            . "- _standard_ (defaut) → resume + points cles\n"
            . "- _detaille/complet_ → resume approfondi\n"
            . "- _en points/bullet_ → liste de points cles uniquement\n\n"
            . "*Contenus supportes :*\n"
            . "🌐 Articles web & blogs\n"
            . "🎬 Videos YouTube (avec transcription si disponible)\n"
            . "🎥 Videos Vimeo (metadonnees + description)\n"
            . "📄 Pages web generales\n\n"
            . "*Fonctionnalites :*\n"
            . "⏱ Estimation du temps de lecture\n"
            . "🔍 Comparaison de 2 liens (_compare_ + 2 URLs)\n"
            . "🌍 Detection automatique de la langue\n"
            . "🏷 Extraction de mots-cles et detection du ton\n"
            . "📌 Extraction des tags uniquement (_mots-cles seulement_)\n"
            . "👤 Auteur et date de publication si disponibles"
        );
    }
}
