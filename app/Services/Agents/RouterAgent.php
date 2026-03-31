<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Todo;
use App\Services\AgentContext;
use App\Services\Agents\SmartContextAgent;
use App\Services\Agents\ConversationMemoryAgent;
use App\Services\Agents\ContextAgent;
use App\Services\LLMClient;
use App\Services\ContextMemory\ContextStore;
use App\Services\ContextMemoryBridge;
use App\Services\ConversationMemoryService;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Log;

class RouterAgent
{
    private LLMClient $claude;
    private SmartContextAgent $smartContext;
    private ConversationMemoryAgent $conversationMemory;
    private ContextAgent $contextAgent;
    private array $registeredAgents = [];

    /**
     * Deterministic fast-path patterns: regex → [agent, model, complexity, autonomy].
     * Checked BEFORE calling the LLM — saves a Sonnet call on obvious messages.
     */
    private array $fastPathPatterns = [
        // Greetings / small talk → chat
        '/^(hey|hi|hello|salut|bonjour|bonsoir|coucou|yo|wesh|slt|bjr|cc|bsr|re|merci|thanks|thx|ok merci)[\s!.?]*$/iu'
            => ['chat', 'fast', 'simple', 'auto'],
        // Reminder with time marker → reminder
        '/\b(rappel(le)?[\s-]?(moi)?|remind\s*me|dans\s+\d+\s*(min|minute|heure|hour|h|jour|day|j))\b/iu'
            => ['reminder', 'fast', 'simple', 'confirm'],
        // Explicit todo keywords → todo
        '/^(ajoute|add|nouvelle?\s+t[aâ]che|new\s+task|todo\s*:)/iu'
            => ['todo', 'fast', 'simple', 'confirm'],
        // Music / Spotify → music
        '/\b(joue|play|met[s]?\s+(de\s+la\s+)?musique|spotify|playlist|next\s*song|chanson\s+suivante|pause\s+musique)\b/iu'
            => ['music', 'fast', 'simple', 'auto'],
        // URL to summarize → content_summarizer
        '/^(r[eé]sum[eé]|summarize|synth[eè]se|tldr|de\s+quoi\s+[cç]a\s+parle)\s+https?:\/\//iu'
            => ['content_summarizer', 'fast', 'simple', 'auto'],
        // Pomodoro → pomodoro
        '/\b(pomodoro|session\s+de\s+(travail|focus)|lance\s+(un\s+)?focus)\b/iu'
            => ['pomodoro', 'fast', 'simple', 'confirm'],
        // Mood → mood_check
        '/^(je\s+(me\s+sens|suis)\s+(fatigu|stress|triste|heureux|bien|mal|nul|depress|anxieu|énervé|content|motivé))/iu'
            => ['mood_check', 'fast', 'simple', 'auto'],
        // Games (hangman, quiz, riddles) → game_master (consolidated D3.3)
        '/\b(pendu|hangman|quiz|quizz|trivia|qcm|challenge|enigme|devinette|riddle|anagramme|20\s*questions|startgame)\b/iu'
            => ['game_master', 'fast', 'simple', 'auto'],
        // Preferences / profile → user_preferences
        '/^(set\s+(language|timezone|date_format|unit_system|communication_style|notification|phone|email)\b|show\s+prefer|mes\s+pr[eé]f[eé]rences|mon\s+profil|my\s+profile|my\s+preferences|import\s+prefer|export\s+prefer|reset\s+prefer|historique\s+prefer|preference\s+history|mode\s+(formel|concis|decontracte|detaille)|passe\s+en\s+(anglais|francais|espagnol)|switch\s+to\s+(english|french|spanish)|fuseaux?\s+disponibles|available\s+timezones)/iu'
            => ['user_preferences', 'fast', 'simple', 'confirm'],
        // Content curation → content_curator
        '/\b(digest|trending|tendance|follow\s+\w+|veille|curation|mes\s+bookmarks?|resume\s+quotidien|daily\s+digest)\b/iu'
            => ['content_curator', 'fast', 'simple', 'auto'],
        // Workflow / chain / streamline → streamline (consolidated D3.3)
        '/(?:^\/workflow\b|\b(?:workflow|chain|enchainer|chainer|pipeline)\s+.*(?:then|puis|ensuite|etape))/iu'
            => ['streamline', 'fast', 'simple', 'confirm'],
        // Budget tracker → budget_tracker
        '/\b(d[eé]pense\s+\d|budget\s+\d|\d+\s*€|j[\x27\x{2019}]ai\s+(?:d[eé]pens[eé]|pay[eé])\s+\d|r[eé]sum[eé]\s+budget|mes\s+d[eé]penses|reset\s+budget|cat[eé]gories\s+budget)\b/iu'
            => ['budget_tracker', 'fast', 'simple', 'confirm'],
        // Daily brief → daily_brief
        '/\b(daily\s*brief|mon\s+brief|briefing\s+du\s+jour|r[eé]sum[eé]\s+du\s+jour|r[eé]sum[eé]\s+matinal|morning\s+brief|configure\s+brief|enable\s+brief|disable\s+brief)\b/iu'
            => ['daily_brief', 'fast', 'simple', 'auto'],
        // Collaborative task / vote → collaborative_task (consolidated D3.3)
        '/(?:^\/(?:vote|approve|decide)\b|\b(?:voter?\s+(?:pour|contre)\s+\d+|consensus|votes?\s+en\s+cours|proposer\s+|soumettre\s+|sondage|poll)\b|^[👍👎❓]\s*\d+\s*$)/iu'
            => ['collaborative_task', 'fast', 'simple', 'confirm'],
        // Recipe / cooking → recipe
        '/\b(recette|cuisiner|cuisine|qu[\x27\x{2019}]est[\s-]ce\s+que\s+je\s+peux\s+faire\s+avec|quoi\s+cuisiner|recipe|cook|meal\s+prep|ingr[eé]dients?\s+disponibles?|plat\s+du\s+jour|liste\s+de\s+courses)\b/iu'
            => ['recipe', 'fast', 'simple', 'auto'],
        // API / CRUD operations on project data → dev (api_query)
        '/\b(avec\s+(les?\s+)?api|via\s+(l.?\s+)?api|appel(le)?\s+(l.?\s+)?api|requ[eê]te\s+api|endpoint|cr[eé][eé]\s+une?\s+(campagne|prospect|booking|user|client|facture|commande)|liste[r]?\s+(les|mes)\s+(campagnes|prospects|bookings|users|clients|factures|commandes)|supprime[r]?\s+(la|le|une?)\s+(campagne|prospect|booking))\b/iu'
            => ['dev', 'fast', 'simple', 'confirm'],
        // Time blocker → time_blocker
        '/\b(bloque[r]?\s+(ma|la)\s+journ[eé]e|organise[r]?\s+m(on|a)\s+temps|optimise[r]?\s+m(on|a)\s+(agenda|journ[eé]e)|time\s*block|blocs?\s+de\s+temps|planifie[r]?\s+(ma|la)\s+journ[eé]e|emploi\s+du\s+temps|planning\s+optimal|deep\s*work|bloc[s]?\s+focus|gestion\s+du\s+temps)\b/iu'
            => ['time_blocker', 'fast', 'simple', 'auto'],
        // AI Assistant / coaching / stats → assistant
        '/\b(mes\s+stats?|my\s+stats?|coaching|coach|quels?\s+agents?|tips?\s+hebdo|suggestions?\s+agents?|agents?\s+suggestions?|assistant\s+ia|fonctionnalit[eé]s\s+disponibles?|que\s+puis[\s-]je\s+faire|what\s+can\s+you\s+do|astuces?\s+agents?|progression|adoption\s+score|aide\s+agents?|help\s+agents?|recommandations?|upskilling|dashboard\s+stats?)\b/iu'
            => ['assistant', 'fast', 'simple', 'auto'],
        // Debug mode toggle → chat (handled by orchestrator before routing, this is just a safety net)
        '/^(mode\s+debug|debug\s+(mode|on|off)|activ(er|e)\s+(le\s+)?debug|d[eé]sactiv(er|e)\s+(le\s+)?debug|(enable|disable|stop|supprime[r]?|enlève[r]?|arrête[r]?)\s+(le\s+)?debug|\/debug|\/nodebug)$/iu'
            => ['chat', 'fast', 'simple', 'auto'],
    ];

