<?php

namespace App\Services\Agents;

use App\Models\Quiz;
use App\Models\QuizScore;
use App\Services\AgentContext;
use App\Services\QuizEngine;
use Illuminate\Support\Carbon;

class InteractiveQuizAgent extends BaseAgent
{
    public function name(): string
    {
        return 'interactive_quiz';
    }

    public function description(): string
    {
        return 'Quizz ludiques avec scoring, catégories variées et classement';
    }

    public function keywords(): array
    {
        return ['quiz', 'quizz', 'trivia', 'question', 'challenge', 'qcm', 'culture générale', 'devinette'];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower($context->body ?? '');
        return (bool) preg_match('/\b(quiz|quizz|trivia|challenge|qcm)\b/iu', $body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Check for active quiz first
        $activeQuiz = $this->getActiveQuiz($context);

        // Parse commands
        if (preg_match('/^\/quiz\s+leaderboard/iu', $lower) || preg_match('/\b(leaderboard|classement|top\s*\d*)\b/iu', $lower)) {
            return $this->handleLeaderboard($context);
        }

        if (preg_match('/^\/quiz\s+(mystats|mes\s*stats|stats)/iu', $lower) || preg_match('/\b(mes\s*stats|my\s*stats|statistiques)\b/iu', $lower)) {
            return $this->handleMyStats($context);
        }

        if (preg_match('/^\/quiz\s+history/iu', $lower) || preg_match('/\b(historique|history)\b.*quiz/iu', $lower)) {
            return $this->handleHistory($context);
        }

        if (preg_match('/\bchallenge\s+@?(\S+)/iu', $body, $challengeMatch)) {
            return $this->handleChallenge($context, $challengeMatch[1]);
        }

        if (preg_match('/\b(stop|quit|abandon|arr[eê]t|annul)/iu', $lower) && $activeQuiz) {
            return $this->handleAbandon($context, $activeQuiz);
        }

        if (preg_match('/\b(cat[eé]gories?|categories?|topics?|th[eè]mes?)\b/iu', $lower)) {
            return $this->handleCategories($context);
        }

        // If active quiz, treat message as answer
        if ($activeQuiz) {
            return $this->handleAnswer($context, $activeQuiz, $body);
        }

        // Start new quiz
        return $this->handleStartQuiz($context, $body);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if ($pendingContext['type'] === 'quiz_answer') {
            $activeQuiz = $this->getActiveQuiz($context);
            if ($activeQuiz) {
                return $this->handleAnswer($context, $activeQuiz, trim($context->body ?? ''));
            }
            $this->clearPendingContext($context);
        }

        return null;
    }

    private function getActiveQuiz(AgentContext $context): ?Quiz
    {
        return Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->latest()
            ->first();
    }

    private function handleStartQuiz(AgentContext $context, string $body): AgentResult
    {
        // Parse category from message
        $category = null;
        if (preg_match('/(?:quiz|quizz|trivia)\s+@?(\w+)/iu', $body, $m)) {
            $category = QuizEngine::resolveCategory($m[1]);
        }
        if (!$category && preg_match('/\b(histoire|science|pop|sport|geo|tech|history|culture|cinema|geographie|technologie|informatique)\b/iu', $body, $m)) {
            $category = QuizEngine::resolveCategory($m[1]);
        }

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        // Generate quiz
        $quizData = QuizEngine::generateQuiz($category);

        $quiz = Quiz::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'category' => $quizData['category'],
            'difficulty' => 'medium',
            'questions' => $quizData['questions'],
            'current_question_index' => 0,
            'correct_answers' => 0,
            'status' => 'playing',
            'started_at' => now(),
        ]);

        $firstQuestion = $quiz->getCurrentQuestion();
        $questionText = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $intro = "🎯 *Quiz {$quizData['category_label']}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "{$quiz->getTotalQuestions()} questions — Bonne chance !\n\n";

        $reply = $intro . $questionText;

        // Set pending context so follow-up answers come back here
        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quiz started', ['category' => $quizData['category'], 'questions' => $quiz->getTotalQuestions()]);

