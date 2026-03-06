<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentSummarizerAgent extends BaseAgent
{
    private const URL_PATTERN = '#https?://[^\s<>\[\]"\']+#i';
    private const YOUTUBE_PATTERN = '#(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([a-zA-Z0-9_-]{11})#i';

    public function name(): string
    {
        return 'content_summarizer';
    }

    public function description(): string
    {
        return 'Agent de resume de contenu web. Resume automatiquement les articles, pages web et videos YouTube (avec transcription). Supporte les resumes courts, standards et detailles.';
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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match(self::URL_PATTERN, $context->body);
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

        $this->log($context, 'Content summarization requested', [
            'urls' => $urls,
            'length' => $length,
        ]);

        $results = [];

        foreach ($urls as $url) {
            try {
                if ($this->isYouTubeUrl($url)) {
                    $content = $this->extractYouTubeContent($url);
                } else {
                    $content = $this->extractWebContent($url);
                }

                if (!$content || mb_strlen($content) < 50) {
                    $results[] = $this->formatErrorResult($url, 'Contenu insuffisant ou inaccessible.');
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

    private function extractUrls(string $body): array
    {
        preg_match_all(self::URL_PATTERN, $body, $matches);
        // Deduplicate and limit to 3 URLs
        return array_slice(array_unique($matches[0] ?? []), 0, 3);
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

        if (preg_match('/\b(detaille|detailed|complet|full|long|approfondi|in[- ]?depth)\b/iu', $bodyLower)) {
            return 'detailed';
        }

        if (preg_match('/\b(court|short|bref|brief|rapide|quick|tldr|tl;?dr|resume\s+court)\b/iu', $bodyLower)) {
            return 'short';
        }

        return 'medium';
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

        // Try yt-dlp to extract subtitles
        $command = sprintf(
            'yt-dlp --skip-download --write-auto-sub --sub-lang fr,en --sub-format vtt --convert-subs srt -o %s %s 2>&1',
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
            // Remove HTML-like tags from subtitles
            $line = strip_tags($line);
            $line = preg_replace('/<[^>]+>/', '', $line);
            // Skip duplicate consecutive lines
            if ($line !== $previousLine) {
                $text[] = $line;
                $previousLine = $line;
            }
        }

        $transcript = implode(' ', $text);
        // Limit to ~8000 chars to stay within context
        return mb_substr($transcript, 0, 8000);
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
                return "[VIDEO YOUTUBE] Titre: {$title} | Auteur: {$author}\n(Transcription non disponible, resume base sur les metadonnees)";
            }
        } catch (\Throwable $e) {
            Log::debug("[content_summarizer] YouTube oEmbed failed: " . $e->getMessage());
        }

        return null;
    }

    private function extractWebContent(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ContentSummarizer/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'fr,en;q=0.8',
                ])
                ->get($url);

            if (!$response->successful()) {
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
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
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

        // Remove script, style, nav, footer, header tags
        $cleanHtml = preg_replace('/<(script|style|nav|footer|header|aside|iframe|noscript)[^>]*>.*?<\/\1>/si', '', $html);

        // Try to find article content
        $articleContent = '';
        if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $cleanHtml, $m)) {
            $articleContent = $m[1];
        } elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $cleanHtml, $m)) {
            $articleContent = $m[1];
        } elseif (preg_match('/<div[^>]*class="[^"]*(?:content|article|post|entry)[^"]*"[^>]*>(.*?)<\/div>/si', $cleanHtml, $m)) {
            $articleContent = $m[1];
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
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) < 50 && $metaDesc) {
            $text = $metaDesc;
        }

        // Limit content length
        $text = mb_substr($text, 0, 8000);

        $header = "[PAGE WEB] URL: {$url}";
        if ($title) $header .= "\nTitre: {$title}";
        if ($metaDesc) $header .= "\nDescription: {$metaDesc}";

        return "{$header}\n\nCONTENU:\n{$text}";
    }

    private function summarizeContent(AgentContext $context, string $content, string $url, string $length): string
    {
        $model = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $lengthInstructions = match ($length) {
            'short' => "RESUME COURT: 2-3 phrases maximum. Va droit a l'essentiel.",
            'detailed' => "RESUME DETAILLE: Resume complet de 10-15 lignes avec tous les points importants, arguments, et details cles.",
            default => "RESUME STANDARD: 3-5 lignes de resume + 3-5 points cles.",
        };

        $systemPrompt = <<<PROMPT
Tu es un expert en synthese de contenu. Resume le contenu fourni de maniere claire et structuree.

{$lengthInstructions}

FORMAT DE REPONSE:
1. Titre/Source en gras (*titre*)
2. Resume
3. Points cles (avec des puces -)
4. Lien source original

REGLES:
- Reponds en francais (sauf si le contenu est en anglais et l'utilisateur semble anglophone)
- Sois factuel et objectif
- Si c'est une video YouTube, mentionne l'auteur/chaine
- Si le contenu est un article technique, mets en avant les concepts cles
- N'invente RIEN, base-toi uniquement sur le contenu fourni
- Si le contenu est insuffisant, dis-le clairement
PROMPT;

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        $response = $this->claude->chat($content, $model, $systemPrompt);

        if (!$response) {
            return $this->formatErrorResult($url, 'Impossible de generer le resume.');
        }

        $icon = $this->isYouTubeUrl($url) ? '🎬' : '📰';
        $lengthLabel = match ($length) {
            'short' => 'court',
            'detailed' => 'detaille',
            default => 'standard',
        };

        return "{$icon} *RESUME ({$lengthLabel})*\n\n" . trim($response) . "\n\n🔗 {$url}";
    }

    private function formatErrorResult(string $url, string $error): string
    {
        return "⚠ *Erreur pour :* {$url}\n{$error}";
    }

    private function friendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'Le site met trop de temps a repondre. Reessaie plus tard.';
        }

        if (str_contains($message, '403') || str_contains($message, 'Forbidden')) {
            return 'Acces refuse par le site. Le contenu est peut-etre protege.';
        }

        if (str_contains($message, '404') || str_contains($message, 'Not Found')) {
            return 'Page introuvable. Verifie que le lien est correct.';
        }

        if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
            return 'Erreur de certificat SSL. Le site pourrait avoir un probleme de securite.';
        }

        return 'Impossible de recuperer le contenu. Verifie que le lien est valide et accessible.';
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
            . "- resume court https://example.com/article\n"
            . "- resume detaille https://youtube.com/watch?v=xxx\n\n"
            . "*Options de longueur :*\n"
            . "- _court/bref/rapide_ → 2-3 phrases\n"
            . "- _standard_ (par defaut) → resume + points cles\n"
            . "- _detaille/complet_ → resume approfondi\n\n"
            . "*Contenus supportes :*\n"
            . "🌐 Articles web & blogs\n"
            . "🎬 Videos YouTube (avec transcription)\n"
            . "📄 Pages web generales"
        );
    }
}
