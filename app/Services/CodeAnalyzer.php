<?php

namespace App\Services;

class CodeAnalyzer
{
    private const SUPPORTED_LANGUAGES = [
        'php', 'javascript', 'js', 'python', 'py', 'sql', 'typescript', 'ts',
        'go', 'rust', 'java', 'c', 'cpp', 'c++', 'ruby', 'rb',
    ];

    /** Maximum number of lines before truncation warning */
    private const MAX_LINES_BEFORE_WARN = 200;

    /**
     * Extract code blocks from a message (```lang ... ``` format).
     */
    public function extractCodeBlocks(string $message): array
    {
        $blocks = [];

        if (preg_match_all('/```(\w*)\s*\n?(.*?)```/s', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $lang = strtolower(trim($match[1]));
                $code = trim($match[2]);

                if (empty($code)) continue;

                $lang = $this->normalizeLanguage($lang ?: $this->detectLanguage($code));

                $lineCount = substr_count($code, "\n") + 1;

                // Truncate very large blocks but keep line count accurate
                $truncated = false;
                if ($lineCount > self::MAX_LINES_BEFORE_WARN) {
                    $lines = explode("\n", $code);
                    $code = implode("\n", array_slice($lines, 0, self::MAX_LINES_BEFORE_WARN))
                        . "\n... [tronque: {$lineCount} lignes au total, affichage des " . self::MAX_LINES_BEFORE_WARN . " premieres]";
                    $truncated = true;
                }

                $blocks[] = [
                    'language'   => $lang,
                    'code'       => $code,
                    'line_count' => $lineCount,
                    'truncated'  => $truncated,
                ];
            }
        }

        // If no code blocks found, try to detect inline code
        if (empty($blocks) && $this->looksLikeCode($message)) {
            $lang = $this->detectLanguage($message);
            $lineCount = substr_count($message, "\n") + 1;
            $blocks[] = [
                'language'   => $lang,
                'code'       => $message,
                'line_count' => $lineCount,
                'truncated'  => false,
            ];
        }

        return $blocks;
    }

    /**
     * Run static pattern analysis on code to detect common issues.
     */
    public function analyzePatterns(string $code, string $language): array
    {
        $checks = match ($language) {
            'php'                  => $this->checkPhpPatterns($code),
            'javascript',
            'typescript'           => $this->checkJsPatterns($code),
            'python'               => $this->checkPythonPatterns($code),
            'sql'                  => $this->checkSqlPatterns($code),
            'go'                   => $this->checkGoPatterns($code),
            'java'                 => $this->checkJavaPatterns($code),
            'rust'                 => $this->checkRustPatterns($code),
            default                => [],
        };

        return $checks;
    }

    /**
     * Format a review report with line numbers and context.
     */
    public function formatReport(array $codeBlocks, array $claudeAnalysis): string
    {
        $report = "";

        foreach ($codeBlocks as $i => $block) {
            if (count($codeBlocks) > 1) {
                $label = strtoupper($block['language'] ?: 'CODE');
                $report .= "*--- Bloc " . ($i + 1) . " ({$label}, {$block['line_count']} lignes) ---*\n\n";
            }
        }

        $report .= $claudeAnalysis['review'] ?? '';

        return $report;
    }

    public function isSupported(string $language): bool
    {
        return in_array(strtolower($language), self::SUPPORTED_LANGUAGES);
    }

    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    private function normalizeLanguage(string $lang): string
    {
        return match (strtolower($lang)) {
            'js'  => 'javascript',
            'ts'  => 'typescript',
            'py'  => 'python',
            'rb'  => 'ruby',
            'c++' => 'cpp',
            default => strtolower($lang),
        };
    }

