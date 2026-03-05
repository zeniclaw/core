<?php

namespace App\Services;

class CodeAnalyzer
{
    private const SUPPORTED_LANGUAGES = ['php', 'javascript', 'js', 'python', 'py', 'sql', 'typescript', 'ts'];

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

                $blocks[] = [
                    'language' => $lang,
                    'code' => $code,
                    'line_count' => substr_count($code, "\n") + 1,
                ];
            }
        }

        // If no code blocks found, try to detect inline code
        if (empty($blocks) && $this->looksLikeCode($message)) {
            $lang = $this->detectLanguage($message);
            $blocks[] = [
                'language' => $lang,
                'code' => $message,
                'line_count' => substr_count($message, "\n") + 1,
            ];
        }

        return $blocks;
    }

    /**
     * Run static pattern analysis on code to detect common issues.
     */
    public function analyzePatterns(string $code, string $language): array
    {
        $issues = [];

        $checks = match ($language) {
            'php' => $this->checkPhpPatterns($code),
            'javascript', 'typescript' => $this->checkJsPatterns($code),
            'python' => $this->checkPythonPatterns($code),
            'sql' => $this->checkSqlPatterns($code),
            default => [],
        };

        return array_merge($issues, $checks);
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
            'js' => 'javascript',
            'ts' => 'typescript',
            'py' => 'python',
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

        // SQL injection
        if (preg_match('/\$_(GET|POST|REQUEST|COOKIE)\s*\[/', $code) &&
            preg_match('/(mysql_query|mysqli_query|->query\(|->exec\()/', $code) &&
            !preg_match('/\bprepare\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type' => 'security',
                'message' => 'SQL Injection potentielle : donnees utilisateur dans une requete SQL sans prepare()',
            ];
        }

        // Direct variable interpolation in SQL
        if (preg_match('/(?:query|exec)\s*\(\s*"[^"]*\$\w+/', $code) ||
            preg_match('/(?:query|exec)\s*\(\s*\'[^\']*\'\s*\.\s*\$/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type' => 'security',
                'message' => 'Concatenation de variables dans une requete SQL — utiliser des requetes preparees',
            ];
        }

        // XSS
        if (preg_match('/echo\s+\$_(GET|POST|REQUEST)/', $code) &&
            !preg_match('/htmlspecialchars|htmlentities|e\(/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type' => 'security',
                'message' => 'XSS potentiel : echo de donnees utilisateur sans echappement (htmlspecialchars)',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*=\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Credentials potentiellement hardcodes — utiliser des variables d\'environnement',
            ];
        }

        // eval usage
        if (preg_match('/\beval\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Utilisation de eval() — risque d\'injection de code',
            ];
        }

        // md5/sha1 for passwords
        if (preg_match('/\b(md5|sha1)\s*\(\s*\$/', $code) && preg_match('/password|passwd|pwd/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Hachage faible (md5/sha1) pour mot de passe — utiliser password_hash()',
            ];
        }

        // Missing null checks
        if (preg_match('/->(\w+)\s*\(/', $code) && preg_match('/\?\?|optional|null/i', $code) === 0) {
            // Light hint, not always relevant
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
                'type' => 'security',
                'message' => 'innerHTML peut causer des XSS — utiliser textContent ou une sanitization library',
            ];
        }

        // eval usage
        if (preg_match('/\beval\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Utilisation de eval() — risque d\'injection de code',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(api_key|apiKey|secret|password|token)\s*[:=]\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Credentials hardcodes — utiliser des variables d\'environnement',
            ];
        }

        // var usage (should use let/const)
        if (preg_match('/\bvar\s+\w+/', $code)) {
            $issues[] = [
                'severity' => 'low',
                'type' => 'quality',
                'message' => 'Utilisation de var — preferer const/let pour un scope correct',
            ];
        }

        // == instead of ===
        if (preg_match('/[^=!<>]==[^=]/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'quality',
                'message' => 'Comparaison laxiste (==) — preferer === pour eviter les coercitions de type',
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
            preg_match('/\.execute\s*\(\s*["\'].*%s/', $code) && preg_match('/\%\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type' => 'security',
                'message' => 'SQL Injection — utiliser des requetes parametrees (placeholders ?)',
            ];
        }

        // eval/exec usage
        if (preg_match('/\b(eval|exec)\s*\(/', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Utilisation de eval()/exec() — risque d\'injection de code',
            ];
        }

        // Hardcoded credentials
        if (preg_match('/\b(password|secret|api_key|token)\s*=\s*["\'][^"\']{3,}["\']/i', $code)) {
            $issues[] = [
                'severity' => 'high',
                'type' => 'security',
                'message' => 'Credentials hardcodes — utiliser des variables d\'environnement (os.environ)',
            ];
        }

        // bare except
        if (preg_match('/\bexcept\s*:/', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'quality',
                'message' => 'Bare except — toujours specifier le type d\'exception',
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
                'type' => 'performance',
                'message' => 'SELECT * — specifier les colonnes necessaires pour de meilleures performances',
            ];
        }

        // Missing WHERE on UPDATE/DELETE
        if (preg_match('/\b(UPDATE|DELETE\s+FROM)\b/i', $code) && !preg_match('/\bWHERE\b/i', $code)) {
            $issues[] = [
                'severity' => 'critical',
                'type' => 'security',
                'message' => 'UPDATE/DELETE sans WHERE — risque de modification/suppression de toutes les lignes',
            ];
        }

        // LIKE with leading wildcard
        if (preg_match('/LIKE\s+["\']%/i', $code)) {
            $issues[] = [
                'severity' => 'medium',
                'type' => 'performance',
                'message' => 'LIKE avec wildcard en debut — empeche l\'utilisation d\'index',
            ];
        }

        return $issues;
    }
}
