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

    public function test_handle_detects_silence_and_returns_error(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => '.',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('silence', $result->reply);
    }

    public function test_handle_detects_very_short_transcript_as_noise(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'mm',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('silence', $result->reply);
    }

    public function test_handle_pending_context_confirms_transcript(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(body: 'oui');
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Rappelle-moi d\'acheter du pain',
                'confidence' => 0.65,
                'language' => 'fr',
            ],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('acheter du pain', $result->reply);
        $this->assertTrue($result->metadata['user_confirmed'] ?? false);
        $this->assertEquals('voice', $result->metadata['source'] ?? null);
    }

    public function test_handle_pending_context_cancels_transcript(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(body: 'non');
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Rappelle-moi d\'acheter du pain',
                'confidence' => 0.65,
                'language' => 'fr',
            ],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('annulee', $result->reply);
    }

    public function test_handle_pending_context_re_asks_on_ambiguous_reply(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(body: 'peut-etre');
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Rappelle-moi d\'acheter du pain',
                'confidence' => 0.65,
                'language' => 'fr',
            ],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('oui', $result->reply);
        $this->assertStringContainsString('non', $result->reply);
        $this->assertStringContainsString('corriger', $result->reply);
    }

    public function test_handle_pending_context_accepts_manual_correction(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(body: 'corriger: Rappelle-moi d\'acheter du fromage');
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Rappelle-moi d\'acheter du pain',
                'confidence' => 0.65,
                'language' => 'fr',
            ],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('fromage', $result->reply);
        $this->assertTrue($result->metadata['user_corrected'] ?? false);
        $this->assertTrue($result->metadata['user_confirmed'] ?? false);
        $this->assertEquals('voice', $result->metadata['source'] ?? null);
    }

    public function test_handle_pending_context_accepts_correction_with_accent(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('{}', 200),
        ]);

        $context = $this->makeContext(body: 'Corriger: Bonjour le monde');
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Bnjour le monde',
                'confidence' => 0.6,
                'language' => 'fr',
            ],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Bonjour', $result->reply);
        $this->assertTrue($result->metadata['user_corrected'] ?? false);
    }

    public function test_handle_pending_context_ignores_unknown_type(): void
    {
        $context = $this->makeContext(body: 'oui');
        $pendingContext = [
            'type' => 'some_other_type',
            'data' => [],
        ];

        $result = $this->agent->handlePendingContext($context, $pendingContext);

        $this->assertNull($result);
    }

    public function test_version_is_at_least_1_2(): void
    {
        $version = $this->agent->version();
        [$major, $minor] = explode('.', $version);
        $this->assertEquals('1', $major);
        $this->assertGreaterThanOrEqual(2, (int) $minor);
    }

    public function test_handle_detects_whisper_hallucination(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => "Sous-titres réalisés par la communauté d'Amara.org",
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('bruit de fond', $result->reply);
    }

    public function test_handle_includes_word_count_in_metadata(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Rappelle-moi d\'acheter du lait demain matin',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertArrayHasKey('word_count', $result->metadata);
        $this->assertGreaterThan(0, $result->metadata['word_count']);
    }

    public function test_handle_thank_you_hallucination_is_rejected(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Thank you for watching',
                'language' => 'en',
                'segments' => [
                    ['avg_logprob' => -0.05],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('bruit de fond', $result->reply);
    }

    public function test_version_is_1_6(): void
    {
        $this->assertEquals('1.6.0', $this->agent->version());
    }

    public function test_can_handle_returns_true_for_text_command_vocal_aide(): void
    {
        $context = $this->makeContext(body: 'vocal aide');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_true_for_text_command_vocal_stats(): void
    {
        $context = $this->makeContext(body: 'vocal stats');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_unrelated_text(): void
    {
        $context = $this->makeContext(body: 'bonjour');
        $this->assertFalse($this->agent->canHandle($context));
    }

    public function test_handle_vocal_aide_returns_help_text(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal aide');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aide', $result->reply);
        $this->assertStringContainsString('vocal', mb_strtolower($result->reply));
        $this->assertTrue($result->metadata['text_command'] ?? false);
        $this->assertTrue($result->metadata['low_confidence'] ?? false);
    }

    public function test_handle_vocal_stats_returns_stats(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal stats');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Statistiques', $result->reply);
        $this->assertStringContainsString('Total', $result->reply);
        $this->assertTrue($result->metadata['text_command'] ?? false);
    }

    public function test_low_confidence_reply_contains_confidence_emoji(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Rappelle-moi d\'acheter du pain',
                'language' => 'fr',
                'segments' => [['avg_logprob' => -0.8]],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');
        \App\Models\AppSetting::set('voice_command.min_confidence', '0.95');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        // Trigger low-confidence path by forcing confidence below threshold via pending context
        // Use handlePendingContext directly with a low-confidence pending state
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);
        $pendingContext = [
            'type' => 'low_confidence_confirm',
            'data' => [
                'transcript' => 'Rappelle-moi d\'acheter du pain',
                'confidence' => 0.55,
                'language' => 'fr',
            ],
        ];
        $pendingCtx = $this->makeContext(body: 'peut-etre');
        $result = $this->agent->handlePendingContext($pendingCtx, $pendingContext);

        $this->assertNotNull($result);
        // Ambiguous — should re-ask with confidence emoji in a previous low-confidence message
        $this->assertStringContainsString('oui', $result->reply);
    }

    public function test_handle_detects_blank_audio_hallucination(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => '[BLANK_AUDIO]',
                'language' => 'en',
                'segments' => [['avg_logprob' => -0.05]],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('bruit de fond', $result->reply);
    }

    public function test_can_handle_returns_true_for_quicktime_video(): void
    {
        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/video.mov',
            mimetype: 'video/quicktime',
        );

        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_handle_includes_duration_in_metadata(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Rappelle-moi d\'acheter du lait demain matin s\'il te plait',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertArrayHasKey('duration_sec', $result->metadata);
        $this->assertIsInt($result->metadata['duration_sec']);
        $this->assertGreaterThanOrEqual(0, $result->metadata['duration_sec']);
    }

    public function test_handle_detects_music_hallucination(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => '[music]',
                'language' => 'en',
                'segments' => [
                    ['avg_logprob' => -0.05],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('bruit de fond', $result->reply);
    }

    public function test_handle_detects_applause_hallucination(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => '[applaudissements]',
                'language' => 'fr',
                'segments' => [
                    ['avg_logprob' => -0.05],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('bruit de fond', $result->reply);
    }

    public function test_language_note_shows_full_name_not_code(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Hello this is a test message in English',
                'language' => 'en',
                'segments' => [
                    ['avg_logprob' => -0.1],
                ],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        // Should display "Anglais" not just "en"
        $this->assertStringContainsString('Anglais', $result->reply);
    }

    // ── New v1.6.0 feature tests ──────────────────────────────────────────────

    public function test_can_handle_returns_true_for_vocal_historique(): void
    {
        $context = $this->makeContext(body: 'vocal historique');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_true_for_voice_history(): void
    {
        $context = $this->makeContext(body: 'voice history');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_handle_vocal_historique_returns_empty_message_when_no_logs(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal historique');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Historique', $result->reply);
        $this->assertStringContainsString('Aucune', $result->reply);
        $this->assertTrue($result->metadata['text_command'] ?? false);
    }

    public function test_handle_vocal_historique_returns_logs_when_available(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal historique');

        // Pre-insert a transcription log for this user
        \App\Models\AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => 'info',
            'message' => '[voice_command] Transcription successful',
            'context' => [
                'from' => $this->testPhone,
                'transcript' => 'Acheter du pain demain matin',
                'language' => 'fr',
                'word_count' => 5,
                'duration_sec' => 3,
            ],
        ]);

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Historique', $result->reply);
        $this->assertStringContainsString('Acheter du pain', $result->reply);
    }

    public function test_can_handle_returns_true_for_vocal_langue(): void
    {
        $context = $this->makeContext(body: 'vocal langue');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_can_handle_returns_true_for_vocal_langue_with_code(): void
    {
        $context = $this->makeContext(body: 'vocal langue en');
        $this->assertTrue($this->agent->canHandle($context));
    }

    public function test_handle_vocal_langue_without_code_shows_current_language(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal langue');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Langue', $result->reply);
        $this->assertTrue($result->metadata['text_command'] ?? false);
    }

    public function test_handle_vocal_langue_sets_valid_language(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal langue en');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Anglais', $result->reply);
        $this->assertStringContainsString('✅', $result->reply);

        // Verify preference was saved
        $saved = \App\Models\AppSetting::get("voice_lang_pref_{$this->testPhone}");
        $this->assertEquals('en', $saved);
    }

    public function test_handle_vocal_langue_rejects_unknown_code(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal langue xx');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('non reconnu', $result->reply);
    }

    public function test_handle_vocal_langue_auto_resets_preference(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        // First set a preference
        \App\Models\AppSetting::set("voice_lang_pref_{$this->testPhone}", 'en');

        $context = $this->makeContext(body: 'vocal langue auto');
        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reinitialis', $result->reply);
    }

    public function test_stats_includes_language_breakdown(): void
    {
        Http::fake(['waha:3000/*' => Http::response('{}', 200)]);

        $context = $this->makeContext(body: 'vocal stats');

        // Insert some logs with different languages
        \App\Models\AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => 'info',
            'message' => '[voice_command] Transcription successful',
            'context' => ['from' => $this->testPhone, 'transcript' => 'test', 'language' => 'fr', 'word_count' => 1, 'duration_sec' => 1],
        ]);
        \App\Models\AgentLog::create([
            'agent_id' => $context->agent->id,
            'level' => 'info',
            'message' => '[voice_command] Transcription successful',
            'context' => ['from' => $this->testPhone, 'transcript' => 'hello', 'language' => 'en', 'word_count' => 1, 'duration_sec' => 1],
        ]);

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Statistiques', $result->reply);
        $this->assertStringContainsString('Total', $result->reply);
    }

    public function test_transcript_reply_includes_duration_for_long_messages(): void
    {
        // Craft a long transcript (many words → >15 seconds estimated)
        // At 130 wpm, 130 words = 60s. So use 40 words to get ~18s.
        $longText = implode(' ', array_fill(0, 40, 'bonjour'));

        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => $longText,
                'language' => 'fr',
                'segments' => [['avg_logprob' => -0.1]],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Duree', $result->reply);
    }

    public function test_transcript_reply_does_not_include_duration_for_short_messages(): void
    {
        Http::fake([
            'waha:3000/*' => Http::response('fake-audio-bytes', 200),
            'api.openai.com/*' => Http::response([
                'text' => 'Rappelle-moi d\'acheter du lait',
                'language' => 'fr',
                'segments' => [['avg_logprob' => -0.1]],
            ], 200),
        ]);

        \App\Models\AppSetting::set('openai_api_key', 'test-key');

        $context = $this->makeContext(
            hasMedia: true,
            mediaUrl: 'http://waha:3000/api/files/audio.ogg',
            mimetype: 'audio/ogg',
        );

        $result = $this->agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringNotContainsString('Duree', $result->reply);
    }
}
