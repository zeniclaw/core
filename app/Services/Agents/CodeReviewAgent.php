<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\CodeAnalyzer;

class CodeReviewAgent extends BaseAgent
{
    private CodeAnalyzer $analyzer;

    /** Maximum total lines across all blocks before size warning */
    private const MAX_TOTAL_LINES = 400;

    /** Maximum characters to store for follow-up mode-change reviews */
    private const MAX_PENDING_CODE_CHARS = 8000;

    public function __construct()
    {
        parent::__construct();
        $this->analyzer = new CodeAnalyzer();
    }

    public function name(): string
    {
        return 'code_review';
    }

    public function description(): string
    {
        return 'Agent de revue de code automatique. Analyse du code source (PHP, JS, Python, SQL, TypeScript, Go, Java, Rust) pour detecter bugs, failles de securite, problemes de performance, violations des bonnes pratiques, et proposer des refactorings. Modes: quick (scan rapide), diff (comparaison), explain (explication), security (audit securite), refactor (refactoring), complexity (complexite cyclomatique), test (generation tests), doc (documentation), migrate (migration version), standards (normes/lint), translate (conversion langage), optimize (optimisation performance), full (analyse complete).';
    }

    public function keywords(): array
    {
        return [
            'code review', 'code-review', 'codereview', 'review code',
            'review my code', 'review this code', 'review the code',
            'verifier code', 'verifier mon code', 'verifier ce code', 'verifie ce code',
            'check code', 'check my code', 'check this code',
            'analyser code', 'analyse de code', 'code analysis',
            'revue de code', 'relecture de code',
            '@codereviewer', 'code reviewer',
            'securite code', 'code security', 'vulnerabilite', 'faille',
            'qualite code', 'code quality', 'bonnes pratiques', 'best practices',
            'bug dans le code', 'trouver bugs', 'find bugs',
            'refactoring', 'refactorer', 'refacto', 'ameliorer code',
            'optimiser code', 'optimize code', 'performance code',
            'quick review', 'revue rapide', 'scan rapide',
            'compare code', 'comparer code', 'diff code', 'avant apres',
            'complexite code', 'code complexity', 'score code',
            // v1.2.0
            'expliquer code', 'explain code', 'que fait ce code', 'what does this code do',
            'expliquer ce code', 'explain this code', 'describe this code', 'decrire ce code',
            'audit securite', 'security audit', 'audit de securite', 'scan securite',
            'audit code', 'code audit', 'vulnerabilites code', 'code vulnerabilities',
            'owasp', 'pentest code', 'security scan', 'scan de securite',
            // v1.3.0
            'refactor this code', 'refactorer ce code', 'nettoyer ce code', 'clean up code',
            'restructurer', 'reorganiser le code', 'propose refactoring',
            'complexite cyclomatique', 'cyclomatic complexity', 'simplifier', 'simplify code',
            'trop complexe', 'code complexe', 'analyse complexite',
            // v1.4.0
            'generer tests', 'generate tests', 'write tests', 'ecrire tests', 'test unitaire',
            'unit test', 'unit tests', 'tests unitaires', 'tester ce code', 'test this code',
            'documenter code', 'doc code', 'generate docs', 'docblock', 'jsdoc', 'phpdoc',
            'commenter code', 'comment code', 'documenter ce code', 'ajouter documentation',
            'aide code review', 'help code review', 'code review help',
            // v1.5.0
            'migrer code', 'migrate code', 'migration code', 'upgrade code', 'moderniser code',
            'modernize code', 'migrer vers php 8', 'migrate to php 8', 'migrer php',
            'python 2 vers 3', 'python 2 to 3', 'upgrade javascript', 'upgrade python',
            'migration php', 'migrer vers', 'migrate to', 'modernisation code',
            'normes code', 'code standards', 'coding standards', 'psr-12', 'psr 12',
            'pep 8', 'pep8', 'eslint rules', 'code style', 'style de code',
            'conventions code', 'naming conventions', 'lint code', 'linter code',
            'conformite code', 'code conformite', 'bonnes pratiques langage',
            // v1.6.0 - translate
            'traduire en', 'traduire vers', 'translate to', 'translate into', 'convert to',
            'convert this code to', 'convertir en', 'convertir vers', 'transposer en',
            'transposer vers', 'reedire en', 'reecrire en', 'rewrite in', 'rewrite as',
            'code en python', 'code en javascript', 'code en typescript', 'code en php',
            'code en java', 'code en go', 'code en rust',
            // v1.6.0 - optimize
            'optimiser performance', 'optimize performance', 'ameliorer performance',
            'improve performance', 'performance bottleneck', 'bottleneck', 'goulot',
            'slow code', 'code lent', 'profil code', 'profiler code', 'profiling',
            'reduire latence', 'reduce latency', 'gagner en vitesse', 'speed up',
            'consommation memoire', 'memory usage', 'cpu usage', 'consommation cpu',
        ];
    }

    public function version(): string
    {
        return '1.6.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        // Help trigger
        if (preg_match('/\b(aide\s*code\s*review|help\s*code\s*review|code\s*review\s*help)\b/iu', $context->body)) {
            return true;
        }

        // Explicit code review / audit / explain / refactor / complexity / test / doc / migrate / standards request
        if (preg_match('/(?:\b|@)(code\s*review|review\s*(my|this|the)?\s*code|verifi(er|e)\s*(ce|mon|le)\s*code|check\s*(this|my)?\s*code|codereviewer|quick\s*review|revue\s*rapide|scan\s*rapide|compare\s*code|comparer\s*code|diff\s*code|explain\s*(this\s*|the\s*|my\s*|ce\s*|mon\s*|le\s*)?code|expliquer\s*(ce|le|mon)\s*code|que\s*fait\s*ce\s*code|security\s*audit|audit\s*(de\s*)?s[eé]curit[eé]|audit\s*code|code\s*audit|scan\s*(de\s*)?s[eé]curit[eé]|refactor\s*(this\s*|the\s*|my\s*|ce\s*|mon\s*|le\s*)?code|refactorer\s*(ce|le|mon)\s*code|nettoyer\s*(ce\s*|le\s*|mon\s*)?code|clean\s+up\s+code|restructurer|propose\s*refactoring|complexit[eé]\s*cyclomatique|cyclomatic\s*complexity|analyse\s*complexit[eé]|simplifi(er|y)\s*(ce\s*|this\s*)?code|g[eé]n[eé]rer\s*tests?|generate\s*tests?|write\s*tests?|[eé]crire\s*tests?|tests?\s*unitaires?|unit\s*tests?|tester\s*(ce\s*)?code|test\s*this\s*code|documenter\s*(ce\s*)?code|doc\s*code|generate\s*docs?|docblock|jsdoc|phpdoc|commenter\s*code|comment\s*code|ajouter\s*documentation|migr(er|ate)\s*(ce\s*|this\s*|vers?\s*|to\s*)?code|migration\s*code|upgrade\s*code|modernis[ae]r?\s*(ce\s*|le\s*|this\s*)?code|migr(er|ate)\s*(vers?\s*|to\s*)(php|python|javascript|js|typescript|ts|es\d*)|psr-?12|pep\s*8|eslint|code\s*standards?|coding\s*standards?|style\s*de\s*code|code\s*style|normes?\s*(de\s*)?code|conventions?\s*code|lint(er)?\s*code|conformit[eé]\s*code)\b/iu', $context->body)) {
            return true;
        }

        // v1.6.0 — translate / optimize fast-paths
        if (preg_match('/\b(traduire?\s*(en|vers)|translat(e\s*(to|into)|ion)|convert\s*(to|this\s*code\s*to)|convertir\s*(en|vers)|transposer\s*(en|vers)|r[eé][eé]crire\s*(en|as|in)|rewrite\s*(in|as)|code\s*en\s*(python|javascript|typescript|php|java|go|rust|ruby|c\+?\+?))\b/iu', $context->body)) {
            return true;
        }
        if (preg_match('/\b(optimi[sz](er|e)?\s*performance|am[eé]lior(er|e)?\s*performance|improve\s*performance|performance\s*bottleneck|bottleneck|goulot|slow\s*code|code\s*lent|profil(er|ing)?\s*code|profiling|speed\s*up|r[eé]duire?\s*latence|reduce\s*latency|memory\s*usage|cpu\s*usage|consommation\s*(m[eé]moire|cpu))\b/iu', $context->body)) {
            return true;
        }

        // Has code blocks
        $blocks = $this->analyzer->extractCodeBlocks($context->body);
        return !empty($blocks);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp();
        }

