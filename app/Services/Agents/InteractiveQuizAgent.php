<?php

namespace App\Services\Agents;

use App\Models\Quiz;
use App\Models\QuizScore;
use App\Services\AgentContext;
use App\Services\QuizEngine;

class InteractiveQuizAgent extends BaseAgent
{
    private const DIFFICULTY_QUESTION_COUNT = [
        'easy'   => 3,
        'medium' => 5,
        'hard'   => 7,
    ];

    private const DIFFICULTY_LABELS = [
        'easy'   => '🟢 Facile',
        'medium' => '🟡 Moyen',
        'hard'   => '🔴 Difficile',
    ];

    public function name(): string
    {
        return 'interactive_quiz';
    }

    public function description(): string
    {
        return 'Quizz ludiques avec scoring, catégories variées, difficultés, classement, maîtrise, coaching IA et question du jour';
    }

    public function keywords(): array
    {
        return [
            'quiz', 'quizz', 'trivia', 'question', 'challenge', 'qcm',
            'culture générale', 'devinette', 'jeu de questions',
            'jouer quiz', 'faire un quiz', 'lancer quiz',
            'leaderboard', 'classement quiz', 'mes stats quiz',
            'question du jour', 'daily quiz',
            'marathon quiz', 'quiz marathon',
            'rejouer quiz', 'replay quiz', 'recommencer quiz',
            'quiz suggest', 'suggère quiz', 'quiz perso', 'quiz personnalisé',
            'améliorer quiz', 'ma catégorie faible',
            'expliquer quiz', 'quiz explain', 'explications quiz',
            'partager score', 'share quiz', 'quiz share',
            'quiz ia', 'quiz sur', 'quiz thème', 'quiz génère', 'quiz custom',
            'quiz streak', 'ma série quiz', 'série quiz', 'quiz serie',
            'quiz chrono', 'chrono quiz', 'quiz speed', 'quiz chronométré',
            'quiz objectif', 'objectif quiz', 'quiz goal', 'mon objectif quiz',
            'quiz rank', 'mon rang', 'mon classement', 'quiz position', 'ma position',
            'quiz progress', 'quiz progression', 'ma progression', 'progress quiz',
            'quiz vs', 'vs quiz', 'comparer quiz', 'quiz duel stats',
            'quiz tip', 'tip quiz', 'quiz conseil', 'quiz astuce', 'conseils quiz',
            'reprendre quiz', 'quiz reprendre', 'resume quiz', 'quiz resume', 'continuer quiz',
            'quiz fun', 'fun quiz', 'faits quiz', 'curiosités quiz', 'quiz curiosité', 'quiz faits',
            'quiz mini', 'mini quiz', 'quiz flash', 'flash quiz', 'quiz 2 questions',
            'quiz badges', 'badges quiz', 'quiz trophées', 'trophées quiz', 'mes badges', 'récompenses quiz',
            'quiz hebdo', 'hebdo quiz', 'classement semaine', 'top semaine', 'quiz weekly', 'weekly quiz', 'semaine quiz',
            'quiz today', 'today quiz', 'quiz aujourd\'hui', 'résumé quiz', 'résumé du jour', 'quiz journée', 'mon quiz aujourd\'hui',
            'quiz record', 'record quiz', 'mes records', 'meilleurs scores', 'meilleures performances', 'top scores',
            'quiz wrong', 'quiz correction', 'quiz erreurs', 'mes erreurs quiz', 'revoir erreurs', 'quiz ratés',
            'quiz trending', 'trending quiz', 'quiz tendances', 'tendances quiz', 'quiz populaire', 'catégories populaires',
            'quiz catstat', 'catstat quiz', 'stats catégorie quiz', 'quiz stat cat', 'quiz detail cat',
            'quiz coach', 'coach quiz', 'coaching quiz', 'quiz coaching', 'mon coach quiz', 'plan quiz',
            'quiz timing', 'timing quiz', 'quiz temps', 'temps quiz', 'vitesse quiz', 'quiz vitesse', 'quiz rapidité',
            'quiz favori', 'favori quiz', 'quiz fav', 'quiz préféré', 'ma catégorie préférée', 'quiz favorite',
            'quiz historique', 'historique quiz', 'quiz history cat', 'historique catégorie',
            'quiz niveau', 'niveau quiz', 'quel niveau', 'difficulté recommandée', 'quiz difficulty',
            'quiz revanche', 'revanche quiz', 'quiz rematch', 'rematch quiz', 'mêmes questions',
            'quiz random', 'random quiz', 'quiz aléatoire', 'quiz hasard', 'quiz surprise', 'surprise quiz',
            'quiz export', 'export quiz', 'exporter quiz', 'quiz résumé complet', 'quiz bilan',
            'quiz diffstats', 'diffstats quiz', 'quiz niveaux', 'stats difficulté', 'quiz par difficulté', 'performance difficulté',
            'quiz mastery', 'mastery quiz', 'quiz maîtrise', 'maîtrise quiz', 'niveaux catégories', 'quiz niveau cat',
            'quiz duel', 'duel quiz', 'mes duels', 'quiz défis', 'défis quiz', 'quiz duels',
            'quiz recommande', 'recommande quiz', 'quiz recommandation', 'quoi jouer quiz', 'que jouer quiz', 'quiz recommend',
            'quiz calendrier', 'calendrier quiz', 'quiz calendar', 'calendar quiz', 'activité quiz mois',
            'quiz compare', 'comparer quiz', 'quiz comparer', 'compare quiz', 'quiz vs catégorie',
            'quiz recap', 'recap quiz', 'récap quiz', 'quiz récap', 'bilan hebdo', 'récap hebdo',
            'quiz defi', 'defi quiz', 'défi du jour', 'défi quiz', 'quiz défi', 'defi du jour',
            'quiz comeback', 'comeback quiz', 'quiz amélioration', 'ma plus grosse amélioration',
            'quiz next', 'next quiz', 'quiz suivant', 'quoi faire quiz', 'quiz quoi faire',
            'quiz focus', 'focus quiz', 'quiz révision', 'révision quiz', 'quiz retravailler', 'quiz erreurs passées',
            'quiz quickstats', 'quickstats quiz', 'quiz qstats', 'stats rapide quiz', 'quiz stats rapide',
        ];
    }

