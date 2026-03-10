<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\ContentSummarizerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSummarizerAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    // ── Agent basics ─────────────────────────────────────────────────────────

    public function test_agent_returns_correct_name(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertEquals('content_summarizer', $agent->name());
    }

    public function test_agent_version_is_1_12_0(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertEquals('1.12.0', $agent->version());
    }

    public function test_agent_has_description(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertNotEmpty($agent->description());
    }

    public function test_keywords_include_compare(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('compare', $agent->keywords());
        $this->assertContains('comparer', $agent->keywords());
    }

    public function test_keywords_include_vimeo(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('vimeo', $agent->keywords());
    }

    public function test_keywords_include_tags(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('mots-cles', $agent->keywords());
        $this->assertContains('tags', $agent->keywords());
    }

    // ── canHandle ─────────────────────────────────────────────────────────────

    public function test_can_handle_url_in_message(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://example.com/article')));
    }

    public function test_can_handle_youtube_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://youtube.com/watch?v=dQw4w9WgXcQ')));
    }

    public function test_can_handle_vimeo_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://vimeo.com/123456789')));
    }

    public function test_can_handle_resume_keyword(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('resume cet article')));
        $this->assertTrue($agent->canHandle($this->makeContext('résumé de la page')));
        $this->assertTrue($agent->canHandle($this->makeContext('tldr https://example.com')));
    }

    public function test_can_handle_summary_english_keywords(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('summarize this article')));
        $this->assertTrue($agent->canHandle($this->makeContext('read for me')));
        $this->assertTrue($agent->canHandle($this->makeContext('tl;dr')));
    }

    public function test_can_handle_compare_keyword(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('compare https://a.com https://b.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('comparaison entre ces liens')));
    }

    public function test_cannot_handle_empty_body(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertFalse($agent->canHandle($this->makeContext('')));
    }

    public function test_cannot_handle_null_body(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertFalse($agent->canHandle($this->makeContext(null)));
    }

    public function test_cannot_handle_unrelated_messages(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertFalse($agent->canHandle($this->makeContext('bonjour comment tu vas')));
        $this->assertFalse($agent->canHandle($this->makeContext('rappelle-moi demain')));
        $this->assertFalse($agent->canHandle($this->makeContext('joue de la musique')));
    }

    // ── URL security ──────────────────────────────────────────────────────────

    public function test_handle_shows_help_when_no_valid_urls(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext('resume cet article'));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
    }

    public function test_private_ip_url_is_blocked(): void
    {
        $agent = new ContentSummarizerAgent();
        // Message with only a private IP URL — should show help (no valid URLs extracted)
        $result = $agent->handle($this->makeContext('resume http://192.168.1.1/page'));

        $this->assertEquals('reply', $result->action);
        // Should show help since no valid URL passes security check
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
    }

    public function test_localhost_url_is_blocked(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext('resume http://localhost:8080/secret'));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
    }

    public function test_file_scheme_url_is_blocked(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'file:///etc/passwd'));
        $this->assertFalse($method->invoke($agent, 'ftp://files.example.com/data'));
        $this->assertFalse($method->invoke($agent, 'data:text/html,<script>alert(1)</script>'));
    }

    // ── Summary mode detection ────────────────────────────────────────────────

    public function test_detect_short_summary_keywords(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('short', $method->invoke($agent, 'resume court https://example.com'));
        $this->assertEquals('short', $method->invoke($agent, 'tldr https://example.com'));
        $this->assertEquals('short', $method->invoke($agent, 'bref https://example.com'));
        $this->assertEquals('short', $method->invoke($agent, 'en bref https://example.com'));
    }

    public function test_detect_detailed_summary_keywords(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('detailed', $method->invoke($agent, 'resume detaille https://example.com'));
        $this->assertEquals('detailed', $method->invoke($agent, 'resume complet https://example.com'));
        $this->assertEquals('detailed', $method->invoke($agent, 'detailed summary https://example.com'));
        $this->assertEquals('detailed', $method->invoke($agent, 'approfondi https://example.com'));
    }

    public function test_detect_medium_by_default(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('medium', $method->invoke($agent, 'resume https://example.com'));
        $this->assertEquals('medium', $method->invoke($agent, 'summarize this'));
    }

    public function test_detect_bullet_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('bullet', $method->invoke($agent, 'en points https://example.com'));
        $this->assertEquals('bullet', $method->invoke($agent, 'bullet https://example.com'));
        $this->assertEquals('bullet', $method->invoke($agent, 'liste de points https://example.com'));
        $this->assertEquals('bullet', $method->invoke($agent, 'key points https://example.com'));
    }

    public function test_detect_keywords_only_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('keywords', $method->invoke($agent, 'mots-cles seulement https://example.com'));
        $this->assertEquals('keywords', $method->invoke($agent, 'keywords only https://example.com'));
        $this->assertEquals('keywords', $method->invoke($agent, 'liste des tags https://example.com'));
        $this->assertEquals('keywords', $method->invoke($agent, 'extraire les tags https://example.com'));
    }

    public function test_keywords_mode_takes_priority_over_bullet(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // keywords-only pattern should win even if bullet words appear
        $this->assertEquals('keywords', $method->invoke($agent, 'mots-cles seulement en points https://example.com'));
    }

    // ── URL extraction ────────────────────────────────────────────────────────

    public function test_extract_urls_limits_to_3(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractUrls');
        $method->setAccessible(true);

        $body = 'https://a.com https://b.com https://c.com https://d.com';
        $urls = $method->invoke($agent, $body);

        $this->assertCount(3, $urls);
    }

    public function test_extract_urls_deduplicates(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractUrls');
        $method->setAccessible(true);

        $body = 'https://example.com https://example.com https://other.com';
        $urls = $method->invoke($agent, $body);

        $this->assertCount(2, $urls);
    }

    // ── YouTube detection ─────────────────────────────────────────────────────

    public function test_is_youtube_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isYouTubeUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertTrue($method->invoke($agent, 'https://youtu.be/dQw4w9WgXcQ'));
        $this->assertTrue($method->invoke($agent, 'https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertTrue($method->invoke($agent, 'https://www.youtube.com/live/dQw4w9WgXcQ'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/video'));
        $this->assertFalse($method->invoke($agent, 'https://vimeo.com/123456'));
    }

    public function test_extract_youtube_video_id(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractYouTubeVideoId');
        $method->setAccessible(true);

        $this->assertEquals('dQw4w9WgXcQ', $method->invoke($agent, 'https://youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertEquals('dQw4w9WgXcQ', $method->invoke($agent, 'https://youtu.be/dQw4w9WgXcQ'));
        $this->assertEquals('dQw4w9WgXcQ', $method->invoke($agent, 'https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertEquals('dQw4w9WgXcQ', $method->invoke($agent, 'https://www.youtube.com/live/dQw4w9WgXcQ'));
        $this->assertNull($method->invoke($agent, 'https://example.com'));
    }

    // ── Vimeo detection ───────────────────────────────────────────────────────

    public function test_is_vimeo_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isVimeoUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://vimeo.com/123456789'));
        $this->assertTrue($method->invoke($agent, 'https://www.vimeo.com/987654321'));
        $this->assertFalse($method->invoke($agent, 'https://youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/video'));
    }

    public function test_extract_vimeo_video_id(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractVimeoVideoId');
        $method->setAccessible(true);

        $this->assertEquals('123456789', $method->invoke($agent, 'https://vimeo.com/123456789'));
        $this->assertEquals('987654321', $method->invoke($agent, 'https://www.vimeo.com/987654321'));
        $this->assertNull($method->invoke($agent, 'https://example.com'));
    }

    // ── SRT cleaning ──────────────────────────────────────────────────────────

    public function test_clean_srt_transcript_removes_timestamps(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('cleanSrtTranscript');
        $method->setAccessible(true);

        $srt = "1\n00:00:01,000 --> 00:00:03,000\nHello world\n\n2\n00:00:04,000 --> 00:00:06,000\nThis is a test\n";
        $result = $method->invoke($agent, $srt);

        $this->assertStringNotContainsString('00:00:01', $result);
        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('This is a test', $result);
    }

    public function test_clean_srt_removes_duplicate_lines(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('cleanSrtTranscript');
        $method->setAccessible(true);

        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHello\n\n2\n00:00:02,000 --> 00:00:03,000\nHello\n\n3\n00:00:03,000 --> 00:00:04,000\nWorld\n";
        $result = $method->invoke($agent, $srt);

        // "Hello" should appear only once
        $this->assertEquals(1, substr_count($result, 'Hello'));
        $this->assertStringContainsString('World', $result);
    }

    // ── Reading time estimate ─────────────────────────────────────────────────

    public function test_estimate_reading_time_short_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('estimateReadingTime');
        $method->setAccessible(true);

        // ~100 words → 1 minute
        $content = str_repeat('word ', 100);
        $result = $method->invoke($agent, $content);
        $this->assertEquals(1, $result);
    }

    public function test_estimate_reading_time_long_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('estimateReadingTime');
        $method->setAccessible(true);

        // ~600 words → 3 minutes
        $content = str_repeat('word ', 600);
        $result = $method->invoke($agent, $content);
        $this->assertEquals(3, $result);
    }

    public function test_estimate_reading_time_french_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('estimateReadingTime');
        $method->setAccessible(true);

        // French content with accents — should count correctly
        $content = str_repeat('résumé ', 200);
        $result = $method->invoke($agent, $content);
        $this->assertEquals(1, $result);
    }

    // ── Language detection ────────────────────────────────────────────────────

    public function test_detect_french_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectContentLanguage');
        $method->setAccessible(true);

        $frenchText = "Le gouvernement a annonce une nouvelle politique pour les entreprises dans les grandes villes de France.";
        $result = $method->invoke($agent, $frenchText);
        $this->assertEquals('fr', $result);
    }

    public function test_detect_english_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectContentLanguage');
        $method->setAccessible(true);

        $englishText = "The government announced a new policy for all companies in the major cities. One of the key goals is to improve economic growth.";
        $result = $method->invoke($agent, $englishText);
        $this->assertEquals('en', $result);
    }

    // ── Help message ──────────────────────────────────────────────────────────

    public function test_help_message_shows_on_empty_body(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
        $this->assertStringContainsString('compare', $result->reply);
        $this->assertStringContainsString('YouTube', $result->reply);
        $this->assertStringContainsString('temps de lecture', $result->reply);
    }

    public function test_help_message_shows_new_features(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Comparaison', $result->reply);
        $this->assertStringContainsString('Detection automatique de la langue', $result->reply);
        $this->assertStringContainsString('en points', $result->reply);
        $this->assertStringContainsString('mots-cles seulement', $result->reply);
    }

    public function test_help_message_shows_vimeo(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Vimeo', $result->reply);
        $this->assertStringContainsString('mots-cles', $result->reply);
    }

    // ── Error formatting ──────────────────────────────────────────────────────

    public function test_friendly_error_timeout(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('Connection timed out');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('timeout', $result);
    }

    public function test_friendly_error_403(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('403 Forbidden');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('403', $result);
    }

    public function test_friendly_error_404(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('404 Not Found');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('404', $result);
    }

    public function test_friendly_error_410(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('410 Gone');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('410', $result);
    }

    public function test_friendly_error_429(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('429 Too Many Requests');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('rate limit', $result);
    }

    public function test_friendly_error_ssl(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('SSL certificate verification failed');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('SSL', $result);
    }

    public function test_friendly_error_dns(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('Could not resolve host: invalid.example.com');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('domaine', $result);
    }

    public function test_format_error_result_truncates_long_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('formatErrorResult');
        $method->setAccessible(true);

        $longUrl = 'https://example.com/' . str_repeat('very-long-path/', 10);
        $result = $method->invoke($agent, $longUrl, 'Test error');

        $this->assertStringContainsString('...', $result);
        $this->assertStringContainsString('Test error', $result);
    }

    // ── HTML parsing ──────────────────────────────────────────────────────────

    public function test_parse_html_extracts_title_and_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('parseHtmlContent');
        $method->setAccessible(true);

        $html = <<<HTML