        // Explicit help request
        if (preg_match('/\b(aide\s*code\s*review|help\s*code\s*review|code\s*review\s*help)\b/iu', $body)) {
            return $this->showHelp();
        }

        $this->log($context, 'Code review requested', ['body_length' => mb_strlen($body)]);

        return $this->runReview($body, $context);
    }

    /**
     * Handle follow-up mode-change requests after a successful review.
     * E.g. user sends "refactor code" or "security audit" after a review
     * — no need to paste the code again.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'code_reviewed') {
            return null;
        }

        $body      = trim($context->body ?? '');
        $savedCode = $pendingContext['data']['raw_code'] ?? null;

        if (!$savedCode) {
            $this->clearPendingContext($context);
            return null;
        }

        // If message contains new code blocks → let normal handle() run
        $newBlocks = $this->analyzer->extractCodeBlocks($body);
        if (!empty($newBlocks)) {
            $this->clearPendingContext($context);
            return null;
        }

        // Detect if user is requesting a specific mode (without new code)
        $newMode = $this->detectMode($body);
        if ($newMode === 'full') {
            // No recognizable mode keyword — not a mode-change follow-up
            $this->clearPendingContext($context);
            return null;
        }

        $this->log($context, 'Code review follow-up: mode change', [
            'previous_mode' => $pendingContext['data']['mode'] ?? 'unknown',
            'new_mode'      => $newMode,
        ]);

        $this->clearPendingContext($context);

        // Re-run analysis using saved code + current mode keyword
        return $this->runReview($savedCode . "\n" . $body, $context);
    }

    // ── Core Analysis ─────────────────────────────────────────────────────────

    private function runReview(string $body, AgentContext $context): AgentResult
    {
        $mode = $this->detectMode($body);

        $codeBlocks = $this->analyzer->extractCodeBlocks($body);

        if (empty($codeBlocks)) {
            return AgentResult::reply(
                "Je n'ai pas trouve de code a analyser dans ton message.\n\n"
                . "Envoie ton code dans un bloc :\n"
                . "‎```php\n// ton code ici\n‎```\n\n"
                . "Langages supportes : PHP, JavaScript, TypeScript, Python, SQL, Go, Java, Rust, Ruby, C/C++\n\n"
                . "Modes disponibles :\n"
                . "• _quick review_ — scan rapide (bugs critiques uniquement)\n"
                . "• _compare code_ — comparer deux versions (2 blocs)\n"
                . "• _explain code_ — comprendre ce que fait le code\n"
                . "• _security audit_ — audit de securite OWASP\n"
                . "• _refactor code_ — propositions de refactoring avec exemples\n"
                . "• _analyse complexite_ — complexite cyclomatique et imbrication\n"
                . "• _generer tests_ — generer des tests unitaires\n"
                . "• _doc code_ — generer la documentation/docblocks\n"
                . "• _migrate code_ — migrer vers PHP 8.4 / Python 3 / ES2022\n"
                . "• _code standards_ — verifier PSR-12, PEP 8, ESLint\n"
                . "• _traduire en [langage]_ — convertir vers un autre langage\n"
                . "• _optimiser performance_ — optimisation N+1, algo, cache\n"
                . "• _code review_ — analyse complete (defaut)\n\n"
                . "Tape _aide code review_ pour plus d'infos."
            );
        }

        // Size guard
        $totalLines  = array_sum(array_column($codeBlocks, 'line_count'));
        $sizeWarning = '';
        if ($totalLines > self::MAX_TOTAL_LINES) {
            $sizeWarning = "⚠ _Code volumineux ({$totalLines} lignes) — analyse limitee aux premieres sections_\n\n";
        }

        // Diff mode requires exactly 2 blocks
        if ($mode === 'diff' && count($codeBlocks) < 2) {
            return AgentResult::reply(
                "Pour le mode comparaison, envoie *deux blocs de code* :\n\n"
                . "- Premier bloc : version *avant*\n"
                . "- Deuxieme bloc : version *apres*\n\n"
                . "Puis ajoute : _compare code_ ou _diff code_"
            );
        }

        // Static pattern analysis (skip for structural/generative modes)
        $staticIssues = [];
        if (!in_array($mode, ['explain', 'refactor', 'test', 'doc', 'migrate', 'standards', 'translate', 'optimize'])) {
            foreach ($codeBlocks as $block) {
                $issues = $this->analyzer->analyzePatterns($block['code'], $block['language']);
                if (!empty($issues)) {
                    $staticIssues[] = [
                        'language' => $block['language'],
                        'issues'   => $issues,
                    ];
                }
            }
        }

        // Build Claude prompt
        $codeContext  = $this->buildCodeContext($codeBlocks, $staticIssues, $mode);
        $model        = $this->resolveModel($context);
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
        $systemPrompt = $this->buildSystemPrompt($mode, $memoryPrompt);

        $response = $this->claude->chat($codeContext, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply(
                "Desole, je n'ai pas pu analyser le code ({$totalLines} lignes, mode: {$mode}).\n\n"
                . "Solutions :\n"
                . "• Divise le code en extraits plus courts\n"
                . "• Utilise _quick review_ pour un scan allege\n"
                . "• Reessaie dans quelques instants"
            );
        }

        // Save code for follow-up mode changes (10 min TTL)
        if (mb_strlen($body) <= self::MAX_PENDING_CODE_CHARS && !empty($codeBlocks)) {
            $this->setPendingContext($context, 'code_reviewed', [
                'raw_code' => $body,
                'mode'     => $mode,
            ], 10);
        }

        $report = $this->formatFinalReport($codeBlocks, $staticIssues, $response, $mode, $sizeWarning);

        $this->log($context, 'Code review completed', [
            'mode'          => $mode,
            'blocks'        => count($codeBlocks),
            'total_lines'   => $totalLines,
            'static_issues' => count($staticIssues),
        ]);

        return AgentResult::reply($report);
    }

    // ── Mode Detection ────────────────────────────────────────────────────────

    /**
     * Detect review mode: 'quick', 'diff', 'explain', 'security', 'refactor', 'complexity', or 'full'.
     */
    private function detectMode(string $body): string
    {
        if (preg_match('/\b(quick\s*review|revue\s*rapide|scan\s*rapide|quick\s*scan)\b/iu', $body)) {
            return 'quick';
        }
        if (preg_match('/\b(compare\s*code|comparer\s*code|diff\s*code|avant\s*apres|before\s*after)\b/iu', $body)) {
            return 'diff';
        }
        if (preg_match('/\b(explain\s*(this\s*|the\s*|my\s*|ce\s*|mon\s*|le\s*)?code|expliquer\s*(ce|le|mon)\s*code|que\s*fait\s*ce\s*code|what\s*does\s*this\s*code\s*do|decrire\s*ce\s*code|describe\s*this\s*code)\b/iu', $body)) {
            return 'explain';
        }
        if (preg_match('/\b(security\s*audit|audit\s*(de\s*)?s[eé]curit[eé]|audit\s*code|code\s*audit|scan\s*(de\s*)?s[eé]curit[eé]|security\s*scan|owasp)\b/iu', $body)) {
            return 'security';
        }
        if (preg_match('/\b(refactor\s*(this\s*|the\s*|my\s*|ce\s*|mon\s*|le\s*)?code|refactorer\s*(ce|le|mon)\s*code|nettoyer\s*(ce\s*|le\s*|mon\s*)?code|clean\s*up\s*code|restructurer|propose\s*refactoring)\b/iu', $body)) {
            return 'refactor';
        }
        if (preg_match('/\b(complexit[eé]\s*cyclomatique|cyclomatic\s*complexity|analyse\s*complexit[eé]|simplifi(er|y)\s*(ce\s*|this\s*)?code|code\s*complexe|trop\s*complexe)\b/iu', $body)) {
            return 'complexity';
        }
        if (preg_match('/\b(g[eé]n[eé]rer\s*tests?|generate\s*tests?|write\s*tests?|[eé]crire\s*tests?|tests?\s*unitaires?|unit\s*tests?|tester\s*(ce\s*)?code|test\s*this\s*code)\b/iu', $body)) {
            return 'test';
        }
        if (preg_match('/\b(documenter\s*(ce\s*)?code|doc\s*code|generate\s*docs?|docblock|jsdoc|phpdoc|commenter\s*code|comment\s*code|ajouter\s*documentation)\b/iu', $body)) {
            return 'doc';
        }
        if (preg_match('/\b(migr(er|ate)\s*(ce\s*|this\s*|vers?\s*|to\s*)?code|migration\s*code|upgrade\s*code|modernis[ae]r?\s*(ce\s*|le\s*|this\s*)?code|migr(er|ate)\s*(vers?\s*|to\s*)(php|python|javascript|js|typescript|ts|es\d*)|modernisation\s*code)\b/iu', $body)) {
            return 'migrate';
        }
        if (preg_match('/\b(psr-?12|pep\s*8|eslint|code\s*standards?|coding\s*standards?|style\s*de\s*code|code\s*style|normes?\s*(de\s*)?code|conventions?\s*code|lint(er)?\s*code|conformit[eé]\s*code|bonnes?\s*pratiques?\s*langage)\b/iu', $body)) {
            return 'standards';
        }
        if (preg_match('/\b(traduire?\s*(en|vers)|translat(e\s*(to|into)|ion)|convert\s*(to|this\s*code\s*to)|convertir\s*(en|vers)|transposer\s*(en|vers)|r[eé][eé]crire\s*(en|as|in)|rewrite\s*(in|as)|code\s*en\s*(python|javascript|typescript|php|java|go|rust|ruby|c\+?\+?))\b/iu', $body)) {
            return 'translate';
        }
        if (preg_match('/\b(optimi[sz](er|e)?\s*performance|am[eé]lior(er|e)?\s*performance|improve\s*performance|performance\s*bottleneck|bottleneck|goulot|slow\s*code|code\s*lent|profil(er|ing)?\s*code|profiling|speed\s*up|r[eé]duire?\s*latence|reduce\s*latency|memory\s*usage|cpu\s*usage|consommation\s*(m[eé]moire|cpu))\b/iu', $body)) {
            return 'optimize';
        }
        return 'full';
    }

    // ── Prompt Building ────────────────────────────────────────────────────────

    private function buildCodeContext(array $codeBlocks, array $staticIssues, string $mode): string
    {
        $parts = [];

        if ($mode === 'diff' && count($codeBlocks) >= 2) {
            $parts[] = "=== VERSION AVANT (BLOC 1 — {$codeBlocks[0]['line_count']} lignes) ===\n" . $this->addLineNumbers($codeBlocks[0]['code']);
            $parts[] = "=== VERSION APRES (BLOC 2 — {$codeBlocks[1]['line_count']} lignes) ===\n" . $this->addLineNumbers($codeBlocks[1]['code']);
        } else {
            foreach ($codeBlocks as $i => $block) {
                $label     = strtoupper($block['language'] ?: 'CODE');
                $truncNote = $block['truncated'] ? ' [TRONQUE]' : '';
                $numbered  = $this->addLineNumbers($block['code']);
                $parts[]   = "=== BLOC " . ($i + 1) . " ({$label}, {$block['line_count']} lignes{$truncNote}) ===\n{$numbered}";
            }
        }

        if (!empty($staticIssues)) {
            $issueLines = ["=== ANALYSE STATIQUE PRE-DETECTION ==="];
            foreach ($staticIssues as $group) {
                $lang = strtoupper($group['language']);
                foreach ($group['issues'] as $issue) {
                    $issueLines[] = "[{$issue['severity']}][{$issue['type']}][{$lang}] {$issue['message']}";
                }
            }
            $parts[] = implode("\n", $issueLines);
        }

        return implode("\n\n", $parts);
    }

    private function buildSystemPrompt(string $mode, string $memoryPrompt): string
    {
        $base = match ($mode) {
            'quick'      => $this->getQuickSystemPrompt(),
            'diff'       => $this->getDiffSystemPrompt(),
            'explain'    => $this->getExplainSystemPrompt(),
            'security'   => $this->getSecuritySystemPrompt(),
            'refactor'   => $this->getRefactorSystemPrompt(),
            'complexity' => $this->getComplexitySystemPrompt(),
            'test'       => $this->getTestSystemPrompt(),
            'doc'        => $this->getDocSystemPrompt(),
            'migrate'    => $this->getMigrateSystemPrompt(),
            'standards'  => $this->getStandardsSystemPrompt(),
            'translate'  => $this->getTranslateSystemPrompt(),
            'optimize'   => $this->getOptimizeSystemPrompt(),
            default      => $this->getFullSystemPrompt(),
        };

        if ($memoryPrompt) {
            $base .= "\n\n" . $memoryPrompt;
        }

        return $base;
    }

    private function getFullSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert senior en revue de code (10+ ans d'experience, specialiste PHP/JS/Python/Go/Java/Rust). Analyse le code fourni et produis un rapport structure, precis et actionnable.

CATEGORIES D'ANALYSE (inspecte chaque categorie systematiquement) :
1. BUGS & ERREURS — erreurs logiques, conditions aux limites non gerees, comportements indefinis, edge cases manques
2. SECURITE — injections SQL/XSS/CSRF/Command, credentials hardcodes, failles OWASP Top 10, exposition de donnees sensibles, deserialisation non securisee
3. PERFORMANCE — requetes N+1, boucles inefficaces, fuites memoire, complexite algorithmique O(n²)+, appels synchrones bloquants, allocations inutiles
4. QUALITE & BONNES PRATIQUES — nommage trompeur, code duplique (DRY), violations SOLID, lisibilite, couplage fort, gestion d'erreurs incomplete, anti-patterns
5. SUGGESTIONS — refactoring propose avec exemple concret, patterns recommandes, modernisation (PHP 8.4, ES2022+, Python 3.12+)

FORMAT DE REPONSE OBLIGATOIRE :
[Resume en 1-2 phrases : qualite generale et point le plus important]

Puis pour chaque probleme (groupe par categorie si plusieurs) :
[SEVERITE] CATEGORIE (ligne X) : Description precise du probleme
→ Correction : explication concrete ou pseudo-code corrige

Exemples de format attendu :
🔴 SECURITE (ligne 14) : SQL Injection — $id injecte directement dans la requete
→ Correction : utiliser un prepared statement avec bindParam(':id', $id)

🟠 PERFORMANCE (ligne 28-35) : Requete N+1 dans la boucle foreach
→ Correction : remplacer par User::with('orders')->get()

🟡 QUALITE (ligne 7) : Variable $x non descriptive
→ Correction : renommer en $userId ou $currentUser

SEVERITES : 🔴 Critique | 🟠 Important | 🟡 Mineur | 🔵 Info/Suggestion

SCORE FINAL :
NOTE : A/B/C/D/F — justification en 1 ligne
COMPLEXITE : Faible/Moyenne/Elevee/Tres elevee — justification

REGLES :
- Reference toujours les numeros de ligne fournis
- Sois precis et actionnable — montre du vrai code corrige si pertinent
- Si le code est globalement bon, dis-le clairement — ne cherche pas de problemes artificiels
- Si l'analyse statique pre-detection a trouve des alertes, confirme ou infirme chacune
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse (WhatsApp les affiche mal)
- Pour les exemples inline, utilise _italique_ ou *gras*
PROMPT;
    }

    private function getQuickSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert securite et qualite de code. Mode SCAN RAPIDE : identifie UNIQUEMENT les problemes CRITIQUES et IMPORTANTS.

