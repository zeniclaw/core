<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\CodeAnalyzer;

class CodeReviewAgent extends BaseAgent
{
    private CodeAnalyzer $analyzer;

    public function __construct()
    {
        parent::__construct();
        $this->analyzer = new CodeAnalyzer();
    }

    public function name(): string
    {
        return 'code_review';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        // Explicit code review request
        if (preg_match('/\b(code\s*review|review\s*(my|this|the)?\s*code|verifi(er|e)\s*(ce|mon|le)\s*code|check\s*(this|my)?\s*code|@codereviewer)\b/iu', $context->body)) {
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

        // Extract code blocks from the message
        $codeBlocks = $this->analyzer->extractCodeBlocks($body);

        if (empty($codeBlocks)) {
            return AgentResult::reply(
                "Je n'ai pas trouve de code a analyser dans ton message.\n\n"
                . "Envoie ton code dans un bloc :\n"
                . "```php\n// ton code ici\n```\n\n"
                . "Langages supportes : PHP, JavaScript, Python, SQL, TypeScript"
            );
        }

        // Run static pattern analysis on each block
        $staticIssues = [];
        foreach ($codeBlocks as $block) {
            $issues = $this->analyzer->analyzePatterns($block['code'], $block['language']);
            if (!empty($issues)) {
                $staticIssues[] = [
                    'language' => $block['language'],
                    'issues' => $issues,
                ];
            }
        }

        // Build the prompt for Claude deep analysis
        $codeContext = $this->buildCodeContext($codeBlocks, $staticIssues);
        $model = $this->resolveModel($context);

        // Enrich with user context memory
        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

        $systemPrompt = $this->buildSystemPrompt($memoryPrompt);

        $response = $this->claude->chat($codeContext, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply("Desole, je n'ai pas pu analyser le code. Reessaie.");
        }

        // Format final report
        $report = $this->formatFinalReport($codeBlocks, $staticIssues, $response);

        $this->log($context, 'Code review completed', [
            'blocks' => count($codeBlocks),
            'static_issues' => count($staticIssues),
        ]);

        return AgentResult::reply($report);
    }

    private function buildCodeContext(array $codeBlocks, array $staticIssues): string
    {
        $parts = [];

        foreach ($codeBlocks as $i => $block) {
            $label = strtoupper($block['language'] ?: 'CODE');
            $numberedCode = $this->addLineNumbers($block['code']);
            $parts[] = "=== BLOC " . ($i + 1) . " ({$label}, {$block['line_count']} lignes) ===\n{$numberedCode}";
        }

        if (!empty($staticIssues)) {
            $parts[] = "=== ANALYSE STATIQUE (pre-detection) ===";
            foreach ($staticIssues as $group) {
                foreach ($group['issues'] as $issue) {
                    $parts[] = "[{$issue['severity']}] [{$issue['type']}] {$issue['message']}";
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function buildSystemPrompt(string $memoryPrompt): string
    {
        $base = <<<'PROMPT'
Tu es un expert en revue de code. Analyse le code fourni et produis un rapport structure.

CATEGORIES D'ANALYSE:
1. BUGS & ERREURS — erreurs logiques, bugs potentiels, edge cases non geres
2. SECURITE — injections SQL/XSS, credentials hardcodes, failles OWASP Top 10
3. PERFORMANCE — requetes N+1, boucles inefficaces, memoire, complexite algorithmique
4. QUALITE & BONNES PRATIQUES — nommage, DRY, SOLID, lisibilite, anti-patterns
5. SUGGESTIONS — refactoring, patterns recommandes, ameliorations

FORMAT DE REPONSE:
Utilise ce format exact pour chaque point:

[SEVERITE] CATEGORIE (ligne X) : Description du probleme
→ Suggestion de correction

SEVERITES: 🔴 Critique | 🟠 Important | 🟡 Mineur | 🔵 Info

REGLES:
- Numerote les lignes dans tes references (ligne X ou lignes X-Y)
- Sois precis et actionnable (donne des exemples de code corrige quand pertinent)
- Commence par un resume en 1-2 lignes
- Termine par une NOTE GLOBALE (A/B/C/D/F) avec justification courte
- Si le code est bon, dis-le ! Ne cherche pas des problemes la ou il n'y en a pas
- Reponds en francais
- N'utilise PAS de blocs ``` dans ta reponse (WhatsApp ne les affiche pas bien en imbrique)
- Pour le code inline, utilise *monospace* ou _italique_
PROMPT;

        if ($memoryPrompt) {
            $base .= "\n\n" . $memoryPrompt;
        }

        return $base;
    }

    private function formatFinalReport(array $codeBlocks, array $staticIssues, string $claudeReview): string
    {
        $header = "🔍 *CODE REVIEW*\n";

        // Summary of blocks analyzed
        $langs = array_map(fn ($b) => strtoupper($b['language'] ?: '?'), $codeBlocks);
        $totalLines = array_sum(array_column($codeBlocks, 'line_count'));
        $header .= count($codeBlocks) . " bloc(s) analyse(s) | "
            . implode(', ', array_unique($langs)) . " | "
            . "{$totalLines} lignes\n\n";

        // Static analysis warnings (if any that Claude might have missed)
        $staticSection = '';
        if (!empty($staticIssues)) {
            $criticalCount = 0;
            foreach ($staticIssues as $group) {
                foreach ($group['issues'] as $issue) {
                    if ($issue['severity'] === 'critical') $criticalCount++;
                }
            }
            if ($criticalCount > 0) {
                $staticSection .= "⚠ *{$criticalCount} alerte(s) critique(s) detectee(s) par analyse statique*\n\n";
            }
        }

        return $header . $staticSection . trim($claudeReview);
    }

    private function addLineNumbers(string $code): string
    {
        $lines = explode("\n", $code);
        $numbered = [];
        foreach ($lines as $i => $line) {
            $num = str_pad($i + 1, 3, ' ', STR_PAD_LEFT);
            $numbered[] = "{$num} | {$line}";
        }
        return implode("\n", $numbered);
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*🔍 Code Review — Analyse de code intelligente*\n\n"
            . "*Comment utiliser :*\n"
            . "Envoie ton code dans un bloc suivi de 'code review' :\n\n"
            . "```php\n"
            . "// ton code PHP ici\n"
            . "```\n"
            . "code review\n\n"
            . "*Langages supportes :*\n"
            . "PHP, JavaScript, Python, SQL, TypeScript\n\n"
            . "*Ce que j'analyse :*\n"
            . "🔴 Bugs & erreurs logiques\n"
            . "🔒 Vulnerabilites de securite (OWASP Top 10)\n"
            . "⚡ Problemes de performance\n"
            . "✨ Qualite de code & bonnes pratiques\n"
            . "💡 Suggestions de refactoring\n\n"
            . "*Declencheurs :* code review, review my code, verifier ce code, @codereviewer"
        );
    }
}
