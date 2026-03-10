<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\CodeReviewAgent;
use App\Services\CodeAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeReviewAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    // ── Agent basics ─────────────────────────────────────────────────────────

    public function test_code_review_agent_returns_correct_name(): void
    {
        $agent = new CodeReviewAgent();
        $this->assertEquals('code_review', $agent->name());
    }

    public function test_can_handle_code_review_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('code review')));
        $this->assertTrue($agent->canHandle($this->makeContext('review my code')));
        $this->assertTrue($agent->canHandle($this->makeContext('verifier ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('check this code')));
        $this->assertTrue($agent->canHandle($this->makeContext('@codereviewer')));
    }

    public function test_cannot_handle_empty_body(): void
    {
        $agent = new CodeReviewAgent();
        $context = $this->makeContext('');
        // canHandle checks for empty body
        $this->assertFalse($agent->canHandle($context));
    }

    public function test_handle_shows_help_on_empty_body(): void
    {
        $agent = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Code Review', $result->reply);
        $this->assertStringContainsString('Langages supportes', $result->reply);
    }

    public function test_handle_asks_for_code_when_no_blocks_found(): void
    {
        $agent = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('pas trouve de code', $result->reply);
    }

    // ── CodeAnalyzer ─────────────────────────────────────────────────────────

    public function test_analyzer_extracts_php_code_blocks(): void
    {
        $analyzer = new CodeAnalyzer();

        $message = "Voici mon code:\n```php\n\$x = 1;\necho \$x;\n```\ncode review";

        $blocks = $analyzer->extractCodeBlocks($message);

        $this->assertCount(1, $blocks);
        $this->assertEquals('php', $blocks[0]['language']);
        $this->assertStringContainsString('$x = 1', $blocks[0]['code']);
        $this->assertEquals(2, $blocks[0]['line_count']);
    }

    public function test_analyzer_extracts_multiple_code_blocks(): void
    {
        $analyzer = new CodeAnalyzer();

        $message = "```php\necho 'hello';\n```\n\n```javascript\nconsole.log('hi');\n```";

        $blocks = $analyzer->extractCodeBlocks($message);

        $this->assertCount(2, $blocks);
        $this->assertEquals('php', $blocks[0]['language']);
        $this->assertEquals('javascript', $blocks[1]['language']);
    }

    public function test_analyzer_normalizes_language_aliases(): void
    {
        $analyzer = new CodeAnalyzer();

        $message = "```js\nconst x = 1;\n```";
        $blocks = $analyzer->extractCodeBlocks($message);

        $this->assertEquals('javascript', $blocks[0]['language']);
    }

    public function test_analyzer_detects_sql_injection_in_php(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = <<<'CODE'
$username = $_GET['user'];
$result = mysqli_query($conn, "SELECT * FROM users WHERE name = '$username'");
CODE;

        $issues = $analyzer->analyzePatterns($code, 'php');

        $hasSecurityIssue = false;
        foreach ($issues as $issue) {
            if ($issue['type'] === 'security' && str_contains($issue['message'], 'SQL Injection')) {
                $hasSecurityIssue = true;
                break;
            }
        }

        $this->assertTrue($hasSecurityIssue, 'Should detect SQL injection vulnerability');
    }

    public function test_analyzer_detects_hardcoded_credentials_php(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = '$password = "super_secret_123";';

        $issues = $analyzer->analyzePatterns($code, 'php');

        $hasCredentialIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'Credentials') || str_contains($issue['message'], 'hardcode')) {
                $hasCredentialIssue = true;
                break;
            }
        }

        $this->assertTrue($hasCredentialIssue, 'Should detect hardcoded credentials');
    }

    public function test_analyzer_detects_eval_in_php(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'eval($userInput);';

        $issues = $analyzer->analyzePatterns($code, 'php');

        $hasEvalIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'eval')) {
                $hasEvalIssue = true;
                break;
            }
        }

        $this->assertTrue($hasEvalIssue, 'Should detect eval() usage');
    }

    public function test_analyzer_detects_xss_in_javascript(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'document.getElementById("output").innerHTML = userInput;';

        $issues = $analyzer->analyzePatterns($code, 'javascript');

        $hasXssIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'innerHTML') || str_contains($issue['message'], 'XSS')) {
                $hasXssIssue = true;
                break;
            }
        }

        $this->assertTrue($hasXssIssue, 'Should detect innerHTML XSS risk');
    }

    public function test_analyzer_detects_hardcoded_credentials_javascript(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'const apiKey = "sk-1234567890abcdef";';

        $issues = $analyzer->analyzePatterns($code, 'javascript');

        $hasCredentialIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'Credentials') || str_contains($issue['message'], 'hardcode')) {
                $hasCredentialIssue = true;
                break;
            }
        }

        $this->assertTrue($hasCredentialIssue, 'Should detect hardcoded API key');
    }

    public function test_analyzer_detects_bare_except_in_python(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = "try:\n    risky()\nexcept:\n    pass";

        $issues = $analyzer->analyzePatterns($code, 'python');

        $hasBareExcept = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'Bare except')) {
                $hasBareExcept = true;
                break;
            }
        }

        $this->assertTrue($hasBareExcept, 'Should detect bare except in Python');
    }

    public function test_analyzer_detects_select_star_in_sql(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'SELECT * FROM users WHERE id = 1;';

        $issues = $analyzer->analyzePatterns($code, 'sql');

        $hasSelectStar = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'SELECT *')) {
                $hasSelectStar = true;
                break;
            }
        }

        $this->assertTrue($hasSelectStar, 'Should detect SELECT *');
    }

    public function test_analyzer_detects_delete_without_where(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'DELETE FROM users;';

        $issues = $analyzer->analyzePatterns($code, 'sql');

        $hasDangerousDelete = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'sans WHERE')) {
                $hasDangerousDelete = true;
                break;
            }
        }

        $this->assertTrue($hasDangerousDelete, 'Should detect DELETE without WHERE');
    }

    public function test_analyzer_detects_language_from_code(): void
    {
        $analyzer = new CodeAnalyzer();

        // PHP code without language tag
        $message = "```\n<?php\necho 'hello';\n```";
        $blocks = $analyzer->extractCodeBlocks($message);

        $this->assertCount(1, $blocks);
        $this->assertEquals('php', $blocks[0]['language']);
    }

    public function test_analyzer_supported_languages(): void
    {
        $analyzer = new CodeAnalyzer();

        $this->assertTrue($analyzer->isSupported('php'));
        $this->assertTrue($analyzer->isSupported('javascript'));
        $this->assertTrue($analyzer->isSupported('python'));
        $this->assertTrue($analyzer->isSupported('sql'));
        $this->assertTrue($analyzer->isSupported('typescript'));
        $this->assertTrue($analyzer->isSupported('go'));
        $this->assertTrue($analyzer->isSupported('java'));
        $this->assertFalse($analyzer->isSupported('cobol'));
    }

    public function test_analyzer_detects_go_error_ignored(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = "result, _ := os.Open(\"file.txt\")";

        $issues = $analyzer->analyzePatterns($code, 'go');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'Erreur ignoree')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect ignored error in Go');
    }

    public function test_analyzer_detects_java_sql_injection(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'Statement stmt = conn.createStatement();
ResultSet rs = stmt.executeQuery("SELECT * FROM users WHERE id = " + userId);';

        $issues = $analyzer->analyzePatterns($code, 'java');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if ($issue['type'] === 'security' && str_contains($issue['message'], 'SQL Injection')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect SQL injection in Java');
    }

    public function test_analyzer_detects_python_mutable_default_arg(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = "def add_item(item, items=[]):\n    items.append(item)\n    return items";

        $issues = $analyzer->analyzePatterns($code, 'python');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'mutable')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect mutable default argument in Python');
    }

    public function test_analyzer_detects_js_promise_without_catch(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'fetch("/api/data").then(res => res.json()).then(data => console.log(data));';

        $issues = $analyzer->analyzePatterns($code, 'javascript');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'catch') || str_contains($issue['message'], 'Promise')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect Promise without .catch()');
    }

    public function test_analyzer_truncates_large_code_blocks(): void
    {
        $analyzer = new CodeAnalyzer();

        // Generate 250 lines of code
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = "echo \$i; // line {$i}";
        }
        $code = implode("\n", $lines);
        $message = "```php\n{$code}\n```\ncode review";

        $blocks = $analyzer->extractCodeBlocks($message);

        $this->assertCount(1, $blocks);
        $this->assertEquals(250, $blocks[0]['line_count']);
        $this->assertTrue($blocks[0]['truncated']);
    }

    public function test_code_review_agent_detects_quick_mode(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('quick review')));
        $this->assertTrue($agent->canHandle($this->makeContext('revue rapide')));
    }

    public function test_code_review_agent_detects_diff_mode(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('compare code')));
        $this->assertTrue($agent->canHandle($this->makeContext('comparer code')));
    }

    public function test_diff_mode_requires_two_blocks(): void
    {
        $agent = new CodeReviewAgent();

        // Only one code block — should ask for two
        $body = "```php\necho 'hello';\n```\ncompare code";
        $context = $this->makeContext($body);

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('deux blocs', $result->reply);
    }

    public function test_no_code_blocks_returns_hint_with_modes(): void
    {
        $agent = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('pas trouve de code', $result->reply);
        $this->assertStringContainsString('quick review', $result->reply);
    }

    public function test_agent_version_is_1_6_0(): void
    {
        $agent = new CodeReviewAgent();
        $this->assertEquals('1.6.0', $agent->version());
    }

    // ── New modes v1.2.0 ──────────────────────────────────────────────────────

    public function test_can_handle_explain_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('explain code')));
        $this->assertTrue($agent->canHandle($this->makeContext('expliquer ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('que fait ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('explain this code')));
    }

    public function test_can_handle_security_audit_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('security audit')));
        $this->assertTrue($agent->canHandle($this->makeContext('audit securite')));
        $this->assertTrue($agent->canHandle($this->makeContext('audit de securite')));
        $this->assertTrue($agent->canHandle($this->makeContext('code audit')));
    }

    public function test_no_code_blocks_returns_hint_with_all_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('explain code', $result->reply);
        $this->assertStringContainsString('security audit', $result->reply);
    }

    public function test_help_message_includes_new_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('explain code', $result->reply);
        $this->assertStringContainsString('security audit', $result->reply);
        $this->assertStringContainsString('OWASP', $result->reply);
    }

    // ── New modes v1.3.0 ──────────────────────────────────────────────────────

    public function test_can_handle_refactor_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('refactor this code')));
        $this->assertTrue($agent->canHandle($this->makeContext('refactorer ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('nettoyer ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('clean up code')));
        $this->assertTrue($agent->canHandle($this->makeContext('restructurer')));
    }

    public function test_can_handle_complexity_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('complexite cyclomatique')));
        $this->assertTrue($agent->canHandle($this->makeContext('cyclomatic complexity')));
        $this->assertTrue($agent->canHandle($this->makeContext('analyse complexite')));
    }

    public function test_refactor_mode_detected(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\necho 'hello';\n```\nrefactor this code");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('REFACTORING', $result->reply);
    }

    public function test_complexity_mode_detected(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\necho 'hello';\n```\nanalyse complexite");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('COMPLEXITE', $result->reply);
    }

    public function test_help_message_includes_refactor_and_complexity_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('refactor code', $result->reply);
        $this->assertStringContainsString('analyse complexite', $result->reply);
        $this->assertStringContainsString('Astuce', $result->reply);
    }

    public function test_no_code_blocks_returns_hint_with_refactor_mode(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('refactor code', $result->reply);
        $this->assertStringContainsString('analyse complexite', $result->reply);
    }

    public function test_analyzer_detects_rust_unwrap(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'let file = File::open("foo.txt").unwrap();';

        $issues = $analyzer->analyzePatterns($code, 'rust');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'unwrap')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect unwrap() usage in Rust');
    }

    public function test_analyzer_detects_rust_unsafe_block(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = "unsafe {\n    let raw = &mut *ptr;\n}";

        $issues = $analyzer->analyzePatterns($code, 'rust');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'unsafe')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect unsafe block in Rust');
    }

    public function test_analyzer_detects_rust_hardcoded_credentials(): void
    {
        $analyzer = new CodeAnalyzer();

        $code = 'let api_key = "sk-super-secret-1234";';

        $issues = $analyzer->analyzePatterns($code, 'rust');

        $hasIssue = false;
        foreach ($issues as $issue) {
            if ($issue['type'] === 'security' && str_contains($issue['message'], 'Credentials')) {
                $hasIssue = true;
                break;
            }
        }

        $this->assertTrue($hasIssue, 'Should detect hardcoded credentials in Rust');
    }

    public function test_handle_pending_context_mode_change(): void
    {
        $agent = new CodeReviewAgent();

        // Simulate a pending context from a previous code review
        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\n\$x = 1;\necho \$x;\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        // Follow-up: request refactor mode without resending code
        $context = $this->makeContext('refactor this code');

        $result = $agent->handlePendingContext($context, $pendingContext);

        // Should process (not null) — the result will be a reply using saved code
        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('REFACTORING', $result->reply);
    }

    public function test_handle_pending_context_ignores_unknown_type(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'some_other_type',
            'data' => [],
        ];

        $context = $this->makeContext('refactor this code');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNull($result);
    }

    public function test_handle_pending_context_falls_through_when_new_code_present(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\necho 'old';\n```",
                'mode'     => 'full',
            ],
        ];

        // Message has new code blocks — should fall through (return null)
        $context = $this->makeContext("```php\necho 'new code';\n```\nrefactor this code");
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNull($result);
    }

    // ── New modes v1.4.0 ──────────────────────────────────────────────────────

    public function test_can_handle_test_generation_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('generer tests')));
        $this->assertTrue($agent->canHandle($this->makeContext('generate tests')));
        $this->assertTrue($agent->canHandle($this->makeContext('write tests')));
        $this->assertTrue($agent->canHandle($this->makeContext('tests unitaires')));
        $this->assertTrue($agent->canHandle($this->makeContext('tester ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('test this code')));
    }

    public function test_can_handle_doc_generation_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('doc code')));
        $this->assertTrue($agent->canHandle($this->makeContext('documenter ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('generate docs')));
        $this->assertTrue($agent->canHandle($this->makeContext('docblock')));
        $this->assertTrue($agent->canHandle($this->makeContext('phpdoc')));
        $this->assertTrue($agent->canHandle($this->makeContext('jsdoc')));
    }

    public function test_can_handle_help_trigger(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('aide code review')));
        $this->assertTrue($agent->canHandle($this->makeContext('help code review')));
        $this->assertTrue($agent->canHandle($this->makeContext('code review help')));
    }

    public function test_help_trigger_returns_help_message(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('aide code review');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Code Review', $result->reply);
        $this->assertStringContainsString('generer tests', $result->reply);
        $this->assertStringContainsString('doc code', $result->reply);
    }

    public function test_test_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nfunction add(\$a, \$b) { return \$a + \$b; }\n```\ngenerer tests");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('TESTS', $result->reply);
    }

    public function test_doc_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nfunction add(\$a, \$b) { return \$a + \$b; }\n```\ndoc code");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('DOCUMENTATION', $result->reply);
    }

    public function test_help_message_includes_test_and_doc_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('generer tests', $result->reply);
        $this->assertStringContainsString('doc code', $result->reply);
    }

    public function test_no_code_hint_mentions_new_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('generer tests', $result->reply);
        $this->assertStringContainsString('doc code', $result->reply);
    }

    public function test_handle_pending_context_test_mode(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\nfunction add(\$a, \$b) { return \$a + \$b; }\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        $context = $this->makeContext('generer tests');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('TESTS', $result->reply);
    }

    // ── New modes v1.5.0 ──────────────────────────────────────────────────────

    public function test_can_handle_migrate_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('migrate code')));
        $this->assertTrue($agent->canHandle($this->makeContext('migrer ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('upgrade code')));
        $this->assertTrue($agent->canHandle($this->makeContext('moderniser ce code')));
        $this->assertTrue($agent->canHandle($this->makeContext('migrer vers php 8')));
        $this->assertTrue($agent->canHandle($this->makeContext('migrate to php 8')));
    }

    public function test_can_handle_standards_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('psr-12')));
        $this->assertTrue($agent->canHandle($this->makeContext('pep 8')));
        $this->assertTrue($agent->canHandle($this->makeContext('code standards')));
        $this->assertTrue($agent->canHandle($this->makeContext('coding standards')));
        $this->assertTrue($agent->canHandle($this->makeContext('code style')));
        $this->assertTrue($agent->canHandle($this->makeContext('eslint')));
    }

    public function test_migrate_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nfunction hello() { echo 'hello'; }\n```\nmigrate code");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('MIGRATION', $result->reply);
    }

    public function test_standards_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nfunction hello() { echo 'hello'; }\n```\ncode standards");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('NORMES', $result->reply);
    }

    public function test_help_message_includes_migrate_and_standards_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('migrate code', $result->reply);
        $this->assertStringContainsString('code standards', $result->reply);
    }

    public function test_no_code_hint_mentions_migrate_and_standards_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('migrate code', $result->reply);
        $this->assertStringContainsString('code standards', $result->reply);
    }

    public function test_handle_pending_context_migrate_mode(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\nfunction get_user(\$id) { return User::find(\$id); }\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        $context = $this->makeContext('migrate code');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('MIGRATION', $result->reply);
    }

    public function test_handle_pending_context_standards_mode(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```python\ndef add(a, b):\n    return a + b\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        $context = $this->makeContext('pep 8');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('NORMES', $result->reply);
    }

    // ── New modes v1.6.0 ──────────────────────────────────────────────────────

    public function test_can_handle_translate_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('traduire en python')));
        $this->assertTrue($agent->canHandle($this->makeContext('translate to javascript')));
        $this->assertTrue($agent->canHandle($this->makeContext('convert to typescript')));
        $this->assertTrue($agent->canHandle($this->makeContext('convertir en go')));
        $this->assertTrue($agent->canHandle($this->makeContext('rewrite in rust')));
        $this->assertTrue($agent->canHandle($this->makeContext('code en python')));
    }

    public function test_can_handle_optimize_keywords(): void
    {
        $agent = new CodeReviewAgent();

        $this->assertTrue($agent->canHandle($this->makeContext('optimiser performance')));
        $this->assertTrue($agent->canHandle($this->makeContext('optimize performance')));
        $this->assertTrue($agent->canHandle($this->makeContext('bottleneck')));
        $this->assertTrue($agent->canHandle($this->makeContext('slow code')));
        $this->assertTrue($agent->canHandle($this->makeContext('profiling')));
        $this->assertTrue($agent->canHandle($this->makeContext('speed up')));
    }

    public function test_translate_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nfunction add(\$a, \$b) { return \$a + \$b; }\n```\ntraduire en python");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('TRADUCTION', $result->reply);
    }

    public function test_optimize_mode_detected_in_report(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext("```php\nforeach (\$ids as \$id) { \$user = User::find(\$id); }\n```\noptimiser performance");

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('OPTIMISATION', $result->reply);
    }

    public function test_help_message_includes_translate_and_optimize_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('traduire en', $result->reply);
        $this->assertStringContainsString('optimiser performance', $result->reply);
    }

    public function test_no_code_hint_mentions_translate_and_optimize_modes(): void
    {
        $agent   = new CodeReviewAgent();
        $context = $this->makeContext('code review please');

        $result = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('traduire en', $result->reply);
        $this->assertStringContainsString('optimiser performance', $result->reply);
    }

    public function test_handle_pending_context_translate_mode(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\nfunction add(\$a, \$b) { return \$a + \$b; }\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        $context = $this->makeContext('traduire en python');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('TRADUCTION', $result->reply);
    }

    public function test_handle_pending_context_optimize_mode(): void
    {
        $agent = new CodeReviewAgent();

        $pendingContext = [
            'type' => 'code_reviewed',
            'data' => [
                'raw_code' => "```php\nforeach (\$ids as \$id) { \$user = User::find(\$id); }\n```\ncode review",
                'mode'     => 'full',
            ],
        ];

        $context = $this->makeContext('optimiser performance');
        $result  = $agent->handlePendingContext($context, $pendingContext);

        $this->assertNotNull($result);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('OPTIMISATION', $result->reply);
    }

    // ── Controller & Router integration ──────────────────────────────────────

    public function test_agent_controller_includes_code_review_in_sub_agents(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\AgentController::class);
        $constant = $reflection->getReflectionConstant('SUB_AGENTS');
        $subAgents = $constant->getValue();

        $this->assertArrayHasKey('code_review', $subAgents);
        $this->assertEquals('Code Review', $subAgents['code_review']['label']);
        $this->assertEquals('🔍', $subAgents['code_review']['icon']);
        $this->assertEquals('blue', $subAgents['code_review']['color']);
    }

    public function test_router_detects_code_review_keywords(): void
    {
        $router = new \App\Services\Agents\RouterAgent();
        $reflection = new \ReflectionClass($router);
        $method = $reflection->getMethod('detectCodeReviewKeywords');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($router, 'code review'));
        $this->assertTrue($method->invoke($router, 'review my code'));
        $this->assertTrue($method->invoke($router, 'verifier ce code'));
        $this->assertTrue($method->invoke($router, '@codereviewer'));
        $this->assertFalse($method->invoke($router, 'bonjour'));
        $this->assertFalse($method->invoke($router, 'code something'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(string $body): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id'      => $agent->id,
            'session_key'   => AgentSession::keyFor($agent->id, 'whatsapp', $this->testPhone),
            'channel'       => 'whatsapp',
            'peer_id'       => $this->testPhone,
            'last_message_at' => now(),
        ]);

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: $this->testPhone,
            senderName: 'Test User',
            body: $body,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            routedAgent: 'code_review',
            routedModel: 'claude-haiku-4-5-20251001',
        );
    }
}