FORMAT CONCIS OBLIGATOIRE :
[Resume en 1 phrase : code safe ou problemes detectes]

Pour chaque probleme critique/important SEULEMENT :
[🔴/🟠] TYPE (ligne X) : Probleme en 1 ligne
→ Correctif rapide

Exemples :
🔴 SECURITE (ligne 5) : eval() sur donnee utilisateur — injection de code possible
→ Supprimer eval(), utiliser une whitelist ou json_decode()

🟠 BUGS (ligne 12) : division par zero possible si $total = 0
→ Ajouter : if ($total === 0) return 0;

NOTE FINALE : A/B/C/D/F — 1 ligne de justification

REGLES :
- Maximum 8-10 points au total
- Ignore les problemes mineurs et suggestions
- Sois ultra-concis, chaque ligne compte
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getDiffSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en revue de code. Mode COMPARAISON DIFF : tu recois deux versions d'un code (avant/apres).

Ta mission :
1. Identifier precisement ce qui a change (ajouts, suppressions, modifications)
2. Evaluer si chaque changement ameliore ou degrade la qualite/securite/performance
3. Signaler les nouveaux bugs ou regressions introduits
4. Valider les ameliorations apportees

FORMAT DE REPONSE OBLIGATOIRE :
[Resume des changements en 1-2 lignes : nombre de modifications, orientation generale]

