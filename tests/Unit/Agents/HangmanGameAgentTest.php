<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\HangmanGame;
use App\Models\HangmanStats;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\HangmanGameAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HangmanGameAgentTest extends TestCase
{
    use RefreshDatabase;

    // ── Basics ────────────────────────────────────────────────────────────────

    public function test_agent_name_is_hangman(): void
    {
        $agent = new HangmanGameAgent();
        $this->assertEquals('hangman', $agent->name());
    }

    public function test_agent_version_is_1_1_0(): void
    {
        $agent = new HangmanGameAgent();
        $this->assertEquals('1.1.0', $agent->version());
    }

    public function test_can_handle_returns_true_for_hangman_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('hangman start');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_returns_true_for_pendu_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('jouer au pendu');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_unrelated_message(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('bonjour comment vas tu');
        $this->assertFalse($agent->canHandle($context));
    }

    // ── Start game ────────────────────────────────────────────────────────────

    public function test_start_game_creates_hangman_game_record(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);

        $this->assertDatabaseHas('hangman_games', [
            'user_phone' => $context->from,
            'agent_id'   => $context->agent->id,
            'status'     => 'playing',
        ]);
    }

    public function test_start_game_abandons_existing_active_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start');

        // Start first game
        $agent->handle($context);

        $firstGame = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        // Start second game — first should be abandoned
        $agent->handle($this->makeContext('/hangman start', context: $context));

        $firstGame->refresh();
        $this->assertEquals('lost', $firstGame->status);
    }

    public function test_start_game_with_custom_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman word SOLEIL');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertDatabaseHas('hangman_games', [
            'user_phone' => $context->from,
            'agent_id'   => $context->agent->id,
            'word'       => 'SOLEIL',
            'status'     => 'playing',
        ]);
    }

    public function test_start_game_rejects_too_short_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman word A');

        $result = $agent->handle($context);

        $this->assertStringContainsString('entre 2 et 30', $result->reply);
        $this->assertDatabaseMissing('hangman_games', ['user_phone' => $context->from]);
    }

    // ── Guess letter ──────────────────────────────────────────────────────────

    public function test_guess_letter_correct(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        $this->createActiveGame($context, 'ARBRE');

        $result = $agent->handle($context);

        $this->assertStringContainsString('A', $result->reply);
        $this->assertStringContainsString('est dans le mot', $result->reply);
    }

    public function test_guess_letter_wrong_increments_wrong_count(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('Z');

        $game = $this->createActiveGame($context, 'ARBRE');

        $agent->handle($context);

        $game->refresh();
        $this->assertEquals(1, $game->wrong_count);
    }

    public function test_guess_same_letter_twice_is_rejected(): void
    {
        $agent   = new HangmanGameAgent();

        $context = $this->makeContext('A');
        $this->createActiveGame($context, 'ARBRE');

        // First guess
        $agent->handle($context);
        // Second guess of same letter
        $result = $agent->handle($this->makeContext('A', context: $context));

        $this->assertStringContainsString('deja propose', $result->reply);
    }

    public function test_guess_without_active_game_prompts_start(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        $result = $agent->handle($context);

        $this->assertStringContainsString('hangman start', $result->reply);
    }

    public function test_winning_game_updates_stats(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        $this->createActiveGame($context, 'A');

        $agent->handle($context);

        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        $this->assertNotNull($stats);
        $this->assertEquals(1, $stats->games_played);
        $this->assertEquals(1, $stats->games_won);
        $this->assertEquals(1, $stats->current_streak);
    }

    public function test_losing_game_resets_streak(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('Z');

        $game = $this->createActiveGame($context, 'ARBRE');
        $game->update(['wrong_count' => 5]); // One away from losing

        $agent->handle($context); // Z is wrong -> 6 wrong = lost

        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        $this->assertEquals(0, $stats->current_streak);
        $this->assertEquals(1, $stats->games_played);
        $this->assertEquals(0, $stats->games_won);
    }

    // ── Hint ─────────────────────────────────────────────────────────────────

    public function test_hint_reveals_a_letter_and_costs_one_error(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman hint');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Indice', $result->reply);

        $game = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->first();

        $this->assertEquals(1, $game->wrong_count);
        $this->assertCount(1, $game->guessed_letters);
    }

    public function test_hint_without_active_game_prompts_start(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman hint');

        $result = $agent->handle($context);

        $this->assertStringContainsString('hangman start', $result->reply);
    }

    // ── Abandon ───────────────────────────────────────────────────────────────

    public function test_abandon_ends_active_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman abandon');

        $game = $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('abandonn', $result->reply);
        $this->assertStringContainsString('LARAVEL', $result->reply);

        $game->refresh();
        $this->assertEquals('lost', $game->status);
    }

    public function test_abandon_without_active_game_informs_user(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman abandon');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Pas de partie en cours', $result->reply);
    }

    public function test_abandon_counts_as_loss_in_stats(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman abandon');

        $this->createActiveGame($context, 'LARAVEL');
        $agent->handle($context);

        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        $this->assertEquals(1, $stats->games_played);
        $this->assertEquals(0, $stats->games_won);
        $this->assertEquals(0, $stats->current_streak);
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function test_status_shows_current_board(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Partie en cours', $result->reply);
        $this->assertStringContainsString('Erreurs', $result->reply);
    }

    public function test_status_without_active_game_informs_user(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Pas de partie en cours', $result->reply);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function test_stats_shows_zero_when_no_games_played(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman stats');

        $result = $agent->handle($context);

        $this->assertStringContainsString('stats Pendu', $result->reply);
        $this->assertStringContainsString('0', $result->reply);
    }

    // ── HangmanStats model ────────────────────────────────────────────────────

    public function test_hangman_stats_get_or_create(): void
    {
        $user  = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $stats = HangmanStats::getOrCreate('33600000000@s.whatsapp.net', $agent->id);
        $this->assertEquals(0, $stats->games_played);

        // Second call returns same record
        $stats2 = HangmanStats::getOrCreate('33600000000@s.whatsapp.net', $agent->id);
        $this->assertEquals($stats->id, $stats2->id);
    }

    public function test_hangman_stats_win_rate(): void
    {
        $user  = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $stats = HangmanStats::getOrCreate('33600000000@s.whatsapp.net', $agent->id);
        $stats->games_played = 4;
        $stats->games_won = 3;

        $this->assertEquals(75.0, $stats->getWinRate());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(string $body, ?AgentContext $context = null): AgentContext
    {
        if ($context) {
            return new AgentContext(
                agent: $context->agent,
                session: $context->session,
                from: $context->from,
                senderName: $context->senderName,
                body: $body,
                hasMedia: false,
                mediaUrl: null,
                mimetype: null,
                media: null,
                routedAgent: 'hangman',
                routedModel: 'claude-haiku-4-5-20251001',
                complexity: 'simple',
                reasoning: 'test',
                autonomy: 'auto',
            );
        }

        $user    = User::factory()->create();
        $agent   = Agent::factory()->create(['user_id' => $user->id]);
        $phone   = '33612345678@s.whatsapp.net';
        $session = AgentSession::create([
            'agent_id'        => $agent->id,
            'session_key'     => AgentSession::keyFor($agent->id, 'whatsapp', $phone),
            'channel'         => 'whatsapp',
            'peer_id'         => $phone,
            'last_message_at' => now(),
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
            routedAgent: 'hangman',
            routedModel: 'claude-haiku-4-5-20251001',
            complexity: 'simple',
            reasoning: 'test',
            autonomy: 'auto',
        );
    }

    private function createActiveGame(AgentContext $context, string $word): HangmanGame
    {
        return HangmanGame::create([
            'user_phone'      => $context->from,
            'agent_id'        => $context->agent->id,
            'word'            => $word,
            'guessed_letters' => [],
            'wrong_count'     => 0,
            'status'          => 'playing',
        ]);
    }
}
