<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Models\Todo;
use App\Services\AgentContext;
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
- "mood_check" = humeur, etat emotionnel, comment ca va (contexte emotionnel), mood, feeling, stress, fatigue, bien-etre

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
- mood_check → toujours "claude-haiku-4-5-20251001", complexity "simple"

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
- MOOD_CHECK = expression d'etat emotionnel: "comment ca va" (contexte emotionnel), "je me sens...", "mood", "je suis fatigue/stresse/triste/bien", "how am i doing", "mood check", "mood stats", etat de bien-etre. PRIORITE HAUTE si contexte emotionnel clair.
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

        $validAgents = ['chat', 'dev', 'reminder', 'project', 'analysis', 'todo', 'music', 'mood_check'];
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