CHANGEMENTS DETECTES :
+ Ajout (ligne X) : description precise
- Suppression (ligne X) : description precise
~ Modification (ligne X) : avant → apres, impact

EVALUATION :
✅ Ameliorations : liste des points positifs avec justification
⚠ Regressions/Nouveaux problemes : liste avec ligne et explication concrete

VERDICT FINAL : Meilleure/Equivalente/Degradee
Justification en 2-3 lignes avec les elements decisifs

REGLES :
- Compare les deux blocs systematiquement ligne par ligne
- Sois objectif — reconnais les bonnes modifications comme les regressions
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getExplainSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en pedagogie du code et architecture logicielle. Mode EXPLICATION : tu dois expliquer clairement ce que fait le code fourni.

Ta mission :
1. Expliquer le but global du code en termes simples
2. Decrire le flux d'execution etape par etape
3. Identifier les patterns et structures utilises
4. Expliquer les choix techniques importants

FORMAT DE REPONSE OBLIGATOIRE :
*But general :*
[1-2 phrases decrivant ce que fait ce code et dans quel contexte il est utilise]

*Flux d'execution :*
[Description etape par etape de comment le code fonctionne, avec references aux lignes]

*Concepts cles utilises :*
[Liste des patterns, algorithmes, ou techniques notables avec breve explication si non trivial]

*Points d'attention :*
[1-3 observations sur les aspects importants a comprendre — dependances, effets de bord, preconditions]

Exemple de format :
*But general :*
Ce code est un middleware d'authentification JWT qui valide les tokens entrants et enrichit la requete avec les donnees utilisateur.

*Flux d'execution :*
1. Ligne 5-8 : extraction du token depuis le header Authorization
2. Ligne 10-15 : verification de la signature via la cle secrete
...

REGLES :
- Adapte le niveau d'explication au type de code (script simple vs architecture complexe)
- Utilise des analogies si utile pour clarifier les concepts
- Reference les numeros de ligne pour faciliter la comprehension
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
- Ne cherche PAS de bugs ou problemes — c'est un mode explication pure
PROMPT;
    }

    private function getSecuritySystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en securite applicative (OWASP, penetration testing, secure code review). Mode AUDIT DE SECURITE : analyse UNIQUEMENT les aspects securite du code.

CATEGORIES A INSPECTER (OWASP Top 10 + extras) :
A01 - Broken Access Control : controles d'acces manquants ou contournables
A02 - Cryptographic Failures : chiffrement faible, donnees sensibles exposees, certificats invalides
A03 - Injection : SQL, NoSQL, OS Command, LDAP, XPath, SSTI, Log injection
A04 - Insecure Design : architecture non securisee, logique metier exploitable
A05 - Security Misconfiguration : config par defaut, verbose errors, CORS trop permissif
A06 - Vulnerable Components : dependances obsoletes avec CVE connues
A07 - Auth Failures : session fixation, tokens previsibles, bruteforce possible
A08 - Data Integrity Failures : deserialisation non securisee, CI/CD pipeline injection
A09 - Logging Failures : donnees sensibles en clair dans les logs, absence de logs
A10 - SSRF : requetes serveur non validees vers ressources internes

FORMAT DE REPONSE OBLIGATOIRE :
*Bilan securite* : [1 phrase : niveau de risque global — Critique/Eleve/Moyen/Faible]

Pour chaque vulnerabilite detectee :
[SEVERITE] OWASP-AXX (ligne X) : Type de vulnerabilite
Vecteur d'attaque : comment un attaquant exploiterait cette faille
→ Remediation : correction concrete avec exemple si applicable
CVE/CWE : reference si applicable

SEVERITES : 🔴 Critique (exploitation immediate) | 🟠 Eleve (exploitation facile) | 🟡 Moyen | 🔵 Info

SCORE DE RISQUE FINAL :
Niveau : Critique/Eleve/Moyen/Faible
Priorite de correction : liste des 3 points les plus urgents

