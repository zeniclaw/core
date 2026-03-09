<?php

namespace App\Services\Agents;

use App\Models\GameAchievement;
use App\Models\UserGameProfile;
use App\Services\AgentContext;
use App\Services\GameEngine\GameFactory;
use Illuminate\Support\Facades\Cache;

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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Check for active game
        $activeGame = $this->getActiveGame($context);

        // Parse commands
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
            return $this->handleAbandon($context);
        }

        if (preg_match('/\b(jeux|games|liste|list|help|aide)\b/iu', $lower) && !$activeGame) {
            return $this->handleListGames($context);
        }

        // If active game, treat message as answer
        if ($activeGame) {
            return $this->handleAnswer($context, $activeGame);
        }

        // Start new game
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

    private function handleStartGame(AgentContext $context, string $body): AgentResult
    {
        // Detect game type
        $gameType = null;

        if (preg_match('/(?:jeu|game|jouer|play)\s+(?:a\s+|au\s+|aux?\s+)?(\w+)/iu', $body, $m)) {
            $gameType = GameFactory::resolveGameType($m[1]);
        }

        if (!$gameType) {
            // Check for direct game type mentions
            foreach (['trivia', 'enigme', 'riddle', 'devinette', '20 questions', 'mot', 'mots', 'anagramme', 'word'] as $kw) {
                if (str_contains(mb_strtolower($body), $kw)) {
                    $gameType = GameFactory::resolveGameType($kw);
                    if ($gameType) break;
                }
            }
        }

        // Default to trivia
        if (!$gameType) {
            $gameType = 'trivia';
        }

        // Clear any existing game
        $this->clearActiveGame($context);

        $game = GameFactory::create($gameType);
        $gameState = $game->initGame();

        $this->setActiveGame($context, $gameState);

        $games = GameFactory::getAvailableGames();
        $gameInfo = $games[$gameType] ?? ['label' => $gameType, 'emoji' => '🎮'];

        $intro = "🎮 *{$gameInfo['emoji']} {$gameInfo['label']}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n\n";

        // Format first question with incremented index for display
        $displayState = $gameState;
        $displayState['current_index'] = $gameState['current_index'] + 1;
        $questionText = $game->formatQuestion($gameState);

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Game started', ['type' => $gameType]);

        return AgentResult::reply($reply, ['action' => 'game_start', 'type' => $gameType]);
    }

    private function handleAnswer(AgentContext $context, array $gameState): AgentResult
    {
        $body = trim($context->body ?? '');
        $gameType = $gameState['type'] ?? 'trivia';

        $game = GameFactory::create($gameType);
        $result = $game->validateAnswer($body, $gameState);
        $newState = $result['game_state'];

        // Handle hint (not a real answer)
        if (isset($result['is_hint']) && $result['is_hint']) {
            $this->setActiveGame($context, $newState);
            $reply = $result['feedback'];
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'game_hint']);
        }

        // Handle question in 20 questions (not a guess)
        if (isset($result['is_question']) && $result['is_question']) {
            $this->setActiveGame($context, $newState);
            $reply = $result['feedback'];
            $this->sendText($context->from, $reply);
            $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
            return AgentResult::reply($reply, ['action' => 'game_question']);
        }

        $isCorrect = $result['correct'] ?? false;

        // Build feedback
        $emoji = $isCorrect ? '✅' : '❌';
        $feedback = "{$emoji} {$result['feedback']}\n";

        // Check if game is finished
        if ($game->isFinished($newState)) {
            $score = $game->getScore($newState);
            $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);

            // Update profile
            $profile->addScore($score);
            $profile->increment('total_games');
            $profile->update(['last_played_at' => now(), 'current_game' => null]);

            if ($score > 0) {
                $profile->incrementStreak();
            } else {
                $profile->resetStreak();
            }

            // Check achievements
            $newAchievements = $this->checkAchievements($context, $profile, $gameType, $newState);

            $this->clearActiveGame($context);
            $this->clearPendingContext($context);

            $correct = $newState['correct'] ?? 0;
            $total = $newState['total'] ?? 0;

            $reply = $feedback . "\n";
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "🏁 *Partie terminee !*\n\n";
            $reply .= "🎯 Score : *{$correct}/{$total}* (+{$score} points)\n";
            $reply .= "⭐ Total : *{$profile->score}* points\n";
            $reply .= "🔥 Streak : *{$profile->streak}* parties\n";

            if (!empty($newAchievements)) {
                $reply .= "\n🏆 *Nouveaux succes :*\n";
                foreach ($newAchievements as $ach) {
                    $emoji = GameAchievement::getEmoji($ach);
                    $label = GameAchievement::getLabel($ach);
                    $reply .= "  {$emoji} {$label}\n";
                }
            }

            $reply .= "\n🎮 Nouveau jeu → _jeu trivia/enigme/mots/20questions_\n";
            $reply .= "🏆 Classement → _leaderboard_\n";
            $reply .= "📊 Mes stats → _mes stats_";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Game completed', ['type' => $gameType, 'score' => $score, 'correct' => $correct, 'total' => $total]);

            return AgentResult::reply($reply, ['action' => 'game_complete', 'score' => $score]);
        }

        // Continue game — next question
        $this->setActiveGame($context, $newState);

        $questionText = $game->formatQuestion($newState);
        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'game_answer', ['game_type' => $gameType], 60);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'game_answer', 'correct' => $isCorrect]);
    }

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
            $phone = substr($profile->user_phone, -4);
            $reply .= "{$rank} ***{$phone} — {$profile->score} pts ({$profile->total_games} parties, streak max: {$profile->best_streak})\n";
        }

        $reply .= "\n📊 _mes stats_ — Tes stats perso";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Leaderboard viewed');

        return AgentResult::reply($reply, ['action' => 'leaderboard']);
    }

    private function handleStatus(AgentContext $context): AgentResult
    {
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);

        $achievementCount = count($profile->achievements ?? []);
        $totalAchievements = count(GameAchievement::ACHIEVEMENTS);

        $reply = "📊 *Mon Profil GameMaster*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "⭐ Score total : *{$profile->score}* points\n";
        $reply .= "🎮 Parties jouees : *{$profile->total_games}*\n";
        $reply .= "🔥 Streak actuel : *{$profile->streak}*\n";
        $reply .= "💎 Meilleur streak : *{$profile->best_streak}*\n";
        $reply .= "🏆 Succes : *{$achievementCount}/{$totalAchievements}*\n";

        if ($profile->last_played_at) {
            $reply .= "📅 Derniere partie : {$profile->last_played_at->format('d/m/Y H:i')}\n";
        }

        $reply .= "\n🎮 _jeu trivia_ — Nouveau jeu\n";
        $reply .= "🏆 _leaderboard_ — Classement\n";
        $reply .= "🏅 _achievements_ — Mes succes";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Status viewed');

        return AgentResult::reply($reply, ['action' => 'status']);
    }

    private function handleAchievements(AgentContext $context): AgentResult
    {
        $profile = UserGameProfile::getOrCreate($context->from, $context->agent->id);
        $unlocked = $profile->achievements ?? [];

        $reply = "🏅 *Mes Succes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach (GameAchievement::ACHIEVEMENTS as $key => $ach) {
            $isUnlocked = in_array($key, $unlocked);
            $status = $isUnlocked ? $ach['emoji'] : '🔒';
            $label = $isUnlocked ? $ach['label'] : "???";
            $desc = $isUnlocked ? $ach['description'] : '???';
            $reply .= "{$status} *{$label}* — {$desc}\n";
        }

        $reply .= "\n" . count($unlocked) . "/" . count(GameAchievement::ACHIEVEMENTS) . " debloques";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'achievements']);
    }

    private function handleAbandon(AgentContext $context): AgentResult
    {
        $this->clearActiveGame($context);
        $this->clearPendingContext($context);

        $reply = "🛑 Partie abandonnee.\n\n";
        $reply .= "🎮 _jeu trivia_ — Nouveau jeu\n";
        $reply .= "📊 _mes stats_ — Mon profil";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Game abandoned');

        return AgentResult::reply($reply, ['action' => 'game_abandon']);
    }

    private function handleListGames(AgentContext $context): AgentResult
    {
        $games = GameFactory::getAvailableGames();

        $reply = "🎮 *Jeux Disponibles*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($games as $key => $game) {
            $reply .= "{$game['emoji']} *{$game['label']}* — {$game['description']}\n";
            $reply .= "   → _jeu {$key}_\n\n";
        }

        $reply .= "🏆 _leaderboard_ — Classement\n";
        $reply .= "📊 _mes stats_ — Mon profil\n";
        $reply .= "🏅 _achievements_ — Mes succes";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'list_games']);
    }

    private function checkAchievements(AgentContext $context, UserGameProfile $profile, string $gameType, array $gameState): array
    {
        $newAchievements = [];

        // First win
        if ($profile->total_games === 1 && ($gameState['correct'] ?? 0) > 0) {
            if ($profile->unlockAchievement('first_win')) {
                $newAchievements[] = 'first_win';
                $this->saveAchievement($context, 'first_win', $gameType);
            }
        }

        // 10 wins
        if ($profile->total_games >= 10) {
            if ($profile->unlockAchievement('ten_wins')) {
                $newAchievements[] = 'ten_wins';
                $this->saveAchievement($context, 'ten_wins', $gameType);
            }
        }

        // 50 wins
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
        if ($gameType === 'trivia' && ($gameState['correct'] ?? 0) === ($gameState['total'] ?? 0) && ($gameState['total'] ?? 0) > 0) {
            if ($profile->unlockAchievement('trivia_master')) {
                $newAchievements[] = 'trivia_master';
                $this->saveAchievement($context, 'trivia_master', $gameType);
            }
        }

        // Riddle solver (10 riddles correct total)
        if ($gameType === 'riddle') {
            $totalRiddles = GameAchievement::where('user_phone', $context->from)
                ->where('game_type', 'riddle')
                ->count();
            if ($totalRiddles >= 10 || ($gameState['correct'] ?? 0) >= 3) {
                if ($profile->unlockAchievement('riddle_solver')) {
                    $newAchievements[] = 'riddle_solver';
                    $this->saveAchievement($context, 'riddle_solver', $gameType);
                }
            }
        }

        return $newAchievements;
    }

    private function saveAchievement(AgentContext $context, string $key, string $gameType): void
    {
        GameAchievement::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'achievement_key' => $key,
            'game_type' => $gameType,
            'unlocked_at' => now(),
        ]);
    }
}
