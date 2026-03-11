<?php

namespace App\Services\Agents;

use App\Models\GameAchievement;
use App\Models\UserGameProfile;
use App\Services\AgentContext;
use App\Services\GameEngine\GameFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GameMasterAgent extends BaseAgent
{
    /** Sprint mode: seconds allowed per question */
    private const SPRINT_TIME_LIMIT = 15;

    /**
     * Achievements defined here that extend GameAchievement::ACHIEVEMENTS.
     * These are agent-specific and don't require a model migration.
     */
    private const EXTRA_ACHIEVEMENTS = [
        'word_wizard' => ['label' => 'Word Wizard', 'emoji' => '📝', 'description' => 'Score parfait en mots melanges'],
        'sprint_ace'  => ['label' => 'Sprint Ace',  'emoji' => '🏃', 'description' => 'Sprint parfait sans aucun timeout'],
    ];

    public function name(): string
    {
        return 'game_master';
    }

    public function description(): string
    {
        return 'Jeux interactifs WhatsApp : trivia, enigmes, 20 questions, mots melanges avec scoring, achievements et classement';
    }

    public function keywords(): array
    {
        return [
            'jeu', 'game', 'jouer', 'play', 'trivia', 'enigme', 'devinette',
            'riddle', 'startgame', 'guess', 'leaderboard', 'score', 'achievement',
            'mot melange', 'anagramme', '20 questions', 'word challenge',
            'defi', 'challenge', 'daily', 'classement', 'encore', 'rejouer',
            'blitz', 'rapide', 'express', 'progression', 'aide jeu',
            'sprint', 'stats type', 'stats avancees', 'top jeux',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body  = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        if (empty($body)) {
            return $this->handleListGames($context);
        }

        $activeGame = $this->getActiveGame($context);

        // ── Global commands — always available ────────────────────────────────
        if (preg_match('/\b(leaderboard|classement|top\s*\d*)\b/iu', $lower)) {
            return $this->handleLeaderboard($context);
        }

        if (preg_match('/\b(mes\s*stats|my\s*stats|status|mon\s*profil|profile)\b/iu', $lower)) {
            return $this->handleStatus($context);
        }

        if (preg_match('/\b(achievements?|succes|trophees?|badges?)\b/iu', $lower)) {
            return $this->handleAchievements($context);
        }

        if (preg_match('/\b(stats\s*(par\s*)?type|stats\s*avancees|top\s*jeux|stats\s*jeux|types?\s*de\s*jeux)\b/iu', $lower)) {
            return $this->handleGameStats($context);
        }

        // ── Commands that require an active game ──────────────────────────────
        if ($activeGame) {
            if (preg_match('/\b(stop|quit|abandon|arr[eê]t|annul)\b/iu', $lower)) {
                return $this->handleAbandon($context, $activeGame);
            }

            if (preg_match('/\b(progression|ma\s*progression|score\s*actuel|ou\s*j\'?en\s*suis|combien\s*de\s*points?)\b/iu', $lower)) {
                return $this->handleCurrentProgress($context, $activeGame);
            }

            if (preg_match('/\b(regles?|comment\s*jouer|aide\s*jeu|comment\s*[cç]a\s*marche)\b/iu', $lower)) {
                return $this->handleGameHelp($context, $activeGame);
            }

            return $this->handleAnswer($context, $activeGame);
        }

        // ── Commands when no active game ──────────────────────────────────────
        if (preg_match('/\b(defi\s*du\s*jour|defi\s*journalier|daily\s*challenge|defi)\b/iu', $lower)) {
            return $this->handleDailyChallenge($context);
        }

        if (preg_match('/\b(encore|rejouer|replay|revanche)\b/iu', $lower)) {
            return $this->handleReplay($context);
        }

        if (preg_match('/\b(blitz|speed\s*trivia)\b/iu', $lower)) {
            return $this->handleBlitz($context);
        }

        if (preg_match('/\b(sprint|mode\s*sprint|sprint\s*trivia)\b/iu', $lower)) {
            return $this->handleSprint($context);
        }

        if (preg_match('/\b(jeux|games|liste|list|help|aide)\b/iu', $lower)) {
            return $this->handleListGames($context);
        }

        return $this->handleStartGame($context, $body);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if ($pendingContext['type'] === 'game_answer') {
            $activeGame = $this->getActiveGame($context);
            if ($activeGame) {
                return $this->handleAnswer($context, $activeGame);
            }
            $this->clearPendingContext($context);
        }

        return null;
    }

    // ─── Game State Helpers ───────────────────────────────────────────────────

    private function getActiveGame(AgentContext $context): ?array
    {
        $key = "game:{$context->from}:{$context->agent->id}";
        return Cache::get($key);
    }

    private function setActiveGame(AgentContext $context, array $gameState): void
    {
        $key = "game:{$context->from}:{$context->agent->id}";
        Cache::put($key, $gameState, now()->addHours(2));
    }

    private function clearActiveGame(AgentContext $context): void
    {
        $key = "game:{$context->from}:{$context->agent->id}";
        Cache::forget($key);
    }

    /**
     * Persist last game type + difficulty so handleReplay can restore them.
     */
    private function setLastGameData(AgentContext $context, string $gameType, string $difficulty): void
    {
        $key = "last_game:{$context->from}:{$context->agent->id}";
        Cache::put($key, ['type' => $gameType, 'difficulty' => $difficulty], now()->addHours(24));
    }

    /**
     * Retrieve last game data with backward-compat for the old string-only format.
     */
    private function getLastGameData(AgentContext $context): array
    {
        $key  = "last_game:{$context->from}:{$context->agent->id}";
        $data = Cache::get($key);

        // Backward compat: was previously stored as just a game-type string
        if (is_string($data)) {
            return ['type' => $data, 'difficulty' => 'medium'];
        }

        return $data ?? ['type' => 'trivia', 'difficulty' => 'medium'];
    }

    /**
     * Increment games-played counter for a given game type (stored in cache).
     */
    private function trackGameTypeStat(AgentContext $context, string $gameType): void
    {
        $key   = "game_type_plays:{$context->from}:{$context->agent->id}";
        $stats = Cache::get($key, []);
        $stats[$gameType] = ($stats[$gameType] ?? 0) + 1;
        Cache::put($key, $stats, now()->addDays(30));
    }

    /**
     * Get per-game-type play stats from cache.
     */
    private function getGameTypeStats(AgentContext $context): array
    {
        $key = "game_type_plays:{$context->from}:{$context->agent->id}";
        return Cache::get($key, []);
    }

    /**
     * Merge base GameAchievement::ACHIEVEMENTS with agent-specific extras.
     */
    private function getAllAchievements(): array
    {
        return array_merge(GameAchievement::ACHIEVEMENTS, self::EXTRA_ACHIEVEMENTS);
    }

    // ─── Start / Launch ───────────────────────────────────────────────────────

    private function handleStartGame(AgentContext $context, string $body): AgentResult
    {
        $gameType   = null;
        $difficulty = $this->parseDifficulty($body);

        if (preg_match('/(?:jeu|game|jouer|play)\s+(?:a\s+|au\s+|aux?\s+)?(\w+)/iu', $body, $m)) {
            $gameType = GameFactory::resolveGameType($m[1]);
        }

        if (!$gameType) {
            foreach (['trivia', 'enigme', 'riddle', 'devinette', '20 questions', 'mot', 'mots', 'anagramme', 'word'] as $kw) {
                if (str_contains(mb_strtolower($body), $kw)) {
                    $gameType = GameFactory::resolveGameType($kw);
                    if ($gameType) break;
                }
            }
        }

        if (!$gameType) {
            $gameType = 'trivia';
        }

        return $this->launchGame($context, $gameType, $difficulty);
    }

    private function handleReplay(AgentContext $context): AgentResult
    {
        $lastGame = $this->getLastGameData($context);
        return $this->launchGame($context, $lastGame['type'], $lastGame['difficulty']);
    }

    /**
     * Mode Blitz : trivia express 3 questions (difficulty easy).
     */
    private function handleBlitz(AgentContext $context): AgentResult
    {
        $this->clearActiveGame($context);

        try {
            $game      = GameFactory::create('trivia', 'easy');
            $gameState = $game->initGame();
        } catch (\Exception $e) {
            Log::error('[GameMasterAgent] Blitz init error: ' . $e->getMessage());
            $reply = "⚠️ Impossible de lancer le mode Blitz. Reessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'error']);
        }

        $gameState['question_sent_at'] = now()->timestamp;
        $gameState['is_blitz']         = true;

        $this->setActiveGame($context, $gameState);

        $questionText = $game->formatQuestion($gameState);

        $reply  = "⚡ *Mode Blitz !*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "_3 questions faciles, reponds vite !_\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => 'trivia'], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Blitz game started');

        return AgentResult::reply($reply, ['action' => 'blitz_start']);
    }

    /**
     * Mode Sprint : 5 questions medium avec limite de temps de 15s par question.
     * Une reponse apres la limite = 0 pts pour cette question.
     */
    private function handleSprint(AgentContext $context): AgentResult
    {
        $this->clearActiveGame($context);

        try {
            $game      = GameFactory::create('trivia', 'medium');
            $gameState = $game->initGame();
        } catch (\Exception $e) {
            Log::error('[GameMasterAgent] Sprint init error: ' . $e->getMessage());
            $reply = "⚠️ Impossible de lancer le mode Sprint. Reessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'error']);
        }

        $gameState['question_sent_at'] = now()->timestamp;
        $gameState['is_sprint']        = true;
        $gameState['sprint_timeouts']  = 0;

        $this->setActiveGame($context, $gameState);

        $questionText = $game->formatQuestion($gameState);

        $reply  = "🏃 *Mode Sprint !*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "_5 questions — " . self::SPRINT_TIME_LIMIT . "s max par reponse !_\n";
        $reply .= "_Depasse le temps = 0 pts pour la question._\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => 'trivia'], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Sprint game started');

        return AgentResult::reply($reply, ['action' => 'sprint_start']);
    }

    private function handleDailyChallenge(AgentContext $context): AgentResult
    {
        $today        = now()->format('Y-m-d');
        $dailyKey     = "daily_challenge:{$context->agent->id}:{$today}";
        $userDailyKey = "daily_played:{$context->from}:{$context->agent->id}:{$today}";

        // Check if user already played today
        if (Cache::has($userDailyKey)) {
            $score     = Cache::get($userDailyKey);
            $hoursLeft = now()->diffInHours(now()->endOfDay());

            $reply  = "📅 *Defi du Jour*\n";
            $reply .= "━━━━━━━━━━━━━━━━\n\n";
            $reply .= "✅ Defi deja complete aujourd'hui !\n";
            $reply .= "🎯 Score : *{$score}* pts\n";
            $reply .= "⏳ Prochain defi dans *{$hoursLeft}h*\n\n";
            $reply .= "🏆 _classement_ — Voir le leaderboard\n";
            $reply .= "⚡ _blitz_ — Mode Blitz\n";
            $reply .= "🏃 _sprint_ — Mode Sprint";

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'daily_already_played']);
        }

        // All users share the same questions for the day
        $dailyTemplate = Cache::remember($dailyKey, now()->endOfDay(), function () {
            $game = GameFactory::create('trivia', 'hard');
            return $game->initGame();
        });

        $gameState                       = $dailyTemplate;
        $gameState['is_daily']           = true;
        $gameState['current_index']      = 0;
        $gameState['correct']            = 0;
        $gameState['streak']             = 0;
        $gameState['bonus_points']       = 0;
        $gameState['question_sent_at']   = now()->timestamp;

        $this->clearActiveGame($context);
        $this->setActiveGame($context, $gameState);

        $game         = GameFactory::create('trivia', 'hard');
        $questionText = $game->formatQuestion($gameState);
        $total        = $gameState['total'] ?? 7;

        $reply  = "📅 *Defi du Jour — 🧠 Trivia (Difficile)*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "_Memes {$total} questions pour tous les joueurs !_\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => 'trivia'], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily challenge started');

        return AgentResult::reply($reply, ['action' => 'daily_challenge_start']);
    }

    private function launchGame(AgentContext $context, string $gameType, string $difficulty): AgentResult
    {
        $this->clearActiveGame($context);

        try {
            $game      = GameFactory::create($gameType, $difficulty);
            $gameState = $game->initGame();
        } catch (\Exception $e) {
            Log::error("[GameMasterAgent] Game init error ({$gameType}): " . $e->getMessage());
            $reply = "⚠️ Impossible de lancer ce jeu. Reessaie avec _jeu trivia_ !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'error']);
        }

        $gameState['question_sent_at'] = now()->timestamp;
        $this->setActiveGame($context, $gameState);

        $games    = GameFactory::getAvailableGames();
        $gameInfo = $games[$gameType] ?? ['label' => $gameType, 'emoji' => '🎮'];

        $difficultyLabel = match ($difficulty) {
            'easy'  => ' _(Facile)_',
            'hard'  => ' _(Difficile)_',
            default => '',
        };

        $intro  = "🎮 *{$gameInfo['emoji']} {$gameInfo['label']}{$difficultyLabel}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n\n";

        $questionText = $game->formatQuestion($gameState);
        $reply        = $intro . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Game started', ['type' => $gameType, 'difficulty' => $difficulty]);

        return AgentResult::reply($reply, ['action' => 'game_start', 'type' => $gameType, 'difficulty' => $difficulty]);
    }

    // ─── Answer Handler ───────────────────────────────────────────────────────

    private function handleAnswer(AgentContext $context, array $gameState): AgentResult
    {
        $body      = trim($context->body ?? '');
        $gameType  = $gameState['type'] ?? 'trivia';
        $isSprint  = $gameState['is_sprint'] ?? false;

        // Empty message — re-display current question
        if (empty($body)) {
            try {
                $game         = GameFactory::create($gameType);
                $questionText = $game->formatQuestion($gameState);
            } catch (\Exception $e) {
                $questionText = '_(question indisponible)_';
            }
            $sprintHint = $isSprint ? "\n⏱ _Limite : " . self::SPRINT_TIME_LIMIT . "s par question_" : '';
            $reply      = "❓ Envoie ta reponse !{$sprintHint}\n\n" . $questionText;
            $this->sendText($context->from, $reply);
            $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
            return AgentResult::reply($reply, ['action' => 'game_empty_answer']);
        }

        $answerTimestamp = now()->timestamp;
        $questionSentAt  = $gameState['question_sent_at'] ?? $answerTimestamp;
        $responseTime    = max(0, $answerTimestamp - $questionSentAt);

        // Sprint mode: check time limit
        $timedOut = $isSprint && $responseTime > self::SPRINT_TIME_LIMIT;

        try {
            $game   = GameFactory::create($gameType);
            $result = $game->validateAnswer($body, $gameState);
        } catch (\Exception $e) {
            Log::error("[GameMasterAgent] Answer validation error ({$gameType}): " . $e->getMessage());
            $reply = "⚠️ Erreur lors du traitement. Reessaie ou tape _stop_ pour abandonner.";
            $this->sendText($context->from, $reply);
            $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
            return AgentResult::reply($reply, ['action' => 'error']);
        }

        $newState = $result['game_state'];
        $newState['min_response_time'] = min(
            $newState['min_response_time'] ?? PHP_INT_MAX,
            $responseTime
        );

        // Sprint timeout: undo score increment the game engine applied
        if ($timedOut && ($result['correct'] ?? false)) {
            $newState['correct']       = max(0, ($newState['correct'] ?? 0) - 1);
            $newState['streak']        = 0;
            $newState['bonus_points'] -= ($result['bonus'] ?? 0);
        }
        if ($timedOut) {
            $newState['sprint_timeouts'] = ($newState['sprint_timeouts'] ?? 0) + 1;
        }

        // Handle hint (not a real answer)
        if (isset($result['is_hint']) && $result['is_hint']) {
            $newState['question_sent_at'] = now()->timestamp;
            $this->setActiveGame($context, $newState);
            $reply = $result['feedback'];
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'game_hint']);
        }

        // Handle question in 20 questions (not a guess)
        if (isset($result['is_question']) && $result['is_question']) {
            $newState['question_sent_at'] = now()->timestamp;
            $this->setActiveGame($context, $newState);
            $reply = $result['feedback'];
            $this->sendText($context->from, $reply);
            $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
            return AgentResult::reply($reply, ['action' => 'game_question']);
        }

        // Build feedback line
        if ($timedOut) {
            $correctText = $result['correct_answer'] ?? '';
            $feedback    = "⏱ *Trop lent !* ({$responseTime}s / " . self::SPRINT_TIME_LIMIT . "s max)\n";
            if ($correctText) {
                $feedback .= "Reponse : *{$correctText}*\n";
            }
        } else {
            $isCorrect = $result['correct'] ?? false;
            $emoji     = $isCorrect ? '✅' : '❌';
            $feedback  = "{$emoji} {$result['feedback']}\n";
        }

        if ($game->isFinished($newState)) {
            return $this->finishGame($context, $newState, $gameType, $feedback, $responseTime);
        }

        // Continue game — next question
        $newState['question_sent_at'] = now()->timestamp;
        $this->setActiveGame($context, $newState);

        $questionText = $game->formatQuestion($newState);

        if ($isSprint) {
            $remaining  = ($newState['total'] ?? 0) - ($newState['current_index'] ?? 0);
            $feedback  .= "_(+{$remaining} question" . ($remaining > 1 ? 's' : '') . " | max " . self::SPRINT_TIME_LIMIT . "s)_\n";
        }

        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);

        $isCorrect = !$timedOut && ($result['correct'] ?? false);
        return AgentResult::reply($reply, ['action' => 'game_answer', 'correct' => $isCorrect]);
    }

    private function finishGame(AgentContext $context, array $newState, string $gameType, string $feedback, int $responseTime): AgentResult
    {
        $game    = GameFactory::create($gameType);
        $score   = $game->getScore($newState);
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);

        $isDaily  = $newState['is_daily']  ?? false;
        $isBlitz  = $newState['is_blitz']  ?? false;
        $isSprint = $newState['is_sprint'] ?? false;

        // Track daily play and per-type stats
        $this->trackDailyPlay($context);
        $this->trackGameTypeStat($context, $gameType);

        $profile->addScore($score);
        $profile->increment('total_games');
        $profile->update(['last_played_at' => now(), 'current_game' => null]);

        if ($score > 0) {
            $profile->incrementStreak();
        } else {
            $profile->resetStreak();
        }

        if ($isDaily) {
            $today        = now()->format('Y-m-d');
            $userDailyKey = "daily_played:{$context->from}:{$context->agent->id}:{$today}";
            Cache::put($userDailyKey, $score, now()->endOfDay());
            $profile->increment('weekly_challenges_completed');
        }

        // Persist last game type + difficulty for handleReplay
        $this->setLastGameData($context, $gameType, $newState['difficulty'] ?? 'medium');

        // Refresh profile after increments
        $profile->refresh();

        $newAchievements = $this->checkAchievements($context, $profile, $gameType, $newState, $responseTime);

        $this->clearActiveGame($context);
        $this->clearPendingContext($context);

        $correct  = $newState['correct'] ?? 0;
        $total    = $newState['total']   ?? 0;
        $scoreBar = $this->buildScoreBar($correct, $total);

        $reply  = $feedback . "\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";

        if ($isBlitz) {
            $reply .= "⚡ *Blitz termine !*\n\n";
        } elseif ($isSprint) {
            $timeouts = $newState['sprint_timeouts'] ?? 0;
            $reply   .= "🏃 *Sprint termine !*";
            if ($timeouts > 0) {
                $reply .= " _({$timeouts} timeout" . ($timeouts > 1 ? 's' : '') . ")_";
            }
            $reply .= "\n\n";
        } elseif ($isDaily) {
            $reply .= "📅 *Defi du Jour termine !*\n\n";
        } else {
            $reply .= "🏁 *Partie terminee !*\n\n";
        }

        if ($scoreBar) {
            $reply .= "{$scoreBar}\n";
        }
        $reply .= "🎯 Score : *{$correct}/{$total}* (+{$score} pts)\n";
        $reply .= "⭐ Total : *{$profile->score}* pts\n";
        $reply .= "🔥 Streak : *{$profile->streak}* parties\n";

        if (!empty($newAchievements)) {
            $reply .= "\n🏆 *Nouveaux succes :*\n";
            $allAch = $this->getAllAchievements();
            foreach ($newAchievements as $ach) {
                $achEmoji = $allAch[$ach]['emoji'] ?? '🏅';
                $label    = $allAch[$ach]['label'] ?? $ach;
                $reply   .= "  {$achEmoji} {$label}\n";
            }
        }

        $reply .= "\n🔁 _encore_ — Rejouer\n";
        $reply .= "⚡ _blitz_ — Mode Blitz (3 questions)\n";
        $reply .= "🏃 _sprint_ — Mode Sprint (15s/question)\n";
        $reply .= "🎮 _jeu trivia/enigme/mots_ — Autre jeu\n";
        $reply .= "🏆 _classement_ — Leaderboard\n";
        $reply .= "📊 _mes stats_ — Mon profil";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Game completed', [
            'type'    => $gameType,
            'score'   => $score,
            'correct' => $correct,
            'total'   => $total,
            'daily'   => $isDaily,
            'blitz'   => $isBlitz,
            'sprint'  => $isSprint,
        ]);

        return AgentResult::reply($reply, ['action' => 'game_complete', 'score' => $score]);
    }

    private function buildScoreBar(int $correct, int $total): string
    {
        if ($total <= 0) {
            return '';
        }
        return str_repeat('🟩', min($correct, $total)) . str_repeat('⬜', max(0, $total - $correct));
    }

    // ─── Current Progress ─────────────────────────────────────────────────────

    /**
     * Show the player's current score mid-game without interrupting the game.
     */
    private function handleCurrentProgress(AgentContext $context, array $gameState): AgentResult
    {
        $gameType     = $gameState['type'] ?? 'jeu';
        $correct      = $gameState['correct'] ?? 0;
        $currentIndex = $gameState['current_index'] ?? 0;
        $total        = $gameState['total'] ?? 0;
        $remaining    = max(0, $total - $currentIndex);
        $isSprint     = $gameState['is_sprint'] ?? false;

        $games    = GameFactory::getAvailableGames();
        $gameInfo = $games[$gameType] ?? ['label' => $gameType, 'emoji' => '🎮'];

        $bar = $currentIndex > 0 ? $this->buildScoreBar($correct, $currentIndex) : '';

        $reply  = "📊 *Progression en cours*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "{$gameInfo['emoji']} {$gameInfo['label']}";
        if ($isSprint) {
            $reply .= " _(Sprint — " . self::SPRINT_TIME_LIMIT . "s/q)_";
        }
        $reply .= "\n";

        if ($bar) {
            $reply .= "{$bar}\n";
        }

        $reply .= "✅ Correct : *{$correct}/{$currentIndex}*\n";
        $reply .= "❓ Restant : *{$remaining}* question" . ($remaining > 1 ? 's' : '') . "\n\n";
        $reply .= "_Continue ! Reponds a la question en cours._\n";
        $reply .= "_stop_ — Abandonner";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'game_progress']);
    }

    // ─── In-game Help ─────────────────────────────────────────────────────────

    /**
     * Explain the rules of the current active game type.
     */
    private function handleGameHelp(AgentContext $context, array $gameState): AgentResult
    {
        $gameType = $gameState['type'] ?? 'trivia';
        $isSprint = $gameState['is_sprint'] ?? false;

        $rules = match ($gameType) {
            'trivia'           => "🧠 *Trivia — Comment jouer*\n\nReponds A, B, C ou D a chaque question.\nTu peux aussi taper le numero (1, 2, 3, 4).\nUne serie de 3+ bonnes reponses donne des points bonus !",
            'riddle'           => "🔮 *Enigmes — Comment jouer*\n\nReponds librement a chaque enigme.\nTape _indice_ pour obtenir un indice.\nPlusieurs formulations de reponse sont acceptees.",
            'twenty_questions' => "❓ *20 Questions — Comment jouer*\n\nPose des questions auxquelles je reponds par oui ou non.\nTu as 20 questions pour deviner ce que je pense.\nTape _devine: [ta reponse]_ pour proposer une reponse finale.",
            'word_challenge'   => "📝 *Mots Melanges — Comment jouer*\n\nRecompose le mot a partir des lettres proposees.\nTape le mot complet en majuscules ou minuscules.\nLa categorie du mot est donnee en indice.",
            default            => "🎮 *Comment jouer*\n\nSuis les instructions affichees a chaque question.",
        };

        if ($isSprint) {
            $rules .= "\n\n⏱ *Mode Sprint actif* : " . self::SPRINT_TIME_LIMIT . "s maximum par reponse !";
        }

        $reply  = $rules . "\n\n";
        $reply .= "_stop_ — Abandonner la partie\n";
        $reply .= "_progression_ — Voir ton score actuel";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'game_help']);
    }

    // ─── Leaderboard ──────────────────────────────────────────────────────────

    private function handleLeaderboard(AgentContext $context): AgentResult
    {
        $leaderboard = UserGameProfile::getLeaderboard($context->agent->id, 10);

        if ($leaderboard->isEmpty()) {
            $reply = "🏆 *Classement GameMaster*\n\nAucun joueur pour l'instant.\nLance un jeu avec _jeu trivia_ !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply  = "🏆 *Classement GameMaster — Top 10*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals     = ['🥇', '🥈', '🥉'];
        $userInTop  = false;

        foreach ($leaderboard as $i => $profile) {
            $rank       = $medals[$i] ?? ($i + 1) . '.';
            $phone      = '...' . substr($profile->user_phone, -4);
            $streakInfo = $profile->best_streak > 1 ? ", streak max: {$profile->best_streak}" : '';
            $isMe       = $profile->user_phone === $context->from;
            $marker     = $isMe ? ' 👈' : '';
            $reply     .= "{$rank} *{$phone}* — {$profile->score} pts ({$profile->total_games} parties{$streakInfo}){$marker}\n";

            if ($isMe) {
                $userInTop = true;
            }
        }

        // Show user's own rank if outside top 10 — efficient COUNT query instead of loading 1000 rows
        if (!$userInTop) {
            $myProfile = UserGameProfile::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->first();

            if ($myProfile && $myProfile->total_games > 0) {
                $userRank  = UserGameProfile::where('agent_id', $context->agent->id)
                    ->where('total_games', '>', 0)
                    ->where('score', '>', $myProfile->score)
                    ->count() + 1;
                $reply .= "\n📍 *Ton classement : #{$userRank}*\n";
            }
        }

        $reply .= "\n📅 _defi_ — Defi du jour\n";
        $reply .= "📊 _mes stats_ — Tes stats perso";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Leaderboard viewed');

        return AgentResult::reply($reply, ['action' => 'leaderboard']);
    }

    // ─── Status ───────────────────────────────────────────────────────────────

    private function handleStatus(AgentContext $context): AgentResult
    {
        $profile          = UserGameProfile::getOrCreate($context->from, $context->agent->id);
        $allAchievements  = $this->getAllAchievements();
        $achievementCount = count($profile->achievements ?? []);
        $totalAchievements = count($allAchievements);

        // Efficient rank query using COUNT instead of loading all profiles
        $myRank = null;
        if ($profile->total_games > 0) {
            $myRank = UserGameProfile::where('agent_id', $context->agent->id)
                ->where('total_games', '>', 0)
                ->where('score', '>', $profile->score)
                ->count() + 1;
        }

        $avgScore  = $profile->total_games > 0
            ? round($profile->score / $profile->total_games, 1)
            : 0;

        $typeStats = $this->getGameTypeStats($context);

        $reply  = "📊 *Mon Profil GameMaster*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($myRank) {
            $reply .= "🏅 Classement : *#{$myRank}*\n";
        }
        $reply .= "⭐ Score total : *{$profile->score}* pts\n";
        $reply .= "🎮 Parties jouees : *{$profile->total_games}*\n";

        if ($profile->total_games > 0) {
            $reply .= "📈 Moy. par partie : *{$avgScore}* pts\n";
        }

        $reply .= "🔥 Streak actuel : *{$profile->streak}*\n";
        $reply .= "💎 Meilleur streak : *{$profile->best_streak}*\n";
        $reply .= "🏆 Succes : *{$achievementCount}/{$totalAchievements}*\n";

        if (($profile->weekly_challenges_completed ?? 0) > 0) {
            $reply .= "📅 Defis completes : *{$profile->weekly_challenges_completed}*\n";
        }

        if (!empty($typeStats)) {
            $reply .= "\n🎲 *Parties par type :*\n";
            $games  = GameFactory::getAvailableGames();
            foreach ($typeStats as $type => $count) {
                $info   = $games[$type] ?? ['emoji' => '🎮', 'label' => $type];
                $reply .= "  {$info['emoji']} {$info['label']} : *{$count}*\n";
            }
        }

        if ($profile->last_played_at) {
            $reply .= "\n🕐 Derniere partie : {$profile->last_played_at->format('d/m/Y H:i')}\n";
        }

        $reply .= "\n🎮 _jeu trivia_ — Nouveau jeu\n";
        $reply .= "⚡ _blitz_ — Mode Blitz\n";
        $reply .= "🏃 _sprint_ — Mode Sprint\n";
        $reply .= "📅 _defi_ — Defi du jour\n";
        $reply .= "🏆 _classement_ — Leaderboard\n";
        $reply .= "🏅 _achievements_ — Mes succes\n";
        $reply .= "📊 _stats type_ — Stats par type";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Status viewed');

        return AgentResult::reply($reply, ['action' => 'status']);
    }

    // ─── Achievements ─────────────────────────────────────────────────────────

    private function handleAchievements(AgentContext $context): AgentResult
    {
        $profile  = UserGameProfile::getOrCreate($context->from, $context->agent->id);
        $unlocked = $profile->achievements ?? [];
        $allAch   = $this->getAllAchievements();

        $reply  = "🏅 *Mes Succes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($allAch as $key => $ach) {
            $isUnlocked = in_array($key, $unlocked);
            if ($isUnlocked) {
                $reply .= "{$ach['emoji']} *{$ach['label']}* — {$ach['description']}\n";
            } else {
                $progress    = $this->getAchievementProgress($key, $profile);
                $progressStr = $progress ? " _{$progress}_" : '';
                $reply      .= "🔒 *???* — ???{$progressStr}\n";
            }
        }

        $count = count($unlocked);
        $total = count($allAch);
        $reply .= "\n✨ *{$count}/{$total}* debloques";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'achievements']);
    }

    /**
     * Return a short progress hint for locked numeric achievements.
     */
    private function getAchievementProgress(string $key, UserGameProfile $profile): ?string
    {
        return match ($key) {
            'ten_wins'   => $profile->total_games < 10 ? "({$profile->total_games}/10 parties)"   : null,
            'fifty_wins' => $profile->total_games < 50 ? "({$profile->total_games}/50 parties)"   : null,
            'streak_3'   => $profile->best_streak < 3  ? "({$profile->best_streak}/3 streak)"     : null,
            'streak_7'   => $profile->best_streak < 7  ? "({$profile->best_streak}/7 streak)"     : null,
            default      => null,
        };
    }

    // ─── Stats par Type de Jeu ────────────────────────────────────────────────

    /**
     * Show the player's games breakdown by type.
     */
    private function handleGameStats(AgentContext $context): AgentResult
    {
        $typeStats = $this->getGameTypeStats($context);
        $profile   = UserGameProfile::getOrCreate($context->from, $context->agent->id);
        $games     = GameFactory::getAvailableGames();

        $reply  = "📊 *Stats par Type de Jeu*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if (empty($typeStats)) {
            $reply .= "Aucune partie enregistree encore.\n\n";
            $reply .= "Lance ton premier jeu !\n";
            $reply .= "🎮 _jeu trivia_ — Trivia\n";
            $reply .= "🔮 _jeu enigme_ — Enigmes\n";
            $reply .= "❓ _jeu 20 questions_ — 20 Questions\n";
            $reply .= "📝 _jeu mots_ — Mots Melanges";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'game_stats_empty']);
        }

        $totalTracked = array_sum($typeStats);

        foreach ($games as $type => $info) {
            $count  = $typeStats[$type] ?? 0;
            $pct    = $totalTracked > 0 ? round($count / $totalTracked * 100) : 0;
            $filled = min($count, 10);
            $bar    = str_repeat('▓', $filled) . str_repeat('░', 10 - $filled);
            $reply .= "{$info['emoji']} *{$info['label']}*\n";
            $reply .= "   {$bar} {$count} partie" . ($count > 1 ? 's' : '') . " ({$pct}%)\n";
        }

        $reply .= "\n📋 Total suivi : *{$totalTracked}* partie" . ($totalTracked > 1 ? 's' : '') . "\n";
        $reply .= "⭐ Score global : *{$profile->score}* pts\n\n";
        $reply .= "🎮 _jeu trivia_ — Jouer\n";
        $reply .= "📊 _mes stats_ — Profil complet";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Game stats by type viewed');

        return AgentResult::reply($reply, ['action' => 'game_stats']);
    }

    // ─── Abandon ──────────────────────────────────────────────────────────────

    private function handleAbandon(AgentContext $context, array $gameState): AgentResult
    {
        $correct      = $gameState['correct'] ?? 0;
        $currentIndex = $gameState['current_index'] ?? 0;
        $total        = $gameState['total'] ?? 0;
        $gameType     = $gameState['type'] ?? 'jeu';

        $games    = GameFactory::getAvailableGames();
        $gameInfo = $games[$gameType] ?? ['label' => $gameType, 'emoji' => '🎮'];

        $this->clearActiveGame($context);
        $this->clearPendingContext($context);

        $reply  = "🛑 *Partie abandonnee*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "{$gameInfo['emoji']} {$gameInfo['label']}\n";

        if ($currentIndex > 0) {
            $bar    = $this->buildScoreBar($correct, $currentIndex);
            $reply .= "{$bar}\n";
            $reply .= "Score partiel : {$correct}/{$currentIndex} bonnes reponses\n";
        } else {
            $reply .= "Partie abandonnee avant la premiere reponse.\n";
        }

        $reply .= "\n🔁 _encore_ — Rejouer\n";
        $reply .= "🎮 _jeu trivia_ — Nouveau jeu\n";
        $reply .= "📊 _mes stats_ — Mon profil";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Game abandoned', ['type' => $gameType, 'correct' => $correct, 'total' => $total]);

        return AgentResult::reply($reply, ['action' => 'game_abandon']);
    }

    // ─── List Games ───────────────────────────────────────────────────────────

    private function handleListGames(AgentContext $context): AgentResult
    {
        $games = GameFactory::getAvailableGames();

        $reply  = "🎮 *Jeux Disponibles*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($games as $key => $game) {
            $reply .= "{$game['emoji']} *{$game['label']}* — {$game['description']}\n";
            $reply .= "   → _jeu {$key}_\n\n";
        }

        $reply .= "⚡ *Mode Blitz* — Trivia express 3 questions\n";
        $reply .= "   → _blitz_\n\n";

        $reply .= "🏃 *Mode Sprint* — 5 questions, " . self::SPRINT_TIME_LIMIT . "s max par reponse\n";
        $reply .= "   → _sprint_\n\n";

        $reply .= "🌶 Difficulte : _jeu trivia facile_ / _jeu mots difficile_\n\n";
        $reply .= "📅 _defi_ — Defi du jour\n";
        $reply .= "🔁 _encore_ — Rejouer le dernier jeu\n";
        $reply .= "🏆 _classement_ — Leaderboard\n";
        $reply .= "📊 _mes stats_ — Mon profil\n";
        $reply .= "🏅 _achievements_ — Mes succes\n";
        $reply .= "📊 _stats type_ — Stats par type de jeu";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'list_games']);
    }

    // ─── Achievements Check ───────────────────────────────────────────────────

    private function checkAchievements(
        AgentContext $context,
        UserGameProfile $profile,
        string $gameType,
        array $gameState,
        int $responseTime = PHP_INT_MAX
    ): array {
        $newAchievements = [];

        // First win
        if ($profile->total_games === 1 && ($gameState['correct'] ?? 0) > 0) {
            if ($profile->unlockAchievement('first_win')) {
                $newAchievements[] = 'first_win';
                $this->saveAchievement($context, 'first_win', $gameType);
            }
        }

        // 10 games
        if ($profile->total_games >= 10) {
            if ($profile->unlockAchievement('ten_wins')) {
                $newAchievements[] = 'ten_wins';
                $this->saveAchievement($context, 'ten_wins', $gameType);
            }
        }

        // 50 games
        if ($profile->total_games >= 50) {
            if ($profile->unlockAchievement('fifty_wins')) {
                $newAchievements[] = 'fifty_wins';
                $this->saveAchievement($context, 'fifty_wins', $gameType);
            }
        }

        // Streak 3
        if ($profile->streak >= 3) {
            if ($profile->unlockAchievement('streak_3')) {
                $newAchievements[] = 'streak_3';
                $this->saveAchievement($context, 'streak_3', $gameType);
            }
        }

        // Streak 7
        if ($profile->streak >= 7) {
            if ($profile->unlockAchievement('streak_7')) {
                $newAchievements[] = 'streak_7';
                $this->saveAchievement($context, 'streak_7', $gameType);
            }
        }

        // Perfect trivia
        if ($gameType === 'trivia'
            && ($gameState['correct'] ?? 0) === ($gameState['total'] ?? 0)
            && ($gameState['total'] ?? 0) > 0
        ) {
            if ($profile->unlockAchievement('trivia_master')) {
                $newAchievements[] = 'trivia_master';
                $this->saveAchievement($context, 'trivia_master', $gameType);
            }
        }

        // Word wizard: perfect score in word_challenge
        if ($gameType === 'word_challenge'
            && ($gameState['correct'] ?? 0) === ($gameState['total'] ?? 0)
            && ($gameState['total'] ?? 0) > 0
        ) {
            if ($profile->unlockAchievement('word_wizard')) {
                $newAchievements[] = 'word_wizard';
                $this->saveAchievement($context, 'word_wizard', $gameType);
            }
        }

        // Riddle solver (10+ riddle games or 3 correct in one session)
        if ($gameType === 'riddle') {
            $totalRiddles = GameAchievement::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('game_type', 'riddle')
                ->count();
            if ($totalRiddles >= 10 || ($gameState['correct'] ?? 0) >= 3) {
                if ($profile->unlockAchievement('riddle_solver')) {
                    $newAchievements[] = 'riddle_solver';
                    $this->saveAchievement($context, 'riddle_solver', $gameType);
                }
            }
        }

        // Speed demon: first answer in < 5 seconds
        if ($responseTime > 0 && $responseTime < 5) {
            if ($profile->unlockAchievement('speed_demon')) {
                $newAchievements[] = 'speed_demon';
                $this->saveAchievement($context, 'speed_demon', $gameType);
            }
        }

        // Sprint ace: complete sprint with 0 timeouts and perfect score
        if (($gameState['is_sprint'] ?? false)
            && ($gameState['sprint_timeouts'] ?? 0) === 0
            && ($gameState['correct'] ?? 0) === ($gameState['total'] ?? 0)
            && ($gameState['total'] ?? 0) > 0
        ) {
            if ($profile->unlockAchievement('sprint_ace')) {
                $newAchievements[] = 'sprint_ace';
                $this->saveAchievement($context, 'sprint_ace', $gameType);
            }
        }

        // All rounder: played all 4 game types (use cache stats + current game)
        $typeStats             = $this->getGameTypeStats($context);
        $typeStats[$gameType]  = ($typeStats[$gameType] ?? 0) + 1;
        $allTypes              = array_keys(GameFactory::getAvailableGames());
        if (empty(array_diff($allTypes, array_keys($typeStats)))) {
            if ($profile->unlockAchievement('all_rounder')) {
                $newAchievements[] = 'all_rounder';
                $this->saveAchievement($context, 'all_rounder', $gameType);
            }
        }

        // 7 consecutive days of play
        $dailyPlayKey = "daily_play_log:{$context->from}:{$context->agent->id}";
        $playedDays   = Cache::get($dailyPlayKey, []);
        if ($this->hasSevenConsecutiveDays($playedDays)) {
            if ($profile->unlockAchievement('streak_7d')) {
                $newAchievements[] = 'streak_7d';
                $this->saveAchievement($context, 'streak_7d', $gameType);
            }
        }

        return $newAchievements;
    }

    private function saveAchievement(AgentContext $context, string $key, string $gameType): void
    {
        try {
            GameAchievement::create([
                'user_phone'      => $context->from,
                'agent_id'        => $context->agent->id,
                'achievement_key' => $key,
                'game_type'       => $gameType,
                'unlocked_at'     => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("[GameMasterAgent] Failed to save achievement {$key}: " . $e->getMessage());
        }
    }

    // ─── Daily Play Tracking ──────────────────────────────────────────────────

    private function trackDailyPlay(AgentContext $context): void
    {
        $dailyPlayKey = "daily_play_log:{$context->from}:{$context->agent->id}";
        $today        = now()->format('Y-m-d');
        $playedDays   = Cache::get($dailyPlayKey, []);

        if (!in_array($today, $playedDays)) {
            $playedDays[] = $today;
            // Keep only last 8 days to detect 7-day streaks
            $playedDays = array_slice(array_unique($playedDays), -8);
            Cache::put($dailyPlayKey, $playedDays, now()->addDays(9));
        }
    }

    private function hasSevenConsecutiveDays(array $playedDays): bool
    {
        if (count($playedDays) < 7) {
            return false;
        }

        $dates = array_map(fn($d) => \Carbon\Carbon::parse($d), $playedDays);
        usort($dates, fn($a, $b) => $a->timestamp <=> $b->timestamp);

        $consecutive = 1;
        for ($i = 1; $i < count($dates); $i++) {
            if ((int) abs($dates[$i]->diffInDays($dates[$i - 1])) === 1) {
                $consecutive++;
                if ($consecutive >= 7) {
                    return true;
                }
            } else {
                $consecutive = 1;
            }
        }

        return false;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function parseDifficulty(string $body, string $default = 'medium'): string
    {
        $lower = mb_strtolower($body);
        if (preg_match('/\b(facile|easy|simple)\b/iu', $lower)) {
            return 'easy';
        }
        if (preg_match('/\b(difficile|hard|dur|expert)\b/iu', $lower)) {
            return 'hard';
        }
        return $default;
    }
}