REGLES :
- Si aucune vulnerabilite trouvee : dis-le clairement avec les bonnes pratiques confirmees
- Reference toujours les numeros de ligne
- Donne des vecteurs d'attaque realistes, pas theoriques
- Sois exhaustif sur les risques de securite, ignore les problemes qualite/perf
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getRefactorSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en refactoring et architecture logicielle (Design Patterns GoF, SOLID, Clean Code de Robert Martin). Mode REFACTORING : propose des ameliorations concretes pour rendre le code plus maintenable, lisible et robuste.

Ta mission :
1. Identifier les anti-patterns, la duplication (DRY), le code trop complexe ou mal structure
2. Proposer des refactorings CONCRETS avec du code avant/apres (concis, 1-4 lignes)
3. Nommer et expliquer le pattern applique (Extract Method, Strategy, Replace Magic Number, etc.)
4. Prioriser par impact sur la maintenabilite et la testabilite

FORMAT DE REPONSE OBLIGATOIRE :
*Etat general :* [1 ligne sur la maintenabilite actuelle — Excellent/Bon/Acceptable/A ameliorer/Critique]

Pour chaque refactoring propose :
[PRIORITE] NOM DU PATTERN (ligne X) : Probleme detecte
Avant : [code actuel simplifie — 1-3 lignes max]
Apres : [code refactore — 1-3 lignes max]
Benefice : [gain en 1 ligne — lisibilite, testabilite, DRY, responsabilite unique, etc.]

Exemples :
🔴 EXTRACT METHOD (ligne 45-67) : methode de 23 lignes avec 3 responsabilites distinctes
Avant : function processOrder($o) { /* validation + calcul + email */ }
Apres : function processOrder($o) { $this->validate($o); $total = $this->calculate($o); $this->notify($o, $total); }
Benefice : responsabilite unique, chaque sous-methode testable independamment

🟠 REPLACE MAGIC NUMBER (ligne 12) : constante numerique sans signification
Avant : if ($elapsed > 86400) { ... }
Apres : const SECONDS_PER_DAY = 86400; if ($elapsed > self::SECONDS_PER_DAY) { ... }
Benefice : code auto-documente, valeur modifiable en un seul endroit

PRIORITES : 🔴 Urgent | 🟠 Important | 🟡 Recommande | 🔵 Optionnel

BILAN REFACTORING :
Score maintenabilite actuel : A/B/C/D/F
Score apres refactoring estime : A/B/C/D/F
Effort estime : Faible (<1h) / Moyen (1-4h) / Eleve (>4h)

REGLES :
- Propose des refactorings CONCRETS, pas des generalisations vagues
- Le code avant/apres doit etre directement exploitable
- Nomme les patterns (Martin Fowler Refactoring, GoF Design Patterns, Clean Code)
- Ne signale PAS les bugs de securite — concentre-toi sur la structure et la lisibilite
- Reference toujours les numeros de ligne
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getComplexitySystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en metriques de code et architecture logicielle. Mode ANALYSE DE COMPLEXITE : evalue et quantifie la complexite du code fourni avec des metriques concretes.

METRIQUES A EVALUER :
1. COMPLEXITE CYCLOMATIQUE — nombre de chemins independants (if/else/switch/boucles/catch/ternaires)
   Seuils : 1-5 Simple | 6-10 Moderee | 11-20 Elevee | 21+ Critique
2. PROFONDEUR D'IMBRICATION — niveau max de nesting (if dans for dans try...)
   Seuils : 1-2 OK | 3 Limite | 4+ Problematique
3. LONGUEUR DES FONCTIONS — nombre de lignes par methode/fonction
   Seuils : <15 Ideal | 15-30 Acceptable | 31-50 Long | 50+ Trop long
4. COUPLAGE — nombre de dependances externes et interactions entre modules
5. COHESION — est-ce que chaque fonction/classe fait une seule chose bien definie ?

FORMAT DE REPONSE OBLIGATOIRE :
*Analyse de complexite*

Pour chaque fonction/methode identifiable :
[NOM] (ligne X-Y) — CC: [score] | Imbrication: [N] | Longueur: [N] lignes | [Simple/Moderee/Elevee/Critique]
  Problemes : [description concise ou "aucun"]

POINTS CHAUDS :
[Liste des 2-3 sections les plus complexes avec justification chiffree]

RECOMMANDATIONS :
[Pour chaque point chaud : suggestion concrete de simplification avec la technique a appliquer]

SCORE GLOBAL :
Complexite moyenne : Simple/Moderee/Elevee/Critique
Maintenabilite : A/B/C/D/F
Actions prioritaires : [2-3 actions concretes et ordonnees pour reduire la complexite]

REGLES :
- Calcule les metriques pour chaque fonction/methode identifiable dans le code
- Sois quantitatif — donne des chiffres, pas juste des impressions
- Si le code est simple et bien structure, dis-le explicitement — c'est aussi une information utile
- Reference toujours les numeros de ligne
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    // ── Report Formatting ──────────────────────────────────────────────────────

    private function formatFinalReport(
        array $codeBlocks,
        array $staticIssues,
        string $claudeReview,
        string $mode,
        string $sizeWarning
    ): string {
        $modeLabel = match ($mode) {
            'quick'      => '⚡ *CODE REVIEW RAPIDE*',
            'diff'       => '🔄 *COMPARAISON DE CODE*',
            'explain'    => '📖 *EXPLICATION DE CODE*',
            'security'   => '🔒 *AUDIT DE SECURITE*',
            'refactor'   => '♻ *REFACTORING*',
            'complexity' => '📊 *ANALYSE DE COMPLEXITE*',
            'test'       => '🧪 *GENERATION DE TESTS*',
            'doc'        => '📝 *DOCUMENTATION*',
            'migrate'    => '🚀 *MIGRATION DE CODE*',
            'standards'  => '📐 *NORMES & STANDARDS*',
            'translate'  => '🌐 *TRADUCTION DE CODE*',
            'optimize'   => '⚡ *OPTIMISATION PERFORMANCE*',
            default      => '🔍 *CODE REVIEW*',
        };

        $langs      = array_map(fn ($b) => strtoupper($b['language'] ?: '?'), $codeBlocks);
        $totalLines = array_sum(array_column($codeBlocks, 'line_count'));
        $header     = "{$modeLabel}\n"
            . count($codeBlocks) . " bloc(s) | "
            . implode(', ', array_unique($langs)) . " | "
            . "{$totalLines} lignes\n\n";

        // Static analysis critical warnings (not shown in structural/generative modes)
        $staticSection = '';
        if (!empty($staticIssues) && !in_array($mode, ['explain', 'refactor', 'test', 'doc', 'migrate', 'standards', 'translate', 'optimize'])) {
            $criticalCount = 0;
            foreach ($staticIssues as $group) {
                foreach ($group['issues'] as $issue) {
                    if (in_array($issue['severity'], ['critical', 'high'])) $criticalCount++;
                }
            }
            if ($criticalCount > 0) {
                $staticSection = "⚠ *{$criticalCount} alerte(s) critique/haute detectee(s) par analyse statique*\n\n";
            }
        }

        return $header . $sizeWarning . $staticSection . trim($claudeReview);
    }

    private function addLineNumbers(string $code): string
    {
        $lines    = explode("\n", $code);
        $numbered = [];
        foreach ($lines as $i => $line) {
            $num        = str_pad($i + 1, 3, ' ', STR_PAD_LEFT);
            $numbered[] = "{$num} | {$line}";
        }
        return implode("\n", $numbered);
    }

    private function getTestSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en test logiciel (TDD, BDD, testing patterns). Mode GENERATION DE TESTS : genere des tests unitaires complets et bien structures pour le code fourni.

