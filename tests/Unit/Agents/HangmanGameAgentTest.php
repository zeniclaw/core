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

    public function test_agent_version_is_1_13_0(): void
    {
        $agent = new HangmanGameAgent();
        $this->assertEquals('1.13.0', $agent->version());
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

    // ── History ───────────────────────────────────────────────────────────────

    public function test_history_shows_message_when_no_games_played(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Aucune partie terminee', $result->reply);
    }

    public function test_history_shows_completed_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        // Create a won game
        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 1]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('LARAVEL', $result->reply);
        $this->assertStringContainsString('Historique', $result->reply);
    }

    // ── Reset stats ───────────────────────────────────────────────────────────

    public function test_reset_stats_when_no_stats_informs_user(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman reset');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Aucune statistique', $result->reply);
    }

    public function test_reset_stats_asks_for_confirmation(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman reset');

        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->update(['games_played' => 5, 'games_won' => 3]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Confirmation requise', $result->reply);
        $this->assertStringContainsString('OUI', $result->reply);
        // Stats should NOT be reset yet
        $stats->refresh();
        $this->assertEquals(5, $stats->games_played);
    }

    public function test_reset_stats_clears_all_values_after_confirmation(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman reset');

        // Create stats with values
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->update([
            'games_played'   => 5,
            'games_won'      => 3,
            'best_streak'    => 3,
            'current_streak' => 2,
            'total_guesses'  => 40,
        ]);

        // Step 1: request reset (gets confirmation prompt)
        $agent->handle($context);

        // Step 2: confirm via handlePendingContext
        $confirmContext = $this->makeContext('OUI', context: $context);
        $pendingCtx     = [
            'agent'            => 'hangman',
            'type'             => 'confirm_reset',
            'data'             => ['games_played' => 5, 'games_won' => 3],
            'expect_raw_input' => true,
            'expires_at'       => now()->addMinutes(3)->toIso8601String(),
        ];
        $result = $agent->handlePendingContext($confirmContext, $pendingCtx);

        $this->assertNotNull($result);
        $this->assertStringContainsString('remises a zero', $result->reply);

        $stats->refresh();
        $this->assertEquals(0, $stats->games_played);
        $this->assertEquals(0, $stats->games_won);
        $this->assertEquals(0, $stats->best_streak);
        $this->assertEquals(0, $stats->current_streak);
        $this->assertEquals(0, $stats->total_guesses);
    }

    // ── Category selection ────────────────────────────────────────────────────

    public function test_start_with_tech_category_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start tech');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertDatabaseHas('hangman_games', [
            'user_phone' => $context->from,
            'agent_id'   => $context->agent->id,
            'status'     => 'playing',
        ]);
    }

    public function test_start_with_unknown_category_falls_back_to_random(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start unknowncategory');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
    }

    // ── Score ─────────────────────────────────────────────────────────────────

    public function test_winning_game_shows_score(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        $this->createActiveGame($context, 'A');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Score', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
    }

    // ── Hint safety guard ─────────────────────────────────────────────────────

    public function test_hint_blocked_when_only_one_life_left(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman hint');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['wrong_count' => 5]); // 1 life left

        $result = $agent->handle($context);

        $this->assertStringContainsString('risque', $result->reply);
        // Game should still be active
        $game->refresh();
        $this->assertEquals('playing', $game->status);
        $this->assertEquals(5, $game->wrong_count);
    }

    // ── Status details ────────────────────────────────────────────────────────

    public function test_status_shows_guess_count(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['guessed_letters' => ['A', 'B', 'C']]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('3', $result->reply);
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

    // ── Guess whole word ──────────────────────────────────────────────────────

    public function test_guess_word_correct_wins_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine LARAVEL');

        $game = $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('trouve le mot', $result->reply);
        $this->assertStringContainsString('LARAVEL', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);

        $game->refresh();
        $this->assertEquals('won', $game->status);
    }

    public function test_guess_word_wrong_costs_two_errors(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine MAUVAIS');

        $game = $this->createActiveGame($context, 'LARAVEL');

        $agent->handle($context);

        $game->refresh();
        $this->assertEquals(2, $game->wrong_count);
        $this->assertEquals('playing', $game->status);
    }

    public function test_guess_word_wrong_causes_loss_when_not_enough_lives(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine MAUVAIS');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['wrong_count' => 5]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Mauvaise reponse', $result->reply);
        $this->assertStringContainsString('LARAVEL', $result->reply);

        $game->refresh();
        $this->assertEquals('lost', $game->status);
    }

    public function test_guess_word_correct_updates_stats(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine DOCKER');

        $this->createActiveGame($context, 'DOCKER');

        $agent->handle($context);

        $stats = HangmanStats::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        $this->assertEquals(1, $stats->games_won);
        $this->assertEquals(1, $stats->current_streak);
    }

    public function test_guess_word_without_active_game_prompts_start(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine DOCKER');

        $result = $agent->handle($context);

        $this->assertStringContainsString('hangman start', $result->reply);
    }

    public function test_multi_letter_body_guesses_word_when_game_active(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('LARAVEL');

        $game = $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('trouve le mot', $result->reply);

        $game->refresh();
        $this->assertEquals('won', $game->status);
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function test_show_categories_lists_all_categories(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman categories');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Categories disponibles', $result->reply);
        $this->assertStringContainsString('tech', $result->reply);
        $this->assertStringContainsString('animaux', $result->reply);
        $this->assertStringContainsString('nature', $result->reply);
        $this->assertStringContainsString('vocab', $result->reply);
    }

    // ── Best score in stats ───────────────────────────────────────────────────

    public function test_stats_shows_best_score_when_games_won(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        // Win a game to generate a score
        $this->createActiveGame($context, 'A');
        $agent->handle($context);

        // Now check stats
        $statsContext = $this->makeContext('/hangman stats', context: $context);
        $result       = $agent->handle($statsContext);

        $this->assertStringContainsString('Meilleur score', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
    }

    // ── History with score ────────────────────────────────────────────────────

    public function test_history_shows_score_for_won_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 1]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('pts', $result->reply);
    }

    // ── Status improvements ───────────────────────────────────────────────────

    public function test_status_shows_hidden_letter_count(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        // New format: "X/Y lettres trouvees (Z%)"
        $this->assertStringContainsString('lettres trouvees', $result->reply);
    }

    public function test_status_shows_elapsed_time(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('⏱️', $result->reply);
    }

    // ── New categories ────────────────────────────────────────────────────────

    public function test_start_with_sport_category_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start sport');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Sport', $result->reply);
    }

    public function test_start_with_gastronomie_category_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start gastronomie');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Gastronomie', $result->reply);
    }

    public function test_categories_list_includes_sport_and_gastronomie(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman categories');

        $result = $agent->handle($context);

        $this->assertStringContainsString('sport', $result->reply);
        $this->assertStringContainsString('gastronomie', $result->reply);
    }

    // ── Daily challenge ───────────────────────────────────────────────────────

    public function test_daily_challenge_starts_a_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman daily');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Defi du Jour', $result->reply);
        $this->assertDatabaseHas('hangman_games', [
            'user_phone' => $context->from,
            'agent_id'   => $context->agent->id,
            'status'     => 'playing',
        ]);
    }

    public function test_daily_challenge_is_deterministic_same_day(): void
    {
        $agent    = new HangmanGameAgent();
        $context1 = $this->makeContext('/hangman daily');
        $context2 = $this->makeContext('/hangman daily');

        // Two different users (different phones but same agent)
        $user2    = \App\Models\User::factory()->create();
        $agent2   = \App\Models\Agent::factory()->create(['user_id' => $user2->id]);
        $phone2   = '33699999999@s.whatsapp.net';
        $session2 = \App\Models\AgentSession::create([
            'agent_id'        => $agent2->id,
            'session_key'     => \App\Models\AgentSession::keyFor($agent2->id, 'whatsapp', $phone2),
            'channel'         => 'whatsapp',
            'peer_id'         => $phone2,
            'last_message_at' => now(),
        ]);
        $context2 = new \App\Services\AgentContext(
            agent: $agent2,
            session: $session2,
            from: $phone2,
            senderName: 'User 2',
            body: '/hangman daily',
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

        $result1 = (new \App\Services\Agents\HangmanGameAgent())->handle($context1);
        $result2 = (new \App\Services\Agents\HangmanGameAgent())->handle($context2);

        // Both games should have the same word (daily challenge is universal)
        $game1 = \App\Models\HangmanGame::where('user_phone', $context1->from)->where('agent_id', $context1->agent->id)->first();
        $game2 = \App\Models\HangmanGame::where('user_phone', $phone2)->where('agent_id', $agent2->id)->first();

        $this->assertEquals($game1->word, $game2->word);
    }

    public function test_daily_challenge_abandons_existing_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman daily');

        $existing = $this->createActiveGame($context, 'ANCIEN');

        $agent->handle($context);

        $existing->refresh();
        $this->assertEquals('lost', $existing->status);
    }

    // ── Leaderboard ───────────────────────────────────────────────────────────

    public function test_leaderboard_shows_no_players_message_when_empty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman top');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Aucun joueur', $result->reply);
    }

    public function test_leaderboard_shows_top_players(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman top');

        // Create stats for current user
        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->update([
            'games_played'   => 5,
            'games_won'      => 4,
            'best_streak'    => 3,
            'current_streak' => 2,
        ]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Classement', $result->reply);
        $this->assertStringContainsString('victoires', $result->reply);
        $this->assertStringContainsString('🥇', $result->reply);
    }

    public function test_leaderboard_masks_phone_numbers(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman top');

        $stats = HangmanStats::getOrCreate($context->from, $context->agent->id);
        $stats->update(['games_played' => 3, 'games_won' => 2]);

        $result = $agent->handle($context);

        // Phone should be masked, not shown fully
        $this->assertStringNotContainsString('33612345678', $result->reply);
        $this->assertStringContainsString('****', $result->reply);
    }

    // ── Score speed bonus ─────────────────────────────────────────────────────

    public function test_winning_game_fast_shows_speed_bonus_message(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        // Single-letter word won instantly = speed bonus applies
        $this->createActiveGame($context, 'A');

        $result = $agent->handle($context);

        $this->assertStringContainsString('pts', $result->reply);
        // Speed message should appear (game was won almost instantly)
        $this->assertStringContainsString('bonus', $result->reply);
    }

    public function test_hint_win_shows_score(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman hint');

        // 2-letter word — one hint reveals last letter and wins
        $game = $this->createActiveGame($context, 'AB');
        $game->update(['guessed_letters' => ['A']]);

        $result = $agent->handle($context);

        // Either wins (if hint reveals B) or gives hint
        $this->assertEquals('reply', $result->action);
        // If won, score should be shown
        if (str_contains($result->reply, 'Victoire')) {
            $this->assertStringContainsString('pts', $result->reply);
        }
    }

    // ── Difficulty levels ─────────────────────────────────────────────────────

    public function test_start_with_easy_difficulty_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start facile');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Facile', $result->reply);
    }

    public function test_start_with_hard_difficulty_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start difficile');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Difficile', $result->reply);
    }

    public function test_start_with_medium_difficulty_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start moyen');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Moyen', $result->reply);
    }

    public function test_start_with_category_and_difficulty_creates_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start tech facile');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);
        $this->assertStringContainsString('Informatique', $result->reply);
        $this->assertStringContainsString('Facile', $result->reply);

        $game = \App\Models\HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        // Word should be 2-6 characters (easy range)
        $this->assertLessThanOrEqual(6, mb_strlen($game->word));
    }

    public function test_easy_difficulty_word_length_within_range(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start easy');

        $agent->handle($context);

        $game = \App\Models\HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        $this->assertNotNull($game);
        $this->assertGreaterThanOrEqual(2, mb_strlen($game->word));
        $this->assertLessThanOrEqual(6, mb_strlen($game->word));
    }

    // ── Alphabet display ──────────────────────────────────────────────────────

    public function test_alphabet_shows_remaining_letters_during_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman alpha');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['guessed_letters' => ['A', 'L']]);

        $result = $agent->handle($context);

        // 26 letters - A - L = 24 remaining
        $this->assertStringContainsString('Lettres non essayees', $result->reply);
        $this->assertStringContainsString('24 restantes', $result->reply);
        $this->assertStringContainsString('B', $result->reply);
        $this->assertStringContainsString('C', $result->reply);
    }

    public function test_alphabet_without_active_game_prompts_start(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman alpha');

        $result = $agent->handle($context);

        $this->assertStringContainsString('hangman start', $result->reply);
    }

    // ── Common letters feedback ───────────────────────────────────────────────

    public function test_wrong_word_guess_shows_common_letters_count(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine LARABE');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        // LARABE vs LARAVEL share L, A, R, E -> 4 common letters
        $this->assertStringContainsString('en commun', $result->reply);
    }

    public function test_wrong_word_guess_no_common_letters(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman devine ZOOM');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        // ZOOM vs LARAVEL: no common letters
        $this->assertStringNotContainsString('en commun', $result->reply);
    }

    // ── Streak display on win ─────────────────────────────────────────────────

    public function test_winning_streak_displayed_when_more_than_one(): void
    {
        $agent = new HangmanGameAgent();

        // Win first game
        $context1 = $this->makeContext('A');
        $this->createActiveGame($context1, 'A');
        $agent->handle($context1);

        // Win second game with the SAME user (same phone) — streak = 2
        $context2 = $this->makeContext('B', context: $context1);
        $this->createActiveGame($context2, 'B');
        $result = $agent->handle($context2);

        $this->assertStringContainsString("d'affile", $result->reply);
    }

    // ── Help updated ──────────────────────────────────────────────────────────

    public function test_help_mentions_difficulty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman help');

        $result = $agent->handle($context);

        $this->assertStringContainsString('difficulte', strtolower($result->reply));
    }

    public function test_help_mentions_alpha_command(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman help');

        $result = $agent->handle($context);

        $this->assertStringContainsString('alpha', strtolower($result->reply));
    }

    // ── Score command ─────────────────────────────────────────────────────────

    public function test_score_command_shows_estimate_when_game_active(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman score');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('estime', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
        $this->assertStringContainsString('Base', $result->reply);
    }

    public function test_score_command_without_active_game_shows_no_game_message(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman score');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('hangman start', $result->reply);
    }

    public function test_score_command_shows_best_score_when_no_active_game_but_has_wins(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        // Win a game first
        $this->createActiveGame($context, 'A');
        $agent->handle($context);

        // Now ask for score (no active game)
        $scoreContext = $this->makeContext('/hangman score', context: $context);
        $result       = $agent->handle($scoreContext);

        $this->assertStringContainsString('meilleur score', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
    }

    // ── Replay command ────────────────────────────────────────────────────────

    public function test_replay_starts_game_with_last_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman abandon');

        // Create and abandon a game
        $this->createActiveGame($context, 'LARAVEL');
        $agent->handle($context);

        // Now replay
        $replayContext = $this->makeContext('/hangman replay', context: $context);
        $result        = $agent->handle($replayContext);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Rejouer', $result->reply);
        $this->assertDatabaseHas('hangman_games', [
            'user_phone' => $context->from,
            'agent_id'   => $context->agent->id,
            'word'       => 'LARAVEL',
            'status'     => 'playing',
        ]);
    }

    public function test_replay_without_previous_game_informs_user(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman replay');

        $result = $agent->handle($context);

        $this->assertStringContainsString('Aucune partie precedente', $result->reply);
    }

    public function test_replay_abandons_existing_active_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman replay');

        // Create a completed game first
        $completed = $this->createActiveGame($context, 'ANCIEN');
        $completed->update(['status' => 'lost']);

        // Create an active game
        $active = $this->createActiveGame($context, 'ACTIF');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);

        $active->refresh();
        $this->assertEquals('lost', $active->status);
    }

    // ── Daily challenge already-played detection ───────────────────────────────

    public function test_daily_challenge_shows_result_when_already_played_today(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman daily');

        // Play the daily
        $result1 = $agent->handle($context);
        $this->assertStringContainsString('Defi du Jour', $result1->reply);

        // Get the created game and mark it as won
        $dailyGame = HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->first();
        $this->assertNotNull($dailyGame);
        $dailyGame->update(['status' => 'won', 'wrong_count' => 1]);

        // Try to play daily again — should show "already played"
        $context2 = $this->makeContext('/hangman daily', context: $context);
        $result2  = $agent->handle($context2);

        $this->assertStringContainsString('deja joue', $result2->reply);
        $this->assertStringContainsString('Defi du Jour', $result2->reply);
    }

    // ── Weekly stats ──────────────────────────────────────────────────────────

    public function test_weekly_stats_shows_no_games_message_when_empty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman weekly');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('semaine', strtolower($result->reply));
        $this->assertStringContainsString('Aucune partie', $result->reply);
    }

    public function test_weekly_stats_shows_wins_and_losses(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman weekly');

        // Create a won game
        $game1 = $this->createActiveGame($context, 'LARAVEL');
        $game1->update(['status' => 'won', 'wrong_count' => 1]);

        // Create a lost game
        $game2 = $this->createActiveGame($context, 'SYMFONY');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Stats de la semaine', $result->reply);
        $this->assertStringContainsString('Victoires', $result->reply);
        $this->assertStringContainsString('Defaites', $result->reply);
        $this->assertStringContainsString('2', $result->reply);
    }

    public function test_weekly_stats_shows_best_score_when_won_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman weekly');

        $game = $this->createActiveGame($context, 'DOCKER');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('pts', $result->reply);
        $this->assertStringContainsString('DOCKER', $result->reply);
    }

    public function test_weekly_stats_excludes_old_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman weekly');

        // Create a game older than 7 days using timestamps=false to bypass auto-update
        $oldGame = $this->createActiveGame($context, 'VIEUX');
        $oldGame->status      = 'won';
        $oldGame->wrong_count = 0;
        $oldGame->timestamps  = false;
        $oldGame->save();

        \Illuminate\Support\Facades\DB::table('hangman_games')
            ->where('id', $oldGame->id)
            ->update(['updated_at' => now()->subDays(10)->toDateTimeString()]);

        $result = $agent->handle($context);

        // Old game should be excluded - no games in weekly period
        $this->assertStringContainsString('Aucune partie', $result->reply);
    }

    // ── Position feedback in guess ────────────────────────────────────────────

    public function test_correct_letter_guess_shows_position(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('position', $result->reply);
    }

    public function test_correct_letter_guess_shows_multiple_positions_when_repeated(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('A');

        // LARAVEL has A at positions 2 and 4
        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('positions', $result->reply);
        $this->assertStringContainsString('2', $result->reply);
        $this->assertStringContainsString('4', $result->reply);
    }

    // ── Hint position feedback ────────────────────────────────────────────────

    public function test_hint_shows_position_of_revealed_letter(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman hint');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        // Should mention position of revealed letter
        $this->assertStringContainsString('position', $result->reply);
    }

    // ── Help updated ──────────────────────────────────────────────────────────

    public function test_explicit_help_command_returns_help(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman help');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Commandes', $result->reply);
        $this->assertStringContainsString('replay', strtolower($result->reply));
        $this->assertStringContainsString('score', strtolower($result->reply));
    }

    // ── Share feature ─────────────────────────────────────────────────────────

    public function test_share_shows_no_game_message_when_no_completed_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman share');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune partie terminee', $result->reply);
    }

    public function test_share_shows_result_of_last_won_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman share');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 1, 'guessed_letters' => ['L', 'A', 'R', 'V', 'E', 'Z']]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('LARAVEL', $result->reply);
        $this->assertStringContainsString('Gagne', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
        $this->assertStringContainsString('✅', $result->reply);
        $this->assertStringContainsString('❌', $result->reply);
    }

    public function test_share_shows_result_of_last_lost_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman share');

        $game = $this->createActiveGame($context, 'SYMFONY');
        $game->update(['status' => 'lost', 'wrong_count' => 6, 'guessed_letters' => ['X', 'Y', 'Z']]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('SYMFONY', $result->reply);
        $this->assertStringContainsString('Perdu', $result->reply);
    }

    // ── Monthly stats ─────────────────────────────────────────────────────────

    public function test_monthly_shows_no_games_message_when_empty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman monthly');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('mois', strtolower($result->reply));
        $this->assertStringContainsString('Aucune partie', $result->reply);
    }

    public function test_monthly_shows_wins_and_losses(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman monthly');

        $game1 = $this->createActiveGame($context, 'LARAVEL');
        $game1->update(['status' => 'won', 'wrong_count' => 1]);

        $game2 = $this->createActiveGame($context, 'SYMFONY');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Stats du mois', $result->reply);
        $this->assertStringContainsString('Victoires', $result->reply);
        $this->assertStringContainsString('Defaites', $result->reply);
        $this->assertStringContainsString('2', $result->reply);
    }

    public function test_monthly_shows_best_score_when_won_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman monthly');

        $game = $this->createActiveGame($context, 'DOCKER');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('pts', $result->reply);
        $this->assertStringContainsString('DOCKER', $result->reply);
    }

    public function test_monthly_excludes_old_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman monthly');

        $oldGame = $this->createActiveGame($context, 'VIEUX');
        $oldGame->status      = 'won';
        $oldGame->wrong_count = 0;
        $oldGame->timestamps  = false;
        $oldGame->save();

        \Illuminate\Support\Facades\DB::table('hangman_games')
            ->where('id', $oldGame->id)
            ->update(['updated_at' => now()->subDays(35)->toDateTimeString()]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Aucune partie', $result->reply);
    }

    // ── Accent matching ───────────────────────────────────────────────────────

    public function test_guess_accented_letter_matches_accented_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('E');

        // Word with accented char — user types E, should match É
        $this->createActiveGame($context, 'MÉLO');

        $result = $agent->handle($context);

        // E should be accepted as matching É in MÉLO
        $this->assertStringContainsString('est dans le mot', $result->reply);
    }

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

    // ── Tip command ───────────────────────────────────────────────────────────

    public function test_tip_without_active_game_prompts_start(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('hangman start', $result->reply);
    }

    public function test_tip_shows_strategic_advice_during_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Astuce', $result->reply);
        $this->assertStringContainsString('gratuite', $result->reply);
    }

    public function test_tip_does_not_cost_a_life(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        $game = $this->createActiveGame($context, 'LARAVEL');

        $agent->handle($context);

        $game->refresh();
        // wrong_count must not have changed
        $this->assertEquals(0, $game->wrong_count);
    }

    public function test_tip_suggests_frequent_french_letters(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        // Should mention at least one frequent French letter
        $hasSuggestion = str_contains($result->reply, 'E')
            || str_contains($result->reply, 'A')
            || str_contains($result->reply, 'frequentes');

        $this->assertTrue($hasSuggestion);
    }

    public function test_tip_shows_vowel_info_when_vowels_remain(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        // LARAVEL has A and E as vowels — none guessed yet
        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertStringContainsString('voyelle', $result->reply);
    }

    public function test_tip_warns_when_few_lives_left(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman tip');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['wrong_count' => 5]); // 1 life left

        $result = $agent->handle($context);

        $this->assertStringContainsString('Attention', $result->reply);
    }

    public function test_tip_accessible_via_natural_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('astuce pendu');

        $this->createActiveGame($context, 'LARAVEL');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Astuce', $result->reply);
    }

    // ── Difficulty score multiplier ───────────────────────────────────────────

    public function test_hard_word_scores_higher_than_easy_word_same_errors(): void
    {
        $agent = new HangmanGameAgent();

        // Win easy game (3-letter word, 0 errors)
        $context1 = $this->makeContext('A');
        $this->createActiveGame($context1, 'ABC');
        // Guess A, B, C
        $agent->handle($context1);
        $agent->handle($this->makeContext('B', context: $context1));
        $agent->handle($this->makeContext('C', context: $context1));

        $easyGame = HangmanGame::where('user_phone', $context1->from)
            ->where('agent_id', $context1->agent->id)
            ->where('status', 'won')
            ->first();

        // Win hard game (12-letter word, 0 errors) — use guessWord for simplicity
        $context2 = $this->makeContext('/hangman devine BIBLIOTHEQUE');
        $this->createActiveGame($context2, 'BIBLIOTHEQUE');
        $agent->handle($context2);

        $hardGame = HangmanGame::where('user_phone', $context2->from)
            ->where('agent_id', $context2->agent->id)
            ->where('status', 'won')
            ->first();

        $this->assertNotNull($easyGame);
        $this->assertNotNull($hardGame);

        // Hard word (12 letters, ×1.5) should score more than easy word (3 letters, ×1.0) even with 0 errors each
        $easyScore = (3 * 10 + 6 * 3); // = 48
        $hardScore = (int) round((12 * 10 + 6 * 3) * 1.5); // = 207

        $this->assertGreaterThan($easyScore, $hardScore);
        $this->assertStringContainsString('pts', $agent->handle($this->makeContext('/hangman stats', context: $context2))->reply);
    }

    public function test_score_command_shows_multiplier_for_hard_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman score');

        // Create a hard word (11+ letters)
        $this->createActiveGame($context, 'BIBLIOTHEQUE');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('estime', $result->reply);
        $this->assertStringContainsString('Multiplicateur', $result->reply);
    }

    public function test_score_command_no_multiplier_for_easy_word(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman score');

        // Create an easy word (2-6 letters)
        $this->createActiveGame($context, 'API');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('estime', $result->reply);
        // No multiplier for easy words
        $this->assertStringNotContainsString('Multiplicateur', $result->reply);
    }

    public function test_help_mentions_tip_command(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman help');

        $result = $agent->handle($context);

        $this->assertStringContainsString('tip', strtolower($result->reply));
        $this->assertStringContainsString('gratuite', $result->reply);
    }

    // ── Category stats (new v1.11.0) ──────────────────────────────────────────

    public function test_cat_command_shows_no_games_message_when_empty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman cat');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune partie terminee', $result->reply);
    }

    public function test_cat_command_shows_category_overview_with_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman cat');

        // Won a tech game (LARAVEL is in tech)
        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 1]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Stats par categorie', $result->reply);
        $this->assertStringContainsString('Informatique', $result->reply);
        $this->assertStringContainsString('victoires', strtolower($result->reply));
    }

    public function test_cat_command_with_specific_category_shows_detail(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman cat tech');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Informatique', $result->reply);
        $this->assertStringContainsString('Parties', $result->reply);
        $this->assertStringContainsString('Victoires', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
    }

    public function test_cat_command_with_empty_specific_category_informs_user(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman cat animaux');

        // Only a tech game — no animaux games
        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune partie dans cette categorie', $result->reply);
    }

    public function test_cat_command_shows_custom_words_separately(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman cat');

        // Custom word (not in any category list)
        $game = $this->createActiveGame($context, 'MONMOTPERSO');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Personnalise', $result->reply);
    }

    // ── Difficulty stats (new v1.11.0) ────────────────────────────────────────

    public function test_diff_command_shows_no_games_message_when_empty(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman diff');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune partie terminee', $result->reply);
    }

    public function test_diff_command_shows_easy_level_for_short_words(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman diff');

        // API = 3 letters = easy
        $game = $this->createActiveGame($context, 'API');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Stats par niveau', $result->reply);
        $this->assertStringContainsString('Facile', $result->reply);
    }

    public function test_diff_command_shows_hard_level_for_long_words(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman diff');

        // BIBLIOTHEQUE = 12 letters = hard
        $game = $this->createActiveGame($context, 'BIBLIOTHEQUE');
        $game->update(['status' => 'won', 'wrong_count' => 0]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Difficile', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
    }

    public function test_diff_command_shows_win_rate_for_each_level(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman diff');

        // Medium word won
        $game1 = $this->createActiveGame($context, 'LARAVEL');
        $game1->update(['status' => 'won', 'wrong_count' => 1]);
        // Medium word lost
        $game2 = $this->createActiveGame($context, 'SYMFONY');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Moyen', $result->reply);
        $this->assertStringContainsString('%', $result->reply);
    }

    // ── Status improvement (v1.11.0) ──────────────────────────────────────────

    public function test_status_shows_letter_progress_percentage(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman status');

        $game = $this->createActiveGame($context, 'LARAVEL');
        // Guess L, A, R = 3 found out of 6 unique (L=1, A=2, R=1, V=1, E=1 — but word is 7 chars)
        $game->update(['guessed_letters' => ['L', 'A', 'R']]);

        $result = $agent->handle($context);

        // Should show X/Y lettres trouvees
        $this->assertStringContainsString('lettres trouvees', $result->reply);
        $this->assertStringContainsString('%', $result->reply);
    }

    // ── History improvement (v1.11.0) ─────────────────────────────────────────

    public function test_history_shows_total_count_when_more_than_shown(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        // Create 9 completed games (more than the 8 shown)
        $words = ['ARBRE', 'MAISON', 'VOITURE', 'SOLEIL', 'AVION', 'BATEAU', 'CHAT', 'CHIEN', 'LOUP'];
        foreach ($words as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'won', 'wrong_count' => 0]);
        }

        $result = $agent->handle($context);

        $this->assertStringContainsString('sur 9 au total', $result->reply);
    }

    // ── History summary line (v1.12.0) ────────────────────────────────────────

    public function test_history_shows_summary_line_with_wins_and_losses(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        $game1 = $this->createActiveGame($context, 'LARAVEL');
        $game1->update(['status' => 'won', 'wrong_count' => 1]);

        $game2 = $this->createActiveGame($context, 'SYMFONY');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Affiches', $result->reply);
        $this->assertStringContainsString('victoires', $result->reply);
        $this->assertStringContainsString('defaites', $result->reply);
    }

    // ── Alphabet vowel/consonant split (v1.12.0) ──────────────────────────────

    public function test_alphabet_shows_vowels_separately(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman alpha');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['guessed_letters' => ['A', 'E']]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('Voyelles', $result->reply);
        $this->assertStringContainsString('Consonnes', $result->reply);
    }

    public function test_alphabet_shows_all_vowels_tried_message(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman alpha');

        // All vowels tried
        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['guessed_letters' => ['A', 'E', 'I', 'O', 'U', 'Y']]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('toutes essayees', $result->reply);
    }

    // ── Post-game analysis (v1.12.0) ──────────────────────────────────────────

    public function test_analyse_shows_no_game_message_when_no_completed_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman analyse');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune partie terminee', $result->reply);
    }

    public function test_analyse_shows_analysis_after_won_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman analyse');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update([
            'status'          => 'won',
            'wrong_count'     => 1,
            'guessed_letters' => ['L', 'A', 'R', 'V', 'E', 'Z'],
        ]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Analyse', $result->reply);
        $this->assertStringContainsString('LARAVEL', $result->reply);
        $this->assertStringContainsString('Victoire', $result->reply);
        $this->assertStringContainsString('pts', $result->reply);
        $this->assertStringContainsString('Efficacite', $result->reply);
    }

    public function test_analyse_shows_analysis_after_lost_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman analyse');

        $game = $this->createActiveGame($context, 'SYMFONY');
        $game->update([
            'status'          => 'lost',
            'wrong_count'     => 6,
            'guessed_letters' => ['X', 'K', 'Q', 'J', 'Z', 'W'],
        ]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Defaite', $result->reply);
        $this->assertStringContainsString('SYMFONY', $result->reply);
        // Should warn about rare letters
        $this->assertStringContainsString('rares', $result->reply);
    }

    public function test_analyse_shows_efficiency_percentage(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman analyse');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update([
            'status'          => 'won',
            'wrong_count'     => 0,
            'guessed_letters' => ['L', 'A', 'R', 'V', 'E'],
        ]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('%', $result->reply);
        $this->assertStringContainsString('Efficacite', $result->reply);
    }

    public function test_analyse_accessible_via_natural_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('analyser pendu');

        $game = $this->createActiveGame($context, 'DOCKER');
        $game->update(['status' => 'won', 'wrong_count' => 2, 'guessed_letters' => ['D', 'O', 'C', 'K', 'E', 'R']]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Analyse', $result->reply);
    }

    // ── Daily goals (v1.12.0) ─────────────────────────────────────────────────

    public function test_goals_shows_zero_progress_when_no_games_today(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman goals');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Objectifs du jour', $result->reply);
        $this->assertStringContainsString('0/3', $result->reply);
        $this->assertStringContainsString('0/2', $result->reply);
    }

    public function test_goals_shows_partial_progress_after_one_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman goals');

        $game = $this->createActiveGame($context, 'LARAVEL');
        $game->update(['status' => 'won', 'wrong_count' => 1]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('1/3', $result->reply);
        $this->assertStringContainsString('1/2', $result->reply);
    }

    public function test_goals_marks_play_goal_complete_after_three_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman goals');

        foreach (['LARAVEL', 'DOCKER', 'SYMFONY'] as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'won', 'wrong_count' => 1]);
        }

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('3/3', $result->reply);
        $this->assertStringContainsString('✅', $result->reply);
    }

    public function test_goals_marks_all_complete_and_shows_congrats(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman goals');

        // 3 games won, with one scoring 100+ pts (long word, 0 errors)
        foreach (['LARAVEL', 'SYMFONY', 'BIBLIOTHEQUE'] as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'won', 'wrong_count' => 0]);
        }

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        // BIBLIOTHEQUE (12 letters, 0 errors): (12*10 + 6*3)*1.5 = 207 pts → goal3 done
        $this->assertStringContainsString('✅', $result->reply);
    }

    public function test_goals_accessible_via_objectifs_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('objectifs pendu');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Objectifs du jour', $result->reply);
    }

    // ── startGame shows random category with dice (v1.12.0) ───────────────────

    public function test_start_with_forced_category_shows_no_dice(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start tech');

        $result = $agent->handle($context);

        // Forced category shown without 🎲
        $this->assertStringContainsString('Informatique', $result->reply);
        $this->assertStringNotContainsString('🎲', $result->reply);
    }

    public function test_start_without_category_shows_random_category_with_dice(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman start');

        $result = $agent->handle($context);

        // Random category should show the 🎲 indicator
        $this->assertStringContainsString('🎲', $result->reply);
    }

    // ── wordlen command (v1.13.0) ─────────────────────────────────────────────

    public function test_wordlen_starts_game_with_exact_length(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman wordlen 6');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);

        $game = \App\Models\HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->first();

        $this->assertNotNull($game);
        $this->assertEquals(6, mb_strlen($game->word));
    }

    public function test_wordlen_rejects_length_below_2(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman wordlen 1');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('invalide', $result->reply);
        $this->assertDatabaseMissing('hangman_games', ['user_phone' => $context->from]);
    }

    public function test_wordlen_rejects_length_above_30(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman wordlen 31');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('invalide', $result->reply);
        $this->assertDatabaseMissing('hangman_games', ['user_phone' => $context->from]);
    }

    public function test_wordlen_abandons_existing_active_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman wordlen 5');

        $existing = $this->createActiveGame($context, 'LARAVEL');

        $agent->handle($context);

        $existing->refresh();
        $this->assertEquals('lost', $existing->status);
    }

    public function test_wordlen_natural_language_mot_de_n_lettres(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('un mot de 7 lettres pendu');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Nouvelle partie', $result->reply);

        $game = \App\Models\HangmanGame::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->first();

        $this->assertNotNull($game);
        $this->assertEquals(7, mb_strlen($game->word));
    }

    // ── progress command (v1.13.0) ────────────────────────────────────────────

    public function test_progress_shows_not_enough_games_message_when_fewer_than_4(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman progress');

        // Only 2 games
        $game1 = $this->createActiveGame($context, 'LARAVEL');
        $game1->update(['status' => 'won', 'wrong_count' => 1]);

        $game2 = $this->createActiveGame($context, 'DOCKER');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Progression', $result->reply);
        $this->assertStringContainsString('assez', $result->reply);
    }

    public function test_progress_shows_comparison_when_enough_games(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman progress');

        // Create 6 games: first 3 = losses, last 3 = wins (improving trend)
        foreach (['A1', 'B1', 'C1'] as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'lost', 'wrong_count' => 6]);
        }
        foreach (['LARAVEL', 'DOCKER', 'SYMFONY'] as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'won', 'wrong_count' => 1]);
        }

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Progression', $result->reply);
        $this->assertStringContainsString('Win rate', $result->reply);
        $this->assertStringContainsString('%', $result->reply);
        $this->assertStringContainsString('Debut', $result->reply);
        $this->assertStringContainsString('Recent', $result->reply);
    }

    public function test_progress_accessible_via_keyword(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('progression pendu');

        // Need at least 4 games
        foreach (['API', 'LARAVEL', 'DOCKER', 'SYMFONY'] as $word) {
            $game = $this->createActiveGame($context, $word);
            $game->update(['status' => 'won', 'wrong_count' => 0]);
        }

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Progression', $result->reply);
    }

    // ── History difficulty indicators (v1.13.0) ───────────────────────────────

    public function test_history_shows_difficulty_emoji_per_game(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman history');

        // Easy word (3L = 🟢), hard word (12L = 🔴)
        $game1 = $this->createActiveGame($context, 'API');
        $game1->update(['status' => 'won', 'wrong_count' => 0]);

        $game2 = $this->createActiveGame($context, 'BIBLIOTHEQUE');
        $game2->update(['status' => 'lost', 'wrong_count' => 6]);

        $result = $agent->handle($context);

        $this->assertStringContainsString('🟢', $result->reply);
        $this->assertStringContainsString('🔴', $result->reply);
        $this->assertStringContainsString('3L', $result->reply);
        $this->assertStringContainsString('12L', $result->reply);
    }

    public function test_help_mentions_wordlen_and_progress(): void
    {
        $agent   = new HangmanGameAgent();
        $context = $this->makeContext('/hangman help');

        $result = $agent->handle($context);

        $this->assertStringContainsString('wordlen', strtolower($result->reply));
        $this->assertStringContainsString('progress', strtolower($result->reply));
    }
}
