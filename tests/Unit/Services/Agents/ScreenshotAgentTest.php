<?php

namespace Tests\Unit\Services\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\ScreenshotAgent;
use App\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenshotAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    // ── Agent basics ─────────────────────────────────────────────────────────

    public function test_screenshot_agent_returns_correct_name(): void
    {
        $agent = new ScreenshotAgent();
        $this->assertEquals('screenshot', $agent->name());
    }

    public function test_screenshot_agent_version_is_1_1_0(): void
    {
        $agent = new ScreenshotAgent();
        $this->assertEquals('1.1.0', $agent->version());
    }

    public function test_can_handle_screenshot_keywords(): void
    {
        $agent = new ScreenshotAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('screenshot')));
        $this->assertTrue($agent->canHandle($this->makeContext('extract-text')));
        $this->assertTrue($agent->canHandle($this->makeContext('ocr')));
        $this->assertTrue($agent->canHandle($this->makeContext('annotate this image')));
        $this->assertTrue($agent->canHandle($this->makeContext('capture my screen')));
        $this->assertTrue($agent->canHandle($this->makeContext('compare images')));
    }

    public function test_cannot_handle_empty_body_no_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('');
        $this->assertFalse($agent->canHandle($context));
    }

    public function test_cannot_handle_unrelated_messages(): void
    {
        $agent = new ScreenshotAgent();
        $this->assertFalse($agent->canHandle($this->makeContext('bonjour')));
        $this->assertFalse($agent->canHandle($this->makeContext('rappelle-moi demain')));
        $this->assertFalse($agent->canHandle($this->makeContext('code review')));
    }

    public function test_can_handle_image_with_text_extract_intent(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('extraire texte', true, 'image/png');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_handle_shows_help_on_empty_input(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Screenshot & Annotate', $result->reply);
        $this->assertStringContainsString('extract-text', $result->reply);
        $this->assertStringContainsString('annotate', $result->reply);
    }

    public function test_extract_text_requires_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('extract-text');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('image', $result->reply);
    }

    public function test_extract_text_rejects_non_image_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('extract-text', true, 'audio/ogg');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('pas une image', $result->reply);
    }

    public function test_annotate_requires_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('annotate arrow red');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('image', $result->reply);
    }

    public function test_compare_without_media_shows_instructions(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('compare images');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Comparaison', $result->reply);
    }

    public function test_compare_with_image_sets_pending_context(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('compare', true, 'image/png');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Etape 1/2', $result->reply);
        $this->assertStringContainsString('premiere image', mb_strtolower($result->reply));

        // Verify pending context was set
        $context->session->refresh();
        $pending = $context->session->pending_agent_context;
        $this->assertNotNull($pending);
        $this->assertEquals('screenshot', $pending['agent']);
        $this->assertEquals('compare_step2', $pending['type']);
        $this->assertArrayHasKey('image1_url', $pending['data']);
    }

    public function test_compare_step2_without_media_clears_context_and_cancels(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('compare');

        // Manually set pending context
        $context->session->update([
            'pending_agent_context' => [
                'agent'      => 'screenshot',
                'type'       => 'compare_step2',
                'data'       => ['image1_url' => 'http://waha:3000/api/files/img1.png'],
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ]);

        $result = $agent->handlePendingContext($context, [
            'type' => 'compare_step2',
            'data' => ['image1_url' => 'http://waha:3000/api/files/img1.png'],
        ]);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('annulee', mb_strtolower($result->reply));
    }

    public function test_capture_shows_capabilities(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('capture my bug');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString("Capture d'ecran", $result->reply);
    }

    // ── Analyze (Claude Vision) ───────────────────────────────────────────────

    public function test_analyze_requires_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('analyse');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('image', mb_strtolower($result->reply));
    }

    public function test_analyze_rejects_non_image_media(): void
    {
        $agent = new ScreenshotAgent();
        $context = $this->makeContext('analyse', true, 'audio/ogg');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('pas une image', $result->reply);
    }

    public function test_can_handle_analyze_keywords(): void
    {
        $agent = new ScreenshotAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('analyse cette image', true, 'image/png')));
        $this->assertTrue($agent->canHandle($this->makeContext('describe ce que tu vois', true, 'image/png')));
    }

    // ── ImageProcessor ───────────────────────────────────────────────────────

    public function test_image_processor_get_info_on_missing_file(): void
    {
        $processor = new ImageProcessor();
        $info = $processor->getImageInfo('/nonexistent/file.png');

        $this->assertArrayHasKey('error', $info);
    }

    public function test_image_processor_extract_text_on_missing_file(): void
    {
        $processor = new ImageProcessor();
        $result = $processor->extractText('/nonexistent/file.png');

        $this->assertNull($result);
    }

    public function test_image_processor_compare_missing_files(): void
    {
        $processor = new ImageProcessor();
        $result = $processor->compareImages('/nonexistent/a.png', '/nonexistent/b.png');

        $this->assertEquals(0.0, $result['similarity']);
        $this->assertStringContainsString('introuvables', $result['description']);
    }

    public function test_image_processor_annotate_missing_file(): void
    {
        $processor = new ImageProcessor();
        $result = $processor->addAnnotations('/nonexistent/file.png', []);

        $this->assertNull($result);
    }

    public function test_image_processor_get_info_on_real_image(): void
    {
        $processor = new ImageProcessor();

        // Create a small test image
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_img_');
        $img = imagecreatetruecolor(100, 50);
        imagepng($img, $tmpFile);
        imagedestroy($img);

        $info = $processor->getImageInfo($tmpFile);

        $this->assertEquals(100, $info['width']);
        $this->assertEquals(50, $info['height']);
        $this->assertStringContainsString('image/png', $info['mime']);
        $this->assertGreaterThan(0, $info['size_bytes']);

        @unlink($tmpFile);
    }

    public function test_image_processor_annotate_real_image(): void
    {
        $processor = new ImageProcessor();

        // Create a small test image
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
        $img = imagecreatetruecolor(200, 200);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        imagepng($img, $tmpFile);
        imagedestroy($img);

        $annotations = [
            ['type' => 'rectangle', 'color' => 'red', 'x1' => 10, 'y1' => 10, 'x2' => 190, 'y2' => 190],
            ['type' => 'arrow', 'color' => 'blue', 'x1' => 50, 'y1' => 50, 'x2' => 150, 'y2' => 150],
            ['type' => 'circle', 'color' => 'green', 'cx' => 100, 'cy' => 100, 'radius' => 40],
            ['type' => 'text', 'color' => 'black', 'x' => 10, 'y' => 10, 'text' => 'Test'],
        ];

        $outputPath = $processor->addAnnotations($tmpFile, $annotations);

        $this->assertNotNull($outputPath);
        $this->assertFileExists($outputPath);

        $outputInfo = getimagesize($outputPath);
        $this->assertEquals(200, $outputInfo[0]);
        $this->assertEquals(200, $outputInfo[1]);

        @unlink($tmpFile);
        @unlink($outputPath);
    }

    public function test_image_processor_compare_identical_images(): void
    {
        $processor = new ImageProcessor();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
        $img = imagecreatetruecolor(50, 50);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagepng($img, $tmpFile);
        imagedestroy($img);

        $result = $processor->compareImages($tmpFile, $tmpFile);

        $this->assertEquals(100.0, $result['similarity']);
        $this->assertNotNull($result['diff_image']);

        @unlink($tmpFile);
        if ($result['diff_image']) @unlink($result['diff_image']);
    }

    public function test_image_processor_compare_different_images(): void
    {
        $processor = new ImageProcessor();

        $tmpFile1 = tempnam(sys_get_temp_dir(), 'test_img1_') . '.png';
        $img1 = imagecreatetruecolor(50, 50);
        $red = imagecolorallocate($img1, 255, 0, 0);
        imagefill($img1, 0, 0, $red);
        imagepng($img1, $tmpFile1);
        imagedestroy($img1);

        $tmpFile2 = tempnam(sys_get_temp_dir(), 'test_img2_') . '.png';
        $img2 = imagecreatetruecolor(50, 50);
        $blue = imagecolorallocate($img2, 0, 0, 255);
        imagefill($img2, 0, 0, $blue);
        imagepng($img2, $tmpFile2);
        imagedestroy($img2);

        $result = $processor->compareImages($tmpFile1, $tmpFile2);

        $this->assertLessThan(100.0, $result['similarity']);
        $this->assertNotNull($result['diff_image']);

        @unlink($tmpFile1);
        @unlink($tmpFile2);
        if ($result['diff_image']) @unlink($result['diff_image']);
    }

    // ── Controller & Router integration ──────────────────────────────────────

    public function test_agent_controller_includes_screenshot_in_sub_agents(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\AgentController::class);
        $constant = $reflection->getReflectionConstant('SUB_AGENTS');
        $subAgents = $constant->getValue();

        $this->assertArrayHasKey('screenshot', $subAgents);
        $this->assertEquals('Screenshot & Annotate', $subAgents['screenshot']['label']);
        $this->assertEquals('📸', $subAgents['screenshot']['icon']);
        $this->assertEquals('cyan', $subAgents['screenshot']['color']);
    }

    public function test_screenshot_agent_can_handle_all_trigger_keywords(): void
    {
        $agent = new ScreenshotAgent();

        // Explicit commands (no media needed)
        $this->assertTrue($agent->canHandle($this->makeContext('screenshot')));
        $this->assertTrue($agent->canHandle($this->makeContext('extract-text')));
        $this->assertTrue($agent->canHandle($this->makeContext('ocr')));
        $this->assertTrue($agent->canHandle($this->makeContext('annotate')));

        // Analyse/describe keywords (require media+image to trigger)
        $this->assertTrue($agent->canHandle($this->makeContext('analyse', true, 'image/png')));
        $this->assertTrue($agent->canHandle($this->makeContext('describe', true, 'image/png')));
        $this->assertTrue($agent->canHandle($this->makeContext('extraire texte', true, 'image/png')));
        $this->assertTrue($agent->canHandle($this->makeContext('capture ecran')));

        // Should NOT handle unrelated messages
        $this->assertFalse($agent->canHandle($this->makeContext('bonjour')));
        $this->assertFalse($agent->canHandle($this->makeContext('rappelle-moi')));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(string $body, bool $hasMedia = false, ?string $mimetype = null): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id'        => $agent->id,
            'session_key'     => $this->testPhone . '_' . uniqid(),
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