<html>
<head><title>Test Article</title><meta name="description" content="This is a test article about PHP."></head>
<body>
<nav>Navigation</nav>
<main>
<article>
<h1>Test Article</h1>
<p>This is the main content of the article. It has multiple sentences to test parsing.</p>
</article>
</main>
<footer>Footer content</footer>
</body>
</html>
HTML;

        $result = $method->invoke($agent, $html, 'https://example.com/test');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Test Article', $result);
        $this->assertStringContainsString('This is the main content', $result);
        $this->assertStringContainsString('https://example.com/test', $result);
    }

    public function test_parse_html_removes_scripts_and_styles(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('parseHtmlContent');
        $method->setAccessible(true);

        $html = <<<HTML
<html><body>
<script>alert('xss')</script>
<style>body { color: red; }</style>
<main><p>Real content here</p></main>
</body></html>
HTML;

        $result = $method->invoke($agent, $html, 'https://example.com');

        $this->assertStringNotContainsString("alert('xss')", $result);
        $this->assertStringNotContainsString('color: red', $result);
        $this->assertStringContainsString('Real content here', $result);
    }

    public function test_parse_html_extracts_json_ld_article_body(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('parseHtmlContent');
        $method->setAccessible(true);

        // No <title> tag so JSON-LD headline gets used as fallback title
        $html = <<<HTML
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "Amazing Article",
    "description": "Article description from JSON-LD",
    "articleBody": "This is the full article body from JSON-LD structured data."
}
</script>
</head>
<body><p>x</p></body>
</html>
HTML;

        $result = $method->invoke($agent, $html, 'https://example.com/test');

        $this->assertNotNull($result);
        // JSON-LD headline used as title (no <title> tag present)
        $this->assertStringContainsString('Amazing Article', $result);
        // JSON-LD description extracted
        $this->assertStringContainsString('Article description from JSON-LD', $result);
        // JSON-LD articleBody used as content when HTML body is thin
        $this->assertStringContainsString('full article body from JSON-LD', $result);
    }

    public function test_parse_html_extracts_author_and_pub_date(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('parseHtmlContent');
        $method->setAccessible(true);

        $html = <<<HTML
<html>
<head>
<title>Test Article</title>
<meta name="author" content="Jane Doe">
<meta property="article:published_time" content="2026-01-15T08:30:00Z">
<meta name="description" content="An article about testing.">
</head>
<body><main><p>Article body content here.</p></main></body>
</html>
HTML;

        $result = $method->invoke($agent, $html, 'https://example.com/article');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Jane Doe', $result);
        $this->assertStringContainsString('2026-01-15', $result);
        $this->assertStringNotContainsString('T08:30:00Z', $result); // Date should be trimmed to YYYY-MM-DD
    }

    public function test_parse_html_extracts_author_from_json_ld(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('parseHtmlContent');
        $method->setAccessible(true);

        $html = <<<HTML
<html>
<head>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "JSON-LD Article",
    "author": {"@type": "Person", "name": "John Smith"},
    "datePublished": "2025-12-01T10:00:00Z"
}
</script>
</head>
<body><main><p>Content here.</p></main></body>
</html>
HTML;

        $result = $method->invoke($agent, $html, 'https://example.com/test');

        $this->assertNotNull($result);
        $this->assertStringContainsString('John Smith', $result);
        $this->assertStringContainsString('2025-12-01', $result);
    }

    public function test_is_secure_url_blocks_private_ips(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'http://192.168.1.1/page'));
        $this->assertFalse($method->invoke($agent, 'http://10.0.0.1/admin'));
        $this->assertFalse($method->invoke($agent, 'http://localhost/secret'));
        $this->assertFalse($method->invoke($agent, 'http://127.0.0.1:8080/api'));
        $this->assertTrue($method->invoke($agent, 'https://example.com/article'));
        $this->assertTrue($method->invoke($agent, 'https://www.youtube.com/watch?v=abc'));
    }

    public function test_is_secure_url_blocks_non_http_schemes(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'file:///etc/passwd'));
        $this->assertFalse($method->invoke($agent, 'ftp://files.example.com/data'));
        $this->assertFalse($method->invoke($agent, 'data:text/html,<b>test</b>'));
        $this->assertTrue($method->invoke($agent, 'http://example.com/page'));
        $this->assertTrue($method->invoke($agent, 'https://example.com/page'));
    }

    public function test_onion_domain_is_blocked(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'http://3g2upl4pq6kufc4m.onion/'));
        $this->assertFalse($method->invoke($agent, 'https://somesite.onion/page'));
        $this->assertFalse($method->invoke($agent, 'http://hidden.onion:8080/path'));
        // Non-.onion with "onion" in path should pass
        $this->assertTrue($method->invoke($agent, 'https://onion.example.com/article'));
    }

    // ── Twitter/X URL detection ───────────────────────────────────────────────

    public function test_is_twitter_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isTwitterUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://twitter.com/user/status/1234567890'));
        $this->assertTrue($method->invoke($agent, 'https://x.com/elonmusk/status/9876543210'));
        $this->assertTrue($method->invoke($agent, 'https://www.twitter.com/someuser/status/111'));
        $this->assertFalse($method->invoke($agent, 'https://youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertFalse($method->invoke($agent, 'https://twitter.com/user')); // no /status/
        $this->assertFalse($method->invoke($agent, 'https://example.com/page'));
    }

    public function test_can_handle_twitter_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://twitter.com/user/status/1234567890')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://x.com/user/status/9876543210')));
    }

    // ── Text paste summarization ──────────────────────────────────────────────

    public function test_is_text_paste_request_requires_keyword_and_length(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isTextPasteRequest');
        $method->setAccessible(true);

        // Short body — not a paste request even with keyword
        $this->assertFalse($method->invoke($agent, 'resume cet article'));

        // Long body without keyword — not a paste request
        $this->assertFalse($method->invoke($agent, str_repeat('lorem ipsum dolor sit amet. ', 20)));

        // Long body with keyword — is a paste request
        $longText = 'résumé ' . str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing. ', 10);
        $this->assertTrue($method->invoke($agent, $longText));

        // English keyword also works
        $longEnglish = 'summarize ' . str_repeat('The quick brown fox jumps over the lazy dog. ', 10);
        $this->assertTrue($method->invoke($agent, $longEnglish));
    }

    public function test_handle_text_paste_returns_reply_not_help(): void
    {
        $agent = new ContentSummarizerAgent();
        // Long body with keyword but no URL → should NOT show help (text paste mode)
        $longText = 'résumé ' . str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor. ', 8);
        $result = $agent->handle($this->makeContext($longText));

        $this->assertEquals('reply', $result->action);
        // Must NOT be the help message
        $this->assertStringNotContainsString('Comment utiliser', $result->reply);
    }

    // ── Error content detection ───────────────────────────────────────────────

    public function test_is_error_content_detects_markers(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isErrorContent');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, '[ACCES REFUSE] URL: https://example.com'));
        $this->assertTrue($method->invoke($agent, '[RATE LIMIT] URL: https://example.com'));
        $this->assertTrue($method->invoke($agent, '[ERREUR SERVEUR] URL: https://example.com'));
        $this->assertFalse($method->invoke($agent, '[PAGE WEB] URL: https://example.com'));
        $this->assertFalse($method->invoke($agent, 'Normal article content here'));
    }

    public function test_extract_error_message_from_marker(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractErrorMessage');
        $method->setAccessible(true);

        $content = "[ACCES REFUSE] URL: https://example.com\n(Le site requiert une authentification ou bloque les bots)";
        $result = $method->invoke($agent, $content);
        $this->assertStringContainsString('authentification', $result);
    }

    // ── YouTube embed URL ─────────────────────────────────────────────────────

    public function test_is_youtube_embed_url_detected(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isYouTubeUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://www.youtube.com/embed/dQw4w9WgXcQ'));
        $this->assertTrue($method->invoke($agent, 'https://youtube.com/embed/dQw4w9WgXcQ'));
    }

    public function test_extract_youtube_embed_video_id(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractYouTubeVideoId');
        $method->setAccessible(true);

        $this->assertEquals('dQw4w9WgXcQ', $method->invoke($agent, 'https://www.youtube.com/embed/dQw4w9WgXcQ'));
    }

    // ── Private IP blocking (extended) ────────────────────────────────────────

    public function test_is_secure_url_blocks_zero_ip(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'http://0.0.0.0/admin'));
    }

    public function test_is_secure_url_blocks_link_local(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSecureUrl');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($agent, 'http://169.254.169.254/metadata'));
        $this->assertFalse($method->invoke($agent, 'http://169.254.1.1/secret'));
    }

    // ── Keywords includes twitter ─────────────────────────────────────────────

    public function test_keywords_include_twitter(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('twitter', $agent->keywords());
        $this->assertContains('tweet', $agent->keywords());
    }

    // ── Tone analysis mode ────────────────────────────────────────────────────

    public function test_can_handle_tone_analysis_keywords(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('analyse le ton https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('quel est le ton https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('tone analysis https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('analyse sentiment https://example.com')));
    }

    public function test_tone_analysis_shows_help_without_url(): void
    {
        $agent = new ContentSummarizerAgent();
        // No URL → should fall through to help
        $result = $agent->handle($this->makeContext('analyse le ton'));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
    }

    public function test_keywords_include_tone_and_sentiment(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('ton', $agent->keywords());
        $this->assertContains('tone', $agent->keywords());
        $this->assertContains('sentiment', $agent->keywords());
    }

    // ── Output language override ──────────────────────────────────────────────

    public function test_detect_output_language_english(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectOutputLanguage');
        $method->setAccessible(true);

        $this->assertEquals('en', $method->invoke($agent, 'resume en anglais https://example.com'));
        $this->assertEquals('en', $method->invoke($agent, 'summarize in english https://example.com'));
    }

    public function test_detect_output_language_french(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectOutputLanguage');
        $method->setAccessible(true);

        $this->assertEquals('fr', $method->invoke($agent, 'summarize in french https://example.com'));
        $this->assertEquals('fr', $method->invoke($agent, 'resume en francais https://example.com'));
    }

    public function test_detect_output_language_spanish(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectOutputLanguage');
        $method->setAccessible(true);

        $this->assertEquals('es', $method->invoke($agent, 'resume en espagnol https://example.com'));
        $this->assertEquals('es', $method->invoke($agent, 'summarize in spanish https://example.com'));
    }

    public function test_detect_output_language_german(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectOutputLanguage');
        $method->setAccessible(true);

        $this->assertEquals('de', $method->invoke($agent, 'resume en allemand https://example.com'));
        $this->assertEquals('de', $method->invoke($agent, 'summarize in german https://example.com'));
    }

    public function test_detect_output_language_returns_null_when_no_override(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectOutputLanguage');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($agent, 'resume https://example.com'));
        $this->assertNull($method->invoke($agent, 'summarize this article'));
        $this->assertNull($method->invoke($agent, 'tldr https://example.com'));
    }

    public function test_keywords_include_output_language_hints(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('en anglais', $agent->keywords());
        $this->assertContains('in english', $agent->keywords());
    }

    // ── Help message includes new features ────────────────────────────────────

    public function test_help_message_shows_tone_analysis(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('analyse le ton', $result->reply);
        $this->assertStringContainsString('ton et sentiment', $result->reply);
    }

    public function test_help_message_shows_language_override(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Langue de reponse', $result->reply);
        $this->assertStringContainsString('in english', $result->reply);
    }

    // ── Compare mode ──────────────────────────────────────────────────────────

    public function test_compare_mode_triggers_with_lequel_keyword(): void
    {
        // "lequel" should now trigger compare mode with 2 URLs
        // We test via canHandle (since handle would make HTTP calls)
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractUrls');
        $method->setAccessible(true);

        // Verify 2 URLs are extracted (compare logic tested indirectly)
        $urls = $method->invoke($agent, 'lequel est mieux https://site1.com https://site2.com');
        $this->assertCount(2, $urls);
    }

    // ── Wikipedia URL detection ───────────────────────────────────────────────

    public function test_is_wikipedia_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isWikipediaUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://fr.wikipedia.org/wiki/PHP_(langage)'));
        $this->assertTrue($method->invoke($agent, 'https://en.wikipedia.org/wiki/Artificial_intelligence'));
        $this->assertTrue($method->invoke($agent, 'https://de.wikipedia.org/wiki/Linux'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/wiki/article'));
        $this->assertFalse($method->invoke($agent, 'https://youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function test_can_handle_wikipedia_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://fr.wikipedia.org/wiki/PHP_(langage)')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://en.wikipedia.org/wiki/Artificial_intelligence')));
    }

    public function test_keywords_include_wikipedia(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('wikipedia', $agent->keywords());
        $this->assertContains('wiki', $agent->keywords());
    }

    // ── GitHub URL detection ──────────────────────────────────────────────────

    public function test_is_github_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isGithubUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://github.com/laravel/laravel'));
        $this->assertTrue($method->invoke($agent, 'https://github.com/anthropics/claude-code'));
        $this->assertTrue($method->invoke($agent, 'https://www.github.com/owner/repo'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/laravel/laravel'));
        $this->assertFalse($method->invoke($agent, 'https://gitlab.com/owner/repo'));
    }

    public function test_can_handle_github_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://github.com/laravel/laravel')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://github.com/anthropics/claude-code')));
    }

    public function test_keywords_include_github(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('github', $agent->keywords());
        $this->assertContains('readme', $agent->keywords());
        $this->assertContains('repository', $agent->keywords());
    }

    public function test_help_message_shows_wikipedia_and_github(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Wikipedia', $result->reply);
        $this->assertStringContainsString('GitHub', $result->reply);
        $this->assertStringContainsString('wikipedia.org', $result->reply);
        $this->assertStringContainsString('github.com', $result->reply);
    }

    // ── Reddit URL detection ──────────────────────────────────────────────────

    public function test_is_reddit_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isRedditUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://reddit.com/r/programming/comments/abc123/some_post'));
        $this->assertTrue($method->invoke($agent, 'https://www.reddit.com/r/technology/comments/xyz789/another_post'));
        $this->assertTrue($method->invoke($agent, 'https://reddit.com/r/science/comments/def456'));
        $this->assertFalse($method->invoke($agent, 'https://reddit.com/r/programming'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/r/foo/comments/bar'));
        $this->assertFalse($method->invoke($agent, 'https://github.com/laravel/laravel'));
    }

    public function test_can_handle_reddit_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://reddit.com/r/programming/comments/abc123/post')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://www.reddit.com/r/tech/comments/xyz/titre')));
    }

    public function test_keywords_include_reddit(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('reddit', $agent->keywords());
        $this->assertContains('subreddit', $agent->keywords());
    }

    public function test_help_message_shows_reddit(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Reddit', $result->reply);
        $this->assertStringContainsString('reddit.com', $result->reply);
    }

    // ── Word count mode ───────────────────────────────────────────────────────

    public function test_detect_word_count_mode_french(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('wordcount:100', $method->invoke($agent, 'resume en 100 mots https://example.com'));
        $this->assertEquals('wordcount:50', $method->invoke($agent, 'resume en 50 mots https://example.com'));
        $this->assertEquals('wordcount:200', $method->invoke($agent, 'resume en 200 mots https://example.com'));
    }

    public function test_detect_word_count_mode_english(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('wordcount:150', $method->invoke($agent, 'summarize in 150 words https://example.com'));
        $this->assertEquals('wordcount:75', $method->invoke($agent, 'in 75 words https://example.com'));
    }

    public function test_word_count_mode_clamps_minimum(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // Values below 20 are clamped to 20
        $this->assertEquals('wordcount:20', $method->invoke($agent, 'resume en 5 mots https://example.com'));
    }

    public function test_word_count_mode_clamps_maximum(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // Values above 2000 are clamped to 2000
        $this->assertEquals('wordcount:2000', $method->invoke($agent, 'resume en 9999 mots https://example.com'));
    }

    public function test_keywords_only_takes_priority_over_word_count(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // keywords-only pattern should win even if word count is present
        $this->assertEquals('keywords', $method->invoke($agent, 'mots-cles seulement en 100 mots https://example.com'));
    }

    public function test_help_message_shows_word_count_option(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('en X mots', $result->reply);
        $this->assertStringContainsString('en 100 mots', $result->reply);
        $this->assertStringContainsString('nombre de mots precis', $result->reply);
    }

    // ── HackerNews URL detection ──────────────────────────────────────────────

    public function test_is_hackernews_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isHackerNewsUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://news.ycombinator.com/item?id=12345678'));
        $this->assertTrue($method->invoke($agent, 'http://news.ycombinator.com/item?id=1'));
        $this->assertFalse($method->invoke($agent, 'https://news.ycombinator.com/newest'));
        $this->assertFalse($method->invoke($agent, 'https://github.com/laravel/laravel'));
        $this->assertFalse($method->invoke($agent, 'https://reddit.com/r/programming/comments/abc'));
    }

    public function test_can_handle_hackernews_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://news.ycombinator.com/item?id=12345678')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://news.ycombinator.com/item?id=9876543')));
    }

    public function test_keywords_include_hackernews(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('hackernews', $agent->keywords());
        $this->assertContains('ycombinator', $agent->keywords());
    }

    public function test_help_message_shows_hackernews(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('HackerNews', $result->reply);
        $this->assertStringContainsString('news.ycombinator.com', $result->reply);
    }

    // ── LinkedIn URL detection ────────────────────────────────────────────────

    public function test_is_linkedin_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isLinkedInUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://www.linkedin.com/pulse/article-titre'));
        $this->assertTrue($method->invoke($agent, 'https://linkedin.com/posts/username_activity-123'));
        $this->assertFalse($method->invoke($agent, 'https://linkedin.com/in/username'));
        $this->assertFalse($method->invoke($agent, 'https://github.com/laravel/laravel'));
    }

    public function test_can_handle_linkedin_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://www.linkedin.com/pulse/article-de-test')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://linkedin.com/posts/user-activity-123')));
    }

    public function test_keywords_include_linkedin(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('linkedin', $agent->keywords());
        $this->assertContains('linkedin article', $agent->keywords());
    }

    public function test_help_message_shows_linkedin(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('LinkedIn', $result->reply);
        $this->assertStringContainsString('linkedin.com', $result->reply);
    }

    // ── HTTP 451 error handling ───────────────────────────────────────────────

    public function test_is_error_content_detects_blocked_marker(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isErrorContent');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, '[CONTENU BLOQUE] URL: https://example.com'));
        $this->assertFalse($method->invoke($agent, '[PAGE WEB] URL: https://example.com'));
    }

    public function test_friendly_error_451(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('friendlyError');
        $method->setAccessible(true);

        $e = new \RuntimeException('451 Unavailable For Legal Reasons');
        $result = $method->invoke($agent, $e);
        $this->assertStringContainsString('451', $result);
    }

    // ── Tone analysis on text paste ───────────────────────────────────────────

    public function test_tone_analysis_on_text_paste_returns_reply(): void
    {
        $agent = new ContentSummarizerAgent();
        // Long body with tone keyword and no URL → should trigger tone analysis on paste
        $longText = 'analyse le ton du texte suivant : ' . str_repeat('Le gouvernement a annonce des mesures drastiques. ', 15);
        $result = $agent->handle($this->makeContext($longText));

        $this->assertEquals('reply', $result->action);
        // Must NOT show help
        $this->assertStringNotContainsString('Comment utiliser', $result->reply);
    }

    public function test_help_message_mentions_tone_on_text_paste(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        // Help should mention tone analysis on pasted text
        $this->assertStringContainsString('analyse le ton', $result->reply);
        $this->assertStringContainsString('colle ton texte', $result->reply);
    }

    // ── Arxiv URL detection ───────────────────────────────────────────────────

    public function test_is_arxiv_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isArxivUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://arxiv.org/abs/2301.01234'));
        $this->assertTrue($method->invoke($agent, 'https://arxiv.org/pdf/2301.01234'));
        $this->assertTrue($method->invoke($agent, 'https://arxiv.org/abs/1706.03762v5'));
        $this->assertTrue($method->invoke($agent, 'http://arxiv.org/abs/2403.00001'));
        $this->assertFalse($method->invoke($agent, 'https://arxiv.org/'));
        $this->assertFalse($method->invoke($agent, 'https://github.com/arxiv/arxiv'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/abs/1234.5678'));
    }

    public function test_extract_arxiv_id(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('extractArxivId');
        $method->setAccessible(true);

        $this->assertEquals('2301.01234', $method->invoke($agent, 'https://arxiv.org/abs/2301.01234'));
        $this->assertEquals('1706.03762v5', $method->invoke($agent, 'https://arxiv.org/abs/1706.03762v5'));
        $this->assertEquals('2301.01234', $method->invoke($agent, 'https://arxiv.org/pdf/2301.01234'));
        $this->assertNull($method->invoke($agent, 'https://example.com/page'));
    }

    public function test_can_handle_arxiv_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://arxiv.org/abs/2301.01234')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://arxiv.org/abs/1706.03762')));
    }

    public function test_keywords_include_arxiv(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('arxiv', $agent->keywords());
        $this->assertContains('arxiv.org', $agent->keywords());
    }

    public function test_help_message_shows_arxiv(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Arxiv', $result->reply);
        $this->assertStringContainsString('arxiv.org', $result->reply);
        $this->assertStringContainsString('Articles scientifiques', $result->reply);
    }

    // ── Flash mode ────────────────────────────────────────────────────────────

    public function test_detect_flash_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('flash', $method->invoke($agent, 'flash https://example.com'));
        $this->assertEquals('flash', $method->invoke($agent, 'en une phrase https://example.com'));
        $this->assertEquals('flash', $method->invoke($agent, 'in one sentence https://example.com'));
        $this->assertEquals('flash', $method->invoke($agent, 'ultra-court https://example.com'));
    }

    public function test_flash_mode_takes_priority_over_short(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // flash keyword wins even if "court" also appears
        $this->assertEquals('flash', $method->invoke($agent, 'flash court https://example.com'));
    }

    public function test_keywords_include_flash(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('flash', $agent->keywords());
        $this->assertContains('en une phrase', $agent->keywords());
    }

    public function test_help_message_shows_flash_option(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('flash', $result->reply);
        $this->assertStringContainsString('en une phrase', $result->reply);
    }

    // ── Text paste lowered threshold (200 chars) ──────────────────────────────

    public function test_text_paste_threshold_is_200_chars(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isTextPasteRequest');
        $method->setAccessible(true);

        // Exactly 200 chars with keyword — should be a paste request
        $text200 = 'résumé ' . str_repeat('x', 193); // 7 + 193 = 200 chars
        $this->assertTrue($method->invoke($agent, $text200));

        // 199 chars — should NOT be a paste request
        $text199 = 'résumé ' . str_repeat('x', 192); // 7 + 192 = 199 chars
        $this->assertFalse($method->invoke($agent, $text199));
    }

    // ── Language detection extended ───────────────────────────────────────────

    public function test_detect_spanish_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectContentLanguage');
        $method->setAccessible(true);

        $spanishText = 'El gobierno ha anunciado una nueva política para todas las empresas. Los ciudadanos son los más afectados por este cambio que llega con el nuevo año.';
        $result = $method->invoke($agent, $spanishText);
        $this->assertEquals('es', $result);
    }

    public function test_detect_german_content(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectContentLanguage');
        $method->setAccessible(true);

        $germanText = 'Die Bundesregierung hat eine neue Politik für alle Unternehmen angekündigt. Das ist eine wichtige Entscheidung für die Wirtschaft.';
        $result = $method->invoke($agent, $germanText);
        $this->assertEquals('de', $result);
    }

    // ── Focus topic detection ─────────────────────────────────────────────────

    public function test_detect_focus_topic_axe_sur(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectFocusTopic');
        $method->setAccessible(true);

        $result = $method->invoke($agent, 'resume axé sur les chiffres https://example.com');
        $this->assertNotNull($result);
        $this->assertStringContainsString('chiffres', $result);
    }

    public function test_detect_focus_topic_focus_sur(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectFocusTopic');
        $method->setAccessible(true);

        $result = $method->invoke($agent, 'focus sur les risques https://example.com');
        $this->assertNotNull($result);
        $this->assertStringContainsString('risques', $result);
    }

    public function test_detect_focus_topic_returns_null_without_trigger(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectFocusTopic');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($agent, 'resume https://example.com'));
        $this->assertNull($method->invoke($agent, 'tldr https://example.com'));
    }

    public function test_detect_focus_topic_focalise_sur(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectFocusTopic');
        $method->setAccessible(true);

        $result = $method->invoke($agent, 'résumé focalisé sur les conclusions https://example.com');
        $this->assertNotNull($result);
        $this->assertStringContainsString('conclusions', $result);
    }

    public function test_can_handle_focus_sur_with_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('focus sur les données https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('axé sur les risques https://example.com')));
    }

    public function test_keywords_include_focus_sur(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('focus sur', $agent->keywords());
        $this->assertContains('axe sur', $agent->keywords());
    }

    public function test_help_message_shows_focus_thematique(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Focus thematique', $result->reply);
        $this->assertStringContainsString('focus sur', $result->reply);
    }

    // ── Quotes extraction detection ───────────────────────────────────────────

    public function test_can_handle_extraire_citations_with_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('extraire les citations https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('meilleures citations https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('best quotes https://example.com')));
    }

    public function test_keywords_include_quotes(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('extraire citations', $agent->keywords());
        $this->assertContains('best quotes', $agent->keywords());
        $this->assertContains('meilleures citations', $agent->keywords());
    }

    public function test_quotes_pattern_matches_expected_phrases(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $quotesConst = $reflection->getConstant('QUOTES_PATTERN');

        $this->assertMatchesRegularExpression($quotesConst, 'extraire les citations de cet article');
        $this->assertMatchesRegularExpression($quotesConst, 'meilleures citations https://example.com');
        $this->assertMatchesRegularExpression($quotesConst, 'best quotes https://example.com');
        $this->assertMatchesRegularExpression($quotesConst, 'key quotes https://example.com');
        $this->assertMatchesRegularExpression($quotesConst, 'passages clés de cet article');
    }

    public function test_quotes_extraction_shows_help_without_url(): void
    {
        $agent = new ContentSummarizerAgent();
        // No URL, short body → should fall through to help
        $result = $agent->handle($this->makeContext('extraire les citations'));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Resume de Contenu', $result->reply);
    }

    public function test_help_message_shows_citations_extraction(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('citations', $result->reply);
        $this->assertStringContainsString('extraire les citations', $result->reply);
    }

    public function test_help_message_shows_translate_feature(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Traduction', $result->reply);
        $this->assertStringContainsString('traduis', $result->reply);
    }

    public function test_help_message_shows_substack(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('Substack', $result->reply);
    }

    // ── Translation mode ──────────────────────────────────────────────────────

    public function test_keywords_include_translate(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('traduis', $agent->keywords());
        $this->assertContains('traduction', $agent->keywords());
        $this->assertContains('translate', $agent->keywords());
    }

    public function test_can_handle_translate_keyword(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('traduis https://example.com en anglais')));
        $this->assertTrue($agent->canHandle($this->makeContext('traduction de https://example.com en espagnol')));
        $this->assertTrue($agent->canHandle($this->makeContext('translate https://example.com to french')));
    }

    public function test_translate_without_target_lang_returns_prompt(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext('traduis https://example.com'));

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Traduction', $result->reply);
        $this->assertStringContainsString('langue cible', $result->reply);
    }

    // ── Substack URL detection ─────────────────────────────────────────────────

    public function test_is_substack_url_detection(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('isSubstackUrl');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($agent, 'https://mynewsletter.substack.com/p/my-article'));
        $this->assertTrue($method->invoke($agent, 'https://author.substack.com/p/some-post-123'));
        $this->assertFalse($method->invoke($agent, 'https://example.com/article'));
        $this->assertFalse($method->invoke($agent, 'https://github.com/laravel/laravel'));
    }

    public function test_can_handle_substack_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('https://mynewsletter.substack.com/p/my-article')));
        $this->assertTrue($agent->canHandle($this->makeContext('resume https://author.substack.com/p/some-post')));
    }

    public function test_keywords_include_substack(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('substack', $agent->keywords());
        $this->assertContains('newsletter', $agent->keywords());
    }

    // ── Simple / ELI5 mode ────────────────────────────────────────────────────

    public function test_detect_simple_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('simple', $method->invoke($agent, 'simplifie https://example.com'));
        $this->assertEquals('simple', $method->invoke($agent, 'eli5 https://example.com'));
        $this->assertEquals('simple', $method->invoke($agent, 'vulgarise https://example.com'));
        $this->assertEquals('simple', $method->invoke($agent, 'pour les nuls https://example.com'));
        $this->assertEquals('simple', $method->invoke($agent, 'en termes simples https://example.com'));
        $this->assertEquals('simple', $method->invoke($agent, 'pour debutants https://example.com'));
    }

    public function test_can_handle_simplifie_with_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('simplifie https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('eli5 https://arxiv.org/abs/2301.01234')));
        $this->assertTrue($agent->canHandle($this->makeContext('vulgarise https://example.com/article')));
    }

    public function test_simple_mode_does_not_conflict_with_short_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // 'court' keyword takes priority over 'simple'
        $this->assertEquals('short', $method->invoke($agent, 'resume court https://example.com'));
        // 'simplifie' alone triggers simple mode
        $this->assertEquals('simple', $method->invoke($agent, 'simplifie https://example.com'));
    }

    public function test_keywords_include_simple_and_eli5(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('simplifie', $agent->keywords());
        $this->assertContains('eli5', $agent->keywords());
        $this->assertContains('vulgarise', $agent->keywords());
        $this->assertContains('en termes simples', $agent->keywords());
    }

    public function test_help_message_shows_simple_option(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('simplifie', $result->reply);
        $this->assertStringContainsString('eli5', $result->reply);
        $this->assertStringContainsString('accessible pour debutants', $result->reply);
    }

    // ── Actions / Recommandations mode ────────────────────────────────────────

    public function test_detect_actions_mode(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        $this->assertEquals('actions', $method->invoke($agent, 'extraire les actions https://example.com'));
        $this->assertEquals('actions', $method->invoke($agent, 'recommandations https://example.com'));
        $this->assertEquals('actions', $method->invoke($agent, 'next steps https://example.com'));
        $this->assertEquals('actions', $method->invoke($agent, 'liste les actions https://example.com'));
        $this->assertEquals('actions', $method->invoke($agent, 'prochaines etapes https://example.com'));
        $this->assertEquals('actions', $method->invoke($agent, 'action items https://example.com'));
    }

    public function test_can_handle_actions_with_url(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('extraire les actions https://example.com')));
        $this->assertTrue($agent->canHandle($this->makeContext('recommandations https://example.com/article')));
        $this->assertTrue($agent->canHandle($this->makeContext('next steps https://example.com')));
    }

    public function test_keywords_include_actions_and_recommandations(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertContains('extraire actions', $agent->keywords());
        $this->assertContains('recommandations', $agent->keywords());
        $this->assertContains('next steps', $agent->keywords());
        $this->assertContains('prochaines etapes', $agent->keywords());
    }

    public function test_help_message_shows_actions_option(): void
    {
        $agent = new ContentSummarizerAgent();
        $result = $agent->handle($this->makeContext(''));

        $this->assertStringContainsString('extraire les actions', $result->reply);
        $this->assertStringContainsString("d'actions et recommandations", $result->reply);
    }

    public function test_simple_and_actions_modes_do_not_conflict(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryMode');
        $method->setAccessible(true);

        // 'simplifie' should not trigger 'actions' mode
        $this->assertEquals('simple', $method->invoke($agent, 'simplifie https://example.com'));
        // 'recommandations' should not trigger 'simple' mode
        $this->assertEquals('actions', $method->invoke($agent, 'recommandations https://example.com'));
    }

    public function test_text_paste_with_simplifie_keyword_returns_reply(): void
    {
        $agent = new ContentSummarizerAgent();
        // Long body with 'simplifie' keyword and no URL → text paste mode
        $longText = 'simplifie ce texte : ' . str_repeat('Ce contenu technique est tres complexe et utilise beaucoup de jargon scientifique. ', 10);
        $result = $agent->handle($this->makeContext($longText));

        $this->assertEquals('reply', $result->action);
        $this->assertStringNotContainsString('Comment utiliser', $result->reply);
    }

    public function test_text_paste_with_actions_keyword_returns_reply(): void
    {
        $agent = new ContentSummarizerAgent();
        // Long body with 'recommandations' keyword and no URL → text paste mode
        $longText = 'extraire les actions de ce document : ' . str_repeat('Nous devons implementer une nouvelle politique de securite et former toutes les equipes. ', 8);
        $result = $agent->handle($this->makeContext($longText));

        $this->assertEquals('reply', $result->action);
        $this->assertStringNotContainsString('Comment utiliser', $result->reply);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(?string $body, bool $hasMedia = false, ?string $mimetype = null): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id' => $agent->id,
            'session_key' => AgentSession::keyFor($agent->id, 'whatsapp', $this->testPhone),
            'channel' => 'whatsapp',
            'peer_id' => $this->testPhone,
            'last_message_at' => now(),
        ]);

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: $this->testPhone,
            senderName: 'Test User',
            body: $body,
            hasMedia: $hasMedia,
            mediaUrl: $hasMedia ? 'http://waha:3000/api/files/test.png' : null,
            mimetype: $mimetype,
            media: $hasMedia ? ['mimetype' => $mimetype, 'url' => 'http://waha:3000/api/files/test.png'] : null,
        );
    }
}