    public function version(): string
    {
        return '1.24.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower($context->body ?? '');
        return (bool) preg_match('/\b(quiz|quizz|trivia|challenge|qcm|devinette|leaderboard|classement|marathon|suggest|perso|daily|quotidien|partager|expliquer|share|chrono|objectif|goal|rang|rank|progress|progression|tip|conseil|astuce|reprendre|resume|curiosit|fun|faits|mini|flash|badge|badges|trophée|trophees|récompense|hebdo|weekly|aujourd\'?hui|journée|résumé|record|correction|erreurs|trending|tendances?|populaire|catstat|coach|coaching|timing|vitesse|rapidité|favori|fav|préféré|favorite|historique|random|aléatoire|hasard|surprise|export|exporter|bilan|diffstats|niveaux?|maîtrise|mastery|duel|duels|défis?|recomman\w*|revanche|rematch|calendrier|calendar|compare[r]?|r[eé]cap|defi|comeback|am[eé]lioration|next|suivant|quoi\s*faire|focus|r[eé]vision|quickstats|qstats)\b/iu', $body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->handleInner($context);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('InteractiveQuizAgent handle() exception', [
                'from'  => $context->from,
                'body'  => mb_substr($context->body ?? '', 0, 300),
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            $this->log($context, 'EXCEPTION: ' . $e->getMessage(), [
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ], 'error');

            $reply = "⚠️ *Quiz* — Une erreur interne est survenue.\nRéessaie dans un instant ou lance un nouveau quiz avec /quiz !";
            $this->sendText($context->from, $reply);

            return AgentResult::reply($reply, ['error' => $e->getMessage()]);
        }
    }

    private function handleInner(AgentContext $context): AgentResult
    {
        $body  = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Check for active quiz first
        $activeQuiz = $this->getActiveQuiz($context);

        // --- Commands ---

        if (preg_match('/^\/quiz\s+help/iu', $lower) || preg_match('/\b(aide|help)\b.*quiz|quiz.*\b(aide|help)\b/iu', $lower)) {
            return $this->handleHelp($context);
        }

        if (preg_match('/^\/quiz\s+leaderboard/iu', $lower) || preg_match('/\b(leaderboard|classement)\b/iu', $lower)) {
            // Category-specific leaderboard: /quiz top histoire
            if (preg_match('/\btop\s+(\w+)\b/iu', $lower, $catMatch)) {
                $category = QuizEngine::resolveCategory($catMatch[1]);
                if ($category) {
                    return $this->handleCategoryLeaderboard($context, $category);
                }
            }
            return $this->handleLeaderboard($context);
        }

        if (preg_match('/^\/quiz\s+(mystats|mes\s*stats|stats)/iu', $lower) || preg_match('/\b(mes\s*stats|my\s*stats|statistiques)\b/iu', $lower)) {
            return $this->handleMyStats($context);
        }

        if (preg_match('/^\/quiz\s+history/iu', $lower) || preg_match('/\b(historique|history)\b.*quiz/iu', $lower)) {
            return $this->handleHistory($context);
        }

        if (preg_match('/^\/quiz\s+categories/iu', $lower) || preg_match('/\b(cat[eé]gories?|categories?|topics?|th[eè]mes?)\b/iu', $lower)) {
            return $this->handleCategories($context);
        }

        // Daily question
        if (preg_match('/^\/quiz\s+daily/iu', $lower) || preg_match('/\b(daily|quotidien|question\s+du\s+jour)\b/iu', $lower)) {
            return $this->handleDailyQuestion($context);
        }

        // Review last quiz
        if (preg_match('/^\/quiz\s+review/iu', $lower) || preg_match('/\b(review|revoir|correction|corrig[eé])\b.*quiz|quiz.*\b(review|revoir|correction|corrig[eé])\b/iu', $lower)) {
            return $this->handleReview($context);
        }

        // Category-specific leaderboard via /quiz top <cat>
        if (preg_match('/^\/quiz\s+top\s+(\w+)/iu', $lower, $topMatch)) {
            $category = QuizEngine::resolveCategory($topMatch[1]);
            if ($category) {
                return $this->handleCategoryLeaderboard($context, $category);
            }
        }

        // Rank — user's position in the global leaderboard
        if (preg_match('/^\/quiz\s+rank/iu', $lower) || preg_match('/\b(rang|rank|position)\b.*quiz|quiz.*\b(rang|rank|position)\b|\bmon\s+(rang|classement|rank)\b/iu', $lower)) {
            return $this->handleRank($context);
        }

        // Progress — 7-day progression report
        if (preg_match('/^\/quiz\s+progress/iu', $lower) || preg_match('/\b(progress|progression)\b.*quiz|quiz.*\b(progress|progression)\b|\bma\s+progression\b/iu', $lower)) {
            return $this->handleProgressReport($context);
        }

        if (preg_match('/\bchallenge\s+@?(\S+)/iu', $body, $challengeMatch)) {
            return $this->handleChallenge($context, $challengeMatch[1]);
        }

        if (preg_match('/^\/quiz\s+marathon/iu', $lower) || (preg_match('/\b(marathon)\b/iu', $lower) && !$activeQuiz)) {
            return $this->handleMarathon($context);
        }

        if (preg_match('/^\/quiz\s+(replay|rejouer|recommencer)/iu', $lower) || (preg_match('/\b(rejouer|replay|recommencer)\b/iu', $lower) && !$activeQuiz)) {
            return $this->handleReplay($context);
        }

        if (preg_match('/^\/quiz\s+(suggest|suggère|suggestion)/iu', $lower) || preg_match('/\bquiz\s+(suggest|suggère|suggestion)\b/iu', $lower)) {
            return $this->handleSuggest($context);
        }

        if (preg_match('/^\/quiz\s+(perso|personnalis[eé]|personalized)/iu', $lower) || preg_match('/\bquiz\s+(perso|personnalis[eé])\b/iu', $lower)) {
            return $this->handlePersonalized($context);
        }

        // Explain wrong answers from last quiz
        if (preg_match('/^\/quiz\s+explain/iu', $lower) || preg_match('/\b(expliquer|explain|explications?)\b.*quiz|quiz.*\b(expliquer|explain|explications?)\b/iu', $lower)) {
            return $this->handleExplain($context);
        }

        // Share last score as Wordle-style card
        if (preg_match('/^\/quiz\s+share/iu', $lower) || preg_match('/\bquiz.*\b(partager|share)\b|\b(partager|share)\b.*quiz/iu', $lower)) {
            return $this->handleShare($context);
        }

        // Daily streak
        if (preg_match('/\b(streak|s[eé]rie)\b.*quiz|quiz.*\b(streak|s[eé]rie)\b|^\/quiz\s+streak/iu', $lower)) {
            return $this->handleDailyStreak($context);
        }

        // Chrono / Speed mode
        if (preg_match('/^\/quiz\s+(chrono|speed|chronom[eè]tre?)/iu', $lower) || preg_match('/\bquiz\s+(chrono|speed)\b/iu', $lower)) {
            return $this->handleChronoMode($context);
        }

        // Daily quiz objective
        if (preg_match('/^\/quiz\s+(objectif|goal)(?:\s+(\d+))?/iu', $lower, $goalMatch)
            || preg_match('/\bquiz\s+(objectif|goal)(?:\s+(\d+))?\b/iu', $lower, $goalMatch)) {
            $goalCount = isset($goalMatch[2]) && $goalMatch[2] !== '' ? (int) $goalMatch[2] : null;
            return $this->handleGoal($context, $goalCount);
        }

        // Vs — head-to-head stats comparison with another user
        if (preg_match('/^\/quiz\s+vs\s+@?(\S+)/iu', $body, $vsMatch) || (!$activeQuiz && preg_match('/\bquiz\s+vs\s+@?(\S+)/iu', $body, $vsMatch))) {
            return $this->handleVs($context, $vsMatch[1]);
        }

        // Tip — AI study tips for a category
        if (preg_match('/^\/quiz\s+(tip|conseil|astuce)(?:\s+(\w+))?/iu', $body, $tipMatch)) {
            $tipCategory = (isset($tipMatch[2]) && $tipMatch[2] !== '') ? QuizEngine::resolveCategory($tipMatch[2]) : null;
            return $this->handleTip($context, $tipCategory);
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+(tip|conseil|astuce)\b/iu', $lower)) {
            return $this->handleTip($context, null);
        }

        // Favori — quick-launch quiz in user's most-played category
        if (preg_match('/^\/quiz\s+(favori|fav|favorite|préféré)\b/iu', $lower) || preg_match('/\bquiz\s+(favori|fav|préféré)\b/iu', $lower)) {
            return $this->handleFavoriteQuiz($context);
        }

        // Category-filtered history: /quiz historique science
        if (preg_match('/^\/quiz\s+historique\s+(\w+)/iu', $body, $histCatMatch)) {
            $resolvedHistCat = QuizEngine::resolveCategory($histCatMatch[1]);
            if ($resolvedHistCat) {
                return $this->handleCategoryHistory($context, $resolvedHistCat);
            }
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+historique\s+(\w+)\b/iu', $body, $histCatMatch2)) {
            $resolvedHistCat2 = QuizEngine::resolveCategory($histCatMatch2[1]);
            if ($resolvedHistCat2) {
                return $this->handleCategoryHistory($context, $resolvedHistCat2);
            }
        }

        // Coach — AI personalized coaching session
        if (preg_match('/^\/quiz\s+(coach|coaching)\b/iu', $lower) || preg_match('/\bquiz\s+(coach|coaching)\b/iu', $lower)) {
            return $this->handleCoach($context);
        }

        // Timing — response time analytics
        if (preg_match('/^\/quiz\s+(timing|temps|vitesse|rapidit[eé])\b/iu', $lower) || preg_match('/\bquiz\s+(timing|temps|vitesse|rapidit[eé])\b/iu', $lower)) {
            return $this->handleTiming($context);
        }

        // Niveau — smart difficulty recommendation based on performance
        if (preg_match('/^\/quiz\s+(niveau|difficulty|difficult[eé]|quel\s*niveau)\b/iu', $lower) || preg_match('/\bquiz\s+(niveau|difficult[eé]\s+recommand[eé]e)\b/iu', $lower)) {
            return $this->handleNiveau($context);
        }

        // Revanche — replay the exact same questions from last completed quiz
        if (preg_match('/^\/quiz\s+(revanche|rematch)\b/iu', $lower) || (!$activeQuiz && preg_match('/\b(revanche|rematch)\b.*quiz|quiz.*\b(revanche|rematch)\b|\bm[eê]mes\s+questions\b/iu', $lower))) {
            return $this->handleRevanche($context);
        }

        // Random category — pick a category the user hasn't played recently
        if (preg_match('/^\/quiz\s+(random|al[eé]atoire|hasard|surprise)\b/iu', $lower) || (!$activeQuiz && preg_match('/\bquiz\s+(random|al[eé]atoire|hasard|surprise)\b/iu', $lower))) {
            return $this->handleRandomCategory($context);
        }

        // Export — full stats summary card
        if (preg_match('/^\/quiz\s+(export|exporter|bilan)\b/iu', $lower) || preg_match('/\bquiz\s+(export|exporter|bilan)\b/iu', $lower)) {
            return $this->handleExport($context);
        }

        // Duel results — past challenge outcomes
        if (preg_match('/^\/quiz\s+(duel|duels?|d[eé]fis?)\b/iu', $lower) || preg_match('/\b(mes?\s+duels?|quiz\s+duel|quiz\s+d[eé]fis?)\b/iu', $lower)) {
            return $this->handleDuelResults($context);
        }

        // Smart recommend — personalized quiz recommendation
        if (preg_match('/^\/quiz\s+(recomman\w*|quoi\s+jouer)\b/iu', $lower) || preg_match('/\bquiz\s+(recomman\w*|quoi\s+jouer)\b|\bque\s+(jouer|faire)\s+quiz\b/iu', $lower)) {
            return $this->handleRecommend($context);
        }

        // Difficulty stats — performance breakdown by difficulty level
        if (preg_match('/^\/quiz\s+(diffstats|niveaux)\b/iu', $lower) || preg_match('/\bquiz\s+(diffstats|niveaux)\b|\bstats?\s+difficult[eé]\b|\bperformance\s+difficult[eé]\b|\bquiz\s+par\s+difficult[eé]\b/iu', $lower)) {
            return $this->handleDifficultyStats($context);
        }

        // Category mastery — mastery levels per category
        if (preg_match('/^\/quiz\s+(mastery|ma[iî]trise)\b/iu', $lower) || preg_match('/\bquiz\s+(mastery|ma[iî]trise)\b|\bniveaux?\s+cat[eé]gories?\b/iu', $lower)) {
            return $this->handleMastery($context);
        }

        // Calendar — monthly activity heatmap
        if (preg_match('/^\/quiz\s+(calendrier|calendar)\b/iu', $lower) || preg_match('/\bquiz\s+(calendrier|calendar)\b|\bcalendrier\s+quiz\b/iu', $lower)) {
            return $this->handleCalendar($context);
        }

        // Compare — side-by-side comparison of two categories
        if (preg_match('/^\/quiz\s+(compare[r]?|comparer)\s+(\w+)\s+(?:vs?\s+)?(\w+)/iu', $body, $compareMatch)) {
            $cmp1 = QuizEngine::resolveCategory($compareMatch[2]);
            $cmp2 = QuizEngine::resolveCategory($compareMatch[3]);
            if ($cmp1 && $cmp2 && $cmp1 !== $cmp2) {
                return $this->handleCompare($context, $cmp1, $cmp2);
            }
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+compare[r]?\s+(\w+)\s+(?:vs?\s+)?(\w+)\b/iu', $body, $compareMatch2)) {
            $cmp1 = QuizEngine::resolveCategory($compareMatch2[1]);
            $cmp2 = QuizEngine::resolveCategory($compareMatch2[2]);
            if ($cmp1 && $cmp2 && $cmp1 !== $cmp2) {
                return $this->handleCompare($context, $cmp1, $cmp2);
            }
        }

        // Weekly recap — AI-generated weekly performance summary
        if (preg_match('/^\/quiz\s+(recap|r[eé]cap|bilan\s*hebdo)\b/iu', $lower) || preg_match('/\bquiz\s+(recap|r[eé]cap)\b|\br[eé]cap\s+hebdo\b/iu', $lower)) {
            return $this->handleWeeklyRecap($context);
        }

        // Snapshot — quick performance card (no LLM, instant)
        if (preg_match('/^\/quiz\s+(snapshot|snap|aper[cç]u)\b/iu', $lower) || preg_match('/\bquiz\s+(snapshot|snap|aper[cç]u)\b/iu', $lower)) {
            return $this->handleSnapshot($context);
        }

        // Défi du jour — daily adaptive challenge with community comparison
        if (preg_match('/^\/quiz\s+(defi|d[eé]fi)\b/iu', $lower) || preg_match('/\bquiz\s+(defi|d[eé]fi)\b|\bd[eé]fi\s+du\s+jour\b/iu', $lower)) {
            return $this->handleDefiDuJour($context);
        }

        // Focus — spaced repetition quiz on previously failed questions
        if (preg_match('/^\/quiz\s+focus(?:\s+(\w+))?\b/iu', $body, $focusMatch) || preg_match('/\bquiz\s+focus(?:\s+(\w+))?\b/iu', $body, $focusMatch)) {
            $focusCat = (isset($focusMatch[1]) && $focusMatch[1] !== '') ? QuizEngine::resolveCategory($focusMatch[1]) : null;
            return $this->handleFocus($context, $focusCat);
        }

        // Quickstats — instant compact stat card (no LLM)
        if (preg_match('/^\/quiz\s+(quickstats|qstats|stats?\s*rapide)\b/iu', $lower) || preg_match('/\bquiz\s+(quickstats|qstats)\b/iu', $lower)) {
            return $this->handleQuickStats($context);
        }

        // Comeback — show biggest improvement category
        if (preg_match('/^\/quiz\s+(comeback|am[eé]lioration|progr[eè]s)\b/iu', $lower) || preg_match('/\bquiz\s+(comeback|am[eé]lioration)\b/iu', $lower)) {
            return $this->handleComeback($context);
        }

        // Next — smart context-aware suggestion
        if (preg_match('/^\/quiz\s+(next|suivant|quoi\s*faire)\b/iu', $lower) || preg_match('/\bquiz\s+(next|suivant|quoi\s*faire)\b/iu', $lower)) {
            return $this->handleNext($context);
        }

        // AI-generated quiz on a custom topic — must come AFTER other commands to avoid false matches
        if (preg_match('/^\/quiz\s+(ia|sur|th[eè]me|g[eé]n[eè]re?|custom)\s+(.+)/iu', $body, $aiMatch)) {
            return $this->handleAIQuiz($context, trim($aiMatch[2]));
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+(?:ia|sur|th[eè]me|g[eé]n[eè]re?)\s+(.+)/iu', $body, $aiMatch2)) {
            return $this->handleAIQuiz($context, trim($aiMatch2[1]));
        }

        // Resume in-progress quiz
        if (preg_match('/^\/quiz\s+(resume|reprendre|continuer)/iu', $lower)
            || preg_match('/\b(reprendre|resume|continuer)\b.*quiz|quiz.*\b(reprendre|resume|continuer)\b/iu', $lower)) {
            return $this->handleResume($context);
        }

        // Fun facts — AI-generated trivia for a category
        if (preg_match('/^\/quiz\s+(fun|faits|curiosit[eé]s?)(?:\s+(\w+))?/iu', $lower, $funMatch)) {
            $funCat = (isset($funMatch[2]) && $funMatch[2] !== '') ? QuizEngine::resolveCategory($funMatch[2]) : null;
            return $this->handleFunFacts($context, $funCat);
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+(fun|faits|curiosit[eé]s?)\b/iu', $lower)) {
            return $this->handleFunFacts($context, null);
        }

        // Mini quiz — 2 questions ultra-rapid
        if (preg_match('/^\/quiz\s+(mini|flash)/iu', $lower) || (!$activeQuiz && preg_match('/\bquiz\s+(mini|flash)\b/iu', $lower))) {
            return $this->handleMiniQuiz($context);
        }

        // Badges — achievement system
        if (preg_match('/^\/quiz\s+(badges?|trophées?|récompenses?)/iu', $lower) || preg_match('/\b(mes\s+badges|badges?\s+quiz|quiz\s+badges?|trophées?\s+quiz)\b/iu', $lower)) {
            return $this->handleBadges($context);
        }

        // Weekly leaderboard
        if (preg_match('/^\/quiz\s+(hebdo|weekly)/iu', $lower) || preg_match('/\b(hebdo|weekly)\b.*quiz|quiz.*\b(hebdo|weekly)\b|\b(classement|top)\s+semaine\b/iu', $lower)) {
            return $this->handleWeeklyLeaderboard($context);
        }

        // Daily summary
        if (preg_match('/^\/quiz\s+(today|aujourd\'?hui|journ[eé]e|r[eé]sum[eé])/iu', $lower) || preg_match('/\bquiz\s+(today|aujourd\'?hui|journ[eé]e)\b|\br[eé]sum[eé]\s+(quiz|du\s+jour)\b/iu', $lower)) {
            return $this->handleDailySummary($context);
        }

        // Personal records — top 5 best quiz scores
        if (preg_match('/^\/quiz\s+(record|records|palmar[eè]s)/iu', $lower) || preg_match('/\b(quiz\s+record|mes\s+records|meilleurs?\s+scores?|top\s+scores?)\b/iu', $lower)) {
            return $this->handlePersonalRecords($context);
        }

        // Correction quiz — practice questions the user historically got wrong
        if (preg_match('/^\/quiz\s+(wrong|correction|erreurs?|rat[eé]s?)/iu', $lower) || preg_match('/\bquiz\s+(wrong|correction|erreurs?)\b|\bmes\s+erreurs\s+quiz\b/iu', $lower)) {
            return $this->handleWrongQuiz($context);
        }

        // Trending — community top categories this week
        if (preg_match('/^\/quiz\s+(trending|tendances?|populaires?)/iu', $lower) || preg_match('/\bquiz\s+(trending|tendances?|populaire)\b|\bcatégories?\s+populaires?\b/iu', $lower)) {
            return $this->handleTrending($context);
        }

        // CatStat — detailed per-category stats for the user
        if (preg_match('/^\/quiz\s+catstat\s+(\w+)/iu', $body, $catstatMatch)) {
            $resolvedCatStat = QuizEngine::resolveCategory($catstatMatch[1]);
            if ($resolvedCatStat) {
                return $this->handleCatStat($context, $resolvedCatStat);
            }
        }
        if (!$activeQuiz && preg_match('/\bquiz\s+catstat\s+(\w+)\b/iu', $body, $catstatMatch2)) {
            $resolvedCatStat2 = QuizEngine::resolveCategory($catstatMatch2[1]);
            if ($resolvedCatStat2) {
                return $this->handleCatStat($context, $resolvedCatStat2);
            }
        }

        if (preg_match('/\b(stop|quit|abandon|arr[eê]t|annul)/iu', $lower) && $activeQuiz) {
            return $this->handleAbandon($context, $activeQuiz);
        }

        // --- Hint during active quiz ---
        if ($activeQuiz && preg_match('/\b(indice|hint|clue)\b/iu', $lower)) {
            return $this->handleHint($context, $activeQuiz);
        }

        // --- Skip during active quiz ---
        if ($activeQuiz && preg_match('/\b(passer|sauter|skip|suivant|next|je\s+sais\s+pas|aucune\s+id[eé]e)\b/iu', $lower)) {
            return $this->handleSkip($context, $activeQuiz);
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
        if (!in_array($pendingContext['type'], ['quiz_answer', 'daily_answer'])) {
            return null;
        }

        $lower = mb_strtolower(trim($context->body ?? ''));

        // Allow user to break out of quiz context with non-quiz commands
        if (preg_match('/^\/(?!quiz\b)/iu', $lower)) {
            $this->clearPendingContext($context);
            return null; // Let orchestrator re-route to appropriate agent
        }

        // Resolve the active quiz: for daily, look up by ID; otherwise find the latest active
        $activeQuiz = $pendingContext['type'] === 'daily_answer'
            ? Quiz::find($pendingContext['data']['quiz_id'] ?? 0)
            : $this->getActiveQuiz($context);

        if (!$activeQuiz || $activeQuiz->status !== 'playing') {
            $this->clearPendingContext($context);
            return null;
        }

        if (preg_match('/\b(stop|quit|abandon|arr[eê]t|annul)\b/iu', $lower)) {
            return $this->handleAbandon($context, $activeQuiz);
        }

        if (preg_match('/\b(indice|hint|clue)\b/iu', $lower)) {
            return $this->handleHint($context, $activeQuiz);
        }

        if (preg_match('/\b(passer|sauter|skip|suivant|next|je\s+sais\s+pas|aucune\s+id[eé]e)\b/iu', $lower)) {
            return $this->handleSkip($context, $activeQuiz);
        }

        return $this->handleAnswer($context, $activeQuiz, trim($context->body ?? ''));
    }

    private function getActiveQuiz(AgentContext $context): ?Quiz
    {
        return Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->latest()
            ->first();
    }

    private function parseDifficulty(string $lower): string
    {
        if (preg_match('/\b(facile|easy|simple|d[eé]butant|novice|basique)\b/iu', $lower)) {
            return 'easy';
        }
        if (preg_match('/\b(difficile|hard|expert|avanc[eé]|dur|impossible|extr[eê]me|challenge)\b/iu', $lower)) {
            return 'hard';
        }
        if (preg_match('/\b(moyen|normal|interm[eé]diaire|medium|standard)\b/iu', $lower)) {
            return 'medium';
        }
        return 'medium';
    }

    private function getDifficultyLabel(string $difficulty): string
    {
        return self::DIFFICULTY_LABELS[$difficulty] ?? self::DIFFICULTY_LABELS['medium'];
    }

    private function getQuestionCount(string $difficulty): int
    {
        return self::DIFFICULTY_QUESTION_COUNT[$difficulty] ?? 5;
    }

    /**
     * Determine whether the user's input looks like a valid quiz answer
     * (A/B/C/D, 1-4, or a text matching one of the options).
     */
    private function isValidAnswerFormat(string $answer, array $question): bool
    {
        $lower = mb_strtolower(trim($answer));

        if (in_array($lower, ['a', 'b', 'c', 'd'])) {
            return true;
        }

        $num = intval($lower);
        if ($num >= 1 && $num <= 4) {
            return true;
        }

        foreach ($question['options'] as $option) {
            if (mb_strtolower($option) === $lower) {
                return true;
            }
        }

        // Fuzzy match: accept if answer is very close to an option (≥80% similarity)
        if (mb_strlen($lower) >= 3) {
            foreach ($question['options'] as $option) {
                $optLower = mb_strtolower($option);
                similar_text($lower, $optLower, $pct);
                if ($pct >= 80.0) {
                    return true;
                }
                if (mb_strlen($lower) >= 4 && str_contains($optLower, $lower)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize any valid answer input (A/B/C/D, 1-4, or option text) to an uppercase letter.
     * Supports fuzzy text matching for user-friendly input.
     * Returns '?' if the input cannot be recognized.
     */
    private function normalizeAnswerToLetter(string $answer, array $question): string
    {
        $lower   = mb_strtolower(trim($answer));
        $letters = ['A', 'B', 'C', 'D'];

        if (in_array($lower, ['a', 'b', 'c', 'd'])) {
            return strtoupper($lower);
        }

        $num = intval($lower);
        if ($num >= 1 && $num <= 4) {
            return $letters[$num - 1];
        }

        // Exact text match
        foreach ($question['options'] as $i => $option) {
            if (mb_strtolower($option) === $lower) {
                return $letters[$i] ?? '?';
            }
        }

        // Fuzzy match: find best matching option (≥80% similarity)
        if (mb_strlen($lower) >= 3) {
            $bestIdx = -1;
            $bestPct = 0.0;
            foreach ($question['options'] as $i => $option) {
                $optLower = mb_strtolower($option);
                similar_text($lower, $optLower, $pct);
                if (mb_strlen($lower) >= 4 && str_contains($optLower, $lower)) {
                    $pct = max($pct, 85.0);
                }
                if ($pct > $bestPct && $pct >= 80.0) {
                    $bestPct = $pct;
                    $bestIdx = $i;
                }
            }
            if ($bestIdx >= 0) {
                return $letters[$bestIdx] ?? '?';
            }
        }

        return '?';
    }

    /**
     * Generate a hint by revealing one eliminated wrong answer.
     */
    private function generateHint(array $question, string $difficulty = 'medium'): string
    {
        $letters      = ['A', 'B', 'C', 'D'];
        $correctIdx   = $question['answer'];
        $wrongIndices = [];

        foreach ($question['options'] as $i => $option) {
            if ($i !== $correctIdx) {
                $wrongIndices[] = $i;
            }
        }

        if (empty($wrongIndices)) {
            return "Pas d'indice disponible.";
        }

        // On easy difficulty, eliminate 2 wrong options (50/50); otherwise 1
        $eliminateCount = ($difficulty === 'easy' && count($wrongIndices) >= 2) ? 2 : 1;
        shuffle($wrongIndices);
        $eliminated = array_slice($wrongIndices, 0, $eliminateCount);

        $lines = [];
        foreach ($eliminated as $idx) {
            $letter = $letters[$idx] ?? '?';
            $option = $question['options'][$idx] ?? '?';
            $lines[] = "La réponse *{$letter}. {$option}* est éliminée ❌";
        }

        $remaining = 4 - $eliminateCount;
        return implode("\n", $lines) . "\nIl reste {$remaining} options.";
    }

    /**
     * Build a compact ✅/❌/⏭️ breakdown string from all answered questions.
     * Uses mb_substr to safely handle multi-byte emoji characters.
     */
    private function buildScoreBreakdown(Quiz $quiz): string
    {
        $emojis = '';
        foreach ($quiz->questions as $q) {
            if (!($q['user_answered'] ?? false)) {
                continue;
            }
            if ($q['user_skipped'] ?? false) {
                $emojis .= '⏭️';
            } elseif ($q['user_correct'] ?? false) {
                $emojis .= '✅';
            } else {
                $emojis .= '❌';
            }
        }
        return $emojis ?: '';
    }

    private function handleStartQuiz(AgentContext $context, string $body): AgentResult
    {
        $lower = mb_strtolower($body);

        $difficulty = $this->parseDifficulty($lower);
        $quickMode  = (bool) preg_match('/\b(rapide|quick|court|flash)\b/iu', $lower);

        $category = null;
        if (preg_match('/(?:quiz|quizz|trivia)\s+@?(\w+)/iu', $body, $m)) {
            $category = QuizEngine::resolveCategory($m[1]);
        }
        if (!$category && preg_match('/\b(histoire|science|pop|sport|geo|tech|history|culture|cinema|geographie|technologie|informatique|musique|film|football|sports|pays|capitale|programming|geography|technology)\b/iu', $body, $m)) {
            $category = QuizEngine::resolveCategory($m[1]);
        }

        $count = $quickMode ? 3 : $this->getQuestionCount($difficulty);

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quizData  = QuizEngine::generateQuiz($category, $count);
        $questions = array_map(function (array $q) {
            $q['hints_used']    = 0;
            $q['user_answered'] = false;
            $q['user_correct']  = false;
            $q['user_skipped']  = false;
            return $q;
        }, $quizData['questions']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => $quizData['category'],
            'difficulty'             => $difficulty,
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $diffLabel = $this->getDifficultyLabel($difficulty);
        $modeLabel = $quickMode ? ' ⚡ Rapide' : '';

        $intro  = "🎯 *Quiz {$quizData['category_label']}*{$modeLabel}\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "{$diffLabel} — {$quiz->getTotalQuestions()} questions\n";
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter la question\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quiz started', [
            'category'   => $quizData['category'],
            'difficulty' => $difficulty,
            'questions'  => $quiz->getTotalQuestions(),
            'quick'      => $quickMode,
        ]);

        return AgentResult::reply($reply, ['action' => 'quiz_start', 'category' => $quizData['category'], 'difficulty' => $difficulty]);
    }

    private function handleAnswer(AgentContext $context, Quiz $quiz, string $answer): AgentResult
    {
        $currentQuestion = $quiz->getCurrentQuestion();

        if (!$currentQuestion) {
            $this->clearPendingContext($context);
            $quiz->update(['status' => 'completed', 'completed_at' => now()]);
            $reply = "⚠️ Ce quiz semble terminé ou corrompu.\n🔄 /quiz — Lancer un nouveau quiz !";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Quiz had no current question', ['quiz_id' => $quiz->id]);
            return AgentResult::reply($reply, ['action' => 'quiz_no_question']);
        }

        if (!$this->isValidAnswerFormat($answer, $currentQuestion)) {
            $questionText = QuizEngine::formatQuestion($currentQuestion, $quiz->current_question_index + 1, $quiz->getTotalQuestions());
            $reply  = "❓ *Réponse non reconnue.* Réponds avec *A*, *B*, *C* ou *D*\n";
            $reply .= "(ou *passer* pour sauter, *stop* pour abandonner)\n\n";
            $reply .= $questionText;
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'quiz_invalid_answer']);
        }

        // Compute per-question response time if shown_at was recorded
        $shownAt          = $currentQuestion['shown_at'] ?? null;
        $responseTimeSecs = $shownAt
            ? max(1, (int) now()->diffInSeconds(\Carbon\Carbon::parse($shownAt)))
            : null;

        $isCorrect   = QuizEngine::checkAnswer($currentQuestion, $answer);
        $correctText = QuizEngine::getCorrectAnswerText($currentQuestion);

        $hintsUsed    = (int) ($currentQuestion['hints_used'] ?? 0);
        $pointsEarned = $isCorrect ? max(0, 1 - $hintsUsed) : 0;

        $newCorrect = $quiz->correct_answers + $pointsEarned;
        $newIndex   = $quiz->current_question_index + 1;

        $userChoice = $this->normalizeAnswerToLetter($answer, $currentQuestion);

        $questions                             = $quiz->questions;
        $idx                                   = $quiz->current_question_index;
        $questions[$idx]['user_answered']      = true;
        $questions[$idx]['user_correct']       = $isCorrect;
        $questions[$idx]['user_choice']        = $userChoice;
        $questions[$idx]['time_taken_secs']    = $responseTimeSecs;

        // Compute consecutive correct streak for motivational feedback
        $streakBonus = '';
        if ($isCorrect) {
            $streak = 0;
            for ($si = $idx; $si >= 0; $si--) {
                if (($questions[$si]['user_answered'] ?? false)
                    && ($questions[$si]['user_correct'] ?? false)
                    && !($questions[$si]['user_skipped'] ?? false)) {
                    $streak++;
                } else {
                    break;
                }
            }
            if ($streak >= 3) {
                $streakBonus = $streak >= 5
                    ? "🔥🔥 *{$streak} bonnes réponses d'affilée !*\n"
                    : "🔥 *{$streak} bonnes réponses d'affilée !*\n";
            }
        }

        // Detect consecutive wrong answers for adaptive difficulty hint
        $wrongStreak = 0;
        if (!$isCorrect) {
            for ($wi = $idx; $wi >= 0; $wi--) {
                if (($questions[$wi]['user_answered'] ?? false)
                    && !($questions[$wi]['user_correct'] ?? false)
                    && !($questions[$wi]['user_skipped'] ?? false)) {
                    $wrongStreak++;
                } else {
                    break;
                }
            }
        }

        $timeLabel = '';
        if ($responseTimeSecs !== null) {
            $timeEmoji = $responseTimeSecs <= 8 ? '⚡' : ($responseTimeSecs <= 25 ? '⏱' : '🐢');
            $timeLabel = " — {$timeEmoji} {$responseTimeSecs}s";
        }

        if ($isCorrect) {
            $feedback = ($pointsEarned === 0)
                ? "✅ *Correct !* (0 pt — indice utilisé){$timeLabel}\n"
                : "✅ *Correct !* +1 pt{$timeLabel}\n";
            $feedback .= $streakBonus;
        } else {
            $letters    = ['A', 'B', 'C', 'D'];
            $choiceIdx  = array_search($userChoice, $letters);
            $choiceOpt  = ($choiceIdx !== false && isset($currentQuestion['options'][$choiceIdx]))
                ? "{$userChoice}. {$currentQuestion['options'][$choiceIdx]}"
                : $userChoice;
            $feedback  = "❌ *Raté !* Ta réponse : {$choiceOpt}{$timeLabel}\n";
            $feedback .= "✔️ Bonne réponse : *{$correctText}*\n";
        }

        $feedback .= "Score : {$newCorrect}/{$newIndex}\n";

        // Adaptive difficulty hint after 3 consecutive wrong answers
        if ($wrongStreak >= 3 && $quiz->difficulty !== 'easy') {
            $feedback .= "\n💡 _3 erreurs d'affilée — essaie un quiz plus facile avec_ `/quiz facile` !\n";
        }

        // Check if quiz is finished
        if ($newIndex >= $quiz->getTotalQuestions()) {
            $quiz->update([
                'correct_answers'        => $newCorrect,
                'current_question_index' => $newIndex,
                'questions'              => $questions,
                'status'                 => 'completed',
                'completed_at'           => now(),
            ]);

            $timeTaken = $quiz->started_at ? (int) now()->diffInSeconds($quiz->started_at) : null;

            QuizScore::create([
                'user_phone'      => $context->from,
                'agent_id'        => $context->agent->id,
                'quiz_id'         => $quiz->id,
                'category'        => $quiz->category,
                'score'           => $newCorrect,
                'total_questions' => $quiz->getTotalQuestions(),
                'time_taken'      => $timeTaken,
                'completed_at'    => now(),
            ]);

            $this->clearPendingContext($context);
            $this->log($context, 'Quiz completed', [
                'score'    => $newCorrect,
                'total'    => $quiz->getTotalQuestions(),
                'time'     => $timeTaken,
                'category' => $quiz->category,
            ]);

            // Daily quiz — special completion message with community stats
            if ($quiz->category === 'daily') {
                return $this->buildDailyCompletionResult($context, $quiz->fresh(), $feedback, $newCorrect);
            }

            $pct       = $quiz->getTotalQuestions() > 0 ? round(($newCorrect / $quiz->getTotalQuestions()) * 100) : 0;
            $scoreText = QuizEngine::formatScore($newCorrect, $quiz->getTotalQuestions());
            $timeStr   = $timeTaken ? gmdate('i:s', $timeTaken) : '??';
            $freshQuiz = $quiz->fresh();
            $breakdown = $freshQuiz ? $this->buildScoreBreakdown($freshQuiz) : '';

            // Compute per-question timing summary
            $allTimes = array_filter(array_column($questions, 'time_taken_secs'));
            $timingLine = '';
            if (count($allTimes) >= 2) {
                $fastestTime = min($allTimes);
                $avgTime     = round(array_sum($allTimes) / count($allTimes));
                $timingLine  = "⚡ Plus rapide : {$fastestTime}s | Moy. : {$avgTime}s/question\n";
            }

            $reply  = $feedback . "\n";
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "🏁 *Quiz terminé !*\n\n";
            $reply .= "{$scoreText}\n";
            if ($breakdown) {
                $reply .= "📋 {$breakdown}\n";
            }
            $reply .= "⏱ Temps : {$timeStr}\n";
            if ($timingLine) {
                $reply .= $timingLine;
            }

            $personalBestMsg = $this->checkPersonalBest($context, $quiz->category, $pct);
            if ($personalBestMsg) {
                $reply .= $personalBestMsg;
            }

            $newDailyStreak  = $this->computeDailyStreak($context);
            $badgeUnlock     = $this->checkNewBadgeUnlock($context, (int) QuizScore::where('user_phone', $context->from)->where('agent_id', $context->agent->id)->count(), $pct, $newDailyStreak);
            if ($badgeUnlock) {
                $reply .= $badgeUnlock;
            }

            $reply .= "\n🧠 /quiz explain — Explications IA des erreurs\n";
            $reply .= "📤 /quiz share — Partager ton score\n";
            $reply .= "🔍 /quiz review — Revoir les réponses\n";
            $reply .= "📊 /quiz mystats — Tes statistiques\n";
            $reply .= "🏆 /quiz leaderboard — Classement\n";
            $reply .= "🔄 /quiz — Nouveau quiz\n";
            $reply .= "🎯 /quiz perso — Quiz dans ta catégorie la plus faible\n";
            $reply .= "⚡ /quiz rapide — Quiz express (3 questions)";

            $this->sendText($context->from, $reply);

            return AgentResult::reply($reply, ['action' => 'quiz_complete', 'score' => $newCorrect, 'total' => $quiz->getTotalQuestions()]);
        }

        // Next question
        $quiz->update([
            'correct_answers'        => $newCorrect,
            'current_question_index' => $newIndex,
            'questions'              => $questions,
        ]);

        $freshQuizNext = $quiz->fresh();
        if (!$freshQuizNext) {
            $reply = $feedback . "\n⚠️ Erreur de chargement. Relance avec /quiz resume.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'quiz_refresh_fail']);
        }
        $this->setQuestionShownAt($freshQuizNext, $newIndex);
        $nextQuestion = $freshQuizNext->getCurrentQuestion();
        if (!$nextQuestion) {
            $this->clearPendingContext($context);
            $freshQuizNext->update(['status' => 'completed', 'completed_at' => now()]);
            $reply = $feedback . "\n⚠️ Ce quiz semble terminé.\n🔄 /quiz — Lancer un nouveau quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'quiz_next_question_missing']);
        }
        $questionText = QuizEngine::formatQuestion($nextQuestion, $newIndex + 1, $quiz->getTotalQuestions());

        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'quiz_answer', 'correct' => $isCorrect, 'progress' => "{$newIndex}/{$quiz->getTotalQuestions()}"]);
    }

    /**
     * Skip the current question (counts as incorrect, reveals answer, advances to next).
     */
    private function handleSkip(AgentContext $context, Quiz $quiz): AgentResult
    {
        $currentQuestion = $quiz->getCurrentQuestion();

        if (!$currentQuestion) {
            $this->clearPendingContext($context);
            $quiz->update(['status' => 'completed', 'completed_at' => now()]);
            $reply = "⚠️ Ce quiz semble terminé.\n🔄 /quiz — Lancer un nouveau quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'skip_no_question']);
        }

        $correctText = QuizEngine::getCorrectAnswerText($currentQuestion);
        $newIndex    = $quiz->current_question_index + 1;

        $questions                        = $quiz->questions;
        $idx                              = $quiz->current_question_index;
        $questions[$idx]['user_answered'] = true;
        $questions[$idx]['user_correct']  = false;
        $questions[$idx]['user_skipped']  = true;

        $feedback  = "⏭️ *Question passée.*\n";
        $feedback .= "La réponse était : *{$correctText}*\n";
        $feedback .= "Score : {$quiz->correct_answers}/{$newIndex}\n";

        if ($newIndex >= $quiz->getTotalQuestions()) {
            $quiz->update([
                'current_question_index' => $newIndex,
                'questions'              => $questions,
                'status'                 => 'completed',
                'completed_at'           => now(),
            ]);

            $timeTaken = $quiz->started_at ? (int) now()->diffInSeconds($quiz->started_at) : null;

            QuizScore::create([
                'user_phone'      => $context->from,
                'agent_id'        => $context->agent->id,
                'quiz_id'         => $quiz->id,
                'category'        => $quiz->category,
                'score'           => $quiz->correct_answers,
                'total_questions' => $quiz->getTotalQuestions(),
                'time_taken'      => $timeTaken,
                'completed_at'    => now(),
            ]);

            $pctSkip    = $quiz->getTotalQuestions() > 0 ? round(($quiz->correct_answers / $quiz->getTotalQuestions()) * 100) : 0;
            $scoreText  = QuizEngine::formatScore($quiz->correct_answers, $quiz->getTotalQuestions());
            $timeStr    = $timeTaken ? gmdate('i:s', $timeTaken) : '??';
            $freshQuiz  = $quiz->fresh();
            $breakdown  = $freshQuiz ? $this->buildScoreBreakdown($freshQuiz) : '';

            $reply  = $feedback . "\n";
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "🏁 *Quiz terminé !*\n\n";
            $reply .= "{$scoreText}\n";
            if ($breakdown) {
                $reply .= "📋 {$breakdown}\n";
            }
            $reply .= "⏱ Temps : {$timeStr}\n";

            $personalBestMsgSkip = $this->checkPersonalBest($context, $quiz->category, $pctSkip);
            if ($personalBestMsgSkip) {
                $reply .= $personalBestMsgSkip;
            }

            $reply .= "\n";
            $reply .= "🔍 /quiz review — Revoir les réponses\n";
            $reply .= "📊 /quiz mystats — Tes statistiques\n";
            $reply .= "🏆 /quiz leaderboard — Classement\n";
            $reply .= "🔄 /quiz rejouer — Même catégorie\n";
            $reply .= "🎯 /quiz perso — Catégorie la plus faible";

            $this->clearPendingContext($context);
            $this->sendText($context->from, $reply);
            $this->log($context, 'Quiz completed via skip', [
                'score' => $quiz->correct_answers,
                'total' => $quiz->getTotalQuestions(),
            ]);

            return AgentResult::reply($reply, ['action' => 'quiz_complete_skip']);
        }

        $quiz->update([
            'current_question_index' => $newIndex,
            'questions'              => $questions,
        ]);

        $freshQuizSkip = $quiz->fresh();
        $this->setQuestionShownAt($freshQuizSkip, $newIndex);
        $nextQuestion = $freshQuizSkip->getCurrentQuestion();
        $questionText = QuizEngine::formatQuestion($nextQuestion, $newIndex + 1, $quiz->getTotalQuestions());

        $reply = $feedback . "\n" . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Question skipped', ['question_index' => $idx]);

        return AgentResult::reply($reply, ['action' => 'quiz_skip', 'question_index' => $idx]);
    }

    private function handleHint(AgentContext $context, Quiz $quiz): AgentResult
    {
        $currentQuestion = $quiz->getCurrentQuestion();

        if (!$currentQuestion) {
            $reply = "❓ Aucune question active en cours.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'hint_error']);
        }

        $hintsUsed = (int) ($currentQuestion['hints_used'] ?? 0);

        if ($hintsUsed >= 1) {
            $reply  = "💡 Tu as déjà utilisé ton indice pour cette question.\n";
            $reply .= "Réponds avec *A*, *B*, *C* ou *D* — ou tape *passer* pour sauter.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'hint_already_used']);
        }

        $hint = $this->generateHint($currentQuestion, $quiz->difficulty ?? 'medium');

        $questions                     = $quiz->questions;
        $idx                           = $quiz->current_question_index;
        $questions[$idx]['hints_used'] = 1;
        $quiz->update(['questions' => $questions]);

        $questionText = QuizEngine::formatQuestion($currentQuestion, $idx + 1, $quiz->getTotalQuestions());

        $reply  = "💡 *Indice* (-1 pt si correct)\n\n";
        $reply .= "{$hint}\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= $questionText;

        $this->sendText($context->from, $reply);
        $this->log($context, 'Hint used', ['question_index' => $idx]);

        return AgentResult::reply($reply, ['action' => 'hint_given']);
    }

    private function handleLeaderboard(AgentContext $context): AgentResult
    {
        $leaderboard = QuizScore::getLeaderboard($context->agent->id, 10);

        if ($leaderboard->isEmpty()) {
            $reply = "🏆 *Classement Quiz*\n\nAucun score enregistré pour l'instant.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'leaderboard_empty']);
        }

        $reply  = "🏆 *Classement Quiz — Top 10*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($leaderboard as $i => $entry) {
            $rank   = $medals[$i] ?? ($i + 1) . '.';
            $phone  = '***' . substr($entry->user_phone, -4);
            $avgPct = round($entry->avg_percentage);
            $isMe   = $entry->user_phone === $context->from ? ' ← toi' : '';
            $reply .= "{$rank} *{$phone}* — {$entry->total_score} pts ({$entry->quizzes_played} quiz, {$avgPct}%){$isMe}\n";
        }

        $reply .= "\n📊 /quiz mystats — Tes stats perso\n";
        $reply .= "🏆 /quiz top <catégorie> — Classement par catégorie";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Leaderboard viewed');

        return AgentResult::reply($reply, ['action' => 'leaderboard']);
    }

    /**
     * Category-specific leaderboard (e.g. /quiz top histoire).
     */
    private function handleCategoryLeaderboard(AgentContext $context, string $category): AgentResult
    {
        $leaderboard = QuizScore::where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->selectRaw('user_phone, SUM(score) as total_score, COUNT(*) as quizzes_played, AVG(score * 100.0 / total_questions) as avg_percentage')
            ->groupBy('user_phone')
            ->orderByDesc('total_score')
            ->limit(10)
            ->get();

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$category] ?? $category;

        if ($leaderboard->isEmpty()) {
            $reply = "🏆 *Classement — {$catLabel}*\n\nAucun score pour cette catégorie encore.\nLance `/quiz {$category}` pour être le premier !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'category_leaderboard_empty']);
        }

        $reply  = "🏆 *Classement {$catLabel} — Top 10*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($leaderboard as $i => $entry) {
            $rank   = $medals[$i] ?? ($i + 1) . '.';
            $phone  = '***' . substr($entry->user_phone, -4);
            $avgPct = round($entry->avg_percentage);
            $isMe   = $entry->user_phone === $context->from ? ' ← toi' : '';
            $reply .= "{$rank} *{$phone}* — {$entry->total_score} pts ({$entry->quizzes_played} quiz, {$avgPct}%){$isMe}\n";
        }

        $reply .= "\n🏆 /quiz leaderboard — Classement général";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Category leaderboard viewed', ['category' => $category]);

        return AgentResult::reply($reply, ['action' => 'category_leaderboard', 'category' => $category]);
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
        $favCat     = $categories[$stats['favorite_category']] ?? $stats['favorite_category'] ?? '—';

        $streak      = (int) $stats['current_streak'];
        $streakEmoji = $streak >= 5 ? '🔥🔥' : ($streak >= 3 ? '🔥' : '⚡');

        $dailyStreak      = $this->computeDailyStreak($context);
        $dailyStreakEmoji  = $dailyStreak >= 7 ? '🌟' : ($dailyStreak >= 3 ? '🔥' : '📅');

        $reply  = "📊 *Mes Stats Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 Quiz joués : *{$stats['quizzes_played']}*\n";
        $reply .= "⭐ Score total : *{$stats['total_score']}*\n";
        $reply .= "📈 Moyenne : *{$stats['avg_percentage']}%*\n";
        $reply .= "🏅 Meilleur : *{$stats['best_score']}%*\n";
        $reply .= "{$streakEmoji} Streak bonnes rép. : *{$streak}* d'affilée\n";
        $reply .= "{$dailyStreakEmoji} Série quotidienne : *{$dailyStreak}* jour(s)\n";
        $reply .= "❤️ Catégorie préférée : {$favCat}\n";

        // Per-category breakdown (exclude 'daily' and 'mix' pseudo-categories)
        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix'])
            ->selectRaw('category, COUNT(*) as played, AVG(score * 100.0 / total_questions) as avg_pct')
            ->groupBy('category')
            ->orderByDesc('avg_pct')
            ->get();

        if ($catScores->isNotEmpty()) {
            $reply .= "\n📂 *Par catégorie :*\n";
            foreach ($catScores as $cs) {
                $label = $categories[$cs->category] ?? $cs->category;
                $avg   = round($cs->avg_pct);
                $bar   = $avg >= 80 ? '🌟' : ($avg >= 60 ? '👍' : ($avg >= 40 ? '😊' : '💪'));
                $reply .= "  {$bar} {$label} — {$cs->played} quiz, {$avg}%\n";
            }
        }

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

        $reply  = "📜 *Historique Quiz — 10 derniers*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($scores as $score) {
            $cat     = match ($score->category) {
                'daily'      => '📅 Quotidien',
                'mix'        => '🎲 Mix',
                'custom'     => '🤖 IA',
                'correction' => '🔁 Correction',
                default      => $categories[$score->category] ?? $score->category,
            };
            $pct     = $score->getPercentage();
            $date    = $score->completed_at?->format('d/m H:i') ?? '—';
            $timeStr = $score->time_taken ? gmdate('i:s', $score->time_taken) : '—';
            $emoji   = $pct >= 80 ? '🌟' : ($pct >= 50 ? '✅' : '❌');

