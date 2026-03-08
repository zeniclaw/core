<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\RouterAgent;
use App\Services\Agents\VoiceCommandAgent;
use App\Services\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceCommandAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';
    private VoiceCommandAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new VoiceCommandAgent();
    }

    private function makeContext(
        ?string $body = null,
        bool $hasMedia = false,
        ?string $mediaUrl = null,
        ?string $mimetype = null,
    ): AgentContext {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $sessionKey = AgentSession::keyFor($agent->id, 'whatsapp', $this->testPhone);
        $session = AgentSession::create([
            'agent_id' => $agent->id,
            'session_key' => $sessionKey,
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
            mediaUrl: $mediaUrl,
            mimetype: $mimetype,
            media: $mediaUrl ? ['url' => $mediaUrl, 'mimetype' => $mimetype] : null,
        );
    }

    public function test_agent_name_returns_voice_command(): void
    {
        $this->assertEquals('voice_command', $this->agent->name());
    }

    public function test_can_handle_returns_true_for_audio_messages(): void
    {
        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg; codecs=opus',
        );

        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_text_messages(): void
    {
        $context = $this->makeContext(body: 'Hello world');
        $this->assertFalse($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_image_messages(): void
    {
        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/photo.jpg',
            mimetype: 'image/jpeg',
        );

        $this->assertFalse($this->agent->canHandle($context));
    }

    public function test_handle_returns_error_when_media_download_fails(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('', 500),
        ]);

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('telecharger', $result->reply);
    }

    public function test_handle_returns_error_when_transcription_fails(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response('', 500),
        ]);

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('transcrire', $result->reply);
    }

    public function test_handle_returns_transcript_on_success(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Rappelle-moi d\'acheter du lait demain',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        // Set the OpenAI API key
        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('acheter du lait', $result->reply);
        $this->assertEquals('voice', $result->metadata['source'] ?? null);
    }

    public function test_router_detects_audio_and_routes_to_voice_command(): void
    {
        Http::fake([
            '*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg; codecs=opus',
        );

        $router = new RouterAgent();
        $routing = $router->route($context);

        $this->assertEquals('voice_command', $routing['agent']);
        $this->assertStringContainsString('Audio', $routing['reasoning']);
    }

    public function test_various_audio_mimetypes_are_detected(): void
    {
        $mimetypes = [
            'audio/ogg',
            'audio/ogg; codecs=opus',
            'audio/mpeg',
            'audio/wav',
            'audio/mp4',
            'audio/webm',
            'audio/amr',
        ];

        foreach ($mimetypes as $mime) {
            $context = $this->makeContext(
                hasMedia: true,
                mediaUrl: 'http://waha:3000/api/files/audio.file',
                mimetype: $mime,
            );

            $this->assertTrue(
                $this->agent->canHandle($context),
                "Expected canHandle to be true for mimetype: {$mime}"
            );
        }
    }
}
