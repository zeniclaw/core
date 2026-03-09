<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\CodeAnalyzer;

class CodeReviewAgent extends BaseAgent
{
    private CodeAnalyzer $analyzer;

    /** Maximum total lines across all blocks before size warning */
    private const MAX_TOTAL_LINES = 400;

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
        return 'Agent de revue de code automatique. Analyse du code source (PHP, JS, Python, SQL, TypeScript, Go, Java, Rust) pour detecter bugs, failles de securite, problemes de performance, violations des bonnes pratiques, et proposer des refactorings. Supporte le mode rapide (quick), la comparaison de diff, et le scoring de complexite.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        // Explicit code review request
        if (preg_match('/(?:\b|@)(code\s*review|review\s*(my|this|the)?\s*code|verifi(er|e)\s*(ce|mon|le)\s*code|check\s*(this|my)?\s*code|codereviewer|quick\s*review|revue\s*rapide|scan\s*rapide|compare\s*code|comparer\s*code|diff\s*code)\b/iu', $context->body)) {
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

        $this->log($context, 'Code review requested', ['body_length' => mb_strlen($body)]);

        // Detect review mode
        $mode = $this->detectMode($body);

        // Extract code blocks from the message
        $codeBlocks = $this->analyzer->extractCodeBlocks($body);

        if (empty($codeBlocks)) {
            return AgentResult::reply(
                "Je n'ai pas trouve de code a analyser dans ton message.\n\n"
                . "Envoie ton code dans un bloc :\n"
                . "‎```php\n// ton code ici\n‎```\n\n"
                . "Langages supportes : PHP, JavaScript, TypeScript, Python, SQL, Go, Java, Rust\n\n"
                . "Modes disponibles :\n"
                . "• _quick review_ — scan rapide (bugs critiques uniquement)\n"
                . "• _compare code_ — comparer deux versions (2 blocs)\n"
                . "• _code review_ — analyse complete (defaut)"
            );
        }

        // Size guard
        $totalLines = array_sum(array_column($codeBlocks, 'line_count'));
        $sizeWarning = '';
        if ($totalLines > self::MAX_TOTAL_LINES) {
            $sizeWarning = "⚠ _Code volumineux ({$totalLines} lignes) — analyse limitee aux premieres sections_\n\n";
        }

        // Check for diff/compare mode: needs exactly 2 blocks
        if ($mode === 'diff' && count($codeBlocks) < 2) {
            return AgentResult::reply(
                "Pour le mode comparaison, envoie *deux blocs de code* :\n\n"
                . "- Premier bloc : version *avant*\n"
                . "- Deuxieme bloc : version *apres*\n\n"
                . "Puis ajoute : _compare code_ ou _diff code_"
            );
        }

        // Run static pattern analysis on each block
        $staticIssues = [];
        foreach ($codeBlocks as $block) {
            $issues = $this->analyzer->analyzePatterns($block['code'], $block['language']);
            if (!empty($issues)) {
                $staticIssues[] = [
                    'language' => $block['language'],
                    'issues'   => $issues,
                ];
            }
        }

        // Build the prompt for Claude deep analysis
        $codeContext = $this->buildCodeContext($codeBlocks, $staticIssues, $mode);
        $model = $this->resolveModel($context);

        // Enrich with user context memory
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $systemPrompt = $this->buildSystemPrompt($mode, $memoryPrompt);

        $response = $this->claude->chat($codeContext, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply(
                "Desole, je n'ai pas pu analyser le code. Possible causes :\n"
                . "• Code trop volumineux\n"
                . "• Service temporairement indisponible\n"
                . "Reessaie avec un extrait plus court ou utilise _quick review_."
            );
        }

        // Format final report
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
     * Detect review mode: 'quick', 'diff', or 'full'.
     */
    private function detectMode(string $body): string
    {
        if (preg_match('/\b(quick\s*review|revue\s*rapide|scan\s*rapide|quick\s*scan)\b/iu', $body)) {
            return 'quick';
        }
        if (preg_match('/\b(compare\s*code|comparer\s*code|diff\s*code|avant\s*apres|before\s*after)\b/iu', $body)) {
            return 'diff';
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
                $label = strtoupper($block['language'] ?: 'CODE');
                $truncNote = $block['truncated'] ? ' [TRONQUE]' : '';
                $numberedCode = $this->addLineNumbers($block['code']);
                $parts[] = "=== BLOC " . ($i + 1) . " ({$label}, {$block['line_count']} lignes{$truncNote}) ===\n{$numberedCode}";
            }
        }

        if (!empty($staticIssues)) {
            $parts[] = "=== ANALYSE STATIQUE PRE-DETECTION ===";
            foreach ($staticIssues as $group) {
                foreach ($group['issues'] as $issue) {
                    $parts[] = "[{$issue['severity']}] [{$issue['type']}] {$issue['message']}";
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function buildSystemPrompt(string $mode, string $memoryPrompt): string
    {
        $base = match ($mode) {
            'quick' => $this->getQuickSystemPrompt(),
            'diff'  => $this->getDiffSystemPrompt(),
            default => $this->getFullSystemPrompt(),
        };

        if ($memoryPrompt) {
            $base .= "\n\n" . $memoryPrompt;
        }

        return $base;
    }

    private function getFullSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert senior en revue de code (10+ ans d'experience). Analyse le code fourni et produis un rapport structure, precis et actionnable.

CATEGORIES D'ANALYSE (inspecte chaque categorie systematiquement) :
1. BUGS & ERREURS — erreurs logiques, bugs potentiels, edge cases non geres, conditions aux limites
2. SECURITE — injections SQL/XSS/CSRF, credentials hardcodes, failles OWASP Top 10, exposition de donnees sensibles
3. PERFORMANCE — requetes N+1, boucles inefficaces, fuites memoire, complexite algorithmique O(n²)+, appels synchrones bloquants
4. QUALITE & BONNES PRATIQUES — nommage, DRY, SOLID, lisibilite, couplage fort, anti-patterns
5. SUGGESTIONS — refactoring propose, patterns recommandes, modernisation (ex: PHP 8.x, ES2022+)

FORMAT DE REPONSE OBLIGATOIRE :
Resume en 1-2 phrases (qualite generale + point cle)

Puis pour chaque probleme trouve :
[SEVERITE] CATEGORIE (ligne X) : Description precise du probleme
→ Correction : code corrige ou explication concrete

SEVERITES : 🔴 Critique | 🟠 Important | 🟡 Mineur | 🔵 Info/Suggestion

SCORE FINAL :
NOTE : A/B/C/D/F — justification en 1 ligne
COMPLEXITE : Faible/Moyenne/Elevee/Tres elevee

REGLES :
- Reference toujours les numeros de ligne
- Sois precis et actionnable (montre du vrai code corrige quand pertinent)
- Si le code est bon, dis-le clairement — ne cherche pas de problemes artificiels
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse (WhatsApp les affiche mal)
- Pour les exemples de code inline, utilise _italique_ ou *gras*
PROMPT;
    }

    private function getQuickSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en securite et qualite de code. Mode SCAN RAPIDE : identifie UNIQUEMENT les problemes CRITIQUES et IMPORTANTS.

FORMAT CONCIS :
Resume en 1 phrase

Pour chaque probleme critique/important seulement :
[🔴/🟠] TYPE (ligne X) : Probleme
→ Correction rapide

NOTE FINALE : A/B/C/D/F (1 ligne)

REGLES :
- Ignore les problemes mineurs et suggestions
- Maximum 8-10 points au total
- Sois ultra-concis et direct
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse
PROMPT;
    }

    private function getDiffSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en revue de code. Mode COMPARAISON DIFF : tu recois deux versions d'un code (avant/apres).

Ta mission :
1. Identifier ce qui a ete modifie (ajouts, suppressions, modifications)
2. Evaluer si les changements ameliorent ou degradent la qualite/securite/performance
3. Signaler les nouveaux bugs ou regressions introduits
4. Valider les ameliorations apportees

FORMAT DE REPONSE :
Resume des changements (1-2 lignes)

CHANGEMENTS DETECTES :
+ Ajout ligne X : description
- Suppression ligne X : description
~ Modification ligne X : description

EVALUATION DES CHANGEMENTS :
✅ Ameliorations : liste
⚠ Regressions/Nouveaux problemes : liste avec explication

VERDICT : Meilleure/Equivalente/Degradee — justification

REGLES :
- Compare les deux blocs cote a cote
- Sois objectif — reconnais les bonnes modifications
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
            'quick' => '⚡ *CODE REVIEW RAPIDE*',
            'diff'  => '🔄 *COMPARAISON DE CODE*',
            default => '🔍 *CODE REVIEW*',
        };

        $header = "{$modeLabel}\n";

        // Summary of blocks analyzed
        $langs      = array_map(fn ($b) => strtoupper($b['language'] ?: '?'), $codeBlocks);
        $totalLines = array_sum(array_column($codeBlocks, 'line_count'));
        $header .= count($codeBlocks) . " bloc(s) | "
            . implode(', ', array_unique($langs)) . " | "
            . "{$totalLines} lignes\n\n";

        // Static analysis critical warnings
        $staticSection = '';
        if (!empty($staticIssues)) {
            $criticalCount = 0;
            foreach ($staticIssues as $group) {
                foreach ($group['issues'] as $issue) {
                    if (in_array($issue['severity'], ['critical', 'high'])) $criticalCount++;
                }
            }
            if ($criticalCount > 0) {
                $staticSection .= "⚠ *{$criticalCount} alerte(s) critique/haute detectee(s) par analyse statique*\n\n";
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

    // ── Help ──────────────────────────────────────────────────────────────────

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*🔍 Code Review — Analyse de code intelligente*\n\n"
            . "*Utilisation :*\n"
            . "Envoie ton code dans un bloc, puis precise le type de review :\n\n"
            . "*Modes disponibles :*\n"
            . "🔍 _code review_ — analyse complete (bugs, securite, perf, qualite)\n"
            . "⚡ _quick review_ — scan rapide des problemes critiques uniquement\n"
            . "🔄 _compare code_ — comparer deux versions (2 blocs requis)\n\n"
            . "*Langages supportes :*\n"
            . "PHP, JavaScript, TypeScript, Python, SQL, Go, Java, Rust\n\n"
            . "*Ce que j'analyse :*\n"
            . "🔴 Bugs & erreurs logiques\n"
            . "🔒 Vulnerabilites de securite (OWASP Top 10)\n"
            . "⚡ Problemes de performance (N+1, etc.)\n"
            . "✨ Qualite de code & bonnes pratiques\n"
            . "💡 Suggestions de refactoring\n"
            . "📊 Score de complexite algorithmique\n\n"
            . "*Declencheurs :* code review, review my code, verifier ce code, @codereviewer, quick review, compare code"
        );
    }
}