Ta mission :
1. Analyser le code et identifier toutes les fonctions/methodes testables
2. Generer des tests unitaires couvrant : cas nominaux, cas limites, cas d'erreur
3. Utiliser le framework de test adapte au langage (PHPUnit pour PHP, Jest pour JS/TS, pytest pour Python, JUnit pour Java, etc.)
4. Viser une couverture de code maximale (happy path + edge cases + error paths)

FORMAT DE REPONSE OBLIGATOIRE :
*Framework utilise :* [nom du framework detecte ou recommande]
*Couverture estimee :* [pourcentage estimé de couverture avec ces tests]

Pour chaque classe/fonction testee :
[NOM DE LA FONCTION] — [N] tests

Test 1 : [nom descriptif en snake_case]
Objectif : [ce que le test verifie en 1 ligne]
Code du test :
[code du test indenté, sans blocs ``` mais avec une indentation claire]

Test 2 : [nom descriptif]
...

RESUME :
Total tests generes : [N]
Cas non couverts : [liste des scenarios difficiles a tester — dependances externes, etc.]
Recommandations : [1-2 suggestions pour ameliorer la testabilite du code si necessaire]

EXEMPLES DE FORMAT ATTENDU :

test_calculate_total_returns_sum_of_items
Objectif : verifie que le total est la somme correcte des items
Code du test :
  public function test_calculate_total_returns_sum_of_items(): void
  {
      $cart = new Cart([new Item(10.0), new Item(5.0)]);
      $this->assertEquals(15.0, $cart->calculateTotal());
  }

test_calculate_total_returns_zero_for_empty_cart
Objectif : verifie le cas limite panier vide
Code du test :
  public function test_calculate_total_returns_zero_for_empty_cart(): void
  {
      $cart = new Cart([]);
      $this->assertEquals(0.0, $cart->calculateTotal());
  }

REGLES :
- Genere des tests REELS et directement utilisables, pas des squelettes vides
- Nomme les tests de facon descriptive (test_what_when_then ou should_do_something_when_condition)
- Couvre obligatoirement : valeurs nulles, tableaux vides, valeurs limites, exceptions attendues
- Pour les dependances externes : montre comment les mocker
- N'utilise PAS de blocs ``` dans ta reponse
- Reponds en francais pour les commentaires, en anglais pour les noms de methodes de test
PROMPT;
    }

    private function getDocSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en documentation technique (PHPDoc, JSDoc, Python docstrings, JavaDoc, Rustdoc). Mode DOCUMENTATION : genere une documentation complete et precise pour le code fourni.

Ta mission :
1. Analyser chaque fonction/methode/classe et generer la documentation adaptee au langage
2. Documenter : but, parametres (types + description), valeur de retour, exceptions levees, exemples d'utilisation
3. Identifier et documenter les comportements non evidents (effets de bord, preconditions, postconditions)
4. Adapter le style au langage (PHPDoc pour PHP, JSDoc pour JS/TS, docstrings pour Python, etc.)

FORMAT DE REPONSE OBLIGATOIRE :
*Style de documentation :* [PHPDoc / JSDoc / Python Docstring / JavaDoc / Rustdoc]

Pour chaque fonction/methode/classe :
[NOM] (ligne X) :
[bloc de documentation complet, indenté, sans blocs ``` mais avec une indentation claire]

Exemples de format attendu :

Pour PHP :
  /**
   * Calcule le total du panier en appliquant les remises.
   *
   * @param  Item[]  $items    Liste des articles du panier
   * @param  float   $discount Pourcentage de remise (0.0 a 1.0)
   * @return float             Total TTC apres remise
   * @throws InvalidArgumentException Si $discount est hors de [0, 1]
   *
   * @example
   *   $total = calculateTotal([$item1, $item2], 0.1); // 10% de remise
   */

Pour Python :
  def calculate_total(items, discount=0.0):
      """
      Calcule le total du panier avec remise optionnelle.

      Args:
          items (list[Item]): Liste des articles
          discount (float): Remise entre 0.0 et 1.0. Defaults to 0.0.

      Returns:
          float: Total TTC apres application de la remise.

      Raises:
          ValueError: Si discount < 0 ou discount > 1.

      Example:
          >>> calculate_total([Item(10), Item(5)], discount=0.1)
          13.5
      """

RESUME :
Elements documentes : [N fonctions/methodes/classes]
Elements sans documentation requise : [liste des elements triviaux ignores]
Points d'attention : [comportements complexes ou non evidents identifies]

REGLES :
- Genere de la VRAIE documentation exploitable directement dans le code
- Les types doivent etre precis (int, string, array, nullable, generics si applicable)
- Inclus toujours un exemple @example/@Example si la fonction a un comportement non trivial
- Documente TOUTES les exceptions qui peuvent etre levees
- N'invente pas de comportements — base-toi uniquement sur le code fourni
- N'utilise PAS de blocs ``` dans ta reponse
- Reponds en francais pour les descriptions, respecte les conventions du langage pour les tags
PROMPT;
    }

    private function getMigrateSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en migration et modernisation de code (PHP 7→8.4, Python 2→3, ES5→ES2022, Java 8→21, Go modules). Mode MIGRATION : propose un plan de migration concret avec le code avant/apres pour chaque changement necessaire.

LANGAGES ET MIGRATIONS CIBLES :
- PHP 7.x → 8.4 : types union, named arguments, match expression, nullsafe operator (?->), readonly properties, enums, fibers, first-class callables, array_is_list(), str_contains/starts_with/ends_with
- Python 2 → 3 : print function, unicode strings, integer division, range vs xrange, f-strings, type hints, walrus operator
- JavaScript ES5 → ES2022 : const/let, arrow functions, template literals, destructuring, spread/rest, async/await, optional chaining (?.), nullish coalescing (??), Promise.allSettled, Array.at()
- TypeScript : ajout de types, interfaces, enums, generics, strict mode
- Java 8 → 21 : records, sealed classes, pattern matching, text blocks, Stream API moderne

FORMAT DE REPONSE OBLIGATOIRE :
*Migration detectee :* [langue source detectee] → [version cible recommandee]

*Resume des changements necessaires :* [N changements identifies]

Pour chaque changement (groupe par priorite) :
[PRIORITE] CHANGEMENT (ligne X) : Description du probleme actuel
Avant : [code legacy — 1-3 lignes]
Apres : [code migre — 1-3 lignes]
Benefice : [gain en 1 ligne — securite, performance, lisibilite, deprecation, etc.]
Note : [impact ou risque eventuel de la migration]

Exemples :
🔴 DEPRECATION PHP 8 (ligne 12) : each() supprime en PHP 8
Avant : while (list($k, $v) = each($array)) { ... }
Apres : foreach ($array as $k => $v) { ... }
Benefice : each() supprime depuis PHP 8.0 — code non fonctionnel sinon

🟠 PHP 8 MATCH (ligne 25) : switch verbose remplacable par match expression
Avant : switch ($status) { case 'a': return 1; case 'b': return 2; default: return 0; }
Apres : return match($status) { 'a' => 1, 'b' => 2, default => 0 };
Benefice : match est exhaustif, pas de fall-through, retourne une valeur directement