    public function __construct()
    {
        $this->claude = new LLMClient();
        $this->smartContext = new SmartContextAgent();
        $this->conversationMemory = new ConversationMemoryAgent();
        $this->contextAgent = new ContextAgent();
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
        // Enrich context with metadata: previous agent, timestamp, action type, estimated sentiment
        try {
            $bridge = ContextMemoryBridge::getInstance();
            $previousAgent = $bridge->getLastAgent($context->from) ?? 'none';
            $actionType = $this->detectActionType($context);
            $sentiment = $this->estimateSentiment($context->body ?? '');

            $context->routingMetadata = [
                'previous_agent' => $previousAgent,
                'timestamp' => now()->toIso8601String(),
                'action_type' => $actionType,
                'estimated_sentiment' => $sentiment,
            ];
        } catch (\Throwable $e) {
            Log::warning('RouterAgent metadata enrichment failed: ' . $e->getMessage());
        }

        // Silently run SmartContextAgent to extract and store user facts
        try {
            $this->smartContext->handle($context);
        } catch (\Throwable $e) {
            Log::warning('SmartContextAgent failed silently: ' . $e->getMessage());
        }

        // Silently run ConversationMemoryAgent to extract and store memorable facts
        try {
            $this->conversationMemory->extractFactsInBackground($context);
        } catch (\Throwable $e) {
            Log::warning('Memory extraction failed', [
                'error' => $e->getMessage(),
                'from' => $context->from ?? 'null',
                'message_preview' => substr($context->message ?? '', 0, 100),
            ]);
        }

        // Silently run ContextAgent to extract entities and update shared context bridge
        try {
            $this->contextAgent->extractAndUpdate($context);
        } catch (\Throwable $e) {
            Log::warning('ContextAgent failed silently: ' . $e->getMessage());
        }

        // ── Only 2 unambiguous fast-paths ──

        // Audio message → voice_command for transcription + re-routing
        $effectiveMimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        if ($context->hasMedia && $this->isAudioMessage($effectiveMimetype)) {
            return [
                'agent' => 'voice_command',
                'model' => 'fast',
                'complexity' => 'simple',
                'autonomy' => 'auto',
                'confidence' => 99,
                'reasoning' => 'Audio message — transcription first',
            ];
        }

        // GitLab URL explicitly in message → dev agent
        if ($context->body && $this->detectGitlabUrl($context->body)) {
            return [
                'agent' => 'dev',
                'model' => 'fast',
                'complexity' => 'simple',
                'autonomy' => 'confirm',
                'reasoning' => 'GitLab URL detected',
                'confidence' => 99,
            ];
        }

        // Image/document media without text → screenshot or document agent
        if ($context->hasMedia && !$context->body) {
            $mime = $effectiveMimetype ?? '';
            if (str_starts_with($mime, 'image/')) {
                return [
                    'agent' => 'screenshot',
                    'model' => 'fast',
                    'complexity' => 'simple',
                    'autonomy' => 'auto',
                    'reasoning' => 'Image without text → OCR/screenshot',
                    'confidence' => 90,
                ];
            }
            if (str_contains($mime, 'pdf') || str_contains($mime, 'document') || str_contains($mime, 'spreadsheet')) {
                return [
                    'agent' => 'document',
                    'model' => 'fast',
                    'complexity' => 'simple',
                    'autonomy' => 'auto',
                    'reasoning' => 'Document without text → document agent',
                    'confidence' => 90,
                ];
            }
        }

        // ── Deterministic fast-path patterns (no LLM call) ──
        if ($context->body && !$context->hasMedia) {
            $fastResult = $this->matchFastPath($context->body, $context);
            if ($fastResult) {
                return $fastResult;
            }
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

        // Recent conversation history for contextual routing (8 messages for better context)
        $conversationMemory = new ConversationMemoryService();
        $recentHistory = $this->getRecentHistory($conversationMemory, $context, 8);

        $message = "Message: \"{$body}\"";
        if ($context->hasMedia) {
            $mime = $effectiveMimetype ?? 'unknown';
            $message .= "\n[Le message contient un media: {$mime}]";
        }
        if ($recentHistory) {
            $message .= "\n\n{$recentHistory}";
        }
        if ($userContext) {
            $message .= "\n\n{$userContext}";
        }
        $message .= $memoryContext;

        $systemPrompt = $this->buildDynamicSystemPrompt();

        $response = $this->claude->chat(
            $message,
            ModelResolver::balanced(),
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
{"agent": "...", "model": "...", "complexity": "...", "autonomy": "auto|confirm", "confidence": 0-100, "reasoning": "..."}

════════════════════════════════════════
CATALOGUE DES AGENTS:
════════════════════════════════════════

{$agentCatalog}

════════════════════════════════════════
REGLES:
════════════════════════════════════════

SELECTION DU MODELE:
- Simple → "claude-sonnet-4-6" / "simple"
- Medium → "claude-sonnet-4-6" / "medium"
- Complex → "claude-opus-4-6" / "complex"
- dev → toujours sonnet / simple
- code_review → toujours sonnet / medium
- analysis → sonnet ou opus selon complexite
- Tous les autres → sonnet / simple

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
7. HISTORIQUE DE CONVERSATION: lis l'historique recent pour comprendre les messages courts/implicites
   - Un numero apres une liste = selection dans la liste → meme agent que celui qui a affiche la liste
   - "corrige X" apres avoir parle d'un projet = dev task sur ce projet
   - Tout message qui fait reference a un echange precedent doit aller vers l'agent concerne

CONFIANCE (confidence):
- 90-100 = certain (mot-cle explicite, contexte clair)
- 70-89 = probable (intention claire mais pas de mot-cle exact)
- 50-69 = incertain (message ambigu, pourrait aller a plusieurs agents)
- 0-49 = tres incertain → utilise "chat" par defaut

DESAMBIGUATION (agents souvent confondus):
- "analyse ce code" → code_review (PAS analysis)
- "analyse ce document/PDF" → analysis OU document (selon media)
- "cree un rappel pour ma reunion" → reminder (PAS smart_meeting, PAS event_reminder)
- "planifie un evenement le 15 mars" → event_reminder (PAS reminder — car date/lieu specifique)
- "resume cet article/URL" → content_summarizer (PAS analysis)
- "mes projets" / "liste les projets" → dev (PAS project)
- "switch sur projet X" / "bosser sur X" → project
- Toute demande mentionnant "api", "endpoint", "appel", "requete", cle API, ou interaction avec un service externe du projet actif → dev (PAS docs, PAS chat, PAS analysis)
- "liste les campagnes" / "cree une campagne" / "les utilisateurs" / tout CRUD sur des donnees du projet → dev (api_query)

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    /**
     * Build the agent catalog section from registered agents.
     * Includes capabilities (tools) for capability-based routing (D3.2, D3.5).
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

            // Capability-based routing (D3.5): list tools each agent provides
            $capabilities = [];
            if ($agent instanceof ToolProviderInterface && method_exists($agent, 'tools')) {
                $tools = $agent->tools();
                foreach ($tools as $tool) {
                    $toolName = $tool['name'] ?? '';
                    // Skip base tools that all agents share
                    if (in_array($toolName, ['teach_skill', 'list_skills', 'forget_skill', 'memory_store', 'memory_search', 'spawn_subagent', 'send_agent_message'])) {
                        continue;
                    }
                    $capabilities[] = $toolName;
                }
            }

            $keywordsStr = !empty($keywords) ? implode(', ', array_slice($keywords, 0, 30)) : 'aucun';
            $capsStr = !empty($capabilities) ? implode(', ', $capabilities) : 'aucun outil specifique';

            $lines[] = "■ \"{$name}\" (v{$version})";
            $lines[] = "  Description: {$description}";
            $lines[] = "  Mots-cles: {$keywordsStr}";
            $lines[] = "  Outils: {$capsStr}";
            $lines[] = "";
        }

        // Inject custom/private agents for routing
        try {
            $customAgents = \App\Models\CustomAgent::where('is_active', true)->get();
            foreach ($customAgents as $ca) {
                $key = $ca->routingKey();
                $tag = $ca->isCoded() ? 'PRIVE' : 'CUSTOM';

                // For coded agents, use keywords/description from the agent class
                if ($ca->isCoded() && ($coded = $ca->makeCodedAgent())) {
                    $desc = method_exists($coded, 'description') ? $coded->description() : ($ca->description ?? '');
                    $kw = method_exists($coded, 'keywords') ? implode(', ', $coded->keywords()) : mb_strtolower($ca->name);
                } else {
                    $desc = $ca->description ?? 'Agent personnalise';
                    $kw = mb_strtolower($ca->name);
                }

                $lines[] = "■ \"{$key}\" (agent {$tag})";
                $lines[] = "  Description: [{$tag}] {$ca->name} — {$desc}";
                $lines[] = "  Mots-cles: {$kw}";
                $lines[] = "";
            }
        } catch (\Throwable $e) {
            Log::warning('RouterAgent: failed to load custom agents: ' . $e->getMessage());
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

■ "content_summarizer" — Resume de contenu web (articles, pages, videos YouTube avec transcription). Resumes courts, standards ou detailles
  Mots-cles: resume, résumé, resumer, summarize, summary, synthese, tldr, article, lien, URL, video, youtube, contenu, de quoi parle, lire pour moi

■ "habit" — Suivi d'habitudes
  Mots-cles: habitude, streak, tracker, challenge

■ "pomodoro" — Sessions de focus
  Mots-cles: pomodoro, focus, timer, session de travail

■ "web_search" — Recherche web en temps reel, actualites, definitions, meteo, comparaisons, stats API
  Mots-cles: cherche, recherche, google, search, trouve, actualite, news, c'est quoi, definition, meteo, weather, compare, vs, prix de, stats api

■ "interactive_quiz" — Quizz ludiques avec scoring, categories variees et classement
  Mots-cles: quiz, quizz, trivia, qcm, challenge, culture generale, devinette, question
CATALOG;
    }

    private function parseRouterResponse(?string $response): array
    {
        $default = [
            'agent' => 'chat',
            'model' => 'fast',
            'complexity' => 'simple',
            'autonomy' => 'confirm',
            'confidence' => 0,
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('RouterAgent: JSON decode error', ['error' => json_last_error_msg(), 'raw' => substr($clean, 0, 500)]);
            return $this->fallbackRoute($context);
        }

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
                'event_reminder', 'habit', 'pomodoro', 'document', 'web_search',
                'user_preferences',
                'conversation_memory',
                'streamline',
                'interactive_quiz',
                'content_curator',
                'context_memory_bridge',
                'game_master',
                'budget_tracker',
                'daily_brief',
                'collaborative_task',
                'recipe',
                'time_blocker',
                'assistant',
            ];
        }
        if (!in_array($parsed['agent'], $validAgents) && !str_starts_with($parsed['agent'], 'custom_')) {
            $parsed['agent'] = 'chat';
        }

        $autonomy = $parsed['autonomy'] ?? 'confirm';
        if (!in_array($autonomy, ['auto', 'confirm'])) {
            $autonomy = 'confirm';
        }

        $confidence = (int) ($parsed['confidence'] ?? 75);
        $confidence = max(0, min(100, $confidence));

        // Low confidence → fallback to chat (safer than wrong agent)
        if ($confidence < 50 && $parsed['agent'] !== 'chat') {
            Log::info('RouterAgent: low confidence fallback', [
                'original_agent' => $parsed['agent'],
                'confidence' => $confidence,
                'reasoning' => $parsed['reasoning'] ?? '',
            ]);
            $parsed['agent'] = 'chat';
            $parsed['reasoning'] = ($parsed['reasoning'] ?? '') . ' [low confidence → chat fallback]';
        }

        return [
            'agent' => $parsed['agent'],
            'model' => $parsed['model'] ?? $default['model'],
            'complexity' => $parsed['complexity'] ?? 'simple',
            'autonomy' => $autonomy,
            'confidence' => $confidence,
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
                $at = $r->scheduled_at->setTimezone(AppSetting::timezone())->format('d/m H:i');
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

    /**
     * Try deterministic fast-path patterns before calling the LLM.
     */
    private function matchFastPath(string $body, AgentContext $context): ?array
    {
        $clean = trim($body);

        // API-related keywords that may indicate the user wants the DevAgent
        $apiKeywords = '/\b(api|endpoint|campagne|prospect|facture|invoice|client|commande|order|produit|product|stat|dashboard|donnees|data|cle|key|token|credential|appel|call|requete|query|users|utilisateurs)\b/iu';

        foreach ($this->fastPathPatterns as $pattern => [$agent, $model, $complexity, $autonomy]) {
            if (preg_match($pattern, $clean)) {
                // If the user has an active API project and the message contains
                // API-related keywords, skip fast-path so the LLM router can
                // decide whether the DevAgent should handle it.
                if (
                    $agent !== 'dev'
                    && !empty($context->session->active_project_id)
                    && preg_match($apiKeywords, $clean)
                ) {
                    Log::info('RouterAgent: fast-path skipped (active project + API keywords)', [
                        'agent' => $agent,
                        'pattern' => $pattern,
                        'active_project_id' => $context->session->active_project_id,
                        'body' => mb_substr($clean, 0, 60),
                    ]);
                    return null;
                }

                Log::info('RouterAgent: fast-path match', ['agent' => $agent, 'pattern' => $pattern, 'body' => mb_substr($clean, 0, 60)]);
                return [
                    'agent' => $agent,
                    'model' => $model,
                    'complexity' => $complexity,
                    'autonomy' => $autonomy,
                    'confidence' => 95,
                    'reasoning' => "Fast-path: pattern match → {$agent}",
                ];
            }
        }

        return null;
    }

    private function detectGitlabUrl(string $body): bool
    {
        return (bool) preg_match('#https?://gitlab\.[^\s]+#i', $body);
    }

    private function detectCodeReviewKeywords(string $body): bool
    {
        return (bool) preg_match(
            '/(?:\b|@)(code\s*review|review\s*(my|this|the)?\s*code|verifi(er|e)\s*(ce|mon|le)\s*code|check\s*(this|my)?\s*code|codereviewer|quick\s*review|revue\s*rapide)\b/iu',
            $body
        );
    }

    private function getRecentHistory(ConversationMemoryService $memory, AgentContext $context, int $count = 5): string
    {
        $data = $memory->read($context->agent->id, $context->from);
        $entries = $data['entries'] ?? [];

        if (empty($entries)) return '';

        $recent = array_slice($entries, -$count);
        $lines = ['HISTORIQUE RECENT:'];
        foreach ($recent as $entry) {
            $msg = mb_substr($entry['sender_message'] ?? '', 0, 250);
            $reply = mb_substr($entry['agent_reply'] ?? '', 0, 350);
            $lines[] = "User: {$msg}";
            $lines[] = "ZeniClaw: {$reply}";
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    private function isAudioMessage(?string $mimetype): bool
    {
        if (!$mimetype) return false;
        $baseMime = explode(';', $mimetype)[0];
        return str_starts_with(trim($baseMime), 'audio/');
    }

    /**
     * Detect the type of action: question, command, or file_upload.
     */
    private function detectActionType(AgentContext $context): string
    {
        if ($context->hasMedia) {
            return 'file_upload';
        }

        $body = trim($context->body ?? '');

        if (str_starts_with($body, '/')) {
            return 'command';
        }

        if (preg_match('/\?\s*$/u', $body) || preg_match('/^(qu|comment|pourquoi|est[\s-]ce|who|what|where|when|why|how|combien|quel)/iu', $body)) {
            return 'question';
        }

        return 'message';
    }

    /**
     * Estimate sentiment from message text (simple heuristic).
     */
    private function estimateSentiment(string $body): string
    {
        $lower = mb_strtolower($body);

        $positivePatterns = '/\b(merci|super|genial|parfait|excellent|top|nickel|bravo|cool|love|content|heureux|motivé)\b/iu';
        $negativePatterns = '/\b(bug|erreur|nul|probleme|merde|chiant|enerve|triste|stress|fatigue|decu|frustre|pire|horrible)\b/iu';

        if (preg_match($positivePatterns, $lower)) {
            return 'positive';
        }
        if (preg_match($negativePatterns, $lower)) {
            return 'negative';
        }

        return 'neutral';
    }
}
