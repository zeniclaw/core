<?php

namespace App\Services\Agents;

use App\Models\Project;
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
    private array $registeredAgents = [];

    public function __construct()
    {
        $this->claude = new AnthropicClient();
        $this->smartContext = new SmartContextAgent();
    }

    /**
     * Register all agents so the router can read their keywords/descriptions.
     */
    public function registerAgents(array $agents): void
    {
        $this->registeredAgents = $agents;
    }

    public function route(AgentContext $context): array
    {
        // Silently run SmartContextAgent to extract and store user facts
        try {
            $this->smartContext->handle($context);
        } catch (\Throwable $e) {
            Log::warning('SmartContextAgent failed silently: ' . $e->getMessage());
        }

        // ── Only 2 unambiguous fast-paths ──

        // Audio message → voice_command for transcription + re-routing
        $effectiveMimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        if ($context->hasMedia && $this->isAudioMessage($effectiveMimetype)) {
            return [
                'agent' => 'voice_command',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'reasoning' => 'Audio message — transcription first',
            ];
        }

        // GitLab URL explicitly in message → dev agent
        if ($context->body && $this->detectGitlabUrl($context->body)) {
            return [
                'agent' => 'dev',
                'model' => 'claude-haiku-4-5-20251001',
                'complexity' => 'simple',
                'autonomy' => 'confirm',
                'reasoning' => 'GitLab URL detected',
            ];
        }

        // ── Full LLM routing with dynamic agent catalog ──

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
        if ($context->hasMedia) {
            $mime = $effectiveMimetype ?? 'unknown';
            $message .= "\n[Le message contient un media: {$mime}]";
        }
        if ($userContext) {
            $message .= "\n\n{$userContext}";
        }
        $message .= $memoryContext;

        $systemPrompt = $this->buildDynamicSystemPrompt();

        $response = $this->claude->chat(
            $message,
            'claude-sonnet-4-20250514',
            $systemPrompt
        );

        return $this->parseRouterResponse($response);
    }

    /**
     * Build the system prompt dynamically from registered agents' keywords and descriptions.
     */
    private function buildDynamicSystemPrompt(): string
    {
        $agentCatalog = $this->buildAgentCatalog();

        return <<<PROMPT
Tu es un routeur IA. Tu analyses le MESSAGE, le CONTEXTE et la MEMOIRE pour choisir le bon agent.

Reponds UNIQUEMENT en JSON:
{"agent": "...", "model": "...", "complexity": "...", "autonomy": "auto|confirm", "reasoning": "..."}

════════════════════════════════════════
CATALOGUE DES AGENTS:
════════════════════════════════════════

{$agentCatalog}

════════════════════════════════════════
REGLES:
════════════════════════════════════════

SELECTION DU MODELE:
- Simple → "claude-haiku-4-5-20251001" / "simple"
- Medium → "claude-sonnet-4-20250514" / "medium"
- Complex → "claude-opus-4-20250514" / "complex"
- dev → toujours haiku / simple
- code_review → toujours sonnet / medium
- analysis → sonnet ou opus selon complexite
- Tous les autres → haiku / simple

AUTONOMIE:
- "auto" = LECTURE: lister, voir, afficher, montrer, verifier, check, status, info, diagnostic
- "confirm" = ECRITURE: modifier, ajouter, fixer, deployer, supprimer, creer, push, merge
- Doute → "confirm"

INTELLIGENCE CONTEXTUELLE:
1. Comprends l'INTENTION, pas les mots-cles exacts
2. Utilise le CONTEXTE (todos, reminders, projets) pour les messages implicites
3. "c'est fait pour X" + todo X → todo
4. Le mot "projet" ne signifie PAS l'agent "project" ! "project" = UNIQUEMENT switcher de projet actif
5. TOUT ce qui concerne lister/consulter/modifier des projets, repos, code, GitLab = dev
6. Doute entre dev et project → TOUJOURS dev

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    /**
     * Build the agent catalog section from registered agents.
     */
    private function buildAgentCatalog(): string
    {
        if (empty($this->registeredAgents)) {
            return $this->getStaticAgentCatalog();
        }

        $lines = [];
        foreach ($this->registeredAgents as $name => $agent) {
            if ($name === 'smart_context' || $name === 'voice_command') {
                continue; // Internal agents, not user-facing
            }

            $description = method_exists($agent, 'description') ? $agent->description() : '';
            $keywords = method_exists($agent, 'keywords') ? $agent->keywords() : [];
            $version = method_exists($agent, 'version') ? $agent->version() : '1.0.0';

            $keywordsStr = !empty($keywords) ? implode(', ', array_slice($keywords, 0, 30)) : 'aucun';

            $lines[] = "■ \"{$name}\" (v{$version})";
            $lines[] = "  Description: {$description}";
            $lines[] = "  Mots-cles: {$keywordsStr}";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * Fallback static catalog if no agents are registered.
     */
    private function getStaticAgentCatalog(): string
    {
        return <<<'CATALOG'
■ "dev" — Developpement & GitLab. Taches de code + smart commands GitLab (lister projets, branches, MRs, pipelines, commits, issues, fichiers, health, rollback, deploy)
  Mots-cles: liste projets, mes projets, mes repos, branches, MRs, merge requests, pipeline, CI, commits, issues, tickets, arborescence, fichiers, rollback, deploy, fix, feature, code, gitlab, dev, repo

■ "chat" — Conversation generale, questions, aide, salutations
  Mots-cles: bonjour, salut, aide, question, merci, explique

■ "todo" — Gestion de taches/listes
  Mots-cles: todo, tache, liste, checklist, cocher, ajouter tache

■ "reminder" — Rappels et alarmes simples
  Mots-cles: rappel, rappelle-moi, alarme, timer, dans X minutes

■ "event_reminder" — Evenements avec date/lieu specifiques
  Mots-cles: evenement, rdv, calendrier, planifier, rendez-vous

■ "project" — UNIQUEMENT switcher de projet actif (pas lister!)
  Mots-cles: switch, bosser sur, passer sur, changer de projet

■ "analysis" — Analyse approfondie, audit, strategie
  Mots-cles: analyse, audit, strategie, compare, revue de document

■ "music" — Musique & Spotify
  Mots-cles: musique, chanson, playlist, spotify, artiste

■ "finance" — Depenses & Budget
  Mots-cles: depense, budget, solde, argent, cout

■ "mood_check" — Humeur & Bien-etre
  Mots-cles: mood, je me sens, humeur, stress, fatigue

■ "smart_meeting" — Reunions
  Mots-cles: reunion, meeting, synthese, compte-rendu

■ "hangman" — Jeu du pendu
  Mots-cles: pendu, hangman, jouer

■ "flashcard" — Apprentissage par flashcards
  Mots-cles: flashcard, revision, deck, apprendre, SRS

■ "code_review" — Revue de code
  Mots-cles: code review, verifier code, check code

■ "screenshot" — OCR & traitement d'images
  Mots-cles: screenshot, OCR, extract text, annoter

■ "content_summarizer" — Resume de liens/articles
  Mots-cles: resume, article, lien, URL, video

■ "habit" — Suivi d'habitudes
  Mots-cles: habitude, streak, tracker, challenge

■ "pomodoro" — Sessions de focus
  Mots-cles: pomodoro, focus, timer, session de travail
CATALOG;
    }

    private function parseRouterResponse(?string $response): array
    {
        $default = [
            'agent' => 'chat',
            'model' => 'claude-haiku-4-5-20251001',
            'complexity' => 'simple',
            'autonomy' => 'confirm',
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

        $validAgents = array_keys($this->registeredAgents);
        if (empty($validAgents)) {
            $validAgents = [
                'chat', 'dev', 'reminder', 'project', 'analysis', 'todo', 'music',
                'mood_check', 'finance', 'smart_meeting', 'hangman', 'flashcard',
                'voice_command', 'code_review', 'screenshot', 'content_summarizer',
                'event_reminder', 'habit', 'pomodoro',
            ];
        }
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
            $lines = ['TODOS ACTIFS:'];
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

        // Available projects
        $activeProjectId = $context->session->active_project_id ?? null;
        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('updated_at')
            ->take(10)
            ->get();

        if ($projects->isNotEmpty()) {
            $lines = ['PROJETS DEV DISPONIBLES:'];
            foreach ($projects as $p) {
                $active = ($p->id === $activeProjectId) ? ' ← ACTIF' : '';
                $lines[] = "  - {$p->name} ({$p->gitlab_url}){$active}";
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    private function detectGitlabUrl(string $body): bool
    {
        return (bool) preg_match('#https?://gitlab\.[^\s]+#i', $body);
    }

    private function isAudioMessage(?string $mimetype): bool
    {
        if (!$mimetype) return false;
        $baseMime = explode(';', $mimetype)[0];
        return str_starts_with(trim($baseMime), 'audio/');
    }
}
