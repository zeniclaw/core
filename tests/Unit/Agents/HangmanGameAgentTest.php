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

    public function test_agent_version_is_1_4_0(): void
    {
        $agent = new HangmanGameAgent();
        $this->assertEquals('1.4.0', $agent->version());
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

    public function test_reset_stats_clears_all_values(): void
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

        $result = $agent->handle($context);

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

        $this->assertStringContainsString('lettre(s) a trouver', $result->reply);
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