            $reply .= "{$emoji} {$cat} — {$score->score}/{$score->total_questions} ({$pct}%) — {$timeStr} — {$date}\n";
        }

        $reply .= "\n🔍 /quiz review — Revoir le dernier quiz\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'History viewed');

        return AgentResult::reply($reply, ['action' => 'history']);
    }

    private function handleAbandon(AgentContext $context, Quiz $quiz): AgentResult
    {
        $quiz->update(['status' => 'abandoned']);
        $this->clearPendingContext($context);

        $answered = $quiz->current_question_index;
        $total    = $quiz->getTotalQuestions();
        $pctDone  = $total > 0 ? round(($answered / $total) * 100) : 0;

        $encouragement = match (true) {
            $pctDone >= 80 => "Tu y étais presque ! 💪",
            $pctDone >= 50 => "La moitié du chemin, continue ! 🎯",
            $answered > 0  => "La prochaine fois sera la bonne ! 🚀",
            default        => "Reviens quand tu es prêt ! 😊",
        };

        $reply  = "🛑 *Quiz abandonné.*\n\n";
        $reply .= "Score partiel : *{$quiz->correct_answers}/{$answered}* (sur {$total} questions)\n";
        $reply .= "{$encouragement}\n\n";
        $reply .= "🔄 /quiz — Nouveau quiz\n";
        $reply .= "🔁 /quiz rejouer — Même catégorie\n";
        $reply .= "⚡ /quiz rapide — Quiz express (3 questions)";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Quiz abandoned', ['progress' => "{$answered}/{$total}", 'pct_done' => $pctDone]);

        return AgentResult::reply($reply, ['action' => 'quiz_abandon']);
    }

    private function handleChallenge(AgentContext $context, string $targetUser): AgentResult
    {
        $target = $targetUser;
        if (!str_contains($target, '@')) {
            $target = preg_replace('/[^0-9]/', '', $target);
            if (empty($target)) {
                $reply = "⚠️ Numéro invalide. Utilise : *challenge +33612345678*";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'challenge_invalid']);
            }
            $target .= '@s.whatsapp.net';
        }

        if ($target === $context->from) {
            $reply = "😅 Tu ne peux pas te défier toi-même ! Tag un ami.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'challenge_self']);
        }

        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quizData  = QuizEngine::generateQuiz(null, 5);
        $questions = array_map(fn(array $q) => array_merge($q, [
            'hints_used'    => 0,
            'user_answered' => false,
            'user_correct'  => false,
            'user_skipped'  => false,
        ]), $quizData['questions']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => $quizData['category'],
            'difficulty'             => 'medium',
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'challenger_phone'       => $target,
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $targetDisplay = '***' . substr(preg_replace('/@.*/', '', $target), -4);

        $intro  = "🎯 *Quiz Challenge !*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "Tu défies *{$targetDisplay}* !\n";
        $intro .= "{$quiz->getTotalQuestions()} questions — À toi de jouer en premier !\n\n";

        $reply = $intro . $questionText;

        $categories     = QuizEngine::getCategories();
        $catLabel       = $categories[$quizData['category']] ?? '🎲 Mix';
        $challengerName = $context->senderName ?? ('***' . substr(preg_replace('/@.*/', '', $context->from), -4));
        $notif  = "⚔️ *Défi Quiz !*\n\n";
        $notif .= "*{$challengerName}* te défie à un quiz !\n";
        $notif .= "Catégorie : {$catLabel} — 5 questions\n\n";
        $notif .= "Envoie `/quiz` pour relever le défi et battre son score ! 🎯";
        $this->sendText($target, $notif);

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Challenge started', ['target' => $target]);

        return AgentResult::reply($reply, ['action' => 'quiz_challenge', 'target' => $target]);
    }

    private function handleCategories(AgentContext $context): AgentResult
    {
        $categories = QuizEngine::getCategories();

        $reply  = "🎯 *Catégories de Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($categories as $key => $label) {
            $reply .= "{$label} — `/quiz {$key}`\n";
        }

        $reply .= "\n*Difficultés disponibles :*\n";
        $reply .= "🟢 Facile (3 questions) — `/quiz facile`\n";
        $reply .= "🟡 Moyen (5 questions) — `/quiz`\n";
        $reply .= "🔴 Difficile (7 questions) — `/quiz difficile`\n";
        $reply .= "⚡ Rapide (3 questions mix) — `/quiz rapide`\n";
        $reply .= "📅 Question du Jour — `/quiz daily`\n";
        $reply .= "\n🏆 /quiz leaderboard — Classement";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'categories']);
    }

    /**
     * Question du Jour — same question for all users each day.
     * Uses the date as a deterministic PRNG seed.
     */
    private function handleDailyQuestion(AgentContext $context): AgentResult
    {
        $today = now()->toDateString();

        // Already answered today?
        $alreadyAnswered = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->whereIn('status', ['completed', 'abandoned'])
            ->exists();

        if ($alreadyAnswered) {
            return $this->handleDailyStats($context, $today);
        }

        // Already started but not answered?
        $activeDailyQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->where('status', 'playing')
            ->first();

        if ($activeDailyQuiz) {
            $currentQuestion = $activeDailyQuiz->getCurrentQuestion();
            $questionText    = QuizEngine::formatQuestion($currentQuestion, 1, 1);
            $reply  = "📅 *Question du Jour* — {$today}\n";
            $reply .= "━━━━━━━━━━━━━━━━\n\n";
            $reply .= "(Question déjà en cours)\n\n";
            $reply .= $questionText;
            $reply .= "\n\n_Une seule tentative par jour !_";
            $this->setPendingContext($context, 'daily_answer', ['quiz_id' => $activeDailyQuiz->id], 60);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'daily_resume']);
        }

        // Generate deterministic daily question
        $question = $this->getDailyQuestion($today);

        // Abandon any active regular quiz (but leave other daily intact)
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->where('category', '!=', 'daily')
            ->update(['status' => 'abandoned']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'daily',
            'difficulty'             => 'medium',
            'questions'              => [array_merge($question, [
                'hints_used'    => 0,
                'user_answered' => false,
                'user_correct'  => false,
                'user_skipped'  => false,
            ])],
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $questionText = QuizEngine::formatQuestion($question, 1, 1);

        $communityCount = Quiz::where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->whereIn('status', ['completed', 'abandoned'])
            ->count();

        $communityMsg = $communityCount > 0
            ? "👥 {$communityCount} personne(s) ont déjà répondu aujourd'hui.\n\n"
            : "🌅 Sois le premier à répondre aujourd'hui !\n\n";

        $reply  = "📅 *Question du Jour* — {$today}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= $communityMsg;
        $reply .= $questionText;
        $reply .= "\n\n_Une seule tentative par jour !_";

        $this->setPendingContext($context, 'daily_answer', ['quiz_id' => $quiz->id], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily question started', ['date' => $today]);

        return AgentResult::reply($reply, ['action' => 'daily_start']);
    }

    /**
     * Build the completion result for a daily quiz — shows community stats.
     */
    private function buildDailyCompletionResult(AgentContext $context, ?Quiz $freshQuiz, string $feedback, int $newCorrect): AgentResult
    {
        $today   = now()->toDateString();
        $correct = $newCorrect > 0;

        $completedToday = Quiz::where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->where('status', 'completed')
            ->get();

        $totalAnswered = $completedToday->count();
        $totalCorrect  = $completedToday->where('correct_answers', '>', 0)->count();
        $successRate   = $totalAnswered > 0 ? round(($totalCorrect / $totalAnswered) * 100) : 0;

        $dailyQ       = $this->getDailyQuestion($today);
        $correctAnswer = QuizEngine::getCorrectAnswerText($dailyQ);

        $reply  = $feedback . "\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "📅 *Question du Jour — Résultats*\n\n";
        $reply .= "✔️ Bonne réponse : *{$correctAnswer}*\n\n";
        $reply .= "👥 *Communauté aujourd'hui :*\n";
        $reply .= "  Participants : {$totalAnswered}\n";
        $reply .= "  Taux de réussite : {$successRate}%\n\n";
        $reply .= "🔄 Reviens demain pour une nouvelle question !\n";
        $reply .= "🎯 /quiz — Lancer un quiz maintenant";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'daily_complete', 'correct' => $correct]);
    }

    /**
     * Show results for today's daily question (when user already answered).
     */
    private function handleDailyStats(AgentContext $context, string $today): AgentResult
    {
        $completedToday = Quiz::where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->where('status', 'completed')
            ->get();

        $totalAnswered = $completedToday->count();
        $totalCorrect  = $completedToday->where('correct_answers', '>', 0)->count();
        $successRate   = $totalAnswered > 0 ? round(($totalCorrect / $totalAnswered) * 100) : 0;

        $myQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->orderByDesc('started_at')
            ->first();

        $myResult = ($myQuiz && $myQuiz->correct_answers > 0)
            ? '✅ Tu as eu la bonne réponse !'
            : '❌ Tu n\'as pas eu la bonne réponse.';

        $dailyQ        = $this->getDailyQuestion($today);
        $correctAnswer = QuizEngine::getCorrectAnswerText($dailyQ);

        $reply  = "📅 *Question du Jour* — Résultats du {$today}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "{$myResult}\n";
        $reply .= "✔️ Bonne réponse : *{$correctAnswer}*\n\n";
        $reply .= "👥 *Communauté :*\n";
        $reply .= "  Participants : {$totalAnswered}\n";
        $reply .= "  Taux de réussite : {$successRate}%\n\n";
        $reply .= "🔄 Reviens demain pour une nouvelle question !\n";
        $reply .= "🎯 /quiz — Lancer un quiz maintenant";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily stats viewed', ['date' => $today]);

        return AgentResult::reply($reply, ['action' => 'daily_stats']);
    }

    /**
     * Get a deterministic daily question based on date.
     * Uses crc32($date) to seed the PRNG consistently for all users on the same day.
     */
    private function getDailyQuestion(string $date): array
    {
        $seed = abs(crc32($date)); // abs to ensure non-negative across 32/64-bit PHP
        mt_srand($seed);
        $quizData = QuizEngine::generateQuiz(null, 1);
        mt_srand(); // Reset PRNG to avoid affecting other random operations

        $question             = $quizData['questions'][0];
        $question['category'] = $question['category'] ?? 'mix';

        return $question;
    }

    /**
     * Review the last completed quiz — show all questions with correct answers.
     */
    private function handleReview(AgentContext $context): AgentResult
    {
        $lastQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->whereNotIn('category', ['daily'])
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastQuiz) {
            $reply = "🔍 *Révision — Dernier Quiz*\n\nAucun quiz terminé à revoir.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'review_empty']);
        }

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$lastQuiz->category] ?? ($lastQuiz->category === 'mix' ? '🎲 Mix' : $lastQuiz->category);
        $date       = $lastQuiz->completed_at?->format('d/m/Y H:i') ?? '—';
        $score      = $lastQuiz->correct_answers;
        $total      = $lastQuiz->getTotalQuestions();

        $reply  = "🔍 *Révision — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "Score : {$score}/{$total} — {$date}\n\n";

        $letters = ['A', 'B', 'C', 'D'];
        foreach ($lastQuiz->questions as $i => $q) {
            $num          = $i + 1;
            $correctIdx   = $q['answer'];
            $correctLtr   = $letters[$correctIdx] ?? '?';
            $correctOpt   = $q['options'][$correctIdx] ?? '?';
            $userAnswered = $q['user_answered'] ?? false;
            $userCorrect  = $q['user_correct'] ?? false;
            $skipped      = $q['user_skipped'] ?? false;
            $userChoice   = $q['user_choice'] ?? null;

            $status = $skipped ? '⏭️' : ($userAnswered ? ($userCorrect ? '✅' : '❌') : '—');

            $reply .= "{$status} *Q{$num}.* {$q['question']}\n";
            $reply .= "   ✔️ {$correctLtr}. {$correctOpt}\n";
            if (!$userCorrect && !$skipped && $userChoice && $userChoice !== $correctLtr) {
                $choiceIdx = array_search($userChoice, $letters);
                $choiceOpt = ($choiceIdx !== false && isset($q['options'][$choiceIdx])) ? $q['options'][$choiceIdx] : '';
                if ($choiceOpt) {
                    $reply .= "   ✗ {$userChoice}. {$choiceOpt} _(ta réponse)_\n";
                }
            }
            $reply .= "\n";
        }

        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Review viewed', ['quiz_id' => $lastQuiz->id]);

        return AgentResult::reply($reply, ['action' => 'review']);
    }

    /**
     * Marathon mode — 10 questions mix, hard difficulty, tracks total time.
     */
    private function handleMarathon(AgentContext $context): AgentResult
    {
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quizData  = QuizEngine::generateQuiz(null, 10);
        $questions = array_map(fn(array $q) => array_merge($q, [
            'hints_used'    => 0,
            'user_answered' => false,
            'user_correct'  => false,
            'user_skipped'  => false,
        ]), $quizData['questions']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'mix',
            'difficulty'             => 'hard',
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, 10);

        $reply  = "🏃 *Quiz Marathon — 10 Questions*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "🔴 Difficile — Mix toutes catégories\n";
        $reply .= "🔥 Enchaine les bonnes réponses pour des bonus !\n";
        $reply .= "💡 *indice* → indice (-1 pt) | *passer* → sauter\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 60);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Marathon started', ['questions' => 10]);

        return AgentResult::reply($reply, ['action' => 'marathon_start']);
    }

    /**
     * Replay — restart a quiz in the same category as the last completed quiz.
     */
    private function handleReplay(AgentContext $context): AgentResult
    {
        $lastScore = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily'])
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastScore) {
            $reply = "🔄 Aucun quiz précédent trouvé.\nLance ton premier quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'replay_none']);
        }

        $category = ($lastScore->category !== 'mix') ? $lastScore->category : '';
        $body     = $category ? "/quiz {$category}" : '/quiz';

        return $this->handleStartQuiz($context, $body);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply  = "🎯 *Aide — Quiz Interactif v{$this->version()}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "*Lancer un quiz :*\n";
        $reply .= "• `/quiz` — Quiz aléatoire (5 questions)\n";
        $reply .= "• `/quiz histoire` — Quiz par catégorie\n";
        $reply .= "• `/quiz facile` — Quiz facile (3 questions)\n";
        $reply .= "• `/quiz difficile` — Quiz difficile (7 questions)\n";
        $reply .= "• `/quiz rapide` — Quiz express (3 questions mix)\n";
        $reply .= "• `/quiz chrono [cat]` — ⏱ Mode Chrono (temps par réponse)\n";
        $reply .= "• `/quiz marathon` — 🏃 Marathon (10 questions)\n";
        $reply .= "• `/quiz daily` — 📅 Question du Jour\n";
        $reply .= "• `rejouer` — 🔄 Rejouer la même catégorie\n";
        $reply .= "• `/quiz perso` — 🎯 Quiz dans ta catégorie la plus faible\n";
        $reply .= "• `/quiz suggest` — 💡 Voir ta catégorie à améliorer\n";
        $reply .= "• `/quiz ia <sujet>` — 🤖 Quiz IA sur N'IMPORTE quel sujet\n";
        $reply .= "• `/quiz resume` — ▶️ Reprendre ton quiz en cours\n";
        $reply .= "• `/quiz mini` — ⚡ Quiz express ultra-rapide (2 questions)\n";
        $reply .= "• `/quiz random` — 🎲 Quiz surprise (catégorie pas jouée récemment)\n";
        $reply .= "• `/quiz focus [cat]` — 🔁 Révision (questions ratées récemment)\n\n";
        $reply .= "*Pendant le quiz :*\n";
        $reply .= "• Réponds avec *A*, *B*, *C* ou *D*\n";
        $reply .= "• `indice` — Obtenir un indice (-1 pt)\n";
        $reply .= "• `passer` — Sauter la question (0 pt, réponse révélée)\n";
        $reply .= "• `stop` — Abandonner le quiz\n\n";
        $reply .= "*Statistiques & Classements :*\n";
        $reply .= "• `/quiz mystats` — Tes stats + détail par catégorie\n";
        $reply .= "• `/quiz streak` — 🔥 Ta série quotidienne\n";
        $reply .= "• `/quiz rank` — 🏅 Ton rang dans le classement\n";
        $reply .= "• `/quiz progress` — 📈 Ta progression sur 7 jours\n";
        $reply .= "• `/quiz objectif [N]` — 🎯 Objectif de quiz quotidien (défaut : 3)\n";
        $reply .= "• `/quiz niveau` — 📊 Recommandation de niveau/difficulté\n";
        $reply .= "• `/quiz leaderboard` — Classement général\n";
        $reply .= "• `/quiz top histoire` — 🏆 Classement par catégorie\n";
        $reply .= "• `/quiz history` — Tes 10 derniers quiz\n";
        $reply .= "• `/quiz review` — 🔍 Revoir le dernier quiz (avec ta réponse)\n";
        $reply .= "• `/quiz categories` — Toutes les catégories\n";
        $reply .= "• `/quiz trending` — 🔥 Catégories tendance de la semaine\n";
        $reply .= "• `/quiz catstat <cat>` — 📂 Stats détaillées d'une catégorie\n";
        $reply .= "• `/quiz diffstats` — 📊 Stats par difficulté (facile/moyen/difficile)\n";
        $reply .= "• `/quiz mastery` — 🎓 Niveaux de maîtrise par catégorie\n";
        $reply .= "• `/quiz quickstats` — ⚡ Stats rapides en 1 message\n";
        $reply .= "• `/quiz export` — 📋 Bilan complet (stats, badges, niveau)\n";
        $reply .= "• `/quiz calendrier` — 📅 Calendrier mensuel d'activité\n";
        $reply .= "• `/quiz compare <cat1> <cat2>` — 📊 Comparer 2 catégories\n\n";
        $reply .= "*Comparer & Progresser :*\n";
        $reply .= "• `/quiz coach` — 🎓 Coaching IA — plan d'amélioration personnalisé\n";
        $reply .= "• `/quiz timing` — ⏱ Analyse de tes temps de réponse\n";
        $reply .= "• `/quiz vs @+336XXXXXXXX` — ⚔️ Comparer tes stats avec un ami\n";
        $reply .= "• `/quiz tip [catégorie]` — 💡 Conseils IA pour progresser\n\n";
        $reply .= "*Défier un ami :*\n";
        $reply .= "• `challenge @+336XXXXXXXX` — Défi !\n";
        $reply .= "• `/quiz duel` — ⚔️ Résultats de tes duels (W/L/D)\n";
        $reply .= "• `/quiz recommande` — 🧭 Recommandation personnalisée\n\n";
        $reply .= "*Après le quiz :*\n";
        $reply .= "• `/quiz explain` — 🧠 Explications IA pour les questions ratées/passées\n";
        $reply .= "• `/quiz share` — 📤 Partager ton score (style Wordle)\n\n";
        $reply .= "*Apprendre sans jouer :*\n";
        $reply .= "• `/quiz fun [catégorie]` — 🤩 3 faits fascinants IA sur une catégorie\n";
        $reply .= "• `/quiz tip [catégorie]` — 💡 Conseils IA pour progresser\n\n";
        $reply .= "*Récompenses :*\n";
        $reply .= "• `/quiz badges` — 🏅 Tes badges et récompenses débloqués\n\n";
        $reply .= "*Raccourcis :*\n";
        $reply .= "• `/quiz favori` — ❤️ Quiz dans ta catégorie préférée\n";
        $reply .= "• `/quiz historique <cat>` — 📜 Historique filtré par catégorie\n";
        $reply .= "• `/quiz revanche` — 🔁 Rejouer les mêmes questions (battre son score)\n\n";
        $reply .= "*Récap & Défis :*\n";
        $reply .= "• `/quiz recap` — 📋 Récap hebdo IA (bilan de ta semaine)\n";
        $reply .= "• `/quiz defi` — 🏅 Défi du Jour (difficulté adaptée + classement communauté)\n\n";
        $reply .= "*Nouveau en v1.24 :*\n";
        $reply .= "• `/quiz focus [cat]` — 🔁 Quiz de révision (questions ratées récemment)\n";
        $reply .= "• `/quiz quickstats` — 📊 Stats rapides en un coup d'œil\n";
        $reply .= "• Indices améliorés : 2 options éliminées en mode Facile (50/50)\n";
        $reply .= "• Prompts IA améliorés : quiz plus variés, explications plus mémorisables\n";
        $reply .= "• Protection anti-injection renforcée sur les quiz IA";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'help']);
    }

    /**
     * Suggest the category where the user performs worst (or has played the least).
     */
    private function handleSuggest(AgentContext $context): AgentResult
    {
        $weakest = $this->getWeakestCategory($context);

        if (!$weakest) {
            $reply  = "💡 *Suggestion de Quiz*\n\n";
            $reply .= "Tu n'as pas encore assez de données pour une suggestion.\n";
            $reply .= "Joue quelques quiz d'abord, puis reviens ici !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n";
            $reply .= "📊 /quiz categories — Voir toutes les catégories";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'suggest_no_data']);
        }

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$weakest['category']] ?? $weakest['category'];
        $avg        = $weakest['avg_pct'];
        $played     = $weakest['played'];

        $motivation = match (true) {
            $avg < 30  => "Tu as du mal avec cette catégorie — c'est le moment de progresser ! 💪",
            $avg < 50  => "Tu peux faire mieux avec un peu de pratique ! 🎯",
            $avg < 70  => "Tu es sur la bonne voie, continue ! 📈",
            default    => "Consolide tes acquis dans ce domaine ! 🌟",
        };

        $reply  = "💡 *Suggestion — Catégorie à améliorer*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📂 Catégorie : *{$catLabel}*\n";
        $reply .= "📊 Ta moyenne : *{$avg}%* ({$played} quiz joués)\n\n";
        $reply .= "{$motivation}\n\n";
        $reply .= "🎯 Lance un quiz maintenant :\n";
        $reply .= "• `/quiz {$weakest['category']}` — Quiz dans cette catégorie\n";
        $reply .= "• `/quiz perso` — Quiz personnalisé automatique";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Suggest viewed', ['weakest_category' => $weakest['category'], 'avg_pct' => $avg]);

        return AgentResult::reply($reply, ['action' => 'suggest', 'category' => $weakest['category']]);
    }

    /**
     * Start a personalized quiz in the user's weakest category.
     */
    private function handlePersonalized(AgentContext $context): AgentResult
    {
        $weakest = $this->getWeakestCategory($context);

        if (!$weakest) {
            // No stats yet — start a random quiz
            return $this->handleStartQuiz($context, '/quiz');
        }

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$weakest['category']] ?? $weakest['category'];

        $this->sendText($context->from, "🎯 Quiz personnalisé dans ta catégorie faible : *{$catLabel}* ({$weakest['avg_pct']}% de moyenne)");

        return $this->handleStartQuiz($context, "/quiz {$weakest['category']}");
    }

    /**
     * Return the weakest category for this user based on historical scores.
     * Returns ['category' => ..., 'avg_pct' => ..., 'played' => ...] or null.
     */
    private function getWeakestCategory(AgentContext $context): ?array
    {
        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->having('played', '>=', 1)
            ->orderBy('avg_pct')
            ->first();

        if (!$catScores) {
            return null;
        }

        return [
            'category' => $catScores->category,
            'avg_pct'  => (int) $catScores->avg_pct,
            'played'   => (int) $catScores->played,
        ];
    }

    /**
     * Explain wrong answers from the last completed quiz using the LLM.
     * Gives the user a brief pedagogical explanation for each missed question.
     */
    private function handleExplain(AgentContext $context): AgentResult
    {
        $lastQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastQuiz) {
            $reply = "🧠 *Explications — Dernier Quiz*\n\nAucun quiz terminé à expliquer.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'explain_empty']);
        }

        $wrongQuestions = array_values(array_filter(
            $lastQuiz->questions,
            fn($q) => ($q['user_answered'] ?? false)
                && (!($q['user_correct'] ?? false) || ($q['user_skipped'] ?? false))
        ));

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$lastQuiz->category] ?? ($lastQuiz->category === 'mix' ? '🎲 Mix' : $lastQuiz->category);

        if (empty($wrongQuestions)) {
            $reply  = "🌟 *Quiz {$catLabel} — Explications*\n\n";
            $reply .= "Tu as eu *toutes* les réponses correctes sans passer ! Rien à expliquer. 🏆\n\n";
            $reply .= "🔄 /quiz — Nouveau quiz\n";
            $reply .= "🎯 /quiz perso — Quiz dans ta catégorie faible";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'explain_perfect']);
        }

        $this->sendText($context->from, "🧠 *Génération des explications...* Un instant !");

        $questionsList = '';
        $letters = ['A', 'B', 'C', 'D'];
        foreach ($wrongQuestions as $i => $q) {
            $correctText    = QuizEngine::getCorrectAnswerText($q);
            $userChoice     = $q['user_choice'] ?? null;
            $userSkipped    = $q['user_skipped'] ?? false;
            $questionsList .= ($i + 1) . ". Question: \"{$q['question']}\"\n   Bonne réponse: {$correctText}\n";
            if ($userSkipped) {
                $questionsList .= "   (L'utilisateur a passé cette question)\n";
            } elseif ($userChoice) {
                $choiceIdx = array_search($userChoice, $letters);
                $choiceText = ($choiceIdx !== false && isset($q['options'][$choiceIdx])) ? $q['options'][$choiceIdx] : $userChoice;
                $questionsList .= "   Réponse de l'utilisateur: {$userChoice}. {$choiceText}\n";
            }
        }

        $model        = $this->resolveModel($context);
        $wrongCount   = count($wrongQuestions);
        $systemPrompt = "Tu es un assistant pédagogique concis pour un quiz WhatsApp. "
            . "Tu vas recevoir exactement {$wrongCount} question(s) avec leur bonne réponse et parfois la réponse incorrecte de l'utilisateur. "
            . "Pour CHACUNE, donne UNE explication courte et mémorisable (1-2 phrases MAX) "
            . "qui explique POURQUOI la bonne réponse est correcte. "
            . "Ne reformule PAS la question. Ne dis PAS 'La bonne réponse est...' (l'utilisateur le sait déjà). "
            . "Va droit au fait : un fait clé OU une astuce mnémotechnique. "
            . "Si l'utilisateur a donné une mauvaise réponse, commence par expliquer en 1 courte phrase pourquoi elle est fausse, "
            . "PUIS donne le fait clé qui justifie la bonne réponse. "
            . "Si l'utilisateur a passé (skippé), donne directement l'explication de la bonne réponse. "
            . "Format STRICT — commence par le numéro, une explication par ligne, séparées par une ligne vide :\n\n"
            . "1. [explication factuelle + astuce mémo]\n\n"
            . "2. [explication factuelle + astuce mémo]\n\n"
            . "Exemples valides :\n"
            . "1. La photosynthèse a lieu dans les chloroplastes — 'chloro' = vert, comme la chlorophylle.\n\n"
            . "2. Napoléon est mort en 1821 à Sainte-Hélène. Waterloo (1815) = défaite, pas mort.\n\n"
            . "3. L'URSS a lancé Spoutnik en 1957 — mnémo : 'SPOUTNIK = SPace Unique TNT In K' (1er satellite).\n\n"
            . "RÈGLES ABSOLUES : "
            . "— Réponds en FRANÇAIS uniquement. "
            . "— Génère exactement {$wrongCount} explication(s), une par numéro. "
            . "— Aucun texte introductif ni conclusif. "
            . "— Sois factuel, précis, évite les généralités. "
            . "— Si la mauvaise réponse de l'utilisateur est une confusion courante, mentionne la différence clé. "
            . "— Termine chaque explication par un emoji pertinent (🧠💡🌍📚🔬🏛️) pour rendre mémorisable. "
            . "— N'invente JAMAIS de fait. Si tu n'es pas sûr, indique 'à vérifier' plutôt que d'affirmer.";

        try {
            $llmResponse = $this->claude->chat(
                "Explique pourquoi ces réponses de quiz sont correctes :\n\n{$questionsList}",
                $model,
                $systemPrompt,
                800
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent explain LLM error', ['error' => $e->getMessage()]);
            $llmResponse = null;
        }

        // Parse numbered explanations from LLM output
        $explanations = [];
        if ($llmResponse) {
            preg_match_all('/(\d+)\.\s+(.+?)(?=\n\d+\.|$)/s', $llmResponse, $expMatches);
            if (!empty($expMatches[1])) {
                foreach ($expMatches[1] as $idx => $num) {
                    $explanations[(int) $num - 1] = trim(preg_replace('/\n+/', ' ', $expMatches[2][$idx]));
                }
            }
        }

        $reply  = "🧠 *Explications — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($wrongQuestions as $i => $q) {
            $correctText  = QuizEngine::getCorrectAnswerText($q);
            $explanation  = $explanations[$i] ?? null;
            $icon         = ($q['user_skipped'] ?? false) ? '⏭️' : '❌';
            $reply .= "{$icon} *" . ($i + 1) . ". {$q['question']}*\n";
            $reply .= "   ✔️ {$correctText}\n";
            if ($explanation) {
                $reply .= "   💡 _{$explanation}_\n";
            }
            $reply .= "\n";
        }

        if (!$llmResponse) {
            $reply .= "⚠️ _L'IA n'a pas pu générer les explications. Réessaie avec /quiz explain._\n\n";
        }

        $reply .= "🔄 /quiz — Nouveau quiz\n";
        $reply .= "🔁 /quiz rejouer — Même catégorie";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Explain viewed', ['quiz_id' => $lastQuiz->id, 'wrong_count' => count($wrongQuestions)]);

        return AgentResult::reply($reply, ['action' => 'explain', 'wrong_count' => count($wrongQuestions)]);
    }

    /**
     * Generate a Wordle-style shareable score card for the last completed quiz.
     */
    private function handleShare(AgentContext $context): AgentResult
    {
        $lastScore = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastScore) {
            $reply = "📤 Aucun score à partager.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'share_empty']);
        }

        $lastQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        $categories = QuizEngine::getCategories();
        $catLabel   = match ($lastScore->category) {
            'daily' => '📅 Quotidien',
            'mix'   => '🎲 Mix',
            default => $categories[$lastScore->category] ?? $lastScore->category,
        };

        $pct     = $lastScore->getPercentage();
        $timeStr = $lastScore->time_taken ? ' ⏱' . gmdate('i:s', $lastScore->time_taken) : '';
        $date    = $lastScore->completed_at?->format('d/m/Y') ?? now()->format('d/m/Y');

        $emojiGrid = $lastQuiz ? $this->buildScoreBreakdown($lastQuiz) : '';

        $scoreEmoji = match (true) {
            $pct === 100 => '🏆',
            $pct >= 80   => '🌟',
            $pct >= 60   => '👍',
            $pct >= 40   => '😊',
            default      => '💪',
        };

        $shareText  = "🎯 *ZeniClaw Quiz* — {$date}\n";
        $shareText .= "{$catLabel} {$scoreEmoji}\n";
        if ($emojiGrid) {
            $shareText .= "{$emojiGrid}\n";
        }
        $shareText .= "{$lastScore->score}/{$lastScore->total_questions} ({$pct}%){$timeStr}\n";
        $shareText .= "\n_Peux-tu faire mieux ? Envoie-moi /quiz ! 😄_";

        $reply  = "📤 *Résultat à partager :*\n\n";
        $reply .= $shareText;

        $this->sendText($context->from, $reply);
        $this->log($context, 'Score shared', [
            'score'    => $lastScore->score,
            'total'    => $lastScore->total_questions,
            'category' => $lastScore->category,
        ]);

        return AgentResult::reply($reply, ['action' => 'share']);
    }

    /**
     * AI-generated quiz on any custom topic using the LLM.
     * The user provides a topic and the LLM generates 5 QCM questions.
     */
    private function handleAIQuiz(AgentContext $context, string $topic): AgentResult
    {
        $topic = mb_substr(trim($topic), 0, 120); // Safety: limit topic length
        $topic = preg_replace('/[\x00-\x1F\x7F]/u', '', $topic); // Strip control chars
        // Strip potential prompt injection patterns
        $topic = preg_replace('/\b(ignore|oublie|forget|system|prompt|instruction|r[eè]gle)\b.*[:\.]/iu', '', $topic);
        $topic = trim($topic);

        if (mb_strlen($topic) < 2) {
            $reply  = "🤖 *Quiz IA*\n\nPrécise un sujet ! Exemples :\n";
            $reply .= "• `/quiz ia les dinosaures`\n";
            $reply .= "• `/quiz sur Harry Potter`\n";
            $reply .= "• `/quiz thème la photosynthèse`";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'ai_quiz_no_topic']);
        }

        $this->sendText($context->from, "🤖 *Génération d'un quiz sur « {$topic} »...* Un instant !");

        $model        = $this->resolveModel($context);
        $systemPrompt = "Tu es un générateur de quiz QCM éducatifs pour WhatsApp. "
            . "Génère exactement 5 questions à choix multiple (QCM) sur le sujet demandé. "
            . "Les questions doivent être factuelles, vérifiables, et rédigées en FRANÇAIS. "
            . "Progression de difficulté : Q1-Q2 faciles, Q3 moyenne, Q4-Q5 difficiles. "
            . "Les 4 options doivent être plausibles et homogènes (même nature : dates avec dates, noms avec noms). "
            . "Les mauvaises réponses doivent être des pièges crédibles (erreurs courantes, valeurs proches). "
            . "IMPORTANT : varie la position de la bonne réponse — répartis A/B/C/D équitablement. "
            . "IMPORTANT : ne pose JAMAIS de question dont la réponse est subjective ou contestable. "
            . "IMPORTANT : chaque question doit tester un aspect DIFFÉRENT du sujet (pas de répétition thématique). "
            . "IMPORTANT : les options incorrectes doivent être du MÊME domaine que la bonne réponse (ex: si la réponse est un pays, les options sont aussi des pays). "
            . "Format OBLIGATOIRE (respecte exactement les sauts de ligne et séparateurs) :\n\n"
            . "QUESTION: [question claire, sans ambiguïté, se terminant par ?]\n"
            . "A) [option A]\n"
            . "B) [option B]\n"
            . "C) [option C]\n"
            . "D) [option D]\n"
            . "ANSWER: [A, B, C ou D — UNE seule lettre]\n"
            . "---\n\n"
            . "Exemples valides :\n"
            . "QUESTION: Quel est le langage de programmation créé par Guido van Rossum ?\n"
            . "A) Java\nB) Ruby\nC) Python\nD) PHP\nANSWER: C\n---\n\n"
            . "QUESTION: En quelle année la Tour Eiffel a-t-elle été inaugurée ?\n"
            . "A) 1876\nB) 1889\nC) 1901\nD) 1912\nANSWER: B\n---\n\n"
            . "Règles ABSOLUES :\n"
            . "- Une seule bonne réponse par question, vérifiable factuellement\n"
            . "- Commence directement avec QUESTION: sans introduction\n"
            . "- Sépare chaque question par --- sur sa propre ligne\n"
            . "- N'ajoute aucune explication, commentaire, ou texte hors du format\n"
            . "- Si le sujet est trop niche, élargis aux thèmes connexes plutôt que d'inventer\n"
            . "- ZÉRO HALLUCINATION : ne génère JAMAIS de fait inventé ou non vérifiable\n"
            . "- Si tu n'es pas sûr à 100% d'un fait, ne l'utilise pas comme réponse correcte\n"
            . "- Évite les questions sur des dates/chiffres très précis sauf si tu es absolument certain\n"
            . "- Ne répète JAMAIS une structure de question (ex: pas 2x 'Quel est le X de Y ?')";

        try {
            $llmResponse = $this->claude->chat(
                "Génère exactement 5 questions QCM en français sur le sujet suivant : {$topic}\n\nCommence directement avec QUESTION: sans texte introductif.",
                $model,
                $systemPrompt,
                1400
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent AI quiz LLM error', ['error' => $e->getMessage(), 'topic' => $topic]);
            $llmResponse = null;
        }

        if (!$llmResponse) {
            $reply  = "⚠️ *Quiz IA* — L'IA n'a pas pu générer le quiz.\n";
            $reply .= "Réessaie dans un instant, ou lance un quiz classique avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'ai_quiz_llm_fail']);
        }

        $questions = $this->parseAIQuestions($llmResponse, $topic);

        if (count($questions) < 2) {
            $reply  = "⚠️ *Quiz IA* — Je n'ai pas pu générer assez de questions sur ce sujet.\n";
            $reply .= "Essaie un sujet plus précis, ou lance /quiz pour un quiz classique !";
            $this->sendText($context->from, $reply);
            $this->log($context, 'AI quiz parse failed', ['topic' => $topic, 'parsed_count' => count($questions)]);
            return AgentResult::reply($reply, ['action' => 'ai_quiz_parse_fail']);
        }

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $preparedQuestions = array_map(fn(array $q) => array_merge($q, [
            'hints_used'    => 0,
            'user_answered' => false,
            'user_correct'  => false,
            'user_skipped'  => false,
        ]), $questions);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'custom',
            'difficulty'             => 'medium',
            'questions'              => $preparedQuestions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $intro  = "🤖 *Quiz IA — {$topic}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "🟡 Moyen — {$quiz->getTotalQuestions()} questions générées par l'IA\n";
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter la question\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'AI quiz started', ['topic' => $topic, 'questions' => $quiz->getTotalQuestions()]);

        return AgentResult::reply($reply, ['action' => 'ai_quiz_start', 'topic' => $topic]);
    }

    /**
     * Parse QCM questions from LLM output.
     * Handles multiple formats: A) / A. / A: / A -
     */
    private function parseAIQuestions(string $llmOutput, string $topic): array
    {
        $questions = [];
        $blocks    = preg_split('/---+/', $llmOutput);

        foreach ($blocks as $block) {
            $block = trim($block);
            if (mb_strlen($block) < 20) {
                continue;
            }

            // Flexible question extraction: QUESTION: or Q: or numbered "1."
            if (!preg_match('/(?:QUESTION|Q)\s*[:\.]\s*(.+?)(?=\n[A-Da-d][\)\.:\-\s])/is', $block, $qMatch)) {
                continue;
            }
            // Flexible option format: A) / A. / A: / A -
            $optPattern = '/^%s[\)\.\:\-]\s*(.+)/im';
            if (!preg_match(sprintf($optPattern, 'A'), $block, $aMatch)) {
                continue;
            }
            if (!preg_match(sprintf($optPattern, 'B'), $block, $bMatch)) {
                continue;
            }
            if (!preg_match(sprintf($optPattern, 'C'), $block, $cMatch)) {
                continue;
            }
            if (!preg_match(sprintf($optPattern, 'D'), $block, $dMatch)) {
                continue;
            }
            // Flexible answer extraction: ANSWER: / RÉPONSE: / Réponse :
            if (!preg_match('/(?:ANSWER|RÉPONSE|R[ée]ponse)\s*[:=]\s*([ABCD])/iu', $block, $ansMatch)) {
                continue;
            }

            $answerMap = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
            $answerIdx = $answerMap[strtoupper(trim($ansMatch[1]))] ?? 0;

            $questions[] = [
                'question' => trim(preg_replace('/\s+/', ' ', $qMatch[1])),
                'options'  => [
                    trim($aMatch[1]),
                    trim($bMatch[1]),
                    trim($cMatch[1]),
                    trim($dMatch[1]),
                ],
                'answer'   => $answerIdx,
                'category' => 'custom',
                'topic'    => $topic,
            ];

            if (count($questions) >= 5) {
                break;
            }
        }

        return $questions;
    }

    /**
     * Show the user's daily quiz streak (consecutive days with at least 1 quiz played).
     */
    private function handleDailyStreak(AgentContext $context): AgentResult
    {
        $streak = $this->computeDailyStreak($context);

        $totalDays = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->selectRaw('DATE(completed_at) as play_date')
            ->groupBy('play_date')
            ->get()
            ->count();

        $playedToday = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('completed_at', now()->toDateString())
            ->exists();

        $streakEmoji = match (true) {
            $streak >= 30 => '🏆',
            $streak >= 14 => '🌟',
            $streak >= 7  => '🔥🔥',
            $streak >= 3  => '🔥',
            $streak >= 1  => '📅',
            default       => '💤',
        };

        $motivation = match (true) {
            $streak >= 30 => "Incroyable ! Tu es un vrai champion du quiz ! 🏆",
            $streak >= 14 => "Deux semaines de suite ! Tu es inarrêtable ! 🌟",
            $streak >= 7  => "Une semaine complète ! Superbe régularité ! 🔥",
            $streak >= 3  => "Belle série ! Continue comme ça ! 🎯",
            $streak >= 1  => "Tu es lancé, ne t'arrête pas ! 📈",
            default       => "Lance un quiz aujourd'hui pour démarrer ta série ! 🚀",
        };

        $reply  = "🔥 *Série Quotidienne Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "{$streakEmoji} Série actuelle : *{$streak} jour(s)*\n";
        $reply .= "📅 Jours de jeu total : *{$totalDays}*\n\n";
        $reply .= "{$motivation}\n";

        if (!$playedToday && $streak > 0) {
            $reply .= "\n⚠️ _Tu n'as pas encore joué aujourd'hui — joue avant minuit pour garder ta série !_\n";
        } elseif (!$playedToday) {
            $reply .= "\n";
        }

        $reply .= "\n📅 /quiz daily — Question du Jour\n";
        $reply .= "🔄 /quiz — Nouveau quiz\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily streak viewed', ['streak' => $streak, 'total_days' => $totalDays]);

        return AgentResult::reply($reply, ['action' => 'daily_streak', 'streak' => $streak]);
    }

    /**
     * Compute the user's daily quiz streak (consecutive days with at least 1 completed quiz).
     */
    private function computeDailyStreak(AgentContext $context): int
    {
        $playedDates = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->selectRaw('DATE(completed_at) as play_date')
            ->groupBy('play_date')
            ->orderByDesc('play_date')
            ->pluck('play_date')
            ->toArray();

        if (empty($playedDates)) {
            return 0;
        }

        $today     = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Streak is broken if most recent play was before yesterday
        if ($playedDates[0] !== $today && $playedDates[0] !== $yesterday) {
            return 0;
        }

        $streak   = 0;
        $expected = \Carbon\Carbon::parse($playedDates[0]);

        foreach ($playedDates as $dateStr) {
            if ($dateStr === $expected->toDateString()) {
                $streak++;
                $expected = $expected->subDay();
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Set the shown_at timestamp on a specific question index in the quiz.
     * Used to compute per-question response times. Silent on failure (non-critical).
     */
    private function setQuestionShownAt(Quiz $quiz, int $index): void
    {
        try {
            $questions = $quiz->questions;
            if (isset($questions[$index]) && empty($questions[$index]['shown_at'])) {
                $questions[$index]['shown_at'] = now()->toISOString();
                $quiz->update(['questions' => $questions]);
            }
        } catch (\Throwable) {
            // Non-critical — silently ignore
        }
    }

    /**
     * Chrono mode — standard 5-question quiz with emphasis on response timing.
     * Timing data is shown after each answer (common to all quiz modes in v1.8.0).
     */
    private function handleChronoMode(AgentContext $context): AgentResult
    {
        $lower = mb_strtolower($context->body ?? '');

        // Optional category: "/quiz chrono histoire"
        $category = null;
        if (preg_match('/(?:chrono|speed)\s+(\w+)/iu', $lower, $m)) {
            $resolved = QuizEngine::resolveCategory($m[1]);
            if ($resolved) {
                $category = $resolved;
            }
        }

        $this->sendText(
            $context->from,
            "⏱ *Mode Chrono activé !* Réponds le plus vite possible — ton temps s'affiche après chaque réponse. C'est parti ! 🚀"
        );

        $body = $category ? "/quiz {$category}" : '/quiz';

        return $this->handleStartQuiz($context, $body);
    }

    /**
     * Daily quiz goal — show progress toward a daily quiz count target.
     * Usage: /quiz objectif [N]  → N defaults to 3 if not provided.
     */
    private function handleGoal(AgentContext $context, ?int $targetCount): AgentResult
    {
        $goal  = ($targetCount !== null && $targetCount > 0 && $targetCount <= 50) ? $targetCount : 3;
        $today = now()->toDateString();

        $playedToday = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('completed_at', $today)
            ->count();

        $remaining   = max(0, $goal - $playedToday);
        $pctProgress = $goal > 0 ? min(100, (int) round(($playedToday / $goal) * 100)) : 0;

        // Visual progress bar (10 blocks)
        $filled      = (int) round($pctProgress / 10);
        $empty       = 10 - $filled;
        $progressBar = str_repeat('█', $filled) . str_repeat('░', $empty);

        $goalEmoji = $pctProgress >= 100 ? '🏆' : ($pctProgress >= 50 ? '📈' : '🎯');

        $reply  = "{$goalEmoji} *Objectif Quiz du Jour*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "Objectif : *{$goal} quiz* aujourd'hui\n";
        $reply .= "Complétés : *{$playedToday}*\n";
        $reply .= "[{$progressBar}] {$pctProgress}%\n\n";

        if ($remaining === 0) {
            $reply .= "✅ *Objectif atteint ! Bravo !* 🏆\n";
            $nextGoal = $goal + 1;
            $reply .= "💡 Vise encore plus haut : `/quiz objectif {$nextGoal}`\n";
        } else {
            $reply .= "📈 Encore *{$remaining}* quiz pour atteindre ton objectif !\n";
        }

        $reply .= "\n🔄 /quiz — Lancer un quiz\n";
        $reply .= "⚡ /quiz rapide — Quiz express (3 questions)\n";
        $reply .= "📅 /quiz daily — Question du jour\n";
        $reply .= "🎯 /quiz objectif " . ($goal + 2) . " — Augmenter l'objectif";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Goal viewed', ['goal' => $goal, 'played_today' => $playedToday, 'pct' => $pctProgress]);

        return AgentResult::reply($reply, ['action' => 'goal', 'goal' => $goal, 'played_today' => $playedToday]);
    }

    /**
     * Check if the current quiz result is a new personal best for the category.
     * Returns a formatted string message if it's a new best, null otherwise.
     */
    private function checkPersonalBest(AgentContext $context, string $category, float $currentPct): ?string
    {
        if (in_array($category, ['daily', 'mix'])) {
            return null;
        }

        $totalScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->count();

        if ($totalScores <= 1) {
            // First quiz in this category — it's automatically a best
            return "\n🥇 *Premier quiz dans cette catégorie — record établi !*\n";
        }

        // Find the highest id of the just-saved score to exclude it
        $latestId = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->max('id');

        // Get actual previous best (MAX percentage across all previous scores)
        $previousBestPct = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->where('id', '!=', $latestId)
            ->selectRaw('MAX(score * 100.0 / total_questions) as max_pct')
            ->value('max_pct');

        if ($previousBestPct === null) {
            return "\n🥇 *Premier quiz dans cette catégorie — record établi !*\n";
        }

        $previousBestPct = (float) $previousBestPct;

        if ($currentPct > $previousBestPct) {
            $diff = round($currentPct - $previousBestPct, 1);
            $prev = round($previousBestPct);
            return "\n🏆 *Nouveau record personnel !* +{$diff}% par rapport à ton meilleur ({$prev}%)\n";
        }

        return null;
    }

    /**
     * Show the user's rank in the global leaderboard with nearby players.
     */
    private function handleRank(AgentContext $context): AgentResult
    {
        $allPlayers = QuizScore::where('agent_id', $context->agent->id)
            ->selectRaw('user_phone, SUM(score) as total_score, COUNT(*) as quizzes_played')
            ->groupBy('user_phone')
            ->orderByDesc('total_score')
            ->get();

        if ($allPlayers->isEmpty()) {
            $reply = "🏅 *Mon Classement*\n\nAucun joueur classé pour l'instant.\nLance ton premier quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'rank_empty']);
        }

        $myRank  = null;
        $myEntry = null;
        foreach ($allPlayers as $i => $player) {
            if ($player->user_phone === $context->from) {
                $myRank  = $i + 1;
                $myEntry = $player;
                break;
            }
        }

        if (!$myRank) {
            $reply = "🏅 *Mon Classement*\n\nTu n'as pas encore de score.\nLance un quiz avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'rank_no_score']);
        }

        $total  = $allPlayers->count();
        $topPct = $total > 0 ? round(($myRank / $total) * 100) : 100;

        $rankEmoji = match (true) {
            $myRank === 1 => '🥇',
            $myRank === 2 => '🥈',
            $myRank === 3 => '🥉',
            $topPct <= 10 => '🌟',
            $topPct <= 25 => '⭐',
            default       => '📊',
        };

        $reply  = "🏅 *Mon Classement Global*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "{$rankEmoji} *#{$myRank}* sur {$total} joueur(s)\n";
        $reply .= "🎮 Quiz joués : *{$myEntry->quizzes_played}*\n";
        $reply .= "⭐ Score total : *{$myEntry->total_score}* pts\n";

        // Show nearby players for context
        $startIdx = max(0, $myRank - 2);
        $nearby   = $allPlayers->slice($startIdx, 5);

        if ($nearby->count() > 1) {
            $reply .= "\n*Classement autour de toi :*\n";
            foreach ($nearby as $j => $player) {
                $rank   = $startIdx + $j + 1;
                $isMe   = $player->user_phone === $context->from;
                $phone  = '***' . substr($player->user_phone, -4);
                $marker = $isMe ? ' ← toi' : '';
                $medal  = match ($rank) {
                    1 => '🥇', 2 => '🥈', 3 => '🥉', default => "{$rank}."
                };
                $reply .= "{$medal} {$phone} — {$player->total_score} pts{$marker}\n";
            }
        }

        $reply .= "\n🏆 /quiz leaderboard — Top 10 complet\n";
        $reply .= "🔄 /quiz — Jouer pour progresser";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Rank viewed', ['rank' => $myRank, 'total_players' => $total]);

        return AgentResult::reply($reply, ['action' => 'rank', 'rank' => $myRank, 'total' => $total]);
    }

    /**
     * Show a 7-day progression report: quiz count and average score per day.
     */
    private function handleProgressReport(AgentContext $context): AgentResult
    {
        $startDate = now()->subDays(6)->startOfDay();

        $scores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $startDate)
            ->selectRaw('DATE(completed_at) as play_date, COUNT(*) as quizzes, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('play_date')
            ->orderBy('play_date')
            ->get()
            ->keyBy('play_date');

        if ($scores->isEmpty()) {
            $reply  = "📈 *Progression — 7 jours*\n\n";
            $reply .= "Aucun quiz joué cette semaine.\n";
            $reply .= "🔄 /quiz — Lance ton premier quiz de la semaine !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'progress_empty']);
        }

        $reply  = "📈 *Progression — 7 derniers jours*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $totalQuizzes = 0;
        $allPcts      = [];
        $dayNames     = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

        for ($d = 6; $d >= 0; $d--) {
            $date    = now()->subDays($d)->toDateString();
            $dayName = $dayNames[(int) now()->subDays($d)->format('w')];
            $dayNum  = now()->subDays($d)->format('d/m');
            $entry   = $scores->get($date);

            if ($entry) {
                $filled  = (int) min(10, (int) round((int) $entry->avg_pct / 10));
                $bar     = str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
                $reply  .= "{$dayName} {$dayNum}: [{$bar}] {$entry->avg_pct}% ({$entry->quizzes}✓)\n";
                $totalQuizzes += (int) $entry->quizzes;
                $allPcts[]    = (int) $entry->avg_pct;
            } else {
                $reply .= "{$dayName} {$dayNum}: [░░░░░░░░░░] —\n";
            }
        }

        if (!empty($allPcts)) {
            $avgOverall = round(array_sum($allPcts) / count($allPcts));
            $trend      = '';

            if (count($allPcts) >= 3) {
                $midpoint  = (int) floor(count($allPcts) / 2);
                $firstHalf = array_slice($allPcts, 0, $midpoint);
                $lastHalf  = array_slice($allPcts, -$midpoint);
                $diff      = round(array_sum($lastHalf) / count($lastHalf) - array_sum($firstHalf) / count($firstHalf));
                $trend     = $diff > 0 ? " +{$diff}% ↑" : ($diff < 0 ? " {$diff}% ↓" : " (stable)");
            }

            $reply .= "\n📊 Moyenne : *{$avgOverall}%*{$trend}\n";
            $reply .= "🎮 Quiz complétés : *{$totalQuizzes}*\n";
        }

        $reply .= "\n📊 /quiz mystats — Stats complètes\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Progress report viewed', ['total_quizzes' => $totalQuizzes]);

        return AgentResult::reply($reply, ['action' => 'progress_report']);
    }

    /**
     * Head-to-head stats comparison between the current user and another user by phone.
     * Usage: /quiz vs @+33612345678
     */
    private function handleVs(AgentContext $context, string $targetUser): AgentResult
    {
        // Normalize: strip non-digits, append WA suffix
        $digits = preg_replace('/[^0-9]/', '', $targetUser);

        if (mb_strlen($digits) < 8) {
            $reply  = "⚠️ *Numéro invalide.* (min. 8 chiffres)\n";
            $reply .= "Utilise : `quiz vs +33612345678`";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'vs_invalid']);
        }

        $targetPhone = $digits . '@s.whatsapp.net';

        if ($targetPhone === $context->from) {
            $reply = "😄 Tu ne peux pas te comparer à toi-même ! Indique le numéro d'un ami.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'vs_self']);
        }

        $myStats    = QuizScore::getUserStats($context->from, $context->agent->id);
        $theirStats = QuizScore::getUserStats($targetPhone, $context->agent->id);

        $targetDisplay = '***' . substr($digits, -4);

        if ($myStats['quizzes_played'] === 0) {
            $reply = "📊 Tu n'as pas encore de scores.\nLance un quiz avec /quiz d'abord !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'vs_no_data_me']);
        }

        if ($theirStats['quizzes_played'] === 0) {
            $reply  = "📊 *{$targetDisplay}* n'a pas encore de scores quiz.\n";
            $reply .= "Invite-les à jouer avec /quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'vs_no_data_them']);
        }

        $myScore    = (int) $myStats['total_score'];
        $theirScore = (int) $theirStats['total_score'];
        $myAvg      = (float) $myStats['avg_percentage'];
        $theirAvg   = (float) $theirStats['avg_percentage'];
        $myPlayed   = (int) $myStats['quizzes_played'];
        $theirPlayed = (int) $theirStats['quizzes_played'];

        // Per-category advantage
        $categories   = QuizEngine::getCategories();
        $myCatScores  = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom'])
            ->selectRaw('category, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->pluck('avg_pct', 'category')
            ->toArray();

        $theirCatScores = QuizScore::where('user_phone', $targetPhone)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom'])
            ->selectRaw('category, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->pluck('avg_pct', 'category')
            ->toArray();

        $meLeads = 0;
        $themLeads = 0;
        $catLines = '';
        foreach ($myCatScores as $cat => $myPct) {
            if (!isset($theirCatScores[$cat])) {
                continue;
            }
            $theirPct = $theirCatScores[$cat];
            $label    = $categories[$cat] ?? $cat;
            if ($myPct > $theirPct) {
                $meLeads++;
                $catLines .= "  ✅ {$label} : {$myPct}% vs {$theirPct}%\n";
            } elseif ($theirPct > $myPct) {
                $themLeads++;
                $catLines .= "  ❌ {$label} : {$myPct}% vs {$theirPct}%\n";
            }
        }

        $scoreWinner = $myScore > $theirScore ? 'me' : ($myScore < $theirScore ? 'them' : 'tie');
        $avgWinner   = $myAvg > $theirAvg ? 'me' : ($myAvg < $theirAvg ? 'them' : 'tie');
        $totalMe     = ($scoreWinner === 'me' ? 1 : 0) + ($avgWinner === 'me' ? 1 : 0) + $meLeads;
        $totalThem   = ($scoreWinner === 'them' ? 1 : 0) + ($avgWinner === 'them' ? 1 : 0) + $themLeads;

        $verdict = match (true) {
            $totalMe > $totalThem   => "🏆 *Tu mènes globalement !*",
            $totalThem > $totalMe   => "💪 *{$targetDisplay} est en tête — à toi de les dépasser !*",
            default                 => "🤝 *Match nul !*",
        };

        $reply  = "⚔️ *Toi vs {$targetDisplay}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "*Toi :*\n";
        $reply .= "  🎮 {$myPlayed} quiz joués\n";
        $reply .= "  ⭐ {$myScore} pts\n";
        $reply .= "  📈 {$myAvg}% de moyenne\n\n";
        $reply .= "*{$targetDisplay} :*\n";
        $reply .= "  🎮 {$theirPlayed} quiz joués\n";
        $reply .= "  ⭐ {$theirScore} pts\n";
        $reply .= "  📈 {$theirAvg}% de moyenne\n\n";

        if ($catLines) {
            $reply .= "📂 *Par catégorie (toi vs eux) :*\n";
            $reply .= $catLines . "\n";
        }

        $reply .= "{$verdict}\n\n";
        $reply .= "🔄 /quiz — Jouer pour les dépasser !\n";
        $reply .= "🏆 /quiz leaderboard — Classement général";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Vs viewed', ['target' => $targetPhone]);

        return AgentResult::reply($reply, ['action' => 'vs', 'target' => $targetPhone]);
    }

    /**
     * Resume an in-progress quiz — re-display the current question.
     * Useful when the pending context expired or the user sent an unrelated message.
     */
    private function handleResume(AgentContext $context): AgentResult
    {
        $activeQuiz = $this->getActiveQuiz($context);

        if (!$activeQuiz) {
            $reply  = "▶️ *Reprendre Quiz*\n\n";
            $reply .= "Aucun quiz en cours.\n";
            $reply .= "🔄 /quiz — Lancer un nouveau quiz\n";
            $reply .= "🔁 /quiz rejouer — Rejouer la même catégorie";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'resume_none']);
        }

        $currentQuestion = $activeQuiz->getCurrentQuestion();

        if (!$currentQuestion) {
            $reply = "⚠️ Quiz introuvable. Lance un nouveau quiz avec /quiz !";
            $activeQuiz->update(['status' => 'abandoned']);
            $this->clearPendingContext($context);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'resume_error']);
        }

        $idx       = $activeQuiz->current_question_index;
        $answered  = $idx;
        $total     = $activeQuiz->getTotalQuestions();
        $score     = $activeQuiz->correct_answers;
        $catLabel  = QuizEngine::getCategories()[$activeQuiz->category] ?? ($activeQuiz->category === 'mix' ? '🎲 Mix' : $activeQuiz->category);

        $questionText = QuizEngine::formatQuestion($currentQuestion, $idx + 1, $total);

        $reply  = "▶️ *Quiz en cours — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "Question *" . ($idx + 1) . "/{$total}* — Score : {$score}/{$answered}\n\n";
        $reply .= $questionText;
        $reply .= "\n\n💡 *indice* → indice (-1 pt) | *passer* → sauter | *stop* → abandonner";

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $activeQuiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Quiz resumed', ['quiz_id' => $activeQuiz->id, 'question' => $idx + 1]);

        return AgentResult::reply($reply, ['action' => 'quiz_resume', 'question' => $idx + 1]);
    }

    /**
     * AI-generated fun facts / trivia for a category.
     * Usage: /quiz fun [catégorie]
     */
    private function handleFunFacts(AgentContext $context, ?string $category): AgentResult
    {
        $categories = QuizEngine::getCategories();

        // Resolve category: argument → weakest → random
        if (!$category) {
            $weakest  = $this->getWeakestCategory($context);
            $category = $weakest ? $weakest['category'] : array_keys($categories)[array_rand(array_keys($categories))];
        }

        $catLabel = $categories[$category] ?? $category;

        $this->sendText($context->from, "🤩 *Génération de faits fascinants sur {$catLabel}...* Un instant !");

        $model        = $this->resolveModel($context);
        $systemPrompt = "Tu es un expert en culture générale qui partage des faits fascinants, surprenants et mémorables. "
            . "Donne exactement 3 faits étonnants sur le sujet demandé. "
            . "Chaque fait doit être court (1-2 phrases), vérifiable et genuinement surprenant ou contre-intuitif. "
            . "Format STRICT — commence immédiatement sans introduction :\n"
            . "🤩 [fait étonnant numéro 1]\n\n"
            . "🧠 [fait mémorisable numéro 2]\n\n"
            . "💡 [astuce ou anecdote pratique numéro 3]\n\n"
            . "Exemple pour 'géographie' :\n"
            . "🤩 La France est le pays le plus visité au monde avec 90 millions de touristes par an — plus que sa propre population.\n\n"
            . "🧠 Le Canada possède 20% des réserves mondiales d'eau douce mais seulement 0,5% de la population mondiale.\n\n"
            . "💡 Astuce mémoire : pour retenir les capitales, associe chaque pays à une image phonétique (ex: Ouagadougou → 'Où? À Dougou!').\n\n"
            . "RÈGLES ABSOLUES : Réponds en FRANÇAIS uniquement. Sois factuel, précis et évite les généralités. "
            . "N'invente JAMAIS de fait — chaque affirmation doit être vérifiable. "
            . "Si un chiffre est approximatif, utilise '~' ou 'environ'. Aucun texte hors du format.";

        try {
            $llmResponse = $this->claude->chat(
                "Donne 3 faits fascinants et surprenants sur : {$catLabel}",
                $model,
                $systemPrompt,
                500
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent fun facts LLM error', ['error' => $e->getMessage()]);
            $llmResponse = null;
        }

        if (!$llmResponse) {
            $reply  = "⚠️ *Faits Fascinants* — L'IA n'a pas pu générer les faits.\n";
            $reply .= "Réessaie avec `/quiz fun {$category}` ou lance un quiz avec `/quiz {$category}` !";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Fun facts LLM failed', ['category' => $category]);
            return AgentResult::reply($reply, ['action' => 'fun_facts_fail']);
        }

        $reply  = "🤩 *Faits Fascinants — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= trim($llmResponse) . "\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "🎯 Teste tes connaissances :\n";
        $reply .= "• `/quiz {$category}` — Quiz sur cette catégorie\n";
        $reply .= "• `/quiz ia {$catLabel}` — Quiz IA personnalisé\n";
        $reply .= "• `/quiz tip {$category}` — Conseils pour progresser\n";
        $reply .= "🤩 /quiz fun [catégorie] — Faits sur une autre catégorie";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Fun facts generated', ['category' => $category]);

        return AgentResult::reply($reply, ['action' => 'fun_facts', 'category' => $category]);
    }

    /**
     * Mini quiz — ultra-fast 2-question quiz, any category mix.
     * Ideal when the user is short on time.
     */
    private function handleMiniQuiz(AgentContext $context): AgentResult
    {
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quizData  = QuizEngine::generateQuiz(null, 2);
        $questions = array_map(fn(array $q) => array_merge($q, [
            'hints_used'    => 0,
            'user_answered' => false,
            'user_correct'  => false,
            'user_skipped'  => false,
        ]), $quizData['questions']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'mix',
            'difficulty'             => 'medium',
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, 2);

        $reply  = "⚡ *Quiz Mini — 2 Questions*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "🎲 Mix — Ultra-rapide, réponds vite !\n";
        $reply .= "💡 *indice* → indice (-1 pt) | *passer* → sauter\n\n";
        $reply .= $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Mini quiz started', ['questions' => 2]);

        return AgentResult::reply($reply, ['action' => 'mini_quiz_start']);
    }

    /**
     * Show the user's earned achievement badges based on their quiz history.
     * Badges are computed dynamically from QuizScore and Quiz records.
     */
    private function handleBadges(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ($stats['quizzes_played'] === 0) {
            $reply  = "🏅 *Mes Badges Quiz*\n\n";
            $reply .= "Tu n'as pas encore de badges.\n";
            $reply .= "Lance ton premier quiz pour en débloquer ! 🚀\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'badges_empty']);
        }

        $badgeData = $this->computeBadges($context);
        $earned    = $badgeData['earned'];
        $locked    = $badgeData['locked'];

        $reply  = "🏅 *Mes Badges Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "✅ *Débloqués ({$badgeData['earned_count']}/{$badgeData['total']}) :*\n";

        if (empty($earned)) {
            $reply .= "  _Aucun badge encore — joue plus pour en débloquer !_\n";
        } else {
            foreach ($earned as $badge) {
                $reply .= "  {$badge[0]} *{$badge[1]}* — {$badge[2]}\n";
            }
        }

        $reply .= "\n🔒 *À débloquer :*\n";
        foreach ($locked as $badge) {
            $reply .= "  ░ {$badge[0]} {$badge[1]} — {$badge[2]}\n";
        }

        $reply .= "\n🔄 /quiz — Jouer pour débloquer plus de badges\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Badges viewed', ['earned' => $badgeData['earned_count'], 'total' => $badgeData['total']]);

        return AgentResult::reply($reply, ['action' => 'badges', 'earned' => $badgeData['earned_count'], 'total' => $badgeData['total']]);
    }

    /**
     * Check if any new badge was just unlocked after a quiz completion.
     * Returns a short congratulatory string (or null) to append to the completion message.
     * Only checks the most impactful thresholds to keep it fast.
     */
    private function checkNewBadgeUnlock(AgentContext $context, int $quizzesPlayed, float $bestPct, int $dailyStreak): ?string
    {
        // Milestone checks (run only when the threshold is just crossed)
        if ($quizzesPlayed === 1) {
            return "\n🎯 *Badge débloqué : Débutant !* Premier quiz terminé — bravo !\n";
        }
        if ($quizzesPlayed === 10) {
            return "\n🏅 *Badge débloqué : Assidu !* 10 quiz au compteur !\n";
        }
        if ($quizzesPlayed === 50) {
            return "\n🏆 *Badge débloqué : Vétéran !* 50 quiz — tu es une légende !\n";
        }
        if ($bestPct >= 100.0 && $quizzesPlayed >= 1) {
            // Only announce once; check if they had a previous perfect score
            $prevPerfect = QuizScore::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->whereRaw('score = total_questions')
                ->count();
            if ($prevPerfect === 1) { // The one just saved
                return "\n💯 *Badge débloqué : Perfectionniste !* Score parfait — incroyable !\n";
            }
        }
        if ($dailyStreak === 3) {
            return "\n🔥 *Badge débloqué : Sur la lancée !* 3 jours d'affilée !\n";
        }
        if ($dailyStreak === 7) {
            return "\n🌟 *Badge débloqué : Série de feu !* Une semaine complète — épique !\n";
        }

        return null;
    }

    /**
     * Weekly leaderboard — top 10 players of the current week (Mon–Sun).
     * Usage: /quiz hebdo  |  /quiz weekly
     */
    private function handleWeeklyLeaderboard(AgentContext $context): AgentResult
    {
        $weekStart = now()->startOfWeek(); // Monday 00:00

        $leaderboard = QuizScore::where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $weekStart)
            ->selectRaw('user_phone, SUM(score) as week_score, COUNT(*) as quizzes_played, AVG(score * 100.0 / total_questions) as avg_percentage')
            ->groupBy('user_phone')
            ->orderByDesc('week_score')
            ->limit(10)
            ->get();

        $weekLabel = now()->startOfWeek()->format('d/m') . ' → ' . now()->endOfWeek()->format('d/m');

        if ($leaderboard->isEmpty()) {
            $reply  = "📅 *Classement Hebdomadaire*\n";
            $reply .= "Semaine du {$weekLabel}\n\n";
            $reply .= "Aucun score cette semaine encore.\n";
            $reply .= "🔄 /quiz — Sois le premier sur le podium !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'weekly_leaderboard_empty']);
        }

        $reply  = "📅 *Classement de la Semaine*\n";
        $reply .= "Semaine du {$weekLabel}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉'];
        foreach ($leaderboard as $i => $entry) {
            $rank   = $medals[$i] ?? ($i + 1) . '.';
            $phone  = '***' . substr($entry->user_phone, -4);
            $avgPct = round($entry->avg_percentage);
            $isMe   = $entry->user_phone === $context->from ? ' ← toi' : '';
            $reply .= "{$rank} *{$phone}* — {$entry->week_score} pts ({$entry->quizzes_played} quiz, {$avgPct}%){$isMe}\n";
        }

        $reply .= "\n🔄 Se remet à zéro lundi prochain !\n";
        $reply .= "🏆 /quiz leaderboard — Classement général (tous les temps)\n";
        $reply .= "🔄 /quiz — Jouer pour grimper";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Weekly leaderboard viewed');

        return AgentResult::reply($reply, ['action' => 'weekly_leaderboard']);
    }

    /**
     * Daily summary — show today's quiz activity for the user.
     * Usage: /quiz today  |  /quiz aujourd'hui
     */
    private function handleDailySummary(AgentContext $context): AgentResult
    {
        $today = now()->toDateString();

        $todayScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('completed_at', $today)
            ->orderBy('completed_at')
            ->get();

        $categories = QuizEngine::getCategories();

        if ($todayScores->isEmpty()) {
            $dailyDone = Quiz::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('category', 'daily')
                ->whereDate('started_at', $today)
                ->whereIn('status', ['completed', 'abandoned'])
                ->exists();

            $reply  = "📆 *Résumé du Jour — {$today}*\n";
            $reply .= "━━━━━━━━━━━━━━━━\n\n";
            $reply .= "Aucun quiz terminé aujourd'hui.\n\n";
            if (!$dailyDone) {
                $reply .= "📅 /quiz daily — Question du jour (pas encore faite !)\n";
            }
            $reply .= "🔄 /quiz — Lance ton premier quiz du jour !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'daily_summary_empty']);
        }

        $totalQuizzes   = $todayScores->count();
        $totalPoints    = $todayScores->sum('score');
        $avgPct         = round($todayScores->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0));
        $bestPct        = round($todayScores->max(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0));
        $dailyCompleted = $todayScores->where('category', 'daily')->count();

        // Yesterday's average for trend comparison
        $yesterday = now()->subDay()->toDateString();
        $yesterdayAvg = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('completed_at', $yesterday)
            ->get()
            ->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);

        $trendStr = '';
        if ($yesterdayAvg !== null && $yesterdayAvg > 0) {
            $diff = $avgPct - round($yesterdayAvg);
            $trendStr = $diff > 0 ? " (+{$diff}% vs hier ↑)" : ($diff < 0 ? " ({$diff}% vs hier ↓)" : " (= vs hier)");
        }

        $streak      = $this->computeDailyStreak($context);
        $streakEmoji = $streak >= 7 ? '🔥🔥' : ($streak >= 3 ? '🔥' : '📅');

        $reply  = "📆 *Résumé du Jour — {$today}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 Quiz joués : *{$totalQuizzes}*\n";
        $reply .= "⭐ Points gagnés : *{$totalPoints}*\n";
        $reply .= "📈 Moyenne : *{$avgPct}%*{$trendStr}\n";
        $reply .= "🏅 Meilleur : *{$bestPct}%*\n";
        $reply .= "{$streakEmoji} Série : *{$streak} jour(s)*\n";

        if ($dailyCompleted > 0) {
            $reply .= "📅 Question du jour : ✅ Faite\n";
        }

        // Per-quiz breakdown
        $reply .= "\n*Détail :*\n";
        foreach ($todayScores as $s) {
            $cat     = match ($s->category) {
                'daily'      => '📅 Quotidien',
                'mix'        => '🎲 Mix',
                'custom'     => '🤖 IA',
                'correction' => '🔁 Correction',
                default      => $categories[$s->category] ?? $s->category,
            };
            $pct    = $s->getPercentage();
            $emoji  = $pct >= 80 ? '🌟' : ($pct >= 50 ? '✅' : '❌');
            $time   = $s->time_taken ? ' — ' . gmdate('i:s', $s->time_taken) : '';
            $reply .= "  {$emoji} {$cat} : {$s->score}/{$s->total_questions} ({$pct}%){$time}\n";
        }

        $dailyDone = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', $today)
            ->whereIn('status', ['completed', 'abandoned'])
            ->exists();

        $reply .= "\n";
        if (!$dailyDone) {
            $reply .= "📅 /quiz daily — Question du jour (pas encore faite !)\n";
        }
        $reply .= "🎯 /quiz objectif — Objectif du jour\n";
        $reply .= "📊 /quiz mystats — Stats complètes\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily summary viewed', ['quizzes_today' => $totalQuizzes, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'daily_summary', 'quizzes_today' => $totalQuizzes]);
    }

    /**
     * AI-generated study tips for a specific quiz category.
     * If no category is specified, defaults to the user's weakest category.
     * Usage: /quiz tip [catégorie]
     */
    private function handleTip(AgentContext $context, ?string $category): AgentResult
    {
        $categories = QuizEngine::getCategories();

        // Resolve category: argument → weakest → random
        if (!$category) {
            $weakest = $this->getWeakestCategory($context);
            if ($weakest) {
                $category = $weakest['category'];
            }
        }

        if (!$category) {
            $catKeys  = array_keys($categories);
            $category = $catKeys[array_rand($catKeys)];
        }

        $catLabel = $categories[$category] ?? $category;

        $this->sendText($context->from, "💡 *Génération de conseils pour {$catLabel}...* Un instant !");

        $model        = $this->resolveModel($context);
        $systemPrompt = "Tu es un coach pédagogique pour un quiz WhatsApp. "
            . "Donne exactement 3 conseils courts, mémorables et pratiques pour progresser dans la catégorie demandée. "
            . "Chaque conseil doit tenir en 1-2 lignes max et être directement actionnable. "
            . "Inclus si possible un moyen mnémotechnique ou une astuce de mémorisation. "
            . "Format STRICT — commence immédiatement par le numéro, sans introduction ni conclusion :\n"
            . "1. [conseil concis et mémorisable]\n"
            . "2. [conseil concis et mémorisable]\n"
            . "3. [conseil concis et mémorisable]\n"
            . "Exemple pour 'géographie' :\n"
            . "1. Associe chaque capitale à une anecdote : Ottawa → 'Ottawa l'outaouaise' (rivière Outaouais).\n"
            . "2. Mémorise les continents par superficie : Asie > Amérique > Afrique > Antarctique > Europe > Océanie.\n"
            . "3. Pour les frontières, dessine mentalement la carte : les voisins partagent toujours une histoire commune.\n"
            . "RÈGLES ABSOLUES : Réponds en FRANÇAIS. Sois factuel, concret et encourageant. "
            . "N'invente JAMAIS de fait. Privilégie les techniques de mémorisation éprouvées (associations visuelles, acronymes, histoires). "
            . "Aucun texte hors du format numéroté.";

        try {
            $llmResponse = $this->claude->chat(
                "Donne 3 conseils courts et mémorables pour améliorer ses connaissances en : {$catLabel}",
                $model,
                $systemPrompt,
                400
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent tip LLM error', ['error' => $e->getMessage()]);
            $llmResponse = null;
        }

        if (!$llmResponse) {
            $reply  = "⚠️ *Quiz Conseils* — L'IA n'a pas pu générer les conseils.\n";
            $reply .= "Réessaie dans un instant, ou lance un quiz avec `/quiz {$category}` !";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Tip LLM failed', ['category' => $category]);
            return AgentResult::reply($reply, ['action' => 'tip_llm_fail']);
        }

        $reply  = "💡 *Conseils — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= trim($llmResponse) . "\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "🎯 Lance un quiz :\n";
        $reply .= "• `/quiz {$category}` — Quiz classique\n";
        $reply .= "• `/quiz perso` — Quiz dans ta catégorie la plus faible\n";
        $reply .= "💡 /quiz tip [catégorie] — Conseils pour une autre catégorie";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Tip generated', ['category' => $category]);

        return AgentResult::reply($reply, ['action' => 'tip', 'category' => $category]);
    }

    /**
     * Personal records — show the user's top 5 best quiz performances (by % score).
     * Ties are broken by total questions count (more questions = harder, ranks higher).
     * Usage: /quiz record
     */
    private function handlePersonalRecords(AgentContext $context): AgentResult
    {
        $topScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily'])
            ->orderByRaw('score * 100.0 / total_questions DESC, total_questions DESC')
            ->limit(5)
            ->get();

        if ($topScores->isEmpty()) {
            $reply  = "🏆 *Mes Meilleurs Scores*\n\n";
            $reply .= "Aucun score enregistré pour l'instant.\n";
            $reply .= "🔄 /quiz — Lance ton premier quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'records_empty']);
        }

        $categories = QuizEngine::getCategories();
        $medals     = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];

        $reply  = "🏆 *Mes Meilleurs Scores — Top 5*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($topScores as $i => $score) {
            $cat = match ($score->category) {
                'mix'        => '🎲 Mix',
                'custom'     => '🤖 IA',
                'correction' => '🔁 Correction',
                default      => $categories[$score->category] ?? $score->category,
            };
            $pct     = $score->getPercentage();
            $date    = $score->completed_at?->format('d/m/Y') ?? '—';
            $timeStr = $score->time_taken ? ' ⏱' . gmdate('i:s', $score->time_taken) : '';
            $medal   = $medals[$i] ?? ($i + 1) . '.';

            $reply .= "{$medal} {$cat} — *{$score->score}/{$score->total_questions}* ({$pct}%){$timeStr}\n";
            $reply .= "   📅 {$date}\n\n";
        }

        // Motivational line based on best score
        $bestPct  = $topScores->first()->getPercentage();
        $motivate = match (true) {
            $bestPct === 100 => "💯 Parfait ! Tu as au moins un score parfait — incroyable !",
            $bestPct >= 80   => "🌟 Excellent ! Peux-tu faire mieux encore ?",
            $bestPct >= 60   => "👍 Bon travail ! Il y a encore de la marge !",
            default          => "💪 Continue à jouer pour améliorer tes records !",
        };

        $reply .= "{$motivate}\n\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques\n";
        $reply .= "🔁 /quiz wrong — Quiz de correction\n";
        $reply .= "🔄 /quiz — Tenter de battre ton record";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Personal records viewed', ['best_pct' => $bestPct]);

        return AgentResult::reply($reply, ['action' => 'personal_records', 'best_pct' => $bestPct]);
    }

    /**
     * Correction quiz — generate a quiz from questions the user previously got wrong.
     * Pulls up to 10 incorrectly answered questions from the last 20 completed quizzes,
     * shuffles them, and creates a new 5-question quiz.
     * Falls back to handlePersonalized() if not enough error history exists.
     * Usage: /quiz wrong  |  /quiz correction
     */
    private function handleWrongQuiz(AgentContext $context): AgentResult
    {
        // Gather incorrectly answered questions from recent quiz history
        $recentQuizzes = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->whereNotIn('category', ['daily', 'custom', 'correction'])
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        $wrongQuestions = [];
        foreach ($recentQuizzes as $quiz) {
            foreach ($quiz->questions as $q) {
                if (($q['user_answered'] ?? false)
                    && !($q['user_correct'] ?? false)
                    && isset($q['question'], $q['options'], $q['answer'])) {
                    $wrongQuestions[] = [
                        'question'      => $q['question'],
                        'options'       => $q['options'],
                        'answer'        => (int) $q['answer'],
                        'category'      => $quiz->category,
                        'hints_used'    => 0,
                        'user_answered' => false,
                        'user_correct'  => false,
                        'user_skipped'  => false,
                    ];
                    if (count($wrongQuestions) >= 10) {
                        break 2;
                    }
                }
            }
        }

        if (count($wrongQuestions) < 2) {
            $reply  = "💪 *Quiz Correction*\n\n";
            $reply .= "Pas encore assez d'erreurs dans ton historique.\n";
            $reply .= "Joue quelques quiz d'abord, puis reviens ici !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n";
            $reply .= "🎯 /quiz perso — Quiz dans ta catégorie la plus faible";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Wrong quiz — insufficient history', ['wrong_count' => count($wrongQuestions)]);
            return AgentResult::reply($reply, ['action' => 'wrong_quiz_no_data']);
        }

        // Deduplicate by question text, shuffle, take up to 5
        $unique = [];
        $seen   = [];
        foreach ($wrongQuestions as $q) {
            $key = md5($q['question']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $q;
            }
        }
        shuffle($unique);
        $questions = array_slice($unique, 0, min(5, count($unique)));

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'correction',
            'difficulty'             => 'medium',
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $intro  = "🔁 *Quiz Correction — Erreurs & Questions Passées*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "Ces *{$quiz->getTotalQuestions()} questions* viennent de tes erreurs et questions passées !\n";
        $intro .= "🎯 Prouve que tu as progressé !\n";
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter la question\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Wrong quiz started', ['questions' => $quiz->getTotalQuestions(), 'source_quizzes' => $recentQuizzes->count()]);

        return AgentResult::reply($reply, ['action' => 'wrong_quiz_start', 'questions' => $quiz->getTotalQuestions()]);
    }

    /**
     * Trending — show top 5 most-played categories in the community over the last 7 days,
     * with their average success rates. Helps users discover popular categories.
     * Usage: /quiz trending  |  /quiz tendances
     */
    private function handleTrending(AgentContext $context): AgentResult
    {
        $since = now()->subDays(7)->startOfDay();

        $trending = QuizScore::where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $since)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as total_played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->orderByDesc('total_played')
            ->limit(5)
            ->get();

        if ($trending->isEmpty()) {
            // Fall back to all-time if no data this week
            $trending = QuizScore::where('agent_id', $context->agent->id)
                ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
                ->selectRaw('category, COUNT(*) as total_played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
                ->groupBy('category')
                ->orderByDesc('total_played')
                ->limit(5)
                ->get();

            if ($trending->isEmpty()) {
                $reply  = "📊 *Catégories Tendance*\n\n";
                $reply .= "Aucune donnée disponible pour l'instant.\n";
                $reply .= "🔄 /quiz — Lance un quiz pour alimenter les tendances !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'trending_empty']);
            }

            $weekLabel = '(tous les temps — pas encore de données cette semaine)';
        } else {
            $weekLabel = now()->subDays(6)->format('d/m') . ' → ' . now()->format('d/m');
        }

        $categories = QuizEngine::getCategories();

        // Which categories has the user played this week?
        $myPlayedCats = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $since)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->pluck('category')
            ->unique()
            ->toArray();

        $reply  = "🔥 *Catégories Tendance — 7 jours*\n";
        $reply .= isset($weekLabel) && str_contains($weekLabel, 'tous') ? "_{$weekLabel}_\n" : "Semaine du {$weekLabel}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $medals = ['🥇', '🥈', '🥉', '4.', '5.'];
        foreach ($trending as $i => $entry) {
            $label    = $categories[$entry->category] ?? $entry->category;
            $medal    = $medals[$i] ?? ($i + 1) . '.';
            $played   = (int) $entry->total_played;
            $avg      = (int) $entry->avg_pct;
            $heat     = $avg >= 80 ? '🌟' : ($avg >= 60 ? '👍' : ($avg >= 40 ? '😊' : '💪'));
            $myMark   = in_array($entry->category, $myPlayedCats) ? ' ✓' : '';
            $reply   .= "{$medal} {$label} — {$played} quiz, {$avg}% {$heat}{$myMark}\n";
        }

        $reply .= "\n✓ = tu as joué cette semaine\n\n";
        $reply .= "💡 Lance un quiz dans une catégorie tendance :\n";

        $topCat = $trending->first();
        if ($topCat) {
            $label  = $categories[$topCat->category] ?? $topCat->category;
            $reply .= "• `/quiz {$topCat->category}` — {$label} (n°1)\n";
        }

        $reply .= "📊 /quiz catstat <catégorie> — Stats détaillées par catégorie\n";
        $reply .= "🏆 /quiz leaderboard — Classement général\n";
        $reply .= "📂 /quiz categories — Toutes les catégories";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Trending viewed', ['top_category' => $topCat->category ?? null]);

        return AgentResult::reply($reply, ['action' => 'trending']);
    }

    /**
     * CatStat — detailed performance stats for the user in a specific quiz category.
     * Shows history, trend, best score, and last 5 results.
     * Usage: /quiz catstat histoire  |  /quiz catstat science
     */
    private function handleCatStat(AgentContext $context, string $category): AgentResult
    {
        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$category] ?? $category;

        $scores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        if ($scores->isEmpty()) {
            $reply  = "📂 *Stats — {$catLabel}*\n\n";
            $reply .= "Tu n'as pas encore joué dans cette catégorie.\n\n";
            $reply .= "🎯 Lance un quiz maintenant :\n";
            $reply .= "• `/quiz {$category}` — Quiz classique\n";
            $reply .= "• `/quiz ia {$catLabel}` — Quiz IA personnalisé\n";
            $reply .= "• `/quiz fun {$category}` — 3 faits fascinants IA\n";
            $reply .= "💡 `/quiz tip {$category}` — Conseils pour progresser";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'catstat_empty', 'category' => $category]);
        }

        $played   = $scores->count();
        $avgPct   = round($scores->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0));
        $bestPct  = round($scores->max(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0));
        $worstPct = round($scores->min(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0));
        $totalPts = (int) $scores->sum('score');

        // Trend: compare oldest half vs newest half
        $trendStr = '';
        if ($played >= 4) {
            $half    = (int) floor($played / 2);
            $oldest  = $scores->slice($half);
            $newest  = $scores->slice(0, $half);
            $oldAvg  = $oldest->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $newAvg  = $newest->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $diff    = round($newAvg - $oldAvg);
            $trendStr = $diff > 0 ? " +{$diff}% ↑" : ($diff < 0 ? " {$diff}% ↓" : " (stable)");
        }

        $perfEmoji = $bestPct >= 100 ? '💯' : ($bestPct >= 80 ? '🌟' : ($bestPct >= 60 ? '👍' : '💪'));

        $reply  = "📂 *Stats détaillées — {$catLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 Quiz joués : *{$played}*\n";
        $reply .= "📈 Moyenne : *{$avgPct}%*{$trendStr}\n";
        $reply .= "{$perfEmoji} Meilleur : *{$bestPct}%* | Pire : {$worstPct}%\n";
        $reply .= "⭐ Points totaux : *{$totalPts}*\n";

        // Last 5 scores with mini trend bar
        $last5 = $scores->take(5);
        if ($last5->count() > 1) {
            $reply .= "\n*5 derniers quiz :*\n";
            foreach ($last5 as $s) {
                $pct    = $s->getPercentage();
                $emoji  = $pct >= 80 ? '🌟' : ($pct >= 50 ? '✅' : '❌');
                $date   = $s->completed_at?->format('d/m') ?? '—';
                $time   = $s->time_taken ? ' ' . gmdate('i:s', $s->time_taken) : '';
                $reply .= "  {$emoji} {$s->score}/{$s->total_questions} ({$pct}%){$time} — {$date}\n";
            }
        }

        // Community context: how does this user compare to others in this category?
        $communityAvg = QuizScore::where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->selectRaw('ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->value('avg_pct');

        if ($communityAvg !== null) {
            $communityAvg = (int) $communityAvg;
            $vs = $avgPct > $communityAvg
                ? "🌟 Au-dessus de la moyenne communauté ({$communityAvg}%)"
                : ($avgPct < $communityAvg
                    ? "📊 En-dessous de la moyenne communauté ({$communityAvg}%)"
                    : "🤝 Dans la moyenne communauté ({$communityAvg}%)");
            $reply .= "\n{$vs}\n";
        }

        $reply .= "\n🎯 `/quiz {$category}` — Rejouer\n";
        $reply .= "💡 `/quiz tip {$category}` — Conseils IA\n";
        $reply .= "🤩 `/quiz fun {$category}` — Faits fascinants\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques";

        $this->sendText($context->from, $reply);
        $this->log($context, 'CatStat viewed', ['category' => $category, 'played' => $played, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'catstat', 'category' => $category]);
    }

    /**
     * Coach — AI-powered personalized coaching session.
     * Analyzes the user's full quiz history and generates a tailored improvement plan.
     * Usage: /quiz coach
     */
    private function handleCoach(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ((int) $stats['quizzes_played'] < 3) {
            $reply  = "🎓 *Coach Quiz IA*\n\n";
            $reply .= "Tu n'as pas encore assez de données (3 quiz minimum).\n";
            $reply .= "Joue quelques quiz d'abord pour débloquer le coaching !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n";
            $reply .= "📅 /quiz daily — Question du jour";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'coach_no_data']);
        }

        $this->sendText($context->from, "🎓 *Coach IA* — Analyse de tes performances en cours... ⏳");

        $categories = QuizEngine::getCategories();

        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, ROUND(MAX(score * 100.0 / total_questions)) as best_pct')
            ->groupBy('category')
            ->orderBy('avg_pct')
            ->get();

        $dailyStreak   = $this->computeDailyStreak($context);
        $quizzesPlayed = (int) $stats['quizzes_played'];
        $avgPct        = (float) $stats['avg_percentage'];
        $bestPct       = (float) $stats['best_score'];

        // Recent 10-quiz trend: compare first half vs second half
        $recentScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get();

        $recentTrend = '';
        if ($recentScores->count() >= 4) {
            $half      = (int) floor($recentScores->count() / 2);
            $firstHalf = $recentScores->slice($half)->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $lastHalf  = $recentScores->slice(0, $half)->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $diff      = round(($lastHalf ?? 0) - ($firstHalf ?? 0));
            $recentTrend = $diff > 0 ? "+{$diff}% en progression récente" : ($diff < 0 ? "{$diff}% en recul récent" : "stable récemment");
        }

        $catAnalysis = '';
        foreach ($catScores as $cs) {
            $label       = $categories[$cs->category] ?? $cs->category;
            $niveau      = $cs->avg_pct < 50 ? 'faible' : ($cs->avg_pct < 70 ? 'moyen' : 'bon');
            $catAnalysis .= "- {$label}: {$cs->avg_pct}% de moyenne ({$cs->played} quiz) — niveau {$niveau}\n";
        }

        // Collect timing data for coaching
        $recentQuizzes = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->latest()
            ->limit(20)
            ->get();
        $timingInfo = '';
        $allTimesCoach = [];
        foreach ($recentQuizzes as $rq) {
            foreach ($rq->questions as $q) {
                if (isset($q['time_taken_secs']) && ($q['user_answered'] ?? false) && !($q['user_skipped'] ?? false)) {
                    $t = (int) $q['time_taken_secs'];
                    if ($t >= 1 && $t <= 300) {
                        $allTimesCoach[] = $t;
                    }
                }
            }
        }
        if (count($allTimesCoach) >= 3) {
            $avgT = round(array_sum($allTimesCoach) / count($allTimesCoach));
            $timingInfo = "- Temps moyen de réponse: {$avgT}s\n";
        }

        $profileStr = "Profil joueur:\n"
            . "- Quiz joués: {$quizzesPlayed}\n"
            . "- Moyenne globale: {$avgPct}%\n"
            . "- Meilleur score: {$bestPct}%\n"
            . "- Série quotidienne: {$dailyStreak} jour(s)\n"
            . $timingInfo
            . ($recentTrend ? "- Tendance récente: {$recentTrend}\n" : '')
            . ($catAnalysis ? "\nPerformances par catégorie:\n{$catAnalysis}" : "\nPas encore de données par catégorie.\n");

        $model        = $this->resolveModel($context);
        $systemPrompt = "Tu es un coach pédagogique expert en quiz pour WhatsApp. "
            . "Analyse le profil du joueur et génère un plan de coaching personnalisé CONCIS et motivant. "
            . "Format STRICT — commence immédiatement sans introduction ni explication :\n\n"
            . "📊 *Diagnostic* : [2 phrases max sur les forces et faiblesses clés]\n\n"
            . "🎯 *3 Priorités cette semaine :*\n"
            . "1. [action concrète avec commande spécifique]\n"
            . "2. [action concrète avec commande spécifique]\n"
            . "3. [action concrète avec commande spécifique]\n\n"
            . "💡 *Astuce du coach* : [1 conseil mémorable ou technique d'apprentissage unique]\n\n"
            . "Exemples d'actions concrètes :\n"
            . "1. Refais 3 quiz en Géographie (/quiz geo) — ta catégorie la plus faible\n"
            . "2. Revois tes erreurs avec /quiz wrong avant chaque nouvelle session\n"
            . "3. Maintiens ta série en jouant /quiz daily chaque matin\n\n"
            . "RÈGLES ABSOLUES : Réponds en FRANÇAIS. Sois encourageant et honnête. "
            . "Cite des commandes exactes (/quiz ...). Maximum 160 mots total. "
            . "Aucun texte hors du format. "
            . "ZÉRO HALLUCINATION : base ton analyse UNIQUEMENT sur les données fournies. "
            . "Ne mentionne JAMAIS de catégorie, score ou statistique qui n'apparaît pas dans le profil.";

        try {
            $llmResponse = $this->claude->chat(
                "Analyse ce profil de joueur quiz et génère un plan de coaching personnalisé :\n\n{$profileStr}",
                $model,
                $systemPrompt,
                600
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent coach LLM error', ['error' => $e->getMessage()]);
            $llmResponse = null;
        }

        if (!$llmResponse) {
            $reply  = "⚠️ *Coach IA* — L'IA n'a pas pu générer le coaching.\n";
            $reply .= "Réessaie dans un instant avec `/quiz coach` !";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Coach LLM failed', ['quizzes_played' => $quizzesPlayed]);
            return AgentResult::reply($reply, ['action' => 'coach_llm_fail']);
        }

        $reply  = "🎓 *Coach Quiz Personnalisé*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= trim($llmResponse) . "\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "📊 /quiz mystats — Tes statistiques\n";
        $reply .= "🔁 /quiz wrong — Quiz de correction\n";
        $reply .= "🎯 /quiz perso — Quiz dans ta catégorie faible\n";
        $reply .= "🔄 /quiz — Lancer un quiz maintenant";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Coach viewed', ['quizzes_played' => $quizzesPlayed, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'coach', 'avg_pct' => $avgPct]);
    }

    /**
     * Timing — detailed response-time analytics across all completed quizzes.
     * Shows average time, fastest/slowest answers, per-category breakdown, and speed rating.
     * Usage: /quiz timing  |  /quiz temps
     */
    private function handleTiming(AgentContext $context): AgentResult
    {
        $quizzes = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->latest()
            ->limit(50)
            ->get();

        if ($quizzes->isEmpty()) {
            $reply  = "⏱ *Analyse des Temps de Réponse*\n\n";
            $reply .= "Aucun quiz terminé.\nLance un quiz avec /quiz !\n\n";
            $reply .= "⏱ /quiz chrono — Mode chrono pour enregistrer tes temps";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'timing_empty']);
        }

        $categories = QuizEngine::getCategories();
        $allTimes   = [];
        $catTimes   = [];

        foreach ($quizzes as $quiz) {
            foreach ($quiz->questions as $q) {
                if (!isset($q['time_taken_secs'])
                    || !($q['user_answered'] ?? false)
                    || ($q['user_skipped'] ?? false)) {
                    continue;
                }
                $t = (int) $q['time_taken_secs'];
                // Sanity filter: ignore impossibly fast (<1s) or timeout responses (>5min)
                if ($t < 1 || $t > 300) {
                    continue;
                }
                $allTimes[]                      = $t;
                $cat                             = $quiz->category ?? 'mix';
                $catTimes[$cat][]                = $t;
            }
        }

        if (count($allTimes) < 3) {
            $counted = count($allTimes);
            $reply  = "⏱ *Analyse des Temps de Réponse*\n\n";
            $reply .= "Pas encore assez de données chronométrées ({$counted} réponse(s) enregistrée(s)).\n\n";
            $reply .= "💡 Tes temps sont automatiquement enregistrés à chaque quiz.\n";
            $reply .= "Lance un quiz pour alimenter cette analyse !\n\n";
            $reply .= "⏱ /quiz chrono — Mode chrono\n";
            $reply .= "🔄 /quiz — Nouveau quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'timing_no_data', 'count' => count($allTimes)]);
        }

        $totalAnswered = count($allTimes);
        $avgTime       = round(array_sum($allTimes) / $totalAnswered);
        $minTime       = min($allTimes);
        $maxTime       = max($allTimes);
        $fastCount     = count(array_filter($allTimes, fn($t) => $t <= 10));
        $slowCount     = count(array_filter($allTimes, fn($t) => $t > 30));

        [$speedLabel, $speedComment] = match (true) {
            $avgTime <= 8  => ['🚀 Ultra-rapide', 'Tu réponds comme un éclair !'],
            $avgTime <= 15 => ['⚡ Rapide', 'Belle réactivité !'],
            $avgTime <= 25 => ['⏱ Normal', 'Bonne réflexion avant de répondre.'],
            $avgTime <= 40 => ['🐢 Prudent', 'Tu prends le temps de réfléchir.'],
            default        => ['🧘 Méditatif', 'Tu ne te précipites vraiment pas !'],
        };

        $reply  = "⏱ *Analyse Temps de Réponse*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📊 *{$totalAnswered}* réponses analysées\n\n";
        $reply .= "{$speedLabel} — Moy. *{$avgTime}s*\n";
        $reply .= "_{$speedComment}_\n\n";
        $reply .= "⚡ Minimum : *{$minTime}s* | Maximum : *{$maxTime}s*\n";
        $reply .= "🔥 Réponses ≤ 10s : *{$fastCount}* ({$this->pct($fastCount, $totalAnswered)}%)\n";
        if ($slowCount > 0) {
            $reply .= "🐢 Réponses > 30s : *{$slowCount}* ({$this->pct($slowCount, $totalAnswered)}%)\n";
        }

        // Per-category average timing (only show if 2+ categories with enough data)
        $catAvgs = [];
        foreach ($catTimes as $cat => $times) {
            if (count($times) < 2) {
                continue;
            }
            $catAvgs[$cat] = round(array_sum($times) / count($times));
        }

        if (count($catAvgs) >= 2) {
            asort($catAvgs); // fastest first
            $reply .= "\n📂 *Temps moyen par catégorie :*\n";
            foreach ($catAvgs as $cat => $avg) {
                $label = match ($cat) {
                    'daily'      => '📅 Quotidien',
                    'mix'        => '🎲 Mix',
                    'custom'     => '🤖 IA',
                    'correction' => '🔁 Correction',
                    default      => $categories[$cat] ?? $cat,
                };
                $speedIcon = $avg <= 10 ? '⚡' : ($avg <= 20 ? '✅' : ($avg <= 35 ? '⏱' : '🐢'));
                $reply    .= "  {$speedIcon} {$label} : *{$avg}s*\n";
            }
        }

        // Trend: compare first 50% of times vs last 50%
        $half = (int) floor($totalAnswered / 2);
        if ($half >= 3) {
            $firstHalfAvg = round(array_sum(array_slice($allTimes, 0, $half)) / $half);
            $lastHalfAvg  = round(array_sum(array_slice($allTimes, -$half)) / $half);
            $timeDiff     = $lastHalfAvg - $firstHalfAvg;
            if (abs($timeDiff) >= 2) {
                $trendIcon = $timeDiff < 0 ? '📈' : '📉';
                $absD      = abs($timeDiff);
                $trendMsg  = $timeDiff < 0
                    ? "Tu réponds *{$absD}s plus vite* qu'au début 📈"
                    : "Tu réponds *{$absD}s plus lentement* qu'au début — normal si les questions sont plus difficiles.";
                $reply .= "\n{$trendIcon} {$trendMsg}\n";
            }
        }

        $reply .= "\n💡 *Astuce :* Les réponses < 10s témoignent d'une excellente maîtrise !\n";
        $reply .= "\n⏱ /quiz chrono — Mode chrono\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques\n";
        $reply .= "🔄 /quiz — Nouveau quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Timing viewed', ['avg_time' => $avgTime, 'total' => $totalAnswered]);

        return AgentResult::reply($reply, ['action' => 'timing', 'avg_time' => $avgTime, 'total' => $totalAnswered]);
    }

    /**
     * Favorite quiz — launch a quiz in the user's most-played category with a stats summary.
     * Usage: /quiz favori  |  /quiz fav
     */
    private function handleFavoriteQuiz(AgentContext $context): AgentResult
    {
        $favCat = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, ROUND(MAX(score * 100.0 / total_questions)) as best_pct')
            ->groupBy('category')
            ->orderByDesc('played')
            ->first();

        if (!$favCat) {
            $reply  = "❤️ *Quiz Favori*\n\n";
            $reply .= "Tu n'as pas encore assez de données pour identifier ta catégorie préférée.\n";
            $reply .= "Joue quelques quiz d'abord !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n";
            $reply .= "📂 /quiz categories — Voir toutes les catégories";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'favorite_no_data']);
        }

        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$favCat->category] ?? $favCat->category;

        $this->sendText(
            $context->from,
            "❤️ *Ta catégorie préférée :* {$catLabel}\n"
            . "📊 {$favCat->played} quiz joués — Moy. {$favCat->avg_pct}% — Meilleur {$favCat->best_pct}%\n\n"
            . "🎯 C'est parti !"
        );

        return $this->handleStartQuiz($context, "/quiz {$favCat->category}");
    }

    /**
     * Category-filtered history — show last 10 quiz results for a specific category.
     * Usage: /quiz historique science  |  /quiz historique histoire
     */
    private function handleCategoryHistory(AgentContext $context, string $category): AgentResult
    {
        $categories = QuizEngine::getCategories();
        $catLabel   = $categories[$category] ?? $category;

        $scores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $category)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get();

        if ($scores->isEmpty()) {
            $reply  = "📜 *Historique — {$catLabel}*\n\n";
            $reply .= "Aucun quiz terminé dans cette catégorie.\n\n";
            $reply .= "🎯 `/quiz {$category}` — Lance ton premier quiz !\n";
            $reply .= "📂 /quiz categories — Toutes les catégories";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'category_history_empty', 'category' => $category]);
        }

        $reply  = "📜 *Historique — {$catLabel} (10 derniers)*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $totalPts = 0;
        $allPcts  = [];

        foreach ($scores as $score) {
            $pct     = $score->getPercentage();
            $date    = $score->completed_at?->format('d/m H:i') ?? '—';
            $timeStr = $score->time_taken ? gmdate('i:s', $score->time_taken) : '—';
            $emoji   = $pct >= 80 ? '🌟' : ($pct >= 50 ? '✅' : '❌');

            $reply    .= "{$emoji} {$score->score}/{$score->total_questions} ({$pct}%) — {$timeStr} — {$date}\n";
            $totalPts += $score->score;
            $allPcts[] = $pct;
        }

        $avgPct = count($allPcts) > 0 ? round(array_sum($allPcts) / count($allPcts)) : 0;

        // Trend: compare first half vs second half
        $trendStr = '';
        if (count($allPcts) >= 4) {
            $half     = (int) floor(count($allPcts) / 2);
            // $allPcts is ordered newest-first, so first half = recent, second half = older
            $recentAvg = array_sum(array_slice($allPcts, 0, $half)) / $half;
            $olderAvg  = array_sum(array_slice($allPcts, -$half)) / $half;
            $diff      = round($recentAvg - $olderAvg);
            $trendStr  = $diff > 0 ? " (+{$diff}% ↑)" : ($diff < 0 ? " ({$diff}% ↓)" : " (stable)");
        }

        $reply .= "\n📊 Moyenne : *{$avgPct}%*{$trendStr}\n";
        $reply .= "⭐ Points totaux : *{$totalPts}*\n";
        $reply .= "\n🎯 `/quiz {$category}` — Rejouer\n";
        $reply .= "📂 `/quiz catstat {$category}` — Stats détaillées\n";
        $reply .= "💡 `/quiz tip {$category}` — Conseils IA\n";
        $reply .= "📜 /quiz history — Historique toutes catégories";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Category history viewed', ['category' => $category, 'count' => $scores->count()]);

        return AgentResult::reply($reply, ['action' => 'category_history', 'category' => $category]);
    }

    /**
     * Random category — pick a category the user hasn't played in the last 7 days.
     * If all categories have been played recently, pick the least-played one.
     * Great for variety and discovering new topics.
     * Usage: /quiz random  |  /quiz surprise  |  /quiz aléatoire
     */
    private function handleRandomCategory(AgentContext $context): AgentResult
    {
        $categories = QuizEngine::getCategories();
        $allCatKeys = array_keys($categories);

        // Find categories played in the last 7 days
        $recentlyPlayed = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', now()->subDays(7))
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->pluck('category')
            ->unique()
            ->toArray();

        // Categories NOT played recently
        $unplayed = array_diff($allCatKeys, $recentlyPlayed);

        if (!empty($unplayed)) {
            $unplayed = array_values($unplayed);
            $chosen   = $unplayed[array_rand($unplayed)];
            $reason   = 'pas jouée cette semaine';
        } else {
            // All played recently — pick the least-played one overall
            $leastPlayed = QuizScore::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
                ->selectRaw('category, COUNT(*) as played')
                ->groupBy('category')
                ->orderBy('played')
                ->first();

            if ($leastPlayed) {
                $chosen = $leastPlayed->category;
                $reason = "la moins jouée ({$leastPlayed->played} quiz)";
            } else {
                $chosen = $allCatKeys[array_rand($allCatKeys)];
                $reason = 'sélection aléatoire';
            }
        }

        $catLabel = $categories[$chosen] ?? $chosen;

        $this->sendText(
            $context->from,
            "🎲 *Quiz Surprise !*\n"
            . "Catégorie choisie : *{$catLabel}*\n"
            . "💡 _{$reason}_\n\n"
            . "C'est parti ! 🚀"
        );

        return $this->handleStartQuiz($context, "/quiz {$chosen}");
    }

    /**
     * Export — generate a complete stats summary card with all key metrics.
     * Provides a comprehensive overview of the user's quiz journey.
     * Usage: /quiz export  |  /quiz bilan
     */
    private function handleExport(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ((int) $stats['quizzes_played'] === 0) {
            $reply  = "📋 *Bilan Quiz*\n\n";
            $reply .= "Aucun quiz terminé pour l'instant.\n";
            $reply .= "🔄 /quiz — Lance ton premier quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'export_empty']);
        }

        $categories    = QuizEngine::getCategories();
        $quizzesPlayed = (int) $stats['quizzes_played'];
        $totalScore    = (int) $stats['total_score'];
        $avgPct        = (float) $stats['avg_percentage'];
        $bestPct       = (float) $stats['best_score'];
        $favCat        = $categories[$stats['favorite_category']] ?? $stats['favorite_category'] ?? '—';
        $dailyStreak   = $this->computeDailyStreak($context);

        // Per-category breakdown
        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, ROUND(MAX(score * 100.0 / total_questions)) as best_pct')
            ->groupBy('category')
            ->orderByDesc('avg_pct')
            ->get();

        // Timing stats
        $quizzes  = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->latest()
            ->limit(50)
            ->get();

        $allTimes = [];
        foreach ($quizzes as $quiz) {
            foreach ($quiz->questions as $q) {
                if (isset($q['time_taken_secs']) && ($q['user_answered'] ?? false) && !($q['user_skipped'] ?? false)) {
                    $t = (int) $q['time_taken_secs'];
                    if ($t >= 1 && $t <= 300) {
                        $allTimes[] = $t;
                    }
                }
            }
        }

        $avgTime = !empty($allTimes) ? round(array_sum($allTimes) / count($allTimes)) : null;

        // Total days played
        $totalDays = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->selectRaw('DATE(completed_at) as play_date')
            ->groupBy('play_date')
            ->get()
            ->count();

        // First quiz date
        $firstQuiz = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('completed_at')
            ->first();
        $firstDate = $firstQuiz?->completed_at?->format('d/m/Y') ?? '—';

        // Rank
        $allPlayers = QuizScore::where('agent_id', $context->agent->id)
            ->selectRaw('user_phone, SUM(score) as total_score')
            ->groupBy('user_phone')
            ->orderByDesc('total_score')
            ->get();

        $myRank = null;
        foreach ($allPlayers as $i => $player) {
            if ($player->user_phone === $context->from) {
                $myRank = $i + 1;
                break;
            }
        }
        $totalPlayers = $allPlayers->count();

        // Badges earned count (uses shared computation)
        $badgeData   = $this->computeBadges($context);
        $badgeCount  = $badgeData['earned_count'];
        $totalBadges = $badgeData['total'];

        // Build the export card
        $reply  = "📋 *BILAN QUIZ — ZeniClaw*\n";
        $reply .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $reply .= "👤 *Profil Joueur*\n";
        $reply .= "  📅 Inscrit depuis : {$firstDate}\n";
        $reply .= "  🎮 Quiz joués : *{$quizzesPlayed}*\n";
        $reply .= "  📅 Jours actifs : *{$totalDays}*\n";
        if ($myRank) {
            $reply .= "  🏅 Rang : *#{$myRank}* / {$totalPlayers}\n";
        }
        $reply .= "\n📊 *Performance*\n";
        $reply .= "  ⭐ Score total : *{$totalScore}* pts\n";
        $reply .= "  📈 Moyenne : *{$avgPct}%*\n";
        $reply .= "  🏅 Meilleur : *{$bestPct}%*\n";
        if ($avgTime !== null) {
            $reply .= "  ⏱ Temps moyen : *{$avgTime}s* / question\n";
        }
        $reply .= "\n🔥 *Séries & Badges*\n";
        $reply .= "  🔥 Série quotidienne : *{$dailyStreak}* jour(s)\n";
        $reply .= "  🏅 Badges débloqués : *{$badgeCount}* / {$totalBadges}\n";
        $reply .= "  ❤️ Catégorie préférée : {$favCat}\n";

        if ($catScores->isNotEmpty()) {
            $reply .= "\n📂 *Détail par catégorie*\n";
            foreach ($catScores as $cs) {
                $label = $categories[$cs->category] ?? $cs->category;
                $bar   = $cs->avg_pct >= 80 ? '🌟' : ($cs->avg_pct >= 60 ? '👍' : ($cs->avg_pct >= 40 ? '😊' : '💪'));
                $reply .= "  {$bar} {$label} : {$cs->played} quiz — Moy. {$cs->avg_pct}% — Best {$cs->best_pct}%\n";
            }
        }

        // Overall level assessment
        $level = match (true) {
            $avgPct >= 85 && $quizzesPlayed >= 50 => '🏆 Expert',
            $avgPct >= 70 && $quizzesPlayed >= 20 => '🌟 Avancé',
            $avgPct >= 55 && $quizzesPlayed >= 10 => '📈 Intermédiaire',
            $quizzesPlayed >= 5                    => '🎯 Débutant motivé',
            default                                => '🌱 Débutant',
        };

        $reply .= "\n🎓 *Niveau global : {$level}*\n";
        $reply .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $reply .= "_Bilan généré le " . now()->format('d/m/Y à H:i') . "_\n";
        $reply .= "_ZeniClaw Quiz v{$this->version()}_\n\n";
        $reply .= "🔄 /quiz — Jouer\n";
        $reply .= "🎓 /quiz coach — Coaching IA\n";
        $reply .= "📊 /quiz mystats — Stats détaillées";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Export generated', ['quizzes_played' => $quizzesPlayed, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'export', 'quizzes_played' => $quizzesPlayed]);
    }

    /**
     * Niveau — analyze the user's performance to recommend an optimal difficulty level.
     * Uses average score, recent trend, and per-difficulty stats to give a tailored recommendation.
     * Usage: /quiz niveau  |  /quiz difficulté
     */
    private function handleNiveau(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ((int) $stats['quizzes_played'] < 3) {
            $reply  = "📊 *Recommandation de Niveau*\n\n";
            $reply .= "Tu n'as pas encore assez de données (3 quiz minimum).\n";
            $reply .= "Lance quelques quiz pour que je puisse analyser ton niveau !\n\n";
            $reply .= "🟢 `/quiz facile` — Commencer en douceur (3 questions)\n";
            $reply .= "🟡 `/quiz` — Niveau moyen (5 questions)\n";
            $reply .= "🔴 `/quiz difficile` — Pour les experts (7 questions)";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'niveau_no_data']);
        }

        $avgPct = (float) $stats['avg_percentage'];
        $categories = QuizEngine::getCategories();

        // Per-difficulty stats
        $diffStats = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->join('quizzes', 'quiz_scores.quiz_id', '=', 'quizzes.id')
            ->selectRaw('quizzes.difficulty, COUNT(*) as played, ROUND(AVG(quiz_scores.score * 100.0 / quiz_scores.total_questions)) as avg_pct')
            ->groupBy('quizzes.difficulty')
            ->get()
            ->keyBy('difficulty');

        // Recent 5 quiz trend
        $recentScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('completed_at')
            ->limit(5)
            ->get();

        $recentAvg = $recentScores->count() > 0
            ? round($recentScores->avg(fn($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0))
            : $avgPct;

        // Determine recommended difficulty
        $recommendation = match (true) {
            $recentAvg >= 85 => 'hard',
            $recentAvg >= 55 => 'medium',
            default          => 'easy',
        };

        // If they're already doing well on their current level, suggest upgrading
        $currentLevel = null;
        $upgrade = false;
        foreach (['easy', 'medium', 'hard'] as $diff) {
            if (isset($diffStats[$diff]) && (int) $diffStats[$diff]->played >= 3) {
                $currentLevel = $diff;
            }
        }
        if ($currentLevel && $currentLevel !== 'hard') {
            $currentAvg = isset($diffStats[$currentLevel]) ? (int) $diffStats[$currentLevel]->avg_pct : 0;
            if ($currentAvg >= 80) {
                $upgrade = true;
                $nextLevel = $currentLevel === 'easy' ? 'medium' : 'hard';
                $recommendation = $nextLevel;
            }
        }

        $recLabel = self::DIFFICULTY_LABELS[$recommendation] ?? '🟡 Moyen';
        $recCount = self::DIFFICULTY_QUESTION_COUNT[$recommendation] ?? 5;

        $reply  = "📊 *Recommandation de Niveau*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        // Show per-difficulty breakdown
        $reply .= "📈 *Tes performances par niveau :*\n";
        foreach (['easy' => '🟢 Facile', 'medium' => '🟡 Moyen', 'hard' => '🔴 Difficile'] as $diff => $label) {
            if (isset($diffStats[$diff])) {
                $d = $diffStats[$diff];
                $emoji = $d->avg_pct >= 80 ? '🌟' : ($d->avg_pct >= 50 ? '✅' : '💪');
                $reply .= "  {$label} : {$emoji} {$d->avg_pct}% ({$d->played} quiz)\n";
            } else {
                $reply .= "  {$label} : _pas encore testé_\n";
            }
        }

        $reply .= "\n📊 Moyenne récente (5 derniers) : *{$recentAvg}%*\n";
        $reply .= "📈 Moyenne globale : *{$avgPct}%*\n\n";

        if ($upgrade) {
            $reply .= "🚀 *Tu maîtrises ton niveau actuel !*\n";
            $reply .= "Je te recommande de passer au niveau supérieur.\n\n";
        }

        $reply .= "🎯 *Niveau recommandé : {$recLabel}* ({$recCount} questions)\n\n";

        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "Lancer un quiz au niveau recommandé :\n";
        $diffCmd = match ($recommendation) {
            'easy'   => 'facile',
            'hard'   => 'difficile',
            default  => '',
        };
        $reply .= $diffCmd
            ? "• `/quiz {$diffCmd}` — {$recLabel}\n"
            : "• `/quiz` — {$recLabel}\n";
        $reply .= "🔄 /quiz categories — Choisir une catégorie\n";
        $reply .= "🎓 /quiz coach — Coaching IA personnalisé";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Niveau viewed', ['recommendation' => $recommendation, 'recent_avg' => $recentAvg, 'global_avg' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'niveau', 'recommendation' => $recommendation]);
    }

    /**
     * Revanche — replay the exact same questions from the user's last completed quiz.
     * Creates a new quiz with identical questions (shuffled options) to let the user try to beat their score.
     * Usage: /quiz revanche  |  /quiz rematch
     */
    private function handleRevanche(AgentContext $context): AgentResult
    {
        $lastQuiz = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->whereNotIn('category', ['daily'])
            ->orderByDesc('completed_at')
            ->first();

        if (!$lastQuiz) {
            $reply  = "🔁 *Quiz Revanche*\n\n";
            $reply .= "Aucun quiz précédent à rejouer.\n";
            $reply .= "Lance un quiz avec /quiz d'abord !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'revanche_none']);
        }

        $originalQuestions = $lastQuiz->questions;
        if (empty($originalQuestions) || count($originalQuestions) < 1) {
            $reply  = "⚠️ *Quiz Revanche* — Le dernier quiz n'a pas de questions récupérables.\n";
            $reply .= "🔄 /quiz — Lancer un nouveau quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'revanche_no_questions']);
        }

        $prevScore = $lastQuiz->correct_answers;
        $prevTotal = $lastQuiz->getTotalQuestions();
        $prevPct   = $prevTotal > 0 ? round(($prevScore / $prevTotal) * 100) : 0;

        // Reset user progress on each question but keep the original Q&A
        $questions = array_map(function (array $q) {
            return [
                'question'      => $q['question'],
                'options'       => $q['options'],
                'answer'        => $q['answer'],
                'category'      => $q['category'] ?? 'mix',
                'topic'         => $q['topic'] ?? null,
                'hints_used'    => 0,
                'user_answered' => false,
                'user_correct'  => false,
                'user_skipped'  => false,
            ];
        }, $originalQuestions);

        // Abandon any existing active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => $lastQuiz->category,
            'difficulty'             => $lastQuiz->difficulty ?? 'medium',
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $categories = QuizEngine::getCategories();
        $catLabel   = match ($lastQuiz->category) {
            'mix'        => '🎲 Mix',
            'custom'     => '🤖 IA',
            'correction' => '🔁 Correction',
            default      => $categories[$lastQuiz->category] ?? $lastQuiz->category,
        };

        $intro  = "🔁 *Quiz Revanche — {$catLabel}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "🎯 Score à battre : *{$prevScore}/{$prevTotal}* ({$prevPct}%)\n";
        $intro .= "Mêmes questions — peux-tu faire mieux ?\n";
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Revanche started', [
            'original_quiz_id' => $lastQuiz->id,
            'prev_score'       => $prevScore,
            'prev_total'       => $prevTotal,
            'category'         => $lastQuiz->category,
        ]);

        return AgentResult::reply($reply, ['action' => 'revanche_start', 'prev_score' => "{$prevScore}/{$prevTotal}"]);
    }


    /**
     * Duel Results — show past challenge/duel results and outcomes.
     * Usage: /quiz duel  |  /quiz duels  |  /quiz défis
     */
    private function handleDuelResults(AgentContext $context): AgentResult
    {
        $myDuels = Quiz::where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->where(function ($q) use ($context) {
                $q->where(function ($sub) use ($context) {
                    $sub->where('user_phone', $context->from)
                        ->whereNotNull('challenger_phone');
                })->orWhere('challenger_phone', $context->from);
            })
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        if ($myDuels->isEmpty()) {
            $reply  = "⚔️ *Mes Duels Quiz*\n\n";
            $reply .= "Tu n'as pas encore participé à un duel.\n";
            $reply .= "Défie un ami avec : `challenge @+33612345678` !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz solo";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'duel_results_empty']);
        }

        $duelPairs = [];
        foreach ($myDuels as $duel) {
            $challengerPhone = $duel->challenger_phone;
            if (!$challengerPhone) {
                continue;
            }
            $opponentPhone = ($duel->user_phone === $context->from) ? $challengerPhone : $duel->user_phone;
            $key = implode('|', [min($context->from, $opponentPhone), max($context->from, $opponentPhone)]);
            if (!isset($duelPairs[$key])) {
                $duelPairs[$key] = ['opponent' => $opponentPhone, 'my_quizzes' => [], 'their_quizzes' => []];
            }
            if ($duel->user_phone === $context->from) {
                $duelPairs[$key]['my_quizzes'][] = $duel;
            } else {
                $duelPairs[$key]['their_quizzes'][] = $duel;
            }
        }

        $reply  = "⚔️ *Mes Duels Quiz*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $totalWins = 0;
        $totalLosses = 0;
        $totalDraws = 0;
        $duelCount = 0;

        foreach ($duelPairs as $pair) {
            $opponentDisplay = '***' . substr(preg_replace('/@.*/', '', $pair['opponent']), -4);
            $myBestScore = 0;
            $myBestTotal = 0;
            $theirBestScore = 0;
            $theirBestTotal = 0;

            foreach ($pair['my_quizzes'] as $quiz) {
                if ($quiz->correct_answers > $myBestScore || $myBestTotal === 0) {
                    $myBestScore = $quiz->correct_answers;
                    $myBestTotal = $quiz->getTotalQuestions();
                }
            }
            foreach ($pair['their_quizzes'] as $quiz) {
                if ($quiz->correct_answers > $theirBestScore || $theirBestTotal === 0) {
                    $theirBestScore = $quiz->correct_answers;
                    $theirBestTotal = $quiz->getTotalQuestions();
                }
            }

            $myPct = $myBestTotal > 0 ? round(($myBestScore / $myBestTotal) * 100) : 0;
            $theirPct = $theirBestTotal > 0 ? round(($theirBestScore / $theirBestTotal) * 100) : 0;

            if (!empty($pair['my_quizzes']) && !empty($pair['their_quizzes'])) {
                $duelCount++;
                if ($myPct > $theirPct) {
                    $totalWins++;
                    $icon = '🏆';
                } elseif ($theirPct > $myPct) {
                    $totalLosses++;
                    $icon = '😤';
                } else {
                    $totalDraws++;
                    $icon = '🤝';
                }
                $reply .= "{$icon} vs *{$opponentDisplay}* — {$myBestScore}/{$myBestTotal} ({$myPct}%) vs {$theirBestScore}/{$theirBestTotal} ({$theirPct}%)\n";
            } elseif (!empty($pair['my_quizzes'])) {
                $reply .= "⏳ vs *{$opponentDisplay}* — {$myBestScore}/{$myBestTotal} ({$myPct}%) — _en attente_\n";
            } else {
                $reply .= "📩 *{$opponentDisplay}* t'a défié — _joue /quiz pour relever le défi !_\n";
            }
        }

        if ($duelCount > 0) {
            $reply .= "\n📊 *Bilan :* {$totalWins}W / {$totalLosses}L / {$totalDraws}D\n";
        }

        $reply .= "\n⚔️ `challenge @+336XXXXXXXX` — Lancer un nouveau défi\n";
        $reply .= "📊 /quiz mystats — Tes statistiques\n";
        $reply .= "🏆 /quiz leaderboard — Classement général";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Duel results viewed', ['duels' => $duelCount, 'wins' => $totalWins]);

        return AgentResult::reply($reply, ['action' => 'duel_results', 'duels' => $duelCount, 'wins' => $totalWins]);
    }

    /**
     * Smart Recommend — personalized quiz recommendation combining user weaknesses,
     * trending community categories, time since last played, and daily streak status.
     * Usage: /quiz recommande  |  /quiz recommend
     */
    private function handleRecommend(AgentContext $context): AgentResult
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);

        if ((int) $stats['quizzes_played'] < 1) {
            $reply  = "🧭 *Recommandation Quiz*\n\n";
            $reply .= "Tu n'as pas encore joué de quiz !\n\n";
            $reply .= "Voici par où commencer :\n";
            $reply .= "• `/quiz` — Quiz aléatoire pour découvrir\n";
            $reply .= "• `/quiz daily` — La question du jour\n";
            $reply .= "• `/quiz categories` — Choisis ta catégorie préférée\n";
            $reply .= "• `/quiz mini` — Quiz ultra-rapide (2 questions)";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'recommend_new_user']);
        }

        $categories = QuizEngine::getCategories();

        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, MAX(completed_at) as last_played')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $since = now()->subDays(7)->startOfDay();
        $trending = QuizScore::where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $since)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as total_played')
            ->groupBy('category')
            ->orderByDesc('total_played')
            ->limit(3)
            ->pluck('total_played', 'category')
            ->toArray();

        $dailyStreak = $this->computeDailyStreak($context);
        $playedToday = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('completed_at', now()->toDateString())
            ->exists();

        $dailyDone = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('started_at', now()->toDateString())
            ->whereIn('status', ['completed', 'abandoned'])
            ->exists();

        $hasWrongQuestions = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->whereNotIn('category', ['daily', 'custom', 'correction'])
            ->latest()->limit(10)->get()
            ->contains(function ($quiz) {
                foreach ($quiz->questions as $q) {
                    if (($q['user_answered'] ?? false) && !($q['user_correct'] ?? false)) {
                        return true;
                    }
                }
                return false;
            });

        $reply  = "🧭 *Recommandation Quiz Personnalisée*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $recommendations = [];

        if (!$dailyDone) {
            $urgency = $dailyStreak > 0 ? "⚠️ Protège ta série de {$dailyStreak} jour(s) !" : "🌅 Commence ta série quotidienne !";
            $recommendations[] = "📅 *Question du jour* — {$urgency}\n   → `/quiz daily`";
        }

        $weakest = $this->getWeakestCategory($context);
        if ($weakest && $weakest['avg_pct'] < 70) {
            $catLabel = $categories[$weakest['category']] ?? $weakest['category'];
            $recommendations[] = "💪 *Catégorie à améliorer :* {$catLabel} ({$weakest['avg_pct']}%)\n   → `/quiz {$weakest['category']}`";
        }

        foreach ($trending as $cat => $playCount) {
            if (!isset($catScores[$cat]) || (int) $catScores[$cat]->played < 2) {
                $catLabel = $categories[$cat] ?? $cat;
                $recommendations[] = "🔥 *Tendance communauté :* {$catLabel} ({$playCount} quiz cette semaine)\n   → `/quiz {$cat}`";
                break;
            }
        }

        foreach ($catScores as $cat => $data) {
            $lastPlayed = $data->last_played ? \Carbon\Carbon::parse($data->last_played) : null;
            if ($lastPlayed && $lastPlayed->diffInDays(now()) >= 7) {
                $catLabel = $categories[$cat] ?? $cat;
                $daysAgo = (int) $lastPlayed->diffInDays(now());
                $recommendations[] = "🔄 *Pas joué depuis {$daysAgo}j :* {$catLabel}\n   → `/quiz {$cat}`";
                break;
            }
        }

        if ($hasWrongQuestions) {
            $recommendations[] = "🔁 *Quiz correction* — Revois tes erreurs récentes\n   → `/quiz wrong`";
        }

        $recommendations = array_slice($recommendations, 0, 4);
        if (empty($recommendations)) {
            $recommendations[] = "🎲 *Quiz aléatoire* — Continue à jouer !\n   → `/quiz`";
        }

        foreach ($recommendations as $i => $rec) {
            $reply .= ($i + 1) . ". {$rec}\n\n";
        }

        $reply .= "━━━━━━━━━━━━━━━━\n";
        $streakIcon = $dailyStreak >= 3 ? '🔥' : '📅';
        $reply .= "{$streakIcon} Série : {$dailyStreak}j";
        $reply .= $playedToday ? " | ✅ Joué aujourd'hui" : " | ⏳ Pas encore joué aujourd'hui";
        $reply .= "\n📊 Moyenne globale : {$stats['avg_percentage']}%\n\n";
        $reply .= "📊 /quiz mystats — Tes statistiques\n";
        $reply .= "📂 /quiz categories — Toutes les catégories";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Recommend viewed', ['recommendations' => count($recommendations), 'streak' => $dailyStreak]);

        return AgentResult::reply($reply, ['action' => 'recommend', 'count' => count($recommendations)]);
    }
    /**
     * Compute percentage rounded to integer (safe division).
     */
    private function pct(int $part, int $total): int
    {
        return $total > 0 ? (int) round(($part / $total) * 100) : 0;
    }

    /**
     * Compute all earned badges for the user.
     * Returns ['badges' => [...], 'earned' => [...], 'locked' => [...], 'earned_count' => int, 'total' => int].
     */
    private function computeBadges(AgentContext $context): array
    {
        $stats = QuizScore::getUserStats($context->from, $context->agent->id);
        $quizzesPlayed = (int) $stats['quizzes_played'];
        $avgPct        = (float) $stats['avg_percentage'];
        $bestScore     = (float) $stats['best_score'];
        $dailyStreak   = $this->computeDailyStreak($context);

        $marathonCount = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->where('difficulty', 'hard')
            ->whereRaw('JSON_LENGTH(questions) >= 10')
            ->count();

        $dailyAnswered = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->count();

        $categoriesPlayed = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom'])
            ->distinct('category')
            ->count('category');

        $expertCategory = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom'])
            ->selectRaw('category, COUNT(*) as played, AVG(score * 100.0 / total_questions) as avg_pct')
            ->groupBy('category')
            ->having('played', '>=', 5)
            ->having('avg_pct', '>=', 80)
            ->first();

        $fastAnswer = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->latest()
            ->limit(200)
            ->get()
            ->contains(function ($quiz) {
                foreach ($quiz->questions as $q) {
                    if (isset($q['time_taken_secs'])
                        && (int) $q['time_taken_secs'] <= 5
                        && ($q['user_correct'] ?? false)
                        && !($q['user_skipped'] ?? false)) {
                        return true;
                    }
                }
                return false;
            });

        $weekStart  = now()->startOfWeek();
        $weekTopUser = QuizScore::where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $weekStart)
            ->selectRaw('user_phone, SUM(score) as week_score')
            ->groupBy('user_phone')
            ->orderByDesc('week_score')
            ->first();
        $isWeekTop = $weekTopUser && $weekTopUser->user_phone === $context->from;

        $aiQuizDone = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'custom')
            ->exists();

        $badges = [
            ['🎯', 'Débutant',        'Premier quiz complété',                          $quizzesPlayed >= 1],
            ['🏅', 'Assidu',          '10 quiz complétés',                              $quizzesPlayed >= 10],
            ['🏆', 'Vétéran',         '50 quiz complétés',                              $quizzesPlayed >= 50],
            ['💯', 'Perfectionniste', 'Score parfait (100%) sur un quiz',               $bestScore >= 100],
            ['🔥', 'Sur la lancée',   '3 jours consécutifs de quiz',                    $dailyStreak >= 3],
            ['🌟', 'Série de feu',    '7 jours consécutifs de quiz',                    $dailyStreak >= 7],
            ['🏃', 'Marathonien',     'Marathon (10 questions) complété',                $marathonCount >= 1],
            ['📅', 'Quotidien',       '5 questions du jour répondues',                  $dailyAnswered >= 5],
            ['🌍', 'Touche-à-tout',   'Quiz joués dans 5 catégories ou plus',           $categoriesPlayed >= 5],
            ['🧠', 'Expert',          '80%+ de moyenne sur 5+ quiz d\'une catégorie',   $expertCategory !== null],
            ['⚡', 'Éclair',          'Bonne réponse en moins de 5 secondes',           $fastAnswer],
            ['👑', 'Roi de la semaine','N°1 du classement hebdomadaire',                $isWeekTop],
            ['🤖', 'Créateur',        'Quiz IA personnalisé complété',                  $aiQuizDone],
        ];

        $earned = array_filter($badges, fn($b) => $b[3]);
        $locked = array_filter($badges, fn($b) => !$b[3]);

        return [
            'badges'       => $badges,
            'earned'       => $earned,
            'locked'       => $locked,
            'earned_count' => count($earned),
            'total'        => count($badges),
        ];
    }

    /**
     * Difficulty Stats — performance breakdown by difficulty level (easy/medium/hard).
     * Usage: /quiz diffstats  |  /quiz niveaux
     */
    private function handleDifficultyStats(AgentContext $context): AgentResult
    {
        $difficulties = ['easy', 'medium', 'hard'];

        $quizzes = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->whereIn('difficulty', $difficulties)
            ->selectRaw('difficulty, COUNT(*) as played, SUM(correct_answers) as total_correct, SUM(JSON_LENGTH(questions)) as total_questions')
            ->groupBy('difficulty')
            ->get()
            ->keyBy('difficulty');

        if ($quizzes->isEmpty()) {
            $reply  = "📊 *Stats par Difficulté*\n\n";
            $reply .= "Aucun quiz terminé pour l'instant.\n";
            $reply .= "🔄 /quiz facile — Essaie un quiz facile !\n";
            $reply .= "🔄 /quiz difficile — Ou tente le difficile !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'diffstats_empty']);
        }

        $reply  = "📊 *Stats par Difficulté*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($difficulties as $diff) {
            $label = self::DIFFICULTY_LABELS[$diff];
            $entry = $quizzes->get($diff);

            if (!$entry || (int) $entry->played === 0) {
                $reply .= "{$label}\n  _Pas encore joué_\n\n";
                continue;
            }

            $played       = (int) $entry->played;
            $totalCorrect = (int) $entry->total_correct;
            $totalQ       = (int) $entry->total_questions;
            $avgPct       = $totalQ > 0 ? (int) round(($totalCorrect / $totalQ) * 100) : 0;

            $bestQuiz = Quiz::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('status', 'completed')
                ->where('difficulty', $diff)
                ->orderByRaw('correct_answers * 100.0 / JSON_LENGTH(questions) DESC')
                ->first();

            $bestPct = 0;
            if ($bestQuiz) {
                $bestTotal = count($bestQuiz->questions);
                $bestPct   = $bestTotal > 0 ? (int) round(($bestQuiz->correct_answers / $bestTotal) * 100) : 0;
            }

            $filled    = (int) min(10, round($avgPct / 10));
            $bar       = str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
            $perfEmoji = $avgPct >= 80 ? '🌟' : ($avgPct >= 60 ? '👍' : ($avgPct >= 40 ? '😊' : '💪'));

            $reply .= "{$label} {$perfEmoji}\n";
            $reply .= "  🎮 {$played} quiz — [{$bar}] {$avgPct}%\n";
            $reply .= "  🏅 Meilleur : {$bestPct}% | Total : {$totalCorrect}/{$totalQ}\n\n";
        }

        $worstDiff = null;
        $worstAvg  = 101;
        $bestDiff  = null;
        $bestAvg   = -1;
        foreach ($quizzes as $diff => $entry) {
            $totalQ = (int) $entry->total_questions;
            $avg    = $totalQ > 0 ? ($entry->total_correct / $totalQ) * 100 : 0;
            if ($avg > $bestAvg) { $bestAvg = $avg; $bestDiff = $diff; }
            if ($avg < $worstAvg) { $worstAvg = $avg; $worstDiff = $diff; }
        }

        if ($worstDiff && $worstDiff !== $bestDiff) {
            $worstLabel = self::DIFFICULTY_LABELS[$worstDiff];
            $reply .= "💡 _Travaille le niveau {$worstLabel} pour progresser !_\n\n";
        }

        $reply .= "🔄 /quiz facile — Quiz 🟢\n";
        $reply .= "🔄 /quiz — Quiz 🟡\n";
        $reply .= "🔄 /quiz difficile — Quiz 🔴\n";
        $reply .= "📊 /quiz mystats — Toutes tes statistiques";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Difficulty stats viewed');

        return AgentResult::reply($reply, ['action' => 'diffstats']);
    }

    /**
     * Category Mastery — mastery levels per category (Novice→Bronze→Argent→Or→Diamant→Maître).
     * Usage: /quiz mastery  |  /quiz maîtrise
     */
    private function handleMastery(AgentContext $context): AgentResult
    {
        $categories = QuizEngine::getCategories();

        $catScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'custom', 'correction'])
            ->selectRaw('category, COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->orderByDesc('avg_pct')
            ->get();

        if ($catScores->isEmpty()) {
            $reply  = "🏅 *Maîtrise par Catégorie*\n\n";
            $reply .= "Tu n'as pas encore de données par catégorie.\n";
            $reply .= "📂 /quiz categories — Voir les catégories\n";
            $reply .= "🔄 /quiz — Lancer un quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'mastery_empty']);
        }

        $reply  = "🏅 *Maîtrise par Catégorie*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $totalMastery = 0;
        $maxMastery   = count($categories) * 5;

        foreach ($categories as $catKey => $catLabel) {
            $entry = $catScores->firstWhere('category', $catKey);

            if (!$entry) {
                $reply .= "🔒 {$catLabel} — _Non exploré_\n";
                continue;
            }

            $played = (int) $entry->played;
            $avgPct = (int) $entry->avg_pct;

            $scoreComp    = $avgPct / 20;
            $volumeComp   = min($played, 10) / 2;
            $masteryScore = $scoreComp + $volumeComp;

            [$lvlEmoji, $lvlName, $lvlNum] = match (true) {
                $masteryScore >= 9   => ['👑', 'Maître', 5],
                $masteryScore >= 7   => ['💎', 'Diamant', 4],
                $masteryScore >= 5   => ['🥇', 'Or', 3],
                $masteryScore >= 3.5 => ['🥈', 'Argent', 2],
                $masteryScore >= 2   => ['🥉', 'Bronze', 1],
                default              => ['🔰', 'Novice', 0],
            };

            $totalMastery += $lvlNum;

            $curT  = match ($lvlNum) { 5 => 9, 4 => 7, 3 => 5, 2 => 3.5, 1 => 2, default => 0 };
            $nextT = match ($lvlNum) { 4 => 2, 3 => 2, 2 => 1.5, 1 => 1.5, 0 => 2, default => 1 };
            $pPct  = $lvlNum >= 5 ? 100 : min(100, (int) round((($masteryScore - $curT) / $nextT) * 100));
            $filled = (int) min(5, round($pPct / 20));
            $bar    = str_repeat('▓', $filled) . str_repeat('░', 5 - $filled);

            $reply .= "{$lvlEmoji} {$catLabel} — *{$lvlName}*\n";
            $reply .= "   [{$bar}] {$played} quiz, {$avgPct}% moy.\n";
        }

        $overallPct   = $maxMastery > 0 ? (int) round(($totalMastery / $maxMastery) * 100) : 0;
        $overallLevel = match (true) {
            $overallPct >= 80 => '👑 Maître Suprême',
            $overallPct >= 60 => '💎 Expert Polyvalent',
            $overallPct >= 40 => '🥇 Connaisseur',
            $overallPct >= 20 => '🥈 Apprenti',
            default           => '🔰 Débutant',
        };

        $reply .= "\n━━━━━━━━━━━━━━━━\n";
        $reply .= "📊 *Maîtrise globale : {$overallPct}%* — {$overallLevel}\n\n";

        $closestCat = null;
        $closestGap = PHP_FLOAT_MAX;
        foreach ($catScores as $e) {
            $sc = (int) $e->avg_pct / 20 + min((int) $e->played, 10) / 2;
            foreach ([2, 3.5, 5, 7, 9] as $t) {
                $gap = $t - $sc;
                if ($gap > 0 && $gap < $closestGap) {
                    $closestGap = $gap;
                    $closestCat = $e->category;
                }
            }
        }

        if ($closestCat) {
            $closestLabel = $categories[$closestCat] ?? $closestCat;
            $reply .= "💡 _Joue {$closestLabel} pour atteindre le niveau suivant !_\n\n";
        }

        $reply .= "🎯 `/quiz <catégorie>` — Jouer pour progresser\n";
        $reply .= "📂 /quiz catstat <cat> — Stats détaillées\n";
        $reply .= "🎓 /quiz coach — Coaching IA\n";
        $reply .= "📋 /quiz export — Bilan complet";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Mastery viewed', ['overall_pct' => $overallPct]);

        return AgentResult::reply($reply, ['action' => 'mastery', 'overall_pct' => $overallPct]);
    }

    /**
     * Calendar — monthly activity heatmap showing which days the user played quizzes.
     * Displays a visual grid with emoji indicators for activity intensity and performance.
     * Usage: /quiz calendrier  |  /quiz calendar
     */
    private function handleCalendar(AgentContext $context): AgentResult
    {
        $now   = now();
        $year  = $now->year;
        $month = $now->month;

        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();

        $dayStats = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereBetween('completed_at', [$monthStart, $monthEnd])
            ->selectRaw('DATE(completed_at) as play_date, COUNT(*) as quizzes, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('play_date')
            ->get()
            ->keyBy('play_date');

        $daysInMonth = $monthEnd->day;
        $firstDayOfWeek = $monthStart->dayOfWeekIso; // 1=Mon, 7=Sun

        $monthNames = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        $reply  = "📅 *Calendrier Quiz — {$monthNames[$month]} {$year}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "Lu  Ma  Me  Je  Ve  Sa  Di\n";

        // Leading blanks for the first week
        $line = str_repeat('      ', $firstDayOfWeek - 1);
        $col  = $firstDayOfWeek;

        $totalActiveDays = 0;
        $totalQuizzes    = 0;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $entry   = $dayStats->get($dateStr);
            $dayStr  = str_pad((string) $day, 2, ' ', STR_PAD_LEFT);

            if ($entry) {
                $totalActiveDays++;
                $totalQuizzes += (int) $entry->quizzes;
                $avgPct = (int) $entry->avg_pct;
                $indicator = $avgPct >= 80 ? '🟢' : ($avgPct >= 50 ? '🟡' : '🔴');
                $cell = "{$indicator}{$dayStr}";
            } elseif ($dateStr === $now->toDateString()) {
                $cell = "⬜{$dayStr}";
            } elseif ($dateStr > $now->toDateString()) {
                $cell = "  {$dayStr}";
            } else {
                $cell = "░░{$dayStr}";
            }

            $line .= "{$cell}  ";

            if ($col === 7) {
                $reply .= trim($line) . "\n";
                $line = '';
                $col  = 1;
            } else {
                $col++;
            }
        }

        if (trim($line) !== '') {
            $reply .= trim($line) . "\n";
        }

        $reply .= "\n*Légende :* 🟢 ≥80% | 🟡 ≥50% | 🔴 <50% | ⬜ aujourd'hui | ░░ repos\n\n";

        $daysSoFar = min($now->day, $daysInMonth);
        $reply .= "📊 *Ce mois :*\n";
        $reply .= "  📅 Jours actifs : *{$totalActiveDays}* / {$daysSoFar}\n";
        $reply .= "  🎮 Quiz joués : *{$totalQuizzes}*\n";

        $streak = $this->computeDailyStreak($context);
        if ($streak > 0) {
            $reply .= "  🔥 Série actuelle : *{$streak}* jour(s)\n";
        }

        $consistencyPct = $daysSoFar > 0 ? (int) round(($totalActiveDays / $daysSoFar) * 100) : 0;
        $consistencyEmoji = $consistencyPct >= 80 ? '🌟' : ($consistencyPct >= 50 ? '👍' : '💪');
        $reply .= "  {$consistencyEmoji} Régularité : *{$consistencyPct}%*\n";

        $reply .= "\n📅 /quiz daily — Question du jour\n";
        $reply .= "🔥 /quiz streak — Voir ta série\n";
        $reply .= "📈 /quiz progress — Progression 7 jours\n";
        $reply .= "🔄 /quiz — Jouer maintenant";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Calendar viewed', ['active_days' => $totalActiveDays, 'month' => $month]);

        return AgentResult::reply($reply, ['action' => 'calendar', 'active_days' => $totalActiveDays]);
    }

    /**
     * Compare — side-by-side comparison of user's performance in two categories.
     * Usage: /quiz compare histoire science  |  /quiz comparer geo tech
     */
    private function handleCompare(AgentContext $context, string $cat1, string $cat2): AgentResult
    {
        $categories = QuizEngine::getCategories();
        $cat1Label  = $categories[$cat1] ?? $cat1;
        $cat2Label  = $categories[$cat2] ?? $cat2;

        $stats1 = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $cat1)
            ->selectRaw('COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, ROUND(MAX(score * 100.0 / total_questions)) as best_pct, SUM(score) as total_pts')
            ->first();

        $stats2 = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $cat2)
            ->selectRaw('COUNT(*) as played, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct, ROUND(MAX(score * 100.0 / total_questions)) as best_pct, SUM(score) as total_pts')
            ->first();

        $played1 = (int) ($stats1->played ?? 0);
        $played2 = (int) ($stats2->played ?? 0);

        if ($played1 === 0 && $played2 === 0) {
            $reply  = "📊 *Comparaison*\n\n";
            $reply .= "Tu n'as joué ni en {$cat1Label} ni en {$cat2Label}.\n";
            $reply .= "Lance un quiz dans ces catégories d'abord !\n\n";
            $reply .= "• `/quiz {$cat1}` — {$cat1Label}\n";
            $reply .= "• `/quiz {$cat2}` — {$cat2Label}";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'compare_no_data']);
        }

        $avg1  = (int) ($stats1->avg_pct ?? 0);
        $avg2  = (int) ($stats2->avg_pct ?? 0);
        $best1 = (int) ($stats1->best_pct ?? 0);
        $best2 = (int) ($stats2->best_pct ?? 0);
        $pts1  = (int) ($stats1->total_pts ?? 0);
        $pts2  = (int) ($stats2->total_pts ?? 0);

        $bar1 = str_repeat('█', (int) min(10, round($avg1 / 10))) . str_repeat('░', 10 - (int) min(10, round($avg1 / 10)));
        $bar2 = str_repeat('█', (int) min(10, round($avg2 / 10))) . str_repeat('░', 10 - (int) min(10, round($avg2 / 10)));

        $avgWin  = $avg1 > $avg2 ? '←' : ($avg2 > $avg1 ? '→' : '=');
        $bestWin = $best1 > $best2 ? '←' : ($best2 > $best1 ? '→' : '=');
        $volWin  = $played1 > $played2 ? '←' : ($played2 > $played1 ? '→' : '=');

        // Timing comparison
        $timingLine = '';
        $quizzes1 = Quiz::where('user_phone', $context->from)->where('agent_id', $context->agent->id)
            ->where('status', 'completed')->where('category', $cat1)->latest()->limit(20)->get();
        $quizzes2 = Quiz::where('user_phone', $context->from)->where('agent_id', $context->agent->id)
            ->where('status', 'completed')->where('category', $cat2)->latest()->limit(20)->get();

        $times1 = $this->extractTimesFromQuizzes($quizzes1);
        $times2 = $this->extractTimesFromQuizzes($quizzes2);

        if (count($times1) >= 3 && count($times2) >= 3) {
            $avgT1 = round(array_sum($times1) / count($times1));
            $avgT2 = round(array_sum($times2) / count($times2));
            $timeWin = $avgT1 < $avgT2 ? '←' : ($avgT2 < $avgT1 ? '→' : '=');
            $timingLine = "⏱ Temps moy. : *{$avgT1}s* {$timeWin} *{$avgT2}s*\n";
        }

        $score1 = ($avgWin === '←' ? 1 : 0) + ($bestWin === '←' ? 1 : 0) + ($volWin === '←' ? 1 : 0);
        $score2 = ($avgWin === '→' ? 1 : 0) + ($bestWin === '→' ? 1 : 0) + ($volWin === '→' ? 1 : 0);

        $verdict = match (true) {
            $score1 > $score2 => "🏆 *Tu es meilleur en {$cat1Label} !*",
            $score2 > $score1 => "🏆 *Tu es meilleur en {$cat2Label} !*",
            default           => "🤝 *Niveau similaire dans les deux catégories !*",
        };

        $reply  = "📊 *Comparaison — {$cat1Label} vs {$cat2Label}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "*{$cat1Label}*\n";
        $reply .= "  [{$bar1}] {$avg1}%\n";
        $reply .= "  🎮 {$played1} quiz | 🏅 Best: {$best1}% | ⭐ {$pts1} pts\n\n";
        $reply .= "*{$cat2Label}*\n";
        $reply .= "  [{$bar2}] {$avg2}%\n";
        $reply .= "  🎮 {$played2} quiz | 🏅 Best: {$best2}% | ⭐ {$pts2} pts\n\n";

        if ($timingLine) {
            $reply .= $timingLine;
        }

        $reply .= "{$verdict}\n\n";

        if ($avg1 < $avg2) {
            $reply .= "💡 _Travaille en {$cat1Label} pour rattraper !_\n";
        } elseif ($avg2 < $avg1) {
            $reply .= "💡 _Travaille en {$cat2Label} pour rattraper !_\n";
        }

        $reply .= "\n🎯 `/quiz {$cat1}` — Quiz {$cat1Label}\n";
        $reply .= "🎯 `/quiz {$cat2}` — Quiz {$cat2Label}\n";
        $reply .= "📂 /quiz catstat <cat> — Stats détaillées\n";
        $reply .= "📊 /quiz mastery — Maîtrise par catégorie";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Compare viewed', ['cat1' => $cat1, 'cat2' => $cat2, 'avg1' => $avg1, 'avg2' => $avg2]);

        return AgentResult::reply($reply, ['action' => 'compare', 'cat1' => $cat1, 'cat2' => $cat2]);
    }

    /**
     * Extract response times from a collection of quizzes (helper for compare/timing).
     */
    private function extractTimesFromQuizzes($quizzes): array
    {
        $times = [];
        foreach ($quizzes as $quiz) {
            foreach ($quiz->questions as $q) {
                if (isset($q['time_taken_secs']) && ($q['user_answered'] ?? false) && !($q['user_skipped'] ?? false)) {
                    $t = (int) $q['time_taken_secs'];
                    if ($t >= 1 && $t <= 300) {
                        $times[] = $t;
                    }
                }
            }
        }
        return $times;
    }

    private function handleWeeklyRecap(AgentContext $context): AgentResult
    {
        $since = now()->subDays(7)->startOfDay();

        $weekScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $since)
            ->orderBy('completed_at')
            ->get();

        if ($weekScores->isEmpty()) {
            $reply  = "📋 *Récap Hebdo*\n\nAucun quiz joué cette semaine.\n";
            $reply .= "Lance un quiz pour avoir ton premier récap !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n📅 /quiz daily — Question du jour";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'recap_empty']);
        }

        $categories   = QuizEngine::getCategories();
        $totalQuizzes = $weekScores->count();
        $totalCorrect = $weekScores->sum('score');
        $totalQ       = $weekScores->sum('total_questions');
        $avgPct       = $totalQ > 0 ? (int) round(($totalCorrect / $totalQ) * 100) : 0;
        $bestScore    = (int) $weekScores->max(fn ($s) => $s->total_questions > 0 ? round(($s->score / $s->total_questions) * 100) : 0);

        $catBreakdown = $weekScores->groupBy('category')->map(function ($group) {
            return [
                'played' => $group->count(),
                'avg'    => ($t = $group->sum('total_questions')) > 0 ? (int) round(($group->sum('score') / $t) * 100) : 0,
            ];
        });

        $prevStart  = now()->subDays(14)->startOfDay();
        $prevScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('completed_at', '>=', $prevStart)
            ->where('completed_at', '<', $since)
            ->get();
        $prevTotalQ = $prevScores->sum('total_questions');
        $prevAvgPct = $prevTotalQ > 0 ? (int) round(($prevScores->sum('score') / $prevTotalQ) * 100) : null;
        $prevCount  = $prevScores->count();

        $weekQuizzes = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();
        $weekTimes = [];
        foreach ($weekQuizzes as $wq) {
            foreach ($wq->questions as $q) {
                if (isset($q['time_taken_secs']) && ($q['user_answered'] ?? false) && ! ($q['user_skipped'] ?? false)) {
                    $t = (int) $q['time_taken_secs'];
                    if ($t >= 1 && $t <= 300) {
                        $weekTimes[] = $t;
                    }
                }
            }
        }
        $avgTimeSec = count($weekTimes) >= 3 ? (int) round(array_sum($weekTimes) / count($weekTimes)) : null;

        $catSummary = '';
        foreach ($catBreakdown as $cat => $data) {
            $label = match ($cat) {
                'daily' => '📅 Quotidien', 'mix' => '🎲 Mix', 'custom' => '🤖 IA',
                'correction' => '🔁 Correction', 'defi-jour' => '🏅 Défi du Jour',
                default => $categories[$cat] ?? $cat,
            };
            $catSummary .= "- {$label}: {$data['played']} quiz, {$data['avg']}% moy.\n";
        }

        $compStr = '';
        if ($prevAvgPct !== null) {
            $diff    = $avgPct - $prevAvgPct;
            $compStr = "Comparaison semaine précédente: {$prevCount} quiz, {$prevAvgPct}% moy. (évolution: " . ($diff > 0 ? "+{$diff}" : "{$diff}") . "%)\n";
        }
        $timingStr = $avgTimeSec !== null ? "Temps moyen de réponse: {$avgTimeSec}s\n" : '';

        $this->sendText($context->from, "📋 *Génération de ton récap hebdo...* Un instant !");

        $model        = $this->resolveModel($context);
        $systemPrompt = "Tu es un analyste de performance quiz pour WhatsApp. "
            . "Génère un résumé hebdomadaire CONCIS et motivant. "
            . "Format STRICT — commence immédiatement :\n\n"
            . "📊 *Bilan* : [2 phrases sur les performances clés]\n\n"
            . "💪 *Point fort* : [la meilleure réalisation]\n\n"
            . "📈 *Axe d'amélioration* : [1 point concret]\n\n"
            . "🎯 *Objectif semaine prochaine* : [1 objectif mesurable avec commande /quiz]\n\n"
            . "RÈGLES : Français uniquement. Maximum 100 mots. Cite des commandes /quiz. "
            . "Sois encourageant mais honnête. Aucun texte hors format. "
            . "ZÉRO HALLUCINATION : base-toi UNIQUEMENT sur les données fournies, n'invente aucune statistique.";

        $profileData = "Résumé semaine ({$since->format('d/m')} → " . now()->format('d/m') . "):\n"
            . "- Quiz joués: {$totalQuizzes}\n- Score global: {$totalCorrect}/{$totalQ} ({$avgPct}%)\n"
            . "- Meilleur score: {$bestScore}%\n" . $timingStr . $compStr
            . "\nPar catégorie:\n{$catSummary}";

        try {
            $llmResponse = $this->claude->chat(
                "Génère un résumé hebdomadaire personnalisé pour ce joueur :\n\n{$profileData}",
                $model, $systemPrompt, 500
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('InteractiveQuizAgent weekly recap LLM error', ['error' => $e->getMessage()]);
            $llmResponse = null;
        }

        $reply  = "📋 *Récap Hebdo — Quiz*\n";
        $reply .= now()->subDays(6)->format('d/m') . ' → ' . now()->format('d/m') . "\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 {$totalQuizzes} quiz | ⭐ {$avgPct}% moy. | 🏅 {$bestScore}% best\n";
        if ($avgTimeSec !== null) {
            $reply .= "⏱ {$avgTimeSec}s/réponse en moyenne\n";
        }
        if ($prevAvgPct !== null) {
            $arrow = $avgPct > $prevAvgPct ? '📈' : ($avgPct < $prevAvgPct ? '📉' : '➡️');
            $diff  = $avgPct - $prevAvgPct;
            $reply .= "{$arrow} vs semaine précédente : " . ($diff >= 0 ? '+' : '') . "{$diff}%\n";
        }
        $reply .= "\n";
        $reply .= $llmResponse ? trim($llmResponse) . "\n\n" : "⚠️ _L'IA n'a pas pu générer l'analyse. Réessaie avec /quiz recap._\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "🎓 /quiz coach — Coaching IA\n📊 /quiz mystats — Statistiques complètes\n";
        $reply .= "🏅 /quiz mastery — Niveaux de maîtrise\n🔄 /quiz — Lancer un quiz";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Weekly recap viewed', ['quizzes' => $totalQuizzes, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'weekly_recap', 'quizzes' => $totalQuizzes, 'avg_pct' => $avgPct]);
    }

    private function handleDefiDuJour(AgentContext $context): AgentResult
    {
        $today = now()->toDateString();

        $existing = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'defi-jour')
            ->whereDate('started_at', $today)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            $pct = $existing->getTotalQuestions() > 0
                ? (int) round(($existing->correct_answers / $existing->getTotalQuestions()) * 100) : 0;

            $communityScores = Quiz::where('agent_id', $context->agent->id)
                ->where('category', 'defi-jour')
                ->whereDate('started_at', $today)
                ->where('status', 'completed')
                ->get();
            $communityAvg = $communityScores->avg(fn ($q) => $q->getTotalQuestions() > 0 ? ($q->correct_answers / $q->getTotalQuestions()) * 100 : 0);

            $reply  = "🏅 *Défi du Jour*\n\nTu as déjà relevé le défi aujourd'hui !\n";
            $reply .= "Ton score : *{$existing->correct_answers}/{$existing->getTotalQuestions()}* ({$pct}%)\n";
            if ($communityScores->count() > 1) {
                $reply .= "👥 Moyenne communauté : " . (int) round($communityAvg) . "% ({$communityScores->count()} joueurs)\n";
                $reply .= $pct > $communityAvg ? "🌟 Tu es au-dessus de la moyenne !\n" : "💪 Continue pour dépasser la moyenne !\n";
            }
            $reply .= "\nReviens demain pour un nouveau défi !\n\n";
            $reply .= "🔄 /quiz — Quiz classique\n📅 /quiz daily — Question du Jour\n📋 /quiz recap — Récap hebdo";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'defi_already_done', 'pct' => $pct]);
        }

        $categories    = QuizEngine::getCategories();
        $catKeys       = array_keys($categories);
        $dayOfYear     = (int) now()->format('z');
        $themeIdx      = $dayOfYear % count($catKeys);
        $todayCategory = $catKeys[$themeIdx];
        $catLabel      = $categories[$todayCategory] ?? $todayCategory;

        $recentScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $todayCategory)
            ->orderByDesc('completed_at')
            ->limit(5)
            ->get();

        if ($recentScores->isEmpty()) {
            $difficulty = 'medium';
        } else {
            $avgPct = $recentScores->avg(fn ($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $difficulty = match (true) {
                $avgPct >= 75 => 'hard',
                $avgPct >= 40 => 'medium',
                default       => 'easy',
            };
        }

        $count     = self::DIFFICULTY_QUESTION_COUNT[$difficulty];
        $diffLabel = self::DIFFICULTY_LABELS[$difficulty];

        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quizData  = QuizEngine::generateQuiz($todayCategory, $count);
        $questions = array_map(function (array $q) {
            $q['hints_used']    = 0;
            $q['user_answered'] = false;
            $q['user_correct']  = false;
            $q['user_skipped']  = false;
            return $q;
        }, $quizData['questions']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => 'defi-jour',
            'difficulty'             => $difficulty,
            'questions'              => $questions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $communityCount = Quiz::where('agent_id', $context->agent->id)
            ->where('category', 'defi-jour')
            ->whereDate('started_at', $today)
            ->distinct('user_phone')
            ->count('user_phone');

        $intro  = "🏅 *Défi du Jour — {$catLabel}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "{$diffLabel} — {$quiz->getTotalQuestions()} questions (difficulté adaptée)\n";
        $intro .= "📅 " . now()->translatedFormat('l d F') . "\n";
        if ($communityCount > 1) {
            $intro .= "👥 {$communityCount} joueurs ont déjà relevé le défi\n";
        }
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Defi du jour started', [
            'category'   => $todayCategory,
            'difficulty' => $difficulty,
            'questions'  => $quiz->getTotalQuestions(),
        ]);

        return AgentResult::reply($reply, ['action' => 'defi_jour_start', 'category' => $todayCategory, 'difficulty' => $difficulty]);
    }

    /**
     * Comeback — show the category where the user improved the most.
     * Compares last 5 quizzes vs previous 5 quizzes per category.
     */
    private function handleComeback(AgentContext $context): AgentResult
    {
        $allScores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'defi-jour', 'ai-custom'])
            ->orderByDesc('completed_at')
            ->get();

        if ($allScores->count() < 4) {
            $reply  = "🚀 *Comeback*\n\n";
            $reply .= "Pas encore assez de données pour analyser ta progression.\n";
            $reply .= "Joue au moins 4 quiz pour débloquer cette fonctionnalité !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'comeback_no_data']);
        }

        $categories   = QuizEngine::getCategories();
        $byCategory   = $allScores->groupBy('category');
        $improvements = [];

        foreach ($byCategory as $cat => $scores) {
            if ($scores->count() < 4) {
                continue;
            }
            $recent   = $scores->take(3);
            $previous = $scores->skip(3)->take(5);

            if ($previous->isEmpty()) {
                continue;
            }

            $recentAvg   = $recent->avg(fn ($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $previousAvg = $previous->avg(fn ($s) => $s->total_questions > 0 ? ($s->score / $s->total_questions) * 100 : 0);
            $diff        = $recentAvg - $previousAvg;

            $improvements[$cat] = [
                'category'     => $cat,
                'label'        => $categories[$cat] ?? $cat,
                'recent_avg'   => (int) round($recentAvg),
                'previous_avg' => (int) round($previousAvg),
                'diff'         => round($diff, 1),
                'played'       => $scores->count(),
            ];
        }

        if (empty($improvements)) {
            $reply  = "🚀 *Comeback*\n\n";
            $reply .= "Pas encore assez de données par catégorie.\n";
            $reply .= "Joue plusieurs quiz dans les mêmes catégories pour voir ta progression !\n\n";
            $reply .= "🔄 /quiz — Lancer un quiz\n📊 /quiz categories — Voir les catégories";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'comeback_insufficient']);
        }

        // Sort by improvement (biggest positive first)
        uasort($improvements, fn ($a, $b) => $b['diff'] <=> $a['diff']);

        $best = reset($improvements);

        $reply  = "🚀 *Comeback — Ta plus grosse progression*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($best['diff'] > 0) {
            $reply .= "🏆 *{$best['label']}* — +{$best['diff']}%\n";
            $reply .= "📈 {$best['previous_avg']}% → *{$best['recent_avg']}%*\n";
            $reply .= "🎮 {$best['played']} quiz joués\n\n";

            $motivation = match (true) {
                $best['diff'] >= 30 => "🔥 Progression spectaculaire ! Tu as fait un bond énorme !",
                $best['diff'] >= 15 => "💪 Belle progression ! Tes efforts paient clairement !",
                $best['diff'] >= 5  => "📈 Tu progresses bien, continue sur cette lancée !",
                default              => "🌱 Petite progression, mais chaque pas compte !",
            };
            $reply .= "{$motivation}\n\n";
        } else {
            $reply .= "📊 Aucune progression nette détectée pour l'instant.\n";
            $reply .= "Continue à jouer et tu verras tes améliorations ici !\n\n";
        }

        // Show top 3 improvements (or declines)
        $top3 = array_slice($improvements, 0, 3);
        $reply .= "*Toutes les catégories :*\n";
        foreach ($top3 as $imp) {
            $arrow = $imp['diff'] > 0 ? '📈' : ($imp['diff'] < 0 ? '📉' : '➡️');
            $sign  = $imp['diff'] > 0 ? '+' : '';
            $reply .= "{$arrow} {$imp['label']} : {$sign}{$imp['diff']}% ({$imp['previous_avg']}% → {$imp['recent_avg']}%)\n";
        }

        if (count($improvements) > 3) {
            $remaining = count($improvements) - 3;
            $reply .= "... et {$remaining} autre(s)\n";
        }

        $reply .= "\n🎯 /quiz perso — Quiz dans ta catégorie la plus faible\n";
        $reply .= "📊 /quiz progress — Progression sur 7 jours\n";
        $reply .= "🎓 /quiz mastery — Niveaux de maîtrise";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Comeback viewed', [
            'best_category' => $best['category'],
            'best_diff'     => $best['diff'],
        ]);

        return AgentResult::reply($reply, ['action' => 'comeback', 'best_category' => $best['category'], 'best_diff' => $best['diff']]);
    }

    /**
     * Next — smart context-aware suggestion based on user's current state.
     */
    private function handleNext(AgentContext $context): AgentResult
    {
        $suggestions = [];

        // Check if there's an active quiz to resume
        $activeQuiz = $this->getActiveQuiz($context);
        if ($activeQuiz) {
            $idx   = $activeQuiz->current_question_index + 1;
            $total = $activeQuiz->getTotalQuestions();
            $suggestions[] = [
                'priority' => 100,
                'emoji'    => '▶️',
                'text'     => "Tu as un quiz en cours ({$idx}/{$total}) !",
                'action'   => '`/quiz resume` — Reprendre ton quiz',
            ];
        }

        // Check daily streak
        $streak     = $this->computeDailyStreak($context);
        $todayDaily = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'daily')
            ->whereDate('completed_at', now()->toDateString())
            ->exists();

        if (!$todayDaily) {
            $streakMsg = $streak > 0
                ? "🔥 Série de {$streak} jours — ne la perds pas !"
                : "Commence une nouvelle série !";
            $suggestions[] = [
                'priority' => 90,
                'emoji'    => '📅',
                'text'     => "Question du Jour pas encore faite. {$streakMsg}",
                'action'   => '`/quiz daily` — Question du Jour',
            ];
        }

        // Check daily challenge
        $todayDefi = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', 'defi-jour')
            ->whereDate('started_at', now()->toDateString())
            ->where('status', 'completed')
            ->exists();

        if (!$todayDefi) {
            $suggestions[] = [
                'priority' => 85,
                'emoji'    => '🏅',
                'text'     => "Défi du Jour disponible !",
                'action'   => '`/quiz defi` — Relever le défi',
            ];
        }

        // Weakest category
        $weakest = $this->getWeakestCategory($context);
        if ($weakest && $weakest['avg_pct'] < 60) {
            $categories = QuizEngine::getCategories();
            $catLabel   = $categories[$weakest['category']] ?? $weakest['category'];
            $suggestions[] = [
                'priority' => 70,
                'emoji'    => '🎯',
                'text'     => "{$catLabel} — ta catégorie la plus faible ({$weakest['avg_pct']}%)",
                'action'   => "`/quiz {$weakest['category']}` — S'entraîner",
            ];
        }

        // Check total quizzes for milestones
        $totalQuizzes = (int) QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->count();

        $milestones   = [10, 25, 50, 100, 200, 500];
        $nextMilestone = null;
        foreach ($milestones as $m) {
            if ($totalQuizzes < $m) {
                $nextMilestone = $m;
                break;
            }
        }

        if ($nextMilestone) {
            $remaining = $nextMilestone - $totalQuizzes;
            if ($remaining <= 5) {
                $suggestions[] = [
                    'priority' => 75,
                    'emoji'    => '🎉',
                    'text'     => "Plus que {$remaining} quiz pour atteindre {$nextMilestone} quiz !",
                    'action'   => '`/quiz` — Jouer maintenant',
                ];
            }
        }

        // Last quiz was recent — suggest review/share
        $lastScore = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('completed_at')
            ->first();

        if ($lastScore && $lastScore->completed_at && $lastScore->completed_at->diffInMinutes(now()) < 10) {
            $pct = $lastScore->total_questions > 0 ? (int) round(($lastScore->score / $lastScore->total_questions) * 100) : 0;
            if ($pct < 60) {
                $suggestions[] = [
                    'priority' => 80,
                    'emoji'    => '🧠',
                    'text'     => "Tu viens de finir un quiz ({$pct}%) — comprends tes erreurs !",
                    'action'   => '`/quiz explain` — Explications IA',
                ];
            } else {
                $suggestions[] = [
                    'priority' => 60,
                    'emoji'    => '📤',
                    'text'     => "Beau score ({$pct}%) ! Partage-le !",
                    'action'   => '`/quiz share` — Partager ton score',
                ];
            }
        }

        // Default suggestion if nothing else
        if (empty($suggestions)) {
            $suggestions[] = [
                'priority' => 50,
                'emoji'    => '🔄',
                'text'     => "Lance un quiz pour commencer !",
                'action'   => '`/quiz` — Quiz aléatoire',
            ];
        }

        // Sort by priority descending
        usort($suggestions, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        $reply  = "🧭 *Que faire ensuite ?*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $shown = 0;
        foreach ($suggestions as $s) {
            if ($shown >= 4) {
                break;
            }
            $reply .= "{$s['emoji']} {$s['text']}\n→ {$s['action']}\n\n";
            $shown++;
        }

        $reply .= "💡 /quiz help — Toutes les commandes";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Next viewed', ['suggestions' => count($suggestions)]);

        return AgentResult::reply($reply, ['action' => 'next', 'suggestions' => count($suggestions)]);
    }

    /**
     * Focus — spaced repetition quiz built from questions the user previously got wrong.
     * Optionally filtered by category. Pulls from the last 20 completed quizzes.
     */
    private function handleFocus(AgentContext $context, ?string $category): AgentResult
    {
        $query = Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(20);

        if ($category) {
            $query->where('category', $category);
        }

        $recentQuizzes = $query->get();

        if ($recentQuizzes->isEmpty()) {
            $reply  = "🔁 *Quiz Focus*\n\nPas encore de quiz terminé";
            $reply .= $category ? " dans cette catégorie" : "";
            $reply .= ".\nJoue quelques quiz d'abord !\n\n🔄 /quiz — Lancer un quiz";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'focus_empty']);
        }

        // Collect all wrong/skipped questions
        $wrongPool = [];
        foreach ($recentQuizzes as $q) {
            foreach ($q->questions as $question) {
                if (($question['user_answered'] ?? false)
                    && (!($question['user_correct'] ?? false) || ($question['user_skipped'] ?? false))
                    && isset($question['question'], $question['options'], $question['answer'])) {
                    $wrongPool[] = $question;
                }
            }
        }

        if (count($wrongPool) < 2) {
            $reply  = "🌟 *Quiz Focus*\n\nTu n'as presque pas d'erreurs récentes — bravo ! 🏆\n";
            $reply .= "Continue avec un quiz classique pour aller encore plus loin.\n\n";
            $reply .= "🔄 /quiz — Nouveau quiz\n🎯 /quiz perso — Quiz personnalisé";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'focus_no_errors']);
        }

        // Deduplicate by question text and pick up to 5
        $seen = [];
        $selected = [];
        shuffle($wrongPool);
        foreach ($wrongPool as $q) {
            $key = md5($q['question']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $selected[] = $q;
                if (count($selected) >= 5) {
                    break;
                }
            }
        }

        // Prepare questions
        $preparedQuestions = array_map(fn(array $q) => array_merge($q, [
            'hints_used'    => 0,
            'user_answered' => false,
            'user_correct'  => false,
            'user_skipped'  => false,
            'shown_at'      => null,
        ]), $selected);

        // Abandon any active quiz
        Quiz::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'playing')
            ->update(['status' => 'abandoned']);

        $quiz = Quiz::create([
            'user_phone'             => $context->from,
            'agent_id'               => $context->agent->id,
            'category'               => $category ?? 'focus',
            'difficulty'             => 'medium',
            'questions'              => $preparedQuestions,
            'current_question_index' => 0,
            'correct_answers'        => 0,
            'status'                 => 'playing',
            'started_at'             => now(),
        ]);

        $this->setQuestionShownAt($quiz, 0);
        $firstQuestion = $quiz->fresh()->getCurrentQuestion();
        $questionText  = QuizEngine::formatQuestion($firstQuestion, 1, $quiz->getTotalQuestions());

        $categories = QuizEngine::getCategories();
        $catLabel   = $category ? ($categories[$category] ?? $category) : '🎯 Mix';

        $intro  = "🔁 *Quiz Focus — {$catLabel}*\n";
        $intro .= "━━━━━━━━━━━━━━━━\n";
        $intro .= "📝 {$quiz->getTotalQuestions()} questions que tu as ratées récemment\n";
        $intro .= "💡 *indice* → indice (-1 pt) | *passer* → sauter\n\n";

        $reply = $intro . $questionText;

        $this->setPendingContext($context, 'quiz_answer', ['quiz_id' => $quiz->id], 30);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Focus quiz started', [
            'category'  => $category ?? 'mix',
            'questions' => $quiz->getTotalQuestions(),
            'pool_size' => count($wrongPool),
        ]);

        return AgentResult::reply($reply, ['action' => 'focus_start', 'questions' => $quiz->getTotalQuestions()]);
    }

    /**
     * QuickStats — instant compact performance card (no LLM, fast response).
     * Shows key metrics in a single glanceable message.
     */
    private function handleQuickStats(AgentContext $context): AgentResult
    {
        $scores = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        if ($scores->isEmpty()) {
            $reply = "📊 *Stats Rapide*\n\nAucun quiz joué pour l'instant.\n🔄 /quiz — Lance ton premier quiz !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'quickstats_empty']);
        }

        $totalQuizzes  = $scores->count();
        $totalCorrect  = $scores->sum('score');
        $totalQuestions = $scores->sum('total_questions');
        $avgPct        = $totalQuestions > 0 ? (int) round(($totalCorrect / $totalQuestions) * 100) : 0;
        $totalTime     = $scores->sum('time_taken');
        $avgTime       = $totalQuizzes > 0 ? (int) round($totalTime / $totalQuizzes) : 0;
        $streak        = $this->computeDailyStreak($context);

        // Best category
        $bestCat = QuizScore::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotIn('category', ['daily', 'mix', 'defi-jour', 'custom', 'focus'])
            ->selectRaw('category, ROUND(AVG(score * 100.0 / total_questions)) as avg_pct')
            ->groupBy('category')
            ->having('avg_pct', '>', 0)
            ->orderByDesc('avg_pct')
            ->first();

        $categories = QuizEngine::getCategories();

        // Performance emoji
        $perfEmoji = match (true) {
            $avgPct >= 80 => '🏆',
            $avgPct >= 60 => '🌟',
            $avgPct >= 40 => '📈',
            default       => '💪',
        };

        // Streak visualization
        $streakBar = '';
        if ($streak > 0) {
            $streakBar = str_repeat('🔥', min($streak, 7));
            if ($streak > 7) {
                $streakBar .= " +{$streak-7}";
            }
        }

        $reply  = "📊 *Stats Rapide* {$perfEmoji}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🎮 *{$totalQuizzes}* quiz joués\n";
        $reply .= "✅ *{$totalCorrect}*/{$totalQuestions} bonnes réponses (*{$avgPct}%*)\n";
        if ($avgTime > 0) {
            $reply .= "⏱ Temps moyen : *" . gmdate('i:s', $avgTime) . "*/quiz\n";
        }
        if ($streak > 0) {
            $reply .= "🔥 Série : *{$streak} jour" . ($streak > 1 ? 's' : '') . "* {$streakBar}\n";
        }
        if ($bestCat) {
            $bestLabel = $categories[$bestCat->category] ?? $bestCat->category;
            $reply .= "⭐ Meilleure catégorie : *{$bestLabel}* ({$bestCat->avg_pct}%)\n";
        }

        // Today's activity
        $todayCount = $scores->filter(fn ($s) => $s->completed_at && $s->completed_at->isToday())->count();
        if ($todayCount > 0) {
            $reply .= "\n📅 Aujourd'hui : *{$todayCount}* quiz complétés";
        }

        $reply .= "\n\n📊 /quiz mystats — Stats détaillées\n";
        $reply .= "🔄 /quiz — Jouer maintenant";

        $this->sendText($context->from, $reply);
        $this->log($context, 'QuickStats viewed', ['total_quizzes' => $totalQuizzes, 'avg_pct' => $avgPct]);

        return AgentResult::reply($reply, ['action' => 'quickstats', 'total_quizzes' => $totalQuizzes]);
    }
}
