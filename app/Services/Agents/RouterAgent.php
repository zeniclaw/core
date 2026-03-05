<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Models\Todo;
use App\Services\AgentContext;
use App\Services\Agents\SmartContextAgent;
use App\Services\AnthropicClient;
use App\Services\ContextMemory\ContextStore;
use Illuminate\Support\Facades\Log;

class RouterAgent
{
    private AnthropicClient $claude;
    private SmartContextAgent $smartContext;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
        $this->smartContext = new SmartContextAgent();
    }

    public function route(AgentContext $context): array
    {
        // Silently run SmartContextAgent to extract and store user facts
        try {
            $this->smartContext->handle($context);
        } catch (\Throwable $e) {
            Log::warning('SmartContextAgent failed silently: ' . $e->getMessage());
        }

        // Fast-path: Audio message → voice_command agent for transcription + re-routing
        $effectiveMimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        if ($context->hasMedia && $this->isAudioMessage($effectiveMimetype)) {
            return [
                'agent' => 'voice_command',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Audio message detected — fast-path to voice_command agent for transcription',
            ];
        }

        // Fast-path: GitLab URL → dev agent with Haiku
        if ($context->body && $this->detectGitlabUrl($context->body)) {
            return [
                'agent' => 'dev',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'confirm',
                'reasoning' => 'GitLab URL detected — fast-path to dev agent',
            ];
        }

        // Fast-path: Mood keywords → mood_check agent with Haiku
        if ($context->body && $this->detectMoodKeywords($context->body)) {
            return [
                'agent' => 'mood_check',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Mood/emotional pattern detected — fast-path to mood_check agent',
            ];
        }

        // Fast-path: Finance keywords → finance agent with Haiku
        if ($context->body && $this->detectFinanceKeywords($context->body)) {
            return [
                'agent' => 'finance',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Finance/expense/budget pattern detected — fast-path to finance agent',
            ];
        }

        // Fast-path: Meeting keywords → smart_meeting agent with Haiku
        if ($context->body && $this->detectMeetingKeywords($context->body)) {
            return [
                'agent' => 'smart_meeting',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Meeting keyword detected — fast-path to smart_meeting agent',
            ];
        }

        // Fast-path: Hangman keywords → hangman agent with Haiku
        if ($context->body && $this->detectHangmanKeywords($context->body)) {
            return [
                'agent' => 'hangman',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Hangman/pendu game pattern detected — fast-path to hangman agent',
            ];
        }

        // Fast-path: Flashcard keywords → flashcard agent with Haiku
        if ($context->body && $this->detectFlashcardKeywords($context->body)) {
            return [
                'agent' => 'flashcard',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Flashcard/learning/SRS pattern detected — fast-path to flashcard agent',
            ];
        }

        // Fast-path: Screenshot/OCR keywords → screenshot agent with Haiku
        if ($context->body && $this->detectScreenshotKeywords($context->body)) {
            return [
                'agent' => 'screenshot',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Screenshot/OCR/annotate pattern detected — fast-path to screenshot agent',
            ];
        }

        // Fast-path: Image media with screenshot-like intent
        if ($context->hasMedia && $this->isImageMessage($context->mimetype ?? ($context->media['mimetype'] ?? null)) &&
            $context->body && $this->detectScreenshotKeywords($context->body)) {
            return [
                'agent' => 'screenshot',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Image + screenshot intent detected — fast-path to screenshot agent',
            ];
        }

        // Fast-path: Event reminder keywords → event_reminder agent with Haiku
        if ($context->body && $this->detectEventReminderKeywords($context->body)) {
            return [
                'agent' => 'event_reminder',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Event/reminder/calendar pattern detected — fast-path to event_reminder agent',
            ];
        }

        // Fast-path: URL content → content_summarizer agent with Haiku
        if ($context->body && $this->detectContentUrl($context->body)) {
            return [
                'agent' => 'content_summarizer',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'URL detected — fast-path to content_summarizer agent',
            ];
        }

        // Fast-path: Code review keywords → code_review agent with Sonnet
        if ($context->body && $this->detectCodeReviewKeywords($context->body)) {
            return [
                'agent' => 'code_review',
                'model' => 'claude-sonnet-4-5-20241022',
                'complexity' => 'medium',
                'autonomy' => 'auto',
                'reasoning' => 'Code review pattern detected — fast-path to code_review agent',
            ];
        }

        // Fast-path: Music keywords → music agent with Haiku
        if ($context->body && $this->detectMusicKeywords($context->body)) {
            return [
                'agent' => 'music',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Music keyword detected — fast-path to music agent',
            ];
        }

        $body = $context->body ?? '[media sans texte]';
        $userContext = $this->buildUserContext($context);

        // Enrich with context memory
        $contextStore = new ContextStore();
        $userFacts = $contextStore->retrieve($context->from);
        $memoryContext = '';
        if (!empty($userFacts)) {
            $lines = [];
            foreach ($userFacts as $fact) {
                $lines[] = "- [{$fact['category']}] {$fact['value']}";
            }
            $memoryContext = "\n\nMEMOIRE CONTEXTUELLE:\n" . implode("\n", $lines);
        }

        $message = "Message: \"{$body}\"";
        if ($userContext) {
            $message .= "\n\nCONTEXTE UTILISATEUR:\n{$userContext}";
        }
        $message .= $memoryContext;

        $response = $this->claude->chat(
            $message,
            'claude-haiku-4-5-20251001',
            $this->buildSystemPrompt()
        );

        return $this->parseRouterResponse($response);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un routeur intelligent. Tu classes des messages WhatsApp et choisis le bon agent et modele Claude.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"agent": "...", "model": "...", "complexity": "...", "autonomy": "auto|confirm", "reasoning": "..."}