        return AgentResult::reply($reply, ['action' => 'quiz_start', 'category' => $quizData['category']]);
    }

    private function handleAnswer(AgentContext $context, Quiz $quiz, string $answer): AgentResult
    {
        $currentQuestion = $quiz->getCurrentQuestion();

        if (!$currentQuestion) {
            $this->clearPendingContext($context);
            return $this->handleStartQuiz($context, '/quiz');
        }

        $isCorrect = QuizEngine::checkAnswer($currentQuestion, $answer);
        $correctText = QuizEngine::getCorrectAnswerText($currentQuestion);

        $newCorrect = $quiz->correct_answers + ($isCorrect ? 1 : 0);
        $newIndex = $quiz->current_question_index + 1;

        if ($isCorrect) {
            $feedback = "✅ *Correct !*\n";
        } else {
            $feedback = "❌ *Raté !*\nLa bonne réponse était : *{$correctText}*\n";
        }

        $feedback .= "Score actuel : {$newCorrect}/{$newIndex}\n";

        // Check if quiz is finished
        if ($newIndex >= $quiz->getTotalQuestions()) {
            // Quiz completed
            $quiz->update([
                'correct_answers' => $newCorrect,
                'current_question_index' => $newIndex,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $timeTaken = $quiz->started_at ? (int) now()->diffInSeconds($quiz->started_at) : null;

            // Save score
            QuizScore::create([
                'user_phone' => $context->from,
                'agent_id' => $context->agent->id,
                'quiz_id' => $quiz->id,
                'category' => $quiz->category,
                'score' => $newCorrect,
                'total_questions' => $quiz->getTotalQuestions(),
                'time_taken' => $timeTaken,
                'completed_at' => now(),
            ]);

            $scoreText = QuizEngine::formatScore($newCorrect, $quiz->getTotalQuestions());
            $timeStr = $timeTaken ? gmdate('i:s', $timeTaken) : '??';

            $reply = $feedback . "\n";
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "🏁 *Quiz terminé !*\n\n";
            $reply .= "{$scoreText}\n";
            $reply .= "⏱ Temps : {$timeStr}\n\n";
            $reply .= "📊 /quiz mystats — Tes statistiques\n";
            $reply .= "🏆 /quiz leaderboard — Classement\n";
            $reply .= "🔄 /quiz — Nouveau quiz\n";

            $this->clearPendingContext($context);
            $this->sendText($context->from, $reply);
            $this->log($context, 'Quiz completed', ['score' => $newCorrect, 'total' => $quiz->getTotalQuestions(), 'time' => $timeTaken]);

            return AgentResult::reply($reply, ['action' => 'quiz_complete', 'score' => $newCorrect, 'total' => $quiz->getTotalQuestions()]);
        }

        // Next question
        $quiz->update([
            'correct_answers' => $newCorrect,
            'current_question_index' => $newIndex,
        ]);

        $nextQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText = QuizEngine::formatQuestion($nextQuestion, $newIndex + 1, $quiz->getTotalQuestions());

        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'quiz_answer', 'correct' => $isCorrect, 'progress' => "{$newIndex}/{$quiz->getTotalQuestions()}"]);
    }

    private function handleLeaderboard(AgentContext $context): AgentResult
    {
        $leaderboard = QuizScore::getLeaderboard($context->agent->id, 10);

        if ($leaderboard->isEmpty()) {
            $reply = "🏆 *Classement Quiz*\n\nAucun score enregistré pour l'instant.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'leaderboard_empty']);
        }

        $reply = "🏆 *Classement Quiz — Top 10*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($leaderboard as $i => $entry) {
            $rank = $medals[$i] ?? ($i + 1) . '.';
            $phone = substr($entry->user_phone, -4);
            $avgPct = round($entry->avg_percentage);
            $reply .= "{$rank} ***{$phone} — {$entry->total_score} pts ({$entry->quizzes_played} quiz, {$avgPct}% avg)\n";
        }

        $reply .= "\n📊 /quiz mystats — Tes stats perso";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Leaderboard viewed');

        return AgentResult::reply($reply, ['action' => 'leaderboard']);
    }

    private function handleMyStats(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ($stats['quizzes_played'] === 0) {
            $reply = "📊 *Mes Stats Quiz*\n\nTu n'as pas encore complété de quiz.\nLance ton premier quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'stats_empty']);
        }

        $categories = QuizEngine::getCategories();
        $favCat = $categories[$stats['favorite_category']] ?? $stats['favorite_category'] ?? '—';

        $reply = "📊 *Mes Stats Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 Quiz joués : *{$stats['quizzes_played']}*\n";
        $reply .= "⭐ Score total : *{$stats['total_score']}*\n";
        $reply .= "📈 Moyenne : *{$stats['avg_percentage']}%*\n";
        $reply .= "🏅 Meilleur : *{$stats['best_score']}%*\n";
        $reply .= "🔥 Streak : *{$stats['current_streak']}* quiz réussis d'affilée\n";
        $reply .= "❤️ Catégorie préférée : {$favCat}\n";
        $reply .= "\n🏆 /quiz leaderboard — Classement\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Stats viewed');

        return AgentResult::reply($reply, ['action' => 'my_stats']);
    }

    private function handleHistory(AgentContext $context): AgentResult
    {
        $scores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get();

        if ($scores->isEmpty()) {
            $reply = "📜 *Historique Quiz*\n\nAucun quiz terminé.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'history_empty']);
        }

        $categories = QuizEngine::getCategories();

        $reply = "📜 *Historique Quiz — 10 derniers*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($scores as $score) {
            $cat = $categories[$score->category] ?? $score->category;
            $pct = $score->getPercentage();
            $date = $score->completed_at?->format('d/m H:i') ?? '—';
            $timeStr = $score->time_taken ? gmdate('i:s', $score->time_taken) : '—';
            $emoji = $pct >= 80 ? '🌟' : ($pct >= 50 ? '✅' : '❌');

            $reply .= "{$emoji} {$cat} — {$score->score}/{$score->total_questions} ({$pct}%) — {$timeStr} — {$date}\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'History viewed');

        return AgentResult::reply($reply, ['action' => 'history']);
    }

    private function handleAbandon(AgentContext $context, Quiz $quiz): AgentResult
    {
        $quiz->update(['status' => 'abandoned']);
        $this->clearPendingContext($context);

        $reply = "🛑 Quiz abandonné.\n\n";
        $reply .= "Score partiel : {$quiz->correct_answers}/{$quiz->current_question_index}\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quiz abandoned');

        return AgentResult::reply($reply, ['action' => 'quiz_abandon']);
    }

    private function handleChallenge(AgentContext $context, string $targetUser): AgentResult
    {
        // Normalize target phone (add @s.whatsapp.net if needed)
        $target = $targetUser;
        if (!str_contains($target, '@')) {
            $target = preg_replace('/[^0-9]/', '', $target);
            $target .= '@s.whatsapp.net';
        }

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        // Generate quiz for the challenger
        $quizData = QuizEngine::generateQuiz(null);

        $quiz = Quiz::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'category' => $quizData['category'],
            'difficulty' => 'medium',
            'questions' => $quizData['questions'],
            'current_question_index' => 0,
            'correct_answers' => 0,
            'status' => 'playing',
            'challenger_phone' => $target,
            'started_at' => now(),
        ]);

        $firstQuestion = $quiz->getCurrentQuestion();
        $questionText = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $intro = "🎯 *Quiz Challenge !*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "Tu défies *" . substr($target, 0, -16) . "* !\n";
        $intro .= "{$quiz->getTotalQuestions()} questions — À toi de jouer en premier !\n\n";

        $reply = $intro . $questionText;

        // Notify the challenged user
        $challengerName = $context->senderName ?? substr($context->from, 0, -16);
        $notif = "⚔️ *Défi Quiz !*\n\n";
        $notif .= "*{$challengerName}* te défie à un quiz !\n";
        $notif .= "Envoie /quiz pour relever le défi ! 🎯";
        $this->sendText($target, $notif);

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Challenge started', ['target' => $target]);

        return AgentResult::reply($reply, ['action' => 'quiz_challenge', 'target' => $target]);
    }

    private function handleCategories(AgentContext $context): AgentResult
    {
        $categories = QuizEngine::getCategories();

        $reply = "🎯 *Catégories de Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($categories as $key => $label) {
            $reply .= "{$label} — /quiz {$key}\n";
        }

        $reply .= "\n🎲 /quiz — Quiz aléatoire (mix de tout)\n";
        $reply .= "🏆 /quiz leaderboard — Classement";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'categories']);
    }
}
