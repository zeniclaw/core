<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a URL and extracts readable text content.
 * Used by the web_fetch tool available to all agents via WebSearchAgent.
 */
class WebFetchService
{
    private const TIMEOUT_SECONDS = 15;
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB
    private const MAX_TEXT_LENGTH = 50000; // chars returned to LLM

    /**
     * Fetch a URL and return extracted text content.
     *
     * @return array{success: bool, url: string, title: ?string, text: ?string, error: ?string, length: int}
     */
    public function fetch(string $url): array
    {
        // Validate URL scheme
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'])) {
            return $this->error($url, 'Only http/https URLs are allowed.');
        }

        // Block private/internal IPs
        $host = $parsed['host'] ?? '';
        if ($this->isPrivateHost($host)) {
            return $this->error($url, 'Private/internal URLs are not allowed.');
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => 'ZeniClaw/1.0 (Web Fetch Bot)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
                ])
                ->get($url);

            if (!$response->successful()) {
                return $this->error($url, "HTTP {$response->status()}");
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES) {
                $body = substr($body, 0, self::MAX_BYTES);
            }

            $contentType = $response->header('Content-Type') ?? '';

            // Plain text content
            if (str_contains($contentType, 'text/plain') || str_contains($contentType, 'application/json')) {
                $text = mb_substr($body, 0, self::MAX_TEXT_LENGTH);
                return $this->success($url, null, $text);
            }

            // HTML content — extract readable text
            $title = $this->extractTitle($body);
            $text = $this->htmlToText($body);
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);

            return $this->success($url, $title, $text);
        } catch (\Exception $e) {
            Log::warning('WebFetchService failed', ['url' => $url, 'error' => $e->getMessage()]);
            return $this->error($url, $e->getMessage());
        }
    }

    /**
     * Convert HTML to readable plain text.
     */
    private function htmlToText(string $html): string
    {
        // Remove script, style, nav, footer, header tags and their content
        $html = preg_replace('/<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?<\/\1>/si', '', $html);

        // Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convert block elements to newlines
        $html = preg_replace('/<\/(p|div|li|tr|h[1-6]|blockquote|article|section)>/i', "\n", $html);
        $html = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n• ", $html);
        $html = preg_replace('/<h([1-6])[^>]*>/i', "\n## ", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return null;
    }

    /**
     * Check if a hostname resolves to a private/internal IP.
     */
    private function isPrivateHost(string $host): bool
    {
        if (!$host) return true;

        // Block common internal hostnames
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'waha', 'redis', 'db', 'app'];
        if (in_array(strtolower($host), $blocked)) {
            return true;
        }

        // Resolve and check IP range
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false; // DNS resolution failed — allow (will fail on HTTP anyway)
        }

        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function success(string $url, ?string $title, string $text): array
    {
        return [
            'success' => true,
            'url' => $url,
            'title' => $title,
            'text' => $text,
            'error' => null,
            'length' => mb_strlen($text),
        ];
    }

    private function error(string $url, string $message): array
    {
        return [
            'success' => false,
            'url' => $url,
            'title' => null,
            'text' => null,
            'error' => $message,
            'length' => 0,
        ];
    }
}
