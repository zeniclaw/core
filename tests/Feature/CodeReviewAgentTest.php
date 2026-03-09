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

    public function test_agent_version_is_1_1_0(): void
    {
        $agent = new CodeReviewAgent();
        $this->assertEquals('1.1.0', $agent->version());
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
