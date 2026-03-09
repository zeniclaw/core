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
        return 'Agent de revue de code automatique. Analyse du code source (PHP, JS, Python, SQL, TypeScript, Go, Java, Rust) pour detecter bugs, failles de securite, problemes de performance, violations des bonnes pratiques, et proposer des refactorings. Modes: quick (scan rapide), diff (comparaison), explain (explication), security (audit securite), full (analyse complete).';
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
            // Nouveaux mots-clés v1.2.0
            'expliquer code', 'explain code', 'que fait ce code', 'what does this code do',
            'expliquer ce code', 'explain this code', 'describe this code', 'decrire ce code',
            'audit securite', 'security audit', 'audit de securite', 'scan securite',
            'audit code', 'code audit', 'vulnerabilites code', 'code vulnerabilities',
            'owasp', 'pentest code', 'security scan', 'scan de securite',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        // Explicit code review / audit / explain request
        if (preg_match('/(?:\b|@)(code\s*review|review\s*(my|this|the)?\s*code|verifi(er|e)\s*(ce|mon|le)\s*code|check\s*(this|my)?\s*code|codereviewer|quick\s*review|revue\s*rapide|scan\s*rapide|compare\s*code|comparer\s*code|diff\s*code|explain\s*(this\s*|the\s*|my\s*|ce\s*|mon\s*|le\s*)?code|expliquer\s*(ce|le|mon)\s*code|que\s*fait\s*ce\s*code|security\s*audit|audit\s*(de\s*)?s[eé]curit[eé]|audit\s*code|code\s*audit|scan\s*(de\s*)?s[eé]curit[eé])\b/iu', $context->body)) {
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
                . "• _explain code_ — comprendre ce que fait le code\n"
                . "• _security audit_ — audit de securite OWASP\n"
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

        // Run static pattern analysis (skip for explain mode — not relevant)
        $staticIssues = [];
        if ($mode !== 'explain') {
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

        // Build the prompt for Claude deep analysis
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
     * Detect review mode: 'quick', 'diff', 'explain', 'security', or 'full'.
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
            'quick'    => $this->getQuickSystemPrompt(),
            'diff'     => $this->getDiffSystemPrompt(),
            'explain'  => $this->getExplainSystemPrompt(),
            'security' => $this->getSecuritySystemPrompt(),
            default    => $this->getFullSystemPrompt(),
        };

        if ($memoryPrompt) {
            $base .= "\n\n" . $memoryPrompt;
        }

        return $base;
    }

    private function getFullSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert senior en revue de code (10+ ans d'experience, specialiste PHP/JS/Python/Go/Java). Analyse le code fourni et produis un rapport structure, precis et actionnable.

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

Exemples de format :
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

    // ── Report Formatting ──────────────────────────────────────────────────────

    private function formatFinalReport(
        array $codeBlocks,
        array $staticIssues,
        string $claudeReview,
        string $mode,
        string $sizeWarning
    ): string {
        $modeLabel = match ($mode) {
            'quick'    => '⚡ *CODE REVIEW RAPIDE*',
            'diff'     => '🔄 *COMPARAISON DE CODE*',
            'explain'  => '📖 *EXPLICATION DE CODE*',
            'security' => '🔒 *AUDIT DE SECURITE*',
            default    => '🔍 *CODE REVIEW*',
        };

        $langs      = array_map(fn ($b) => strtoupper($b['language'] ?: '?'), $codeBlocks);
        $totalLines = array_sum(array_column($codeBlocks, 'line_count'));
        $header     = "{$modeLabel}\n"
            . count($codeBlocks) . " bloc(s) | "
            . implode(', ', array_unique($langs)) . " | "
            . "{$totalLines} lignes\n\n";

        // Static analysis critical warnings (not shown in explain mode)
        $staticSection = '';
        if (!empty($staticIssues) && $mode !== 'explain') {
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
            . "🔄 _compare code_ — comparer deux versions (2 blocs requis)\n"
            . "📖 _explain code_ — comprendre ce que fait le code\n"
            . "🔒 _security audit_ — audit de securite OWASP Top 10 approfondi\n\n"
            . "*Langages supportes :*\n"
            . "PHP, JavaScript, TypeScript, Python, SQL, Go, Java, Rust\n\n"
            . "*Ce que j'analyse :*\n"
            . "🔴 Bugs & erreurs logiques\n"
            . "🔒 Vulnerabilites de securite (OWASP Top 10)\n"
            . "⚡ Problemes de performance (N+1, etc.)\n"
            . "✨ Qualite de code & bonnes pratiques\n"
            . "💡 Suggestions de refactoring\n"
            . "📊 Score de complexite algorithmique\n\n"
            . "*Declencheurs :* code review, quick review, compare code, explain code, security audit, @codereviewer"
        );
    }
}