AGENTS DISPONIBLES:
- "chat" = conversation, question, salutation, conseil, aide generale
- "dev" = demande de modification de CODE, bug fix, deploiement, feature, tache concrete sur un projet
- "reminder" = rappel, alarme, memo, notification programmee, timer
- "project" = changer de projet actif, selectionner un projet (SANS tache concrete)
- "analysis" = analyse approfondie, revue de document, strategie, audit, comparaison detaillee
- "todo" = gestion de liste de taches, checklist, cocher/decocher, to-do list
- "music" = musique, chanson, artiste, playlist, recommandation musicale, spotify, top charts, paroles
- "finance" = depenses, budget, solde, argent, finances, achats, cout, paye, combien j'ai depense, rapport financier, alertes budget
- "mood_check" = humeur, etat emotionnel, comment ca va (contexte emotionnel), mood, feeling, stress, fatigue, bien-etre
- "smart_meeting" = reunion, synthese reunion, reunion start, reunion end, compte-rendu, meeting, capture de reunion
- "hangman" = jeu du pendu, hangman, /hangman, deviner un mot, jeu de mots interactif
- "flashcard" = flashcards, apprentissage, revision, SRS, repetition espacee, deck, creer carte, etudier
- "code_review" = revue de code, analyse de code, verifier du code, code review, bugs, securite code
- "screenshot" = capture ecran, screenshot, OCR, extract text, annoter image, comparer images, annotation
- "event_reminder" = evenement a planifier, rappel d'evenement, calendrier, remind me about, add event, list events, rendez-vous

REGLES DE SELECTION DU MODELE:
- chat simple (salut, merci, ok) → "claude-haiku-4-5-20251001", complexity "simple"
- chat medium (question technique, conseil) → "claude-sonnet-4-5-20241022", complexity "medium"
- chat complex (debat, analyse approfondie, sujet complexe) → "claude-opus-4-20250514", complexity "complex"
- dev → toujours "claude-haiku-4-5-20251001", complexity "simple"
- reminder → toujours "claude-haiku-4-5-20251001", complexity "simple"
- project simple → "claude-haiku-4-5-20251001", project medium → "claude-sonnet-4-5-20241022"
- analysis medium → "claude-sonnet-4-5-20241022", analysis complex → "claude-opus-4-20250514"
- todo → toujours "claude-haiku-4-5-20251001", complexity "simple"
- music → toujours "claude-haiku-4-5-20251001", complexity "simple"
- finance → toujours "claude-haiku-4-5-20251001", complexity "simple"
- mood_check → toujours "claude-haiku-4-5-20251001", complexity "simple"
- smart_meeting → toujours "claude-haiku-4-5-20251001", complexity "simple"
- hangman → toujours "claude-haiku-4-5-20251001", complexity "simple"
- flashcard → toujours "claude-haiku-4-5-20251001", complexity "simple"
- code_review → toujours "claude-sonnet-4-5-20241022", complexity "medium"
- screenshot → toujours "claude-haiku-4-5-20251001", complexity "simple"
- content_summarizer → toujours "claude-haiku-4-5-20251001", complexity "simple"
- event_reminder → toujours "claude-haiku-4-5-20251001", complexity "simple"

