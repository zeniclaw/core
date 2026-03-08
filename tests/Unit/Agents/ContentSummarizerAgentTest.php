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

    public function test_agent_version_is_1_1_0(): void
    {
        $agent = new ContentSummarizerAgent();
        $this->assertEquals('1.1.0', $agent->version());
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

    // ── Summary length detection ──────────────────────────────────────────────

    public function test_detect_short_summary_keywords(): void
    {
        $agent = new ContentSummarizerAgent();
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('detectSummaryLength');
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
        $method = $reflection->getMethod('detectSummaryLength');
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
        $method = $reflection->getMethod('detectSummaryLength');
        $method->setAccessible(true);

        $this->assertEquals('medium', $method->invoke($agent, 'resume https://example.com'));
        $this->assertEquals('medium', $method->invoke($agent, 'summarize this'));
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