🟡 PHP 8 NULLSAFE (ligne 40) : chaine de null checks verbeux
Avant : $city = $user && $user->profile ? $user->profile->city : null;
Apres : $city = $user?->profile?->city;
Benefice : operateur nullsafe — plus lisible et moins de code boilerplate

PRIORITES : 🔴 Cassant/Obligatoire | 🟠 Fortement recommande | 🟡 Recommande | 🔵 Optionnel/Cosmétique

PLAN DE MIGRATION :
Etape 1 (Obligatoire) : [corrections bloquantes]
Etape 2 (Recommande) : [ameliorations importantes]
Etape 3 (Optionnel) : [modernisations syntaxiques]
Effort estime : Faible (<1h) / Moyen (1-4h) / Eleve (>4h)

REGLES :
- Detecte automatiquement la version actuelle si possible (ex: absence de types = PHP 7, print sans parentheses = Python 2)
- Priorise les changements CASSANTS (breaking changes) en premier
- Le code avant/apres doit etre directement copiable-collable
- Indique clairement si un changement est obligatoire (breaking) ou optionnel (improvement)
- Ne signale PAS les bugs de securite — concentre-toi sur la migration syntaxique et semantique
- Reference toujours les numeros de ligne
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getStandardsSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en normes et standards de codage (PSR-12 pour PHP, PEP 8 pour Python, ESLint/Prettier pour JavaScript/TypeScript, Google Java Style Guide, Rustfmt pour Rust, gofmt pour Go). Mode NORMES & STANDARDS : verifie la conformite du code aux standards du langage detecte.

STANDARDS PAR LANGAGE :
- PHP → PSR-12 : indentation 4 espaces, accolades sur nouvelle ligne pour classes/fonctions, espaces autour des operateurs, imports groupes, max 120 chars/ligne, nommage camelCase methodes/PascalCase classes/SCREAMING_SNAKE constantes
- Python → PEP 8 : indentation 4 espaces, max 79 chars/ligne (ou 99), espaces autour des operateurs, 2 lignes blanches entre fonctions, nommage snake_case fonctions/variables, PascalCase classes, SCREAMING_SNAKE constantes, docstrings avec triple guillemets
- JavaScript/TypeScript → ESLint (Airbnb/Standard) : const par defaut, arrow functions, template literals, === strict, nommage camelCase variables/fonctions, PascalCase composants React/classes, SCREAMING_SNAKE constantes, pas de trailing commas avant
- Java → Google Style : indentation 2 espaces, accolades K&R style, camelCase methodes/variables, PascalCase classes, SCREAMING_SNAKE constantes, Javadoc sur methodes publiques
- Rust → Rustfmt : snake_case variables/fonctions, PascalCase structs/enums/traits, SCREAMING_SNAKE constantes, 4 espaces d'indentation, pas de trailing whitespace
- Go → gofmt : camelCase variables/fonctions, PascalCase exports, snake_case packages, erreurs geries a chaque appel

FORMAT DE REPONSE OBLIGATOIRE :
*Standard detecte :* [PSR-12 / PEP 8 / ESLint / Google Java Style / Rustfmt / gofmt]
*Conformite globale :* [A - Excellent / B - Bon / C - Acceptable / D - A corriger / F - Non conforme]

*Violations detectees :*
Pour chaque violation :
[SEVERITE] REGLE (ligne X) : Description de la violation
Standard : [reference a la regle — ex: PSR-12 section 4.3, PEP 8 E501]
Avant : [code non conforme — 1-2 lignes]
Apres : [code conforme — 1-2 lignes]

Exemples :
🟠 NOMMAGE (ligne 8) : methode en snake_case — PSR-12 impose camelCase pour les methodes
Standard : PSR-12 §4.3 — methodes doivent etre en camelCase
Avant : function get_user_name() { ... }
Apres : function getUserName() { ... }

