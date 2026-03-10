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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $activeGame = $this->getActiveGame($context);

        if (preg_match('/\b(leaderboard|classement|top\s*\d*)\b/iu', $lower)) {
            return $this->handleLeaderboard($context);
        }

        if (preg_match('/\b(mes\s*stats|my\s*stats|status|mon\s*profil|profile)\b/iu', $lower)) {
            return $this->handleStatus($context);
        }

        if (preg_match('/\b(achievements?|succes|trophees?|badges?)\b/iu', $lower)) {
            return $this->handleAchievements($context);
        }

        if (preg_match('/\b(stop|quit|abandon|arr[eê]t|annul)\b/iu', $lower) && $activeGame) {
            return $this->handleAbandon($context, $activeGame);
        }

        if (preg_match('/\b(defi\s*du\s*jour|defi\s*journalier|daily\s*challenge|defi)\b/iu', $lower) && !$activeGame) {
            return $this->handleDailyChallenge($context);
        }

        if (preg_match('/\b(encore|rejouer|replay|revanche)\b/iu', $lower) && !$activeGame) {
            return $this->handleReplay($context);
        }

        if (preg_match('/\b(jeux|games|liste|list|help|aide)\b/iu', $lower) && !$activeGame) {
            return $this->handleListGames($context);
        }

        if ($activeGame) {
            return $this->handleAnswer($context, $activeGame);
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

    private function setLastGameType(AgentContext $context, string $gameType): void
    {
        $key = "last_game:{$context->from}:{$context->agent->id}";
        Cache::put($key, $gameType, now()->addHours(24));
    }

    private function getLastGameType(AgentContext $context): ?string
    {
        $key = "last_game:{$context->from}:{$context->agent->id}";
        return Cache::get($key);
    }

    // ─── Start / Launch ───────────────────────────────────────────────────────

    private function handleStartGame(AgentContext $context, string $body): AgentResult
    {
        $gameType = null;
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
        $gameType = $this->getLastGameType($context) ?? 'trivia';
        return $this->launchGame($context, $gameType, 'medium');
    }

    private function handleDailyChallenge(AgentContext $context): AgentResult
    {
        $today = now()->format('Y-m-d');
        $dailyKey = "daily_challenge:{$context->agent->id}:{$today}";
        $userDailyKey = "daily_played:{$context->from}:{$context->agent->id}:{$today}";

        // Check if user already played today
        if (Cache::has($userDailyKey)) {
            $score = Cache::get($userDailyKey);
            $reply = "📅 *Defi du Jour*\n";
            $reply .= "━━━━━━━━━━━━━━━━\n\n";
            $reply .= "Tu as deja joue le defi du jour ! ✅\n";
            $reply .= "Score : *{$score}* pts\n\n";
            $reply .= "Reviens demain pour un nouveau defi.\n";
            $reply .= "🏆 _classement_ — Voir le leaderboard";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'daily_already_played']);
        }

        // All users share the same questions for the day
        $dailyTemplate = Cache::remember($dailyKey, now()->endOfDay(), function () {
            $game = GameFactory::create('trivia', 'hard');
            return $game->initGame();
        });

        $gameState = $dailyTemplate;
        $gameState['is_daily'] = true;
        $gameState['current_index'] = 0;
        $gameState['correct'] = 0;
        $gameState['streak'] = 0;
        $gameState['bonus_points'] = 0;
        $gameState['question_sent_at'] = now()->timestamp;

        $this->clearActiveGame($context);
        $this->setActiveGame($context, $gameState);

        $game = GameFactory::create('trivia', 'hard');
        $questionText = $game->formatQuestion($gameState);

        $reply = "📅 *Defi du Jour — 🧠 Trivia (Difficile)*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "_Memes questions pour tous les joueurs aujourd'hui !_\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => 'trivia'], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily challenge started');

        return AgentResult::reply($reply, ['action' => 'daily_challenge_start']);
    }

    private function launchGame(AgentContext $context, string $gameType, string $difficulty): AgentResult
    {
        $this->clearActiveGame($context);

        $game = GameFactory::create($gameType, $difficulty);
        $gameState = $game->initGame();
        $gameState['question_sent_at'] = now()->timestamp;

        $this->setActiveGame($context, $gameState);

        $games = GameFactory::getAvailableGames();
        $gameInfo = $games[$gameType] ?? ['label' => $gameType, 'emoji' => '🎮'];

        $difficultyLabel = match ($difficulty) {
            'easy'  => ' _(Facile)_',
            'hard'  => ' _(Difficile)_',
            default => '',
        };

        $intro = "🎮 *{$gameInfo['emoji']} {$gameInfo['label']}{$difficultyLabel}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n\n";

        $questionText = $game->formatQuestion($gameState);
        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Game started', ['type' => $gameType, 'difficulty' => $difficulty]);

        return AgentResult::reply($reply, ['action' => 'game_start', 'type' => $gameType, 'difficulty' => $difficulty]);
    }

    // ─── Answer Handler ───────────────────────────────────────────────────────

    private function handleAnswer(AgentContext $context, array $gameState): AgentResult
    {
        $body = trim($context->body ?? '');
        $gameType = $gameState['type'] ?? 'trivia';

        $answerTimestamp = now()->timestamp;
        $questionSentAt = $gameState['question_sent_at'] ?? $answerTimestamp;
        $responseTime = max(0, $answerTimestamp - $questionSentAt);

        $game = GameFactory::create($gameType);
        $result = $game->validateAnswer($body, $gameState);
        $newState = $result['game_state'];

        $newState['min_response_time'] = min(
            $newState['min_response_time'] ?? PHP_INT_MAX,
            $responseTime
        );

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

        $isCorrect = $result['correct'] ?? false;
        $emoji = $isCorrect ? '✅' : '❌';
        $feedback = "{$emoji} {$result['feedback']}\n";

        if ($game->isFinished($newState)) {
            return $this->finishGame($context, $newState, $gameType, $feedback, $responseTime);
        }

        // Continue game — next question
        $newState['question_sent_at'] = now()->timestamp;
        $this->setActiveGame($context, $newState);

        $questionText = $game->formatQuestion($newState);
        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'game_answer', 'correct' => $isCorrect]);
    }

    private function finishGame(AgentContext $context, array $newState, string $gameType, string $feedback, int $responseTime): AgentResult
    {
        $game = GameFactory::create($gameType);
        $score = $game->getScore($newState);
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);

        $isDaily = $newState['is_daily'] ?? false;

        // Update daily play tracking before modifying last_played_at
        $this->trackDailyPlay($context);

        $profile->addScore($score);
        $profile->increment('total_games');
        $profile->update(['last_played_at' => now(), 'current_game' => null]);

        if ($score > 0) {
            $profile->incrementStreak();
        } else {
            $profile->resetStreak();
        }

        if ($isDaily) {
            $today = now()->format('Y-m-d');
            $userDailyKey = "daily_played:{$context->from}:{$context->agent->id}:{$today}";
            Cache::put($userDailyKey, $score, now()->endOfDay());
            $profile->increment('weekly_challenges_completed');
        }

        $this->setLastGameType($context, $gameType);

        // Refresh profile after increments
        $profile->refresh();

        $newAchievements = $this->checkAchievements($context, $profile, $gameType, $newState, $responseTime);

        $this->clearActiveGame($context);
        $this->clearPendingContext($context);

        $correct = $newState['correct'] ?? 0;
        $total = $newState['total'] ?? 0;
        $scoreBar = $this->buildScoreBar($correct, $total);

        $reply = $feedback . "\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= $isDaily ? "📅 *Defi du Jour termine !*\n\n" : "🏁 *Partie terminee !*\n\n";
        if ($scoreBar) {
            $reply .= "{$scoreBar}\n";
        }
        $reply .= "🎯 Score : *{$correct}/{$total}* (+{$score} pts)\n";
        $reply .= "⭐ Total : *{$profile->score}* pts\n";
        $reply .= "🔥 Streak : *{$profile->streak}* parties\n";

        if (!empty($newAchievements)) {
            $reply .= "\n🏆 *Nouveaux succes :*\n";
            foreach ($newAchievements as $ach) {
                $achEmoji = GameAchievement::getEmoji($ach);
                $label = GameAchievement::getLabel($ach);
                $reply .= "  {$achEmoji} {$label}\n";
            }
        }

        $reply .= "\n🔁 _encore_ — Rejouer\n";
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

    // ─── Leaderboard ──────────────────────────────────────────────────────────

    private function handleLeaderboard(AgentContext $context): AgentResult
    {
        $leaderboard = UserGameProfile::getLeaderboard($context->agent->id, 10);

        if ($leaderboard->isEmpty()) {
            $reply = "🏆 *Classement GameMaster*\n\nAucun joueur pour l'instant.\nLance un jeu avec _jeu trivia_ !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "🏆 *Classement GameMaster — Top 10*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($leaderboard as $i => $profile) {
            $rank = $medals[$i] ?? ($i + 1) . '.';
            $phone = '...'.substr($profile->user_phone, -4);
            $streakInfo = $profile->best_streak > 1 ? ", streak max: {$profile->best_streak}" : '';
            $reply .= "{$rank} *{$phone}* — {$profile->score} pts ({$profile->total_games} parties{$streakInfo})\n";
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
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);

        $achievementCount = count($profile->achievements ?? []);
        $totalAchievements = count(GameAchievement::ACHIEVEMENTS);

        // Find player rank
        $leaderboard = UserGameProfile::getLeaderboard($context->agent->id, 100);
        $rank = null;
        foreach ($leaderboard as $i => $p) {
            if ($p->user_phone === $context->from) {
                $rank = $i + 1;
                break;
            }
        }

        $reply = "📊 *Mon Profil GameMaster*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($rank) {
            $reply .= "🏅 Classement : *#{$rank}*\n";
        }
        $reply .= "⭐ Score total : *{$profile->score}* pts\n";
        $reply .= "🎮 Parties jouees : *{$profile->total_games}*\n";
        $reply .= "🔥 Streak actuel : *{$profile->streak}*\n";
        $reply .= "💎 Meilleur streak : *{$profile->best_streak}*\n";
        $reply .= "🏆 Succes : *{$achievementCount}/{$totalAchievements}*\n";

        if (($profile->weekly_challenges_completed ?? 0) > 0) {
            $reply .= "📅 Defis completes : *{$profile->weekly_challenges_completed}*\n";
        }

        if ($profile->last_played_at) {
            $reply .= "📅 Derniere partie : {$profile->last_played_at->format('d/m/Y H:i')}\n";
        }

        $reply .= "\n🎮 _jeu trivia_ — Nouveau jeu\n";
        $reply .= "📅 _defi_ — Defi du jour\n";
        $reply .= "🏆 _classement_ — Leaderboard\n";
        $reply .= "🏅 _achievements_ — Mes succes";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Status viewed');

        return AgentResult::reply($reply, ['action' => 'status']);
    }

    // ─── Achievements ─────────────────────────────────────────────────────────

    private function handleAchievements(AgentContext $context): AgentResult
    {
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);
        $unlocked = $profile->achievements ?? [];

        $reply = "🏅 *Mes Succes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach (GameAchievement::ACHIEVEMENTS as $key => $ach) {
            $isUnlocked = in_array($key, $unlocked);
            $status = $isUnlocked ? $ach['emoji'] : '🔒';
            $label  = $isUnlocked ? $ach['label'] : '???';
            $desc   = $isUnlocked ? $ach['description'] : '???';
            $reply .= "{$status} *{$label}* — {$desc}\n";
        }

        $reply .= "\n" . count($unlocked) . "/" . count(GameAchievement::ACHIEVEMENTS) . " debloques";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'achievements']);
    }

    // ─── Abandon ──────────────────────────────────────────────────────────────

    private function handleAbandon(AgentContext $context, array $gameState): AgentResult
    {
        $correct  = $gameState['correct'] ?? 0;
        $total    = count($gameState['questions'] ?? $gameState['words'] ?? []);
        $gameType = $gameState['type'] ?? 'jeu';

        $this->clearActiveGame($context);
        $this->clearPendingContext($context);

        $reply = "🛑 *Partie abandonnee*\n";
        if ($total > 0) {
            $bar = $this->buildScoreBar($correct, $total);
            $reply .= "{$bar}\n";
            $reply .= "Score partiel : {$correct}/{$total}\n";
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

        $reply = "🎮 *Jeux Disponibles*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($games as $key => $game) {
            $reply .= "{$game['emoji']} *{$game['label']}* — {$game['description']}\n";
            $reply .= "   → _jeu {$key}_\n\n";
        }

        $reply .= "🌶 Difficulte : _jeu trivia facile_ / _jeu mots difficile_\n\n";
        $reply .= "📅 _defi_ — Defi du jour\n";
        $reply .= "🔁 _encore_ — Rejouer le dernier jeu\n";
        $reply .= "🏆 _classement_ — Leaderboard\n";
        $reply .= "📊 _mes stats_ — Mon profil\n";
        $reply .= "🏅 _achievements_ — Mes succes";

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

        // Riddle solver (10+ riddle completions or 3 correct in one session)
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

        // All rounder: played all 4 game types
        $playedTypes = GameAchievement::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->distinct()
            ->pluck('game_type')
            ->toArray();
        $playedTypes[] = $gameType;
        $allTypes = array_keys(GameFactory::getAvailableGames());
        if (empty(array_diff($allTypes, $playedTypes))) {
            if ($profile->unlockAchievement('all_rounder')) {
                $newAchievements[] = 'all_rounder';
                $this->saveAchievement($context, 'all_rounder', $gameType);
            }
        }

        // 7 consecutive days of play
        $dailyPlayKey = "daily_play_log:{$context->from}:{$context->agent->id}";
        $playedDays = Cache::get($dailyPlayKey, []);
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
        $today = now()->format('Y-m-d');
        $playedDays = Cache::get($dailyPlayKey, []);

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
