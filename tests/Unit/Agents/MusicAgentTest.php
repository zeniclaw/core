<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Models\UserMusicPreference;
use App\Services\AgentContext;
use App\Services\Agents\MusicAgent;
use App\Services\Agents\RouterAgent;
use App\Services\SpotifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MusicAgentTest extends TestCase
{
    use RefreshDatabase;

    // ── MusicAgent basics ────────────────────────────────────────────────────

    public function test_music_agent_name_is_music(): void
    {
        $agent = new MusicAgent();
        $this->assertEquals('music', $agent->name());
    }

    public function test_music_agent_can_handle_when_routed(): void
    {
        $agent = new MusicAgent();
        $context = $this->makeContext('cherche du Daft Punk', routedAgent: 'music');

        $this->assertTrue($agent->canHandle($context));
    }

    public function test_music_agent_cannot_handle_when_not_routed(): void
    {
        $agent = new MusicAgent();
        $context = $this->makeContext('salut', routedAgent: 'chat');

        $this->assertFalse($agent->canHandle($context));
    }

    // ── SpotifyService ───────────────────────────────────────────────────────

    public function test_spotify_mood_to_genres_returns_genres(): void
    {
        $spotify = new SpotifyService();

        $this->assertEquals(['pop', 'dance', 'happy'], $spotify->moodToGenres('happy'));
        $this->assertEquals(['chill', 'ambient', 'acoustic'], $spotify->moodToGenres('relax'));
        $this->assertEquals(['hip-hop', 'rap'], $spotify->moodToGenres('rap'));
        $this->assertEquals(['pop'], $spotify->moodToGenres('unknown_mood'));
    }

    public function test_spotify_mood_to_genres_is_case_insensitive(): void
    {
        $spotify = new SpotifyService();

        $this->assertEquals(['rock', 'alt-rock', 'hard-rock'], $spotify->moodToGenres('Rock'));
        $this->assertEquals(['jazz', 'blues'], $spotify->moodToGenres('JAZZ'));
    }

    public function test_spotify_mood_to_genres_french_moods(): void
    {
        $spotify = new SpotifyService();

        $this->assertEquals(['pop', 'dance', 'happy'], $spotify->moodToGenres('joyeux'));
        $this->assertEquals(['sad', 'acoustic', 'piano'], $spotify->moodToGenres('triste'));
        $this->assertEquals(['chill', 'ambient', 'acoustic'], $spotify->moodToGenres('detente'));
        $this->assertEquals(['study', 'ambient', 'classical'], $spotify->moodToGenres('concentration'));
        $this->assertEquals(['party', 'dance', 'edm'], $spotify->moodToGenres('fete'));
    }

    public function test_spotify_search_track_returns_null_without_credentials(): void
    {
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $spotify = new SpotifyService();
        // Clear cached token
        \Illuminate\Support\Facades\Cache::forget('spotify_access_token');

        $result = $spotify->searchTrack('test');
        $this->assertNull($result);
    }

    public function test_spotify_search_track_with_valid_token(): void
    {
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'name' => 'Get Lucky',
                            'artists' => [['name' => 'Daft Punk']],
                            'album' => ['name' => 'Random Access Memories'],
                            'external_urls' => ['spotify' => 'https://open.spotify.com/track/123'],
                            'duration_ms' => 248000,
                        ],
                    ],
                ],
            ]),
        ]);

        \Illuminate\Support\Facades\Cache::forget('spotify_access_token');

        config(['services.spotify.client_id' => 'test-id', 'services.spotify.client_secret' => 'test-secret']);
        $spotify = new SpotifyService();
        $result = $spotify->searchTrack('Daft Punk');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('tracks', $result);
        $this->assertCount(1, $result['tracks']['items']);
        $this->assertEquals('Get Lucky', $result['tracks']['items'][0]['name']);
    }

    // ── RouterAgent music detection ─────────────────────────────────────────

    public function test_router_detects_music_keywords(): void
    {
        $router = new RouterAgent();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('detectMusicKeywords');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($router, 'mets de la musique'));
        $this->assertTrue($method->invoke($router, 'cherche une chanson'));
        $this->assertTrue($method->invoke($router, 'playlist pour courir'));
        $this->assertTrue($method->invoke($router, 'top charts France'));
        $this->assertTrue($method->invoke($router, 'paroles de Bohemian Rhapsody'));
        $this->assertTrue($method->invoke($router, 'recommande musique chill'));
        $this->assertTrue($method->invoke($router, 'spotify'));
        $this->assertTrue($method->invoke($router, 'quel artiste écouter'));
    }

    public function test_router_does_not_detect_non_music(): void
    {
        $router = new RouterAgent();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('detectMusicKeywords');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($router, 'salut comment ça va'));
        $this->assertFalse($method->invoke($router, 'rappelle-moi demain'));
        $this->assertFalse($method->invoke($router, 'fix le bug sur la page login'));
    }

    // ── UserMusicPreference model ───────────────────────────────────────────

    public function test_user_music_preference_can_be_created(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $pref = UserMusicPreference::create([
            'agent_id' => $agent->id,
            'phone' => '33612345678@s.whatsapp.net',
            'favorite_genres' => ['rock', 'jazz'],
            'favorite_artists' => ['Daft Punk', 'Miles Davis'],
            'preferred_mood' => 'chill',
        ]);

        $this->assertDatabaseHas('user_music_preferences', [
            'agent_id' => $agent->id,
            'phone' => '33612345678@s.whatsapp.net',
            'preferred_mood' => 'chill',
        ]);

        $this->assertEquals(['rock', 'jazz'], $pref->favorite_genres);
        $this->assertEquals(['Daft Punk', 'Miles Davis'], $pref->favorite_artists);
    }

    public function test_user_music_preference_genres_cast_to_array(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $pref = UserMusicPreference::create([
            'agent_id' => $agent->id,
            'phone' => '33600000000@s.whatsapp.net',
            'favorite_genres' => ['pop', 'electro'],
            'favorite_artists' => [],
        ]);

        $pref->refresh();

        $this->assertIsArray($pref->favorite_genres);
        $this->assertContains('pop', $pref->favorite_genres);
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    private function makeContext(string $body, string $routedAgent = 'music'): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id' => $agent->id,
            'phone' => '33612345678@s.whatsapp.net',
        ]);

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: '33612345678@s.whatsapp.net',
            senderName: 'Test User',
            body: $body,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            routedAgent: $routedAgent,
            routedModel: 'claude-haiku-4-5-20251001',
            complexity: 'simple',
            reasoning: 'test',
            autonomy: 'auto',
        );
    }
}