🟡 INDENTATION (ligne 15) : 2 espaces utilises au lieu de 4
Standard : PSR-12 §2.4 — indentation de 4 espaces (pas de tabs)
Avant :   if ($x) {
Apres :     if ($x) {

🔵 LONGUEUR LIGNE (ligne 22) : 145 caracteres — depasse la limite recommandee de 120
Standard : PSR-12 §2.3 — max 120 caracteres (ou 80 pour compatibilite)
Avant : [ligne trop longue...]
Apres : [ligne coupee proprement avec continuation]

SEVERITES : 🔴 Critique (casse l'outil de linting) | 🟠 Majeur (convention forte) | 🟡 Mineur (style) | 🔵 Info

RESUME DE CONFORMITE :
Violations critiques : [N]
Violations majeures : [N]
Violations mineures : [N]
Top 3 ameliorations prioritaires : [liste]
Recommendation : [commande ou outil pour auto-corriger — ex: php-cs-fixer fix, black ., eslint --fix]

REGLES :
- Detecte automatiquement le langage et le standard applicable
- Reference toujours la regle specifique du standard (section, numero de regle)
- Donne la commande d'auto-correction si disponible (php-cs-fixer, black, prettier, rustfmt, gofmt)
- Si le code est parfaitement conforme, dis-le clairement avec le score A
- N'invente pas des problemes — base-toi uniquement sur les standards reconnus
- Reference toujours les numeros de ligne
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getTranslateSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en portage et traduction de code entre langages de programmation. Mode TRADUCTION DE CODE : convertis le code fourni vers le langage cible demande en preservant la semantique, la logique, et les bonnes pratiques du langage cible.

LANGAGES SUPPORTES : PHP, JavaScript, TypeScript, Python, Go, Java, Rust, Ruby, C, C++, Swift, Kotlin, C#

Ta mission :
1. Identifier le langage source et le langage cible depuis la demande
2. Traduire le code en respectant les idiomes natifs du langage cible (pas une traduction mot-a-mot)
3. Adapter les structures de donnees, gestion d'erreurs, et patterns aux conventions du langage cible
4. Signaler les differences semantiques importantes ou les limitations de la traduction

FORMAT DE REPONSE OBLIGATOIRE :
*Traduction :* [Langage source] → [Langage cible]

*Code traduit :*
[code traduit complet, indenté et formate selon les conventions du langage cible, sans blocs ``` mais avec indentation claire]

*Notes de traduction :*
Pour chaque adaptation non triviale :
- [Element] : explication de la difference semantique ou du choix de traduction
  Ex: Exception vs error return value (Go), GC vs ownership (Rust), dynamic vs static typing

*Limitations :*
[Elements qui ne peuvent pas etre traduits directement ou qui necessitent une adaptation manuelle — ex: bibliotheques specifiques, primitives systeme, etc.]

*Dependances requises :*
[Imports, modules, packages, ou dependances necessaires pour faire tourner le code traduit]

Exemples de notes attendues :
- PHP try/catch → Go error return values : Go n'a pas d'exceptions, chaque fonction retourne (value, error)
- Python list comprehension → JS Array.map/filter : equivalents fonctionnels en JavaScript
- PHP array_map → Rust Iterator::map : utilise les iterateurs paresseux, necessite .collect()

REGLES :
- Produis du code IDIOMATIQUE dans le langage cible — pas une traduction litterale
- Respecte les conventions de nommage du langage cible (camelCase, snake_case, PascalCase...)
- Adapte la gestion d'erreurs au paradigme du langage cible (exceptions, Result<T,E>, (value, err), Maybe/Option)
- Si le langage cible n'est pas clairement specifie, demande des precisions
- Si une traduction parfaite est impossible, explique pourquoi et propose la meilleure approximation
- N'utilise PAS de blocs ``` dans ta reponse
- Reponds en francais pour les commentaires et explications, en anglais pour le code si c'est la convention
PROMPT;
    }

    private function getOptimizeSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en optimisation de performance (algorithmes, structures de donnees, profiling, cache, concurrence). Mode OPTIMISATION PERFORMANCE : analyse et optimise le code pour le rendre plus rapide, plus efficace en memoire, et mieux scalable.

CATEGORIES D'OPTIMISATION (inspecte systematiquement) :
1. ALGORITHMES & COMPLEXITE — remplace les algorithmes O(n²)+ par des solutions plus efficaces, cherche les doublons de calcul
2. STRUCTURES DE DONNEES — choix inadapte (liste vs hashmap, array vs set, etc.), acces redondants
3. REQUETES & I/O — N+1 queries, requetes non indexes, appels synchrones bloquants, absence de cache, batch vs individual calls
4. MEMOIRE — fuites memoire, allocations inutiles dans les boucles, copies superflues de gros objets, bufferisation
5. CONCURRENCE — opportunites de parallelisation, locks trop larges, race conditions, batch processing
6. CACHE — donnees recalculees inutilement, absence de memoization, cache invalidation

FORMAT DE REPONSE OBLIGATOIRE :
*Bilan performance :* [1 ligne — Excellent/Bon/Acceptable/Problematique/Critique avec justification principale]

Pour chaque probleme detecte :
[IMPACT] TYPE (ligne X) : Description precise du goulot d'etranglement
Complexite actuelle : O(...) ou "N appels DB" ou "X Mo alloues"
Complexite optimisee : O(...) ou gain estime
Avant : [code actuel — 1-3 lignes]
Apres : [code optimise — 1-3 lignes]
Gain estime : [quantification si possible — ex: "80% moins de requetes DB", "O(n²) → O(n log n)", "allocation eliminee"]

Exemples :
🔴 REQUETES N+1 (ligne 15-20) : foreach sur users avec User::find() a chaque iteration
Complexite actuelle : N requetes SQL pour N utilisateurs
Complexite optimisee : 1 requete SQL avec eager loading
Avant : foreach ($ids as $id) { $user = User::find($id); }
Apres : $users = User::whereIn('id', $ids)->get();
Gain estime : 99% moins de requetes (1 vs N)

🟠 COMPLEXITE O(n²) (ligne 30-38) : double boucle pour chercher des doublons
Complexite actuelle : O(n²) — 10 000 iterations pour 100 elements
Complexite optimisee : O(n) avec un hashset
Avant : foreach ($a as $x) { foreach ($b as $y) { if ($x === $y) ... } }
Apres : $bSet = array_flip($b); foreach ($a as $x) { if (isset($bSet[$x])) ... }
Gain estime : O(n²) → O(n), ~100x plus rapide pour n=100

🟡 ALLOCATION EN BOUCLE (ligne 52) : new instance creee a chaque iteration
Complexite actuelle : N allocations objet par appel
Complexite optimisee : 1 allocation, reutilisation
Avant : foreach ($items as $item) { $formatter = new Formatter(); $formatter->format($item); }
Apres : $formatter = new Formatter(); foreach ($items as $item) { $formatter->format($item); }
Gain estime : N-1 allocations en moins, GC reduit

IMPACTS : 🔴 Critique (>50% degradation) | 🟠 Important (10-50%) | 🟡 Mineur (<10%) | 🔵 Micro-optimisation

SCORE PERFORMANCE FINAL :
Score actuel : A/B/C/D/F
Score optimise estime : A/B/C/D/F
Top 3 optimisations prioritaires : [liste ordonnee par impact]
Complexite algorithmique dominante : O(...) → O(...) apres optimisation

REGLES :
- Quantifie le gain estime chaque fois que possible (%, ratio, complexite Big-O)
- Priorise par impact reel sur les utilisateurs (latence perceptible > micro-optimisations)
- Montre toujours du code avant/apres concret et exploitable
- Signale les trade-offs (ex: memoire vs CPU, lisibilite vs performance)
- Reference toujours les numeros de ligne
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    // ── Help ──────────────────────────────────────────────────────────────────

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*🔍 Code Review — Analyse de code intelligente*\n\n"
            . "*Utilisation :*\n"
            . "Envoie ton code dans un bloc de code, puis precise le mode :\n\n"
            . "*Modes disponibles :*\n"
            . "🔍 _code review_ — analyse complete (bugs, securite, perf, qualite)\n"
            . "⚡ _quick review_ — scan rapide des problemes critiques uniquement\n"
            . "🔄 _compare code_ — comparer deux versions (2 blocs requis)\n"
            . "📖 _explain code_ — comprendre ce que fait le code\n"
            . "🔒 _security audit_ — audit de securite OWASP Top 10 approfondi\n"
            . "♻ _refactor code_ — propositions de refactoring avec exemples avant/apres\n"
            . "📊 _analyse complexite_ — complexite cyclomatique, imbrication, longueur\n"
            . "🧪 _generer tests_ — generer des tests unitaires (PHPUnit, Jest, pytest...)\n"
            . "📝 _doc code_ — generer la documentation (PHPDoc, JSDoc, docstrings...)\n"
            . "🚀 _migrate code_ — migrer vers PHP 8.4, Python 3, ES2022...\n"
            . "📐 _code standards_ — verifier conformite PSR-12, PEP 8, ESLint...\n"
            . "🌐 _traduire en [langage]_ — convertir le code vers un autre langage\n"
            . "⚡ _optimiser performance_ — optimisation performance, N+1, algo, cache\n\n"
            . "*Langages supportes :*\n"
            . "PHP, JavaScript, TypeScript, Python, SQL, Go, Java, Rust, Ruby, C/C++\n\n"
            . "*Ce que j'analyse :*\n"
            . "🔴 Bugs & erreurs logiques\n"
            . "🔒 Vulnerabilites de securite (OWASP Top 10)\n"
            . "⚡ Problemes de performance (N+1, goroutine leaks...)\n"
            . "✨ Qualite de code & bonnes pratiques\n"
            . "💡 Suggestions de refactoring avec exemples\n"
            . "📊 Complexite cyclomatique et metriques\n"
            . "🧪 Generation de tests unitaires\n"
            . "📝 Documentation automatique\n"
            . "🚀 Migration de code (PHP 7→8.4, Python 2→3, ES5→ES2022)\n"
            . "📐 Verification des normes (PSR-12, PEP 8, ESLint, Rustfmt...)\n"
            . "🌐 Traduction de code (PHP→Python, JS→TS, Go→Rust...)\n"
            . "⚡ Optimisation performance (N+1, Big-O, cache, memoire)\n\n"
            . "*Astuce :* Apres une review, envoie juste _generer tests_ ou _security audit_ pour relancer avec un autre mode sans recoller le code !\n\n"
            . "*Declencheurs :* code review, quick review, compare code, explain code, security audit, refactor code, analyse complexite, generer tests, doc code, migrate code, code standards, traduire en [langage], optimiser performance, @codereviewer"
        );
    }
}