DISTINCTION CRITIQUE entre PROJECT et DEV:
- PROJECT = l'utilisateur veut SELECTIONNER/CHANGER de projet actif, SANS decrire une tache precise de code.
  Exemples PROJECT: "je veux bosser sur X", "switch sur mon-app", "on passe sur le projet emilie",
  "j'aimerais developper sur cahier bleu", "je veux travailler sur le site vitrine",
  "je bosse sur X maintenant", "je veux passer sur un autre projet"
- DEV = le message contient une TACHE CONCRETE et SPECIFIQUE de code a realiser.
  Exemples DEV: "fix le bug sur la page login", "ajoute un bouton de deconnexion",
  "deploie la derniere version", "change la couleur du header en bleu"
- Si le message mentionne un nom de projet MAIS PAS de tache precise → c'est PROJECT, pas DEV !
- REMINDER = mots-cles: rappel, rappelle-moi, alarme, dans X minutes, a Xh
- ANALYSIS = demande d'analyse profonde, document a reviewer, strategie, audit
- TODO = gestion de checklist, todo list, ajouter/cocher/decocher une tache, "ma liste", "ma todo"
- MUSIC = musique, chanson, artiste, playlist, recommandation, spotify, top charts, paroles, "mets de la musique", "ecouter", genre musical
- FINANCE = depenses, budget, solde, argent, finances, "j'ai depense", "combien", "depense X euros", "budget alimentation", "rapport financier", "alertes budget", achat, cout, paye. Tout ce qui concerne l'argent et les finances personnelles.
- MOOD_CHECK = expression d'etat emotionnel: "comment ca va" (contexte emotionnel), "je me sens...", "mood", "je suis fatigue/stresse/triste/bien", "how am i doing", "mood check", "mood stats", etat de bien-etre. PRIORITE HAUTE si contexte emotionnel clair.
- SMART_MEETING = reunion, "reunion start", "reunion end", "synthese reunion", meeting, capture de reunion, compte-rendu. Tout ce qui concerne la gestion de reunions.
- HANGMAN = jeu du pendu, hangman, /hangman, "nouvelle partie pendu", deviner un mot, jeu interactif de mots.
- FLASHCARD = flashcard, /flashcard, "creer carte", "reviser", "deck", apprentissage, SRS, repetition espacee, "etudier", "apprendre".
- CODE_REVIEW = "code review", "review my code", "verifier ce code", "check this code", "@codereviewer", code avec demande d'analyse. Si le message contient un bloc de code (```) avec une demande de review/verification.
- SCREENSHOT = "screenshot", "capture ecran", "extract text", "OCR", "annoter", "annotate", "comparer images", "lire le texte", "@screenshot". Tout ce qui concerne le traitement d'images: extraction de texte, annotation, comparaison.
- CONTENT_SUMMARIZER = resume de liens, articles web, videos YouTube. Si le message contient une URL (http/https) avec intention de resume ou simplement un lien partage.
- EVENT_REMINDER = evenement planifie, "remind me about", "add event", "list events", "remove event", rendez-vous, calendrier, "event on", planifier un evenement. ATTENTION: ne pas confondre avec REMINDER (rappel simple/timer) — EVENT_REMINDER est pour les evenements avec date/heure/lieu specifiques.
- CHAT = tout le reste

AUTONOMIE (champ obligatoire pour TOUS les agents):
- "auto" = LECTURE/DIAGNOSTIC : consulter logs, verifier statut, inspecter code, lister, afficher, analyser, tester, reviewer, chercher, debug, expliquer, montrer, "regarde", "c'est quoi", "montre moi", "qu'est-ce que", "verifie", "check"
- "confirm" = ECRITURE/MODIFICATION : modifier code, ajouter feature, fix bug, deployer, push, merge, supprimer, refactorer, creer
- En cas de doute → "confirm"