    private function detectLanguage(string $code): string
    {
        if (preg_match('/(<\?php|\buse\s+\w+\\\\|->|::|function\s+\w+\s*\(.*\)\s*:\s*\w+)/i', $code)) {
            return 'php';
        }
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $code)) {
            return 'sql';
        }
        if (preg_match('/\b(def\s+\w+|import\s+\w+|from\s+\w+\s+import|class\s+\w+.*:)\b/', $code)) {
            return 'python';
        }
        if (preg_match('/\bpackage\s+main\b|\bfunc\s+\w+\s*\(/', $code)) {
            return 'go';
        }
        if (preg_match('/\bpublic\s+(static\s+)?(class|void|int|String)\b/', $code)) {
            return 'java';
        }
        if (preg_match('/\b(const|let|var|function|=>|require\(|import\s+.*\s+from)\b/', $code)) {
            return 'javascript';
        }

        return 'unknown';
    }

    private function looksLikeCode(string $text): bool
    {
        $codeIndicators = [
            '/function\s+\w+\s*\(/',
            '/\bclass\s+\w+/',
            '/\bif\s*\(/',
            '/\bfor\s*\(/',
            '/\bwhile\s*\(/',
            '/\breturn\s+/',
            '/\$\w+\s*=/',
            '/\bSELECT\b.*\bFROM\b/i',
            '/\bdef\s+\w+\s*\(/',
            '/\bimport\s+/',
        ];

        $matches = 0;
        foreach ($codeIndicators as $pattern) {
            if (preg_match($pattern, $text)) $matches++;
        }

        return $matches >= 2;
    }

    // ── PHP Patterns ─────────────────────────────────────────────────────────

    private function checkPhpPatterns(string $code): array
    {
        $issues = [];

        // SQL injection via $_GET/$_POST
        if (preg_match('/\$_(GET|POST|REQUEST|COOKIE)\s*\[/', $code) &&
            preg_match('/(mysql_query|mysqli_query|->query\(|->exec\()/', $code) &&
            !preg_match('/\bprepare\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'SQL Injection potentielle : donnees utilisateur dans une requete SQL sans prepare()',
            ];
        }

        // Direct variable interpolation in SQL
        if (preg_match('/(?:query|exec)\s*\(\s*"[^"]*\$\w+/', $code) ||
            preg_match('/(?:query|exec)\s*\(\s*\'[^\']*\'\s*\.\s*\$/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'Concatenation de variables dans une requete SQL — utiliser des requetes preparees',
            ];
        }

        // XSS
        if (preg_match('/echo\s+\$_(GET|POST|REQUEST)/', $code) &&
            !preg_match('/htmlspecialchars|htmlentities|e\(/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'XSS potentiel : echo de donnees utilisateur sans echappement (htmlspecialchars)',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*=\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials potentiellement hardcodes — utiliser des variables d\'environnement',
            ];
        }

        // eval usage
        if (preg_match('/\beval\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Utilisation de eval() — risque d\'injection de code',
            ];
        }

        // md5/sha1 for passwords
        if (preg_match('/\b(md5|sha1)\s*\(\s*\$/', $code) && preg_match('/password|passwd|pwd/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Hachage faible (md5/sha1) pour mot de passe — utiliser password_hash()',
            ];
        }

        // N+1 query pattern (loop with DB call)
        if (preg_match('/foreach\s*\(.*\)\s*\{[^}]*(?:->find|->where|->get|DB::)/s', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'performance',
                'message'  => 'Probleme N+1 potentiel : requete DB dans une boucle — utiliser eager loading (with())',
            ];
        }

        // Missing CSRF in raw form handling
        if (preg_match('/\$_POST\[/', $code) && !preg_match('/csrf|_token|X-CSRF/i', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'security',
                'message'  => 'Traitement de formulaire POST sans protection CSRF apparente',
            ];
        }

        return $issues;
    }

    // ── JavaScript Patterns ──────────────────────────────────────────────────

    private function checkJsPatterns(string $code): array
    {
        $issues = [];

        // innerHTML XSS
        if (preg_match('/\.innerHTML\s*=/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'innerHTML peut causer des XSS — utiliser textContent ou une sanitization library',
            ];
        }

        // eval usage
        if (preg_match('/\beval\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Utilisation de eval() — risque d\'injection de code',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(api_key|apiKey|secret|password|token)\s*[:=]\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials hardcodes — utiliser des variables d\'environnement',
            ];
        }

        // var usage (should use let/const)
        if (preg_match('/\bvar\s+\w+/', $code)) {
            $issues[] = [
                'severity' => 'low',
                'type'     => 'quality',
                'message'  => 'Utilisation de var — preferer const/let pour un scope correct',
            ];
        }

        // == instead of ===
        if (preg_match('/[^=!<>]==[^=]/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'Comparaison laxiste (==) — preferer === pour eviter les coercitions de type',
            ];
        }

        // Promise without .catch()
        if (preg_match('/\.then\s*\(/', $code) && !preg_match('/\.catch\s*\(|try\s*\{/s', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'Promise sans .catch() — les rejections non gerees peuvent crasher l\'application',
            ];
        }

        // document.write (legacy, XSS risk)
        if (preg_match('/document\.write\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'security',
                'message'  => 'document.write() est obsolete et risque XSS — utiliser DOM manipulation moderne',
            ];
        }

        return $issues;
    }

    // ── Python Patterns ──────────────────────────────────────────────────────

    private function checkPythonPatterns(string $code): array
    {
        $issues = [];

        // SQL injection
        if (preg_match('/\.execute\s*\(\s*f?["\'].*\{/', $code) ||
            (preg_match('/\.execute\s*\(\s*["\'].*%s/', $code) && preg_match('/\%\s*\(/', $code))) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'SQL Injection — utiliser des requetes parametrees (placeholders ?)',
            ];
        }

        // eval/exec usage
        if (preg_match('/\b(eval|exec)\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Utilisation de eval()/exec() — risque d\'injection de code',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*=\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials hardcodes — utiliser des variables d\'environnement (os.environ)',
            ];
        }

        // bare except
        if (preg_match('/\bexcept\s*:/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'Bare except — toujours specifier le type d\'exception',
            ];
        }

        // Mutable default argument
        if (preg_match('/def\s+\w+\s*\([^)]*=\s*(\[\]|\{\}|\(\))/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'Argument mutable par defaut ([], {}) — bug classique Python, utiliser None + initialisation dans le corps',
            ];
        }

        return $issues;
    }

    // ── SQL Patterns ─────────────────────────────────────────────────────────

    private function checkSqlPatterns(string $code): array
    {
        $issues = [];

        // SELECT *
        if (preg_match('/SELECT\s+\*/i', $code)) {
            $issues[] = [
                'severity' => 'low',
                'type'     => 'performance',
                'message'  => 'SELECT * — specifier les colonnes necessaires pour de meilleures performances',
            ];
        }

        // Missing WHERE on UPDATE/DELETE
        if (preg_match('/\b(UPDATE|DELETE\s+FROM)\b/i', $code) && !preg_match('/\bWHERE\b/i', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'UPDATE/DELETE sans WHERE — risque de modification/suppression de toutes les lignes',
            ];
        }

        // LIKE with leading wildcard
        if (preg_match('/LIKE\s+["\']%/i', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'performance',
                'message'  => 'LIKE avec wildcard en debut — empeche l\'utilisation d\'index',
            ];
        }

        // N+1 in subquery form (correlated subquery in SELECT)
        if (preg_match('/SELECT.*\(\s*SELECT.*FROM.*WHERE.*\)/is', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'performance',
                'message'  => 'Sous-requete correlee dans SELECT — potentiel N+1, verifier si un JOIN serait plus efficace',
            ];
        }

        // Missing index hint for large table joins
        if (preg_match('/\bJOIN\b.*\bON\b/i', $code) && preg_match('/\bORDER\s+BY\b/i', $code)) {
            $issues[] = [
                'severity' => 'low',
                'type'     => 'performance',
                'message'  => 'JOIN avec ORDER BY — verifier la presence d\'index sur les colonnes de jointure et de tri',
            ];
        }

        return $issues;
    }

    // ── Go Patterns ──────────────────────────────────────────────────────────

    private function checkGoPatterns(string $code): array
    {
        $issues = [];

        // Ignored errors
        if (preg_match('/,\s*_\s*:?=/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'quality',
                'message'  => 'Erreur ignoree avec _ — toujours verifier les erreurs en Go',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*:?=\s*"[^"]{3,}"/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials hardcodes — utiliser des variables d\'environnement (os.Getenv)',
            ];
        }

        // Goroutine leak risk (goroutine without WaitGroup or context)
        if (preg_match('/\bgo\s+func\s*\(/', $code) && !preg_match('/WaitGroup|context\.Context|ctx\.Done/i', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'performance',
                'message'  => 'Goroutine sans WaitGroup ni context — risque de goroutine leak',
            ];
        }

        return $issues;
    }

    // ── Rust Patterns ─────────────────────────────────────────────────────────

    private function checkRustPatterns(string $code): array
    {
        $issues = [];

        // unwrap() — can panic in production
        if (preg_match('/\.unwrap\s*\(\)/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'quality',
                'message'  => 'unwrap() peut causer un panic en production — utiliser match, if let, ou ? pour propager l\'erreur correctement',
            ];
        }

        // unsafe block
        if (preg_match('/\bunsafe\s*\{/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Bloc unsafe detecte — verifier qu\'il est indispensable et correctement documente avec un commentaire SAFETY',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*=\s*"[^"]{3,}"/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials hardcodes — utiliser des variables d\'environnement (std::env::var)',
            ];
        }

        // clone() in a loop
        if (preg_match('/\b(for|while|loop)\b[^{]*\{[^}]*\.clone\(\)/s', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'performance',
                'message'  => 'clone() dans une boucle — verifier si des references (&) ou Cow<> suffiraient pour eviter les allocations inutiles',
            ];
        }

        // lock().unwrap() — mutex poisoning risk
        if (preg_match('/\.lock\(\)\.unwrap\(\)/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'lock().unwrap() — si le mutex est empoisonne (panic dans un thread), cela propage la panique ; utiliser match ou unwrap_or_else',
            ];
        }

        return $issues;
    }

    // ── Java Patterns ─────────────────────────────────────────────────────────

    private function checkJavaPatterns(string $code): array
    {
        $issues = [];

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|apiKey|token)\s*=\s*"[^"]{3,}"/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type'     => 'security',
                'message'  => 'Credentials hardcodes — utiliser des variables d\'environnement ou un vault',
            ];
        }

        // SQL injection via concatenation
        if (preg_match('/executeQuery\s*\(\s*"[^"]*"\s*\+/', $code) ||
            preg_match('/createStatement.*executeQuery/s', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type'     => 'security',
                'message'  => 'SQL Injection potentielle — utiliser PreparedStatement avec des parametres',
            ];
        }

        // NullPointerException risk
        if (preg_match('/\.(\w+)\(\)\.(\w+)\(\)/', $code) && !preg_match('/Optional|Objects\.requireNonNull|!=\s*null/i', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type'     => 'quality',
                'message'  => 'Chaining de methodes sans verification null — risque de NullPointerException',
            ];
        }

        // printStackTrace (bad practice)
        if (preg_match('/\.printStackTrace\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'low',
                'type'     => 'quality',
                'message'  => 'printStackTrace() — utiliser un logger (SLF4J/Log4J) a la place',
            ];
        }

        return $issues;
    }
}
