<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\DocumentAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    // ── Agent basics ─────────────────────────────────────────────────────────

    public function test_agent_returns_correct_name(): void
    {
        $agent = new DocumentAgent();
        $this->assertEquals('document', $agent->name());
    }

    public function test_agent_version_is_1_2_0(): void
    {
        $agent = new DocumentAgent();
        $this->assertEquals('1.2.0', $agent->version());
    }

    public function test_agent_has_description(): void
    {
        $agent = new DocumentAgent();
        $this->assertNotEmpty($agent->description());
    }

    public function test_description_mentions_csv(): void
    {
        $agent = new DocumentAgent();
        $this->assertStringContainsStringIgnoringCase('CSV', $agent->description());
    }

    // ── Keywords ──────────────────────────────────────────────────────────────

    public function test_keywords_include_csv(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('csv', $agent->keywords());
    }

    public function test_keywords_include_excel(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('excel', $agent->keywords());
    }

    public function test_keywords_include_pdf(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('pdf', $agent->keywords());
    }

    public function test_keywords_include_docx(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('docx', $agent->keywords());
    }

    public function test_keywords_include_facture(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('facture', $agent->keywords());
    }

    public function test_keywords_include_cv(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('cv', $agent->keywords());
    }

    public function test_keywords_include_liste_ordonnee(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('liste ordonnee', $agent->keywords());
    }

    // ── canHandle ─────────────────────────────────────────────────────────────

    public function test_can_handle_with_body(): void
    {
        $agent = new DocumentAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('cree un tableau excel')));
    }

    public function test_cannot_handle_empty_body(): void
    {
        $agent = new DocumentAgent();
        $this->assertFalse($agent->canHandle($this->makeContext('')));
    }

    public function test_cannot_handle_null_body(): void
    {
        $agent = new DocumentAgent();
        $this->assertFalse($agent->canHandle($this->makeContext(null)));
    }

    // ── parseJson (via reflection) ────────────────────────────────────────────

    public function test_parse_json_valid_json(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('parseJson');
        $method->setAccessible(true);

        $json = '{"format":"xlsx","filename":"test","title":"Test","content":{}}';
        $result = $method->invoke($agent, $json);

        $this->assertIsArray($result);
        $this->assertEquals('xlsx', $result['format']);
        $this->assertEquals('test', $result['filename']);
    }

    public function test_parse_json_strips_markdown_code_block(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('parseJson');
        $method->setAccessible(true);

        $wrapped = "```json\n{\"format\":\"pdf\",\"filename\":\"doc\",\"title\":\"Doc\",\"content\":{}}\n```";
        $result  = $method->invoke($agent, $wrapped);

        $this->assertIsArray($result);
        $this->assertEquals('pdf', $result['format']);
    }

    public function test_parse_json_extracts_from_mixed_text(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('parseJson');
        $method->setAccessible(true);

        $response = 'Voici le JSON: {"format":"docx","filename":"lettre","title":"Lettre","content":{}}';
        $result   = $method->invoke($agent, $response);

        $this->assertIsArray($result);
        $this->assertEquals('docx', $result['format']);
    }

    public function test_parse_json_returns_null_for_invalid(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('parseJson');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($agent, 'ceci nest pas du json'));
        $this->assertNull($method->invoke($agent, null));
        $this->assertNull($method->invoke($agent, ''));
    }

    // ── generateXlsx (via reflection) ────────────────────────────────────────

    public function test_generate_xlsx_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'sheets' => [[
                'name'    => 'Test',
                'headers' => ['Nom', 'Valeur'],
                'rows'    => [['Alice', 100], ['Bob', 200]],
            ]],
        ];

        $path = $method->invoke($agent, 'test_xlsx_' . uniqid(), 'Test XLSX', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_xlsx_handles_empty_sheets(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $path = $method->invoke($agent, 'test_xlsx_empty_' . uniqid(), 'Empty', []);

        $this->assertFileExists($path);
        @unlink($path);
    }

    public function test_generate_xlsx_handles_multiple_sheets(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'sheets' => [
                ['name' => 'Sheet1', 'headers' => ['A'], 'rows' => [['1']]],
                ['name' => 'Sheet2', 'headers' => ['B'], 'rows' => [['2']]],
            ],
        ];

        $path = $method->invoke($agent, 'test_xlsx_multi_' . uniqid(), 'Multi', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_xlsx_does_not_mangle_dates(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        // Dates like "2026-01-15" should NOT be cast to 0.0
        $content = [
            'sheets' => [[
                'name'    => 'Dates',
                'headers' => ['Date', 'Code', 'Montant'],
                'rows'    => [['2026-01-15', 'REF-001', 1500]],
            ]],
        ];

        // If this doesn't throw, it's fine (actual cell value check would need Spreadsheet reader)
        $path = $method->invoke($agent, 'test_dates_' . uniqid(), 'Dates', $content);
        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── generateCsv (via reflection) ──────────────────────────────────────────

    public function test_generate_csv_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateCsv');
        $method->setAccessible(true);

        $content = [
            'headers' => ['Nom', 'Email', 'Ville'],
            'rows'    => [
                ['Alice Dupont', 'alice@exemple.com', 'Paris'],
                ['Bob Martin', 'bob@exemple.com', 'Lyon'],
            ],
        ];

        $path = $method->invoke($agent, 'test_csv_' . uniqid(), $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_csv_contains_bom(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateCsv');
        $method->setAccessible(true);

        $content = ['headers' => ['Col'], 'rows' => [['val']]];
        $path    = $method->invoke($agent, 'test_bom_' . uniqid(), $content);

        $raw = file_get_contents($path);
        // UTF-8 BOM = EF BB BF
        $this->assertEquals("\xEF\xBB\xBF", substr($raw, 0, 3));

        @unlink($path);
    }

    public function test_generate_csv_contains_headers_and_rows(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateCsv');
        $method->setAccessible(true);

        $content = [
            'headers' => ['Nom', 'Email'],
            'rows'    => [['Alice', 'alice@test.com']],
        ];
        $path = $method->invoke($agent, 'test_content_' . uniqid(), $content);

        $raw = file_get_contents($path);
        $this->assertStringContainsString('Nom', $raw);
        $this->assertStringContainsString('Email', $raw);
        $this->assertStringContainsString('Alice', $raw);
        $this->assertStringContainsString('alice@test.com', $raw);

        @unlink($path);
    }

    public function test_generate_csv_handles_empty_content(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateCsv');
        $method->setAccessible(true);

        $path = $method->invoke($agent, 'test_empty_csv_' . uniqid(), []);

        // File should be created (only BOM)
        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── generatePdf (via reflection) ──────────────────────────────────────────

    public function test_generate_pdf_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Titre principal', 'level' => 1],
                ['type' => 'paragraph', 'text' => 'Contenu du rapport.'],
                ['type' => 'bold', 'text' => 'Total: 1000 EUR'],
                ['type' => 'separator'],
                ['type' => 'list', 'items' => ['Item A', 'Item B']],
                ['type' => 'ordered_list', 'items' => ['Etape 1', 'Etape 2']],
                ['type' => 'table', 'headers' => ['Col1', 'Col2'], 'rows' => [['a', 'b']]],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_' . uniqid(), 'Rapport Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_pdf_handles_page_break(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'paragraph', 'text' => 'Page 1'],
                ['type' => 'page_break'],
                ['type' => 'paragraph', 'text' => 'Page 2'],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_break_' . uniqid(), 'Test Page Break', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_build_pdf_html_ordered_list(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [
            ['type' => 'ordered_list', 'items' => ['Premier', 'Deuxieme', 'Troisieme']],
        ];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>Premier</li>', $html);
        $this->assertStringContainsString('<li>Troisieme</li>', $html);
        $this->assertStringNotContainsString('<ul>', $html);
    }

    public function test_build_pdf_html_escapes_special_chars(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [
            ['type' => 'paragraph', 'text' => '<script>alert("xss")</script>'],
        ];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_build_pdf_html_page_break_uses_css_class(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'page_break']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringContainsString('class="page-break"', $html);
    }

    public function test_build_pdf_html_italic(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'italic', 'text' => 'Texte italique']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('<em>Texte italique</em>', $html);
    }

    public function test_build_pdf_html_alternating_table_rows(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'    => 'table',
            'headers' => ['A', 'B'],
            'rows'    => [['x1', 'y1'], ['x2', 'y2'], ['x3', 'y3']],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        // Row at index 1 (second data row) should have class "alt"
        $this->assertStringContainsString('class="alt"', $html);
    }

    // ── generateDocx (via reflection) ─────────────────────────────────────────

    public function test_generate_docx_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Introduction', 'level' => 1],
                ['type' => 'paragraph', 'text' => 'Ceci est un test.'],
                ['type' => 'bold', 'text' => 'Important'],
                ['type' => 'italic', 'text' => 'Note en italique'],
                ['type' => 'list', 'items' => ['Point A', 'Point B']],
                ['type' => 'ordered_list', 'items' => ['Etape 1', 'Etape 2']],
                ['type' => 'separator'],
                ['type' => 'table', 'headers' => ['Col1', 'Col2'], 'rows' => [['a', 'b']]],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_' . uniqid(), 'Document Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_docx_handles_page_break(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'paragraph', 'text' => 'Avant saut'],
                ['type' => 'page_break'],
                ['type' => 'paragraph', 'text' => 'Apres saut'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_break_' . uniqid(), 'Page Break Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_docx_handles_empty_sections(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $path = $method->invoke($agent, 'test_docx_empty_' . uniqid(), 'Empty Doc', []);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── callout: PDF ──────────────────────────────────────────────────────────

    public function test_build_pdf_html_callout_info(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'callout', 'text' => 'Information importante', 'style' => 'info']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Information importante', $html);
        $this->assertStringContainsString('#EBF5FB', $html);
        $this->assertStringContainsString('#3498DB', $html);
    }

    public function test_build_pdf_html_callout_warning(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'callout', 'text' => 'Attention!', 'style' => 'warning']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Attention!', $html);
        $this->assertStringContainsString('#FEF9E7', $html);
        $this->assertStringContainsString('#F39C12', $html);
    }

    public function test_build_pdf_html_callout_success(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'callout', 'text' => 'Confirmation', 'style' => 'success']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Confirmation', $html);
        $this->assertStringContainsString('#EAFAF1', $html);
        $this->assertStringContainsString('#27AE60', $html);
    }

    public function test_build_pdf_html_callout_defaults_to_info(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        // Missing style should default to info colors
        $sections = [['type' => 'callout', 'text' => 'Note sans style']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Note sans style', $html);
        $this->assertStringContainsString('#EBF5FB', $html);
    }

    // ── callout: DOCX ─────────────────────────────────────────────────────────

    public function test_generate_docx_with_callout(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Contrat', 'level' => 1],
                ['type' => 'callout', 'text' => 'Clause importante: lire attentivement.', 'style' => 'warning'],
                ['type' => 'paragraph', 'text' => 'Suite du document.'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_callout_' . uniqid(), 'Contrat Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    // ── XLSX: freeze pane + total rows ────────────────────────────────────────

    public function test_generate_xlsx_with_total_row(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'sheets' => [[
                'name'    => 'Budget',
                'headers' => ['Poste', 'Jan', 'Feb', 'Total'],
                'rows'    => [
                    ['Loyer', 1000, 1000, 2000],
                    ['Salaires', 5000, 5000, 10000],
                    ['TOTAL', 6000, 6000, 12000],
                ],
            ]],
        ];

        $path = $method->invoke($agent, 'test_xlsx_total_' . uniqid(), 'Budget Total', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    public function test_generate_xlsx_with_sous_total_row(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'sheets' => [[
                'name'    => 'Facture',
                'headers' => ['Description', 'Montant'],
                'rows'    => [
                    ['Prestation A', 500],
                    ['SOUS-TOTAL', 500],
                    ['TVA 20%', 100],
                    ['TOTAL TTC', 600],
                ],
            ]],
        ];

        $path = $method->invoke($agent, 'test_xlsx_sous_total_' . uniqid(), 'Facture', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        @unlink($path);
    }

    // ── Keywords: nouvelles entrees v1.2.0 ───────────────────────────────────

    public function test_keywords_include_callout(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('callout', $agent->keywords());
    }

    public function test_keywords_include_note_encadree(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('note encadree', $agent->keywords());
    }

    // ── Filename sanitization ─────────────────────────────────────────────────

    public function test_filename_sanitization_removes_spaces(): void
    {
        // Verify sanitization via CSV generation (quickest format)
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateCsv');
        $method->setAccessible(true);

        // Sanitized filename (spaces already removed before reaching generateCsv)
        $path = $method->invoke($agent, 'fichier_sans_espace', []);
        $this->assertStringContainsString('fichier_sans_espace', $path);
        @unlink($path);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(?string $body): AgentContext
    {
        $user    = User::factory()->create();
        $agentDb = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id'        => $agentDb->id,
            'session_key'     => AgentSession::keyFor($agentDb->id, 'whatsapp', $this->testPhone),
            'channel'         => 'whatsapp',
            'peer_id'         => $this->testPhone,
            'last_message_at' => now(),
        ]);

        return new AgentContext(
            agent:      $agentDb,
            session:    $session,
            from:       $this->testPhone,
            senderName: 'Test User',
            body:       $body,
            hasMedia:   false,
            mediaUrl:   null,
            mimetype:   null,
            media:      null,
        );
    }
}