REGLE CRITIQUE — UTILISE LE CONTEXTE UTILISATEUR:
Tu recevras le contexte de l'utilisateur (ses todos actifs, reminders en cours, projet actif, etc.).
Utilise ce contexte pour comprendre les messages IMPLICITES:
- Si l'utilisateur a un todo "Acheter du pain" et dit "j'ai achete du pain" ou "c'est fait pour le pain" → c'est TODO (il veut cocher)
- Si l'utilisateur a un reminder "Appeler Jean" et dit "j'ai appele Jean" → c'est TODO ou REMINDER selon le contexte
- Si l'utilisateur a un projet actif et parle d'un sujet lie → c'est probablement DEV ou PROJECT
- En general, si le message FAIT REFERENCE a un element existant du contexte, route vers l'agent correspondant
- Ne te limite PAS aux mots-cles exacts. Comprends l'INTENTION derriere le message.

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function parseRouterResponse(?string $response): array
    {
        $default = [
            'agent' => 'chat',
            'model' => 'claude-haiku-4-5-20251001',
            'complexity' => 'simple',
            'reasoning' => 'fallback to chat',
        ];

        if (!$response) return $default;

        $clean = trim($response);

        // Strip markdown code blocks
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Extract JSON object
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);

        if (!$parsed || empty($parsed['agent'])) {
            Log::warning('RouterAgent: failed to parse response', ['raw' => $response]);
            return $default;
        }

        $validAgents = ['chat', 'dev', 'reminder', 'project', 'analysis', 'todo', 'music', 'mood_check', 'finance', 'smart_meeting', 'hangman', 'flashcard', 'voice_command', 'code_review', 'screenshot', 'content_summarizer', 'event_reminder'];
        if (!in_array($parsed['agent'], $validAgents)) {
            $parsed['agent'] = 'chat';
        }

        $autonomy = $parsed['autonomy'] ?? 'confirm';
        if (!in_array($autonomy, ['auto', 'confirm'])) {
            $autonomy = 'confirm';
        }

        return [
            'agent' => $parsed['agent'],
            'model' => $parsed['model'] ?? $default['model'],
            'complexity' => $parsed['complexity'] ?? 'simple',
            'autonomy' => $autonomy,
            'reasoning' => $parsed['reasoning'] ?? '',
        ];
    }

    private function buildUserContext(AgentContext $context): string
    {
        $parts = [];

        // Active todos
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('id')
            ->get();

        if ($todos->isNotEmpty()) {
            $lines = ['TODOS:'];
            foreach ($todos->values() as $i => $todo) {
                $status = $todo->is_done ? 'FAIT' : 'A FAIRE';
                $lines[] = "  " . ($i + 1) . ". [{$status}] {$todo->title}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Pending reminders
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->take(5)
            ->get();

        if ($reminders->isNotEmpty()) {
            $lines = ['REMINDERS EN ATTENTE:'];
            foreach ($reminders as $r) {
                $at = $r->scheduled_at->setTimezone('Europe/Paris')->format('d/m H:i');
                $recurrence = $r->recurrence_rule ? " (recurrent: {$r->recurrence_rule})" : '';
                $lines[] = "  - {$r->message} → {$at}{$recurrence}";
            }
            $parts[] = implode("\n", $lines);
        }

        // Active project
        $activeProjectId = $context->session->active_project_id ?? null;
        if ($activeProjectId) {
            $project = \App\Models\Project::find($activeProjectId);
            if ($project) {
                $parts[] = "PROJET ACTIF: {$project->name}";
            }
        }

        return implode("\n\n", $parts);
    }

    private function detectGitlabUrl(string $body): bool
    {
        return (bool) preg_match('#https?://gitlab\.[^\s]+#i', $body);
    }

    private function detectMoodKeywords(string $body): bool
    {
        $patterns = [
            '/\bmood\b/i',
            '/\bmood[\s_-]?(check|stats?)\b/i',
            '/\bhow\s+am\s+i\s+doing\b/i',
            '/\bje\s+(me\s+sens|suis)\s+(bien|mal|triste|stresse|fatigue|energique|deprime|anxieux|epuise|down|super|top)\b/iu',
            '/\bcomment\s+(tu\s+te\s+sens|je\s+me\s+sens)\b/iu',
            '/\bstate\s+of\s+mind\b/i',
            '/\bfeeling\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function detectFinanceKeywords(string $body): bool
    {
        $patterns = [
            '/\b(depense|depenses|expense)\b/iu',
            '/\bajout\s+depense\b/iu',
            '/\bbudget\s+\S+/iu',
            '/\b(solde|balance)\b/iu',
            '/\b(spent|paye|achete|cout|coute)\s+\d/iu',
            '/\bfinance|financier|financiere\b/iu',
            '/\b(stats?|statistiques?)\s*(financ|depense|budget)/iu',
            '/\b(alertes?\s*budget|budget\s*alertes?)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function detectMeetingKeywords(string $body): bool
    {
        $patterns = [
            '/\br[ée]union\s+start\b/iu',
            '/\br[ée]union\s+end\b/iu',
            '/\bsynth[eè]se\s+r[ée]union\b/iu',
            '/\br[ée]union\s+\w+/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function detectHangmanKeywords(string $body): bool
    {
        $patterns = [
            '/\/hangman\b/i',
            '/\bhangman\b/i',
            '/\b(jeu\s+(du\s+)?pendu|pendu)\b/iu',
            '/\bjouer\s+au\s+pendu\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function isAudioMessage(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }

        $baseMime = explode(';', $mimetype)[0];
        return str_starts_with(trim($baseMime), 'audio/');
    }

    private function detectFlashcardKeywords(string $body): bool
    {
        $patterns = [
            '/\/flashcard\b/i',
            '/\bflashcard(s)?\b/i',
            '/\b(creer?|nouveau|nouvelle)\s+(deck|carte|card)\b/iu',
            '/\b(reviser|revision)\s+(mes\s+)?(cartes?|flashcards?|deck)\b/iu',
            '/\brepetition\s+espacee\b/iu',
            '/\b(etudier|study)\s+(mes\s+)?(cartes?|flashcards?|deck)\b/iu',
            '/\bsrs\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function detectCodeReviewKeywords(string $body): bool
    {
        $patterns = [
            '/\bcode\s*review\b/i',
            '/\breview\s+(my|this|the)\s*code\b/i',
            '/\b(verifi(er|e)|check)\s*(ce|mon|le|this|my)\s*code\b/iu',
            '/\b@codereviewer\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        // Code block + review intent
        if (preg_match('/```\w*\s*\n.+```/s', $body) &&
            preg_match('/\b(review|verifi|analyse|check|bug|securit|optimi)/iu', $body)) {
            return true;
        }

        return false;
    }

    private function detectScreenshotKeywords(string $body): bool
    {
        $patterns = [
            '/\b@?screenshot\b/i',
            '/\b(extract[\s-]?text|ocr)\b/i',
            '/\b(extraire[\s-]?texte|lire\s+le\s+texte)\b/iu',
            '/\bannotat(e|er|ion)\b/iu',
            '/\b(compare[r]?\s+(image|photo|capture))/iu',
            '/\b(capture\s+(ecran|screen|image|photo))\b/iu',
            '/\b(surligner|marquer)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function isImageMessage(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }
        $base = explode(';', $mimetype)[0];
        return str_starts_with(trim($base), 'image/');
    }

    private function detectContentUrl(string $body): bool
    {
        // Must contain a URL (but not just a GitLab URL which has its own handler)
        if (!preg_match('#https?://[^\s<>\[\]"\']+#i', $body)) {
            return false;
        }

        // Exclude GitLab URLs (handled by dev agent)
        if ($this->detectGitlabUrl($body)) {
            return false;
        }

        // Exclude if it's clearly a code review request with code blocks
        if ($this->detectCodeReviewKeywords($body)) {
            return false;
        }

        return true;
    }

    private function detectEventReminderKeywords(string $body): bool
    {
        $patterns = [
            '/\bremind\s+me\s+about\b/i',
            '/\badd\s+event\b/i',
            '/\blist\s+events?\b/i',
            '/\bremove\s+event\b/i',
            '/\bupdate\s+event\b/i',
            '/\bevent\s+on\b/i',
            '/\b(ajouter?|creer?|planifier)\s+(un\s+)?(evenement|event|rdv|rendez[\s-]?vous)\b/iu',
            '/\b(mes\s+)?(evenements?|events?|calendrier)\b/iu',
            '/\b(supprimer|annuler)\s+(un\s+)?(evenement|event)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    private function detectMusicKeywords(string $body): bool
    {
        $keywords = [
            'musique', 'chanson', 'artiste', 'playlist',
            'spotify', 'top charts', 'paroles', 'recommend',
            'ecouter', 'écouter', 'mets de la musique',
            'cherche.*chanson', 'cherche.*artiste', 'cherche.*musique',
            'recommande.*musique', 'recommande.*chanson',
        ];

        $pattern = '/\b(' . implode('|', $keywords) . ')/iu';
        return (bool) preg_match($pattern, $body);
    }
}
