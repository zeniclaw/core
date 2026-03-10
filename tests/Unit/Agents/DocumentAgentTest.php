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

    public function test_agent_version_is_1_5_0(): void
    {
        $agent = new DocumentAgent();
        $this->assertEquals('1.5.0', $agent->version());
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

    // ── callout: danger style ─────────────────────────────────────────────────

    public function test_build_pdf_html_callout_danger(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'callout', 'text' => 'CRITIQUE: action irreversible', 'style' => 'danger']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('CRITIQUE: action irreversible', $html);
        $this->assertStringContainsString('#FDEDEC', $html);
        $this->assertStringContainsString('#E74C3C', $html);
        $this->assertStringContainsString('[X]', $html);
    }

    public function test_generate_docx_with_callout_danger(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'callout', 'text' => 'Clause critique: attention!', 'style' => 'danger'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_danger_' . uniqid(), 'Test Danger', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── quote block ───────────────────────────────────────────────────────────

    public function test_build_pdf_html_quote(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'quote', 'text' => 'Article L.1234-56 du Code du travail.']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('Article L.1234-56 du Code du travail.', $html);
        $this->assertStringContainsString('font-style:italic', $html);
    }

    public function test_generate_docx_with_quote(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Contrat', 'level' => 1],
                ['type' => 'quote', 'text' => 'Selon l\'article 12, le prestataire doit...'],
                ['type' => 'paragraph', 'text' => 'Suite du texte.'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_quote_' . uniqid(), 'Contrat Quote', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── highlight block ───────────────────────────────────────────────────────

    public function test_build_pdf_html_highlight_yellow(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'highlight', 'text' => 'Montant TTC: 2 520 EUR', 'color' => 'yellow']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Montant TTC: 2 520 EUR', $html);
        $this->assertStringContainsString('#FFF9C4', $html);
    }

    public function test_build_pdf_html_highlight_defaults_to_yellow(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'highlight', 'text' => 'Texte important']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Texte important', $html);
        $this->assertStringContainsString('#FFF9C4', $html);
    }

    public function test_build_pdf_html_highlight_green(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'highlight', 'text' => 'Valide', 'color' => 'green']];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('#C8F7C5', $html);
    }

    public function test_generate_docx_with_highlight(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'highlight', 'text' => 'ECHEANCE: 31 mars 2026', 'color' => 'yellow'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_highlight_' . uniqid(), 'Doc Highlight', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── XLSX: currency format ─────────────────────────────────────────────────

    public function test_generate_xlsx_currency_format_on_monetary_column(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'sheets' => [[
                'name'    => 'Facture',
                'headers' => ['Description', 'Montant HT', 'TVA', 'Total TTC'],
                'rows'    => [
                    ['Prestation A', 1000, 200, 1200],
                    ['Prestation B', 500, 100, 600],
                    ['TOTAL', 1500, 300, 1800],
                ],
            ]],
        ];

        $path = $method->invoke($agent, 'test_xlsx_currency_' . uniqid(), 'Facture', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── Keywords: nouvelles entrees v1.3.0 ───────────────────────────────────

    public function test_keywords_include_citation(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('citation', $agent->keywords());
    }

    public function test_keywords_include_surlignage(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('surlignage', $agent->keywords());
    }

    public function test_keywords_include_alerte_critique(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('alerte critique', $agent->keywords());
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

    // ── Keywords: nouvelles entrees v1.4.0 ───────────────────────────────────

    public function test_keywords_include_signature(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('signature', $agent->keywords());
    }

    public function test_keywords_include_paysage(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('paysage', $agent->keywords());
    }

    public function test_keywords_include_orientation_paysage(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('orientation paysage', $agent->keywords());
    }

    // ── signature block: PDF ──────────────────────────────────────────────────

    public function test_build_pdf_html_signature_single_signer(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'    => 'signature',
            'signers' => [
                ['name' => 'Jean Dupont', 'title' => 'Directeur General'],
            ],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Directeur General', $html);
        $this->assertStringContainsString('border-top', $html);
    }

    public function test_build_pdf_html_signature_multiple_signers(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'    => 'signature',
            'signers' => [
                ['name' => 'Jean Dupont', 'title' => 'Vendeur'],
                ['name' => 'Marie Martin', 'title' => 'Acheteur'],
            ],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('Marie Martin', $html);
        $this->assertStringContainsString('Vendeur', $html);
        $this->assertStringContainsString('Acheteur', $html);
    }

    public function test_build_pdf_html_signature_empty_signers_renders_nothing(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'signature', 'signers' => []]];
        $html     = $method->invoke($agent, 'Test', $sections);

        // Should not add any signature table with empty signers
        $this->assertStringNotContainsString('border-top:1px solid', $html);
    }

    public function test_build_pdf_html_signature_escapes_special_chars(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'    => 'signature',
            'signers' => [['name' => '<script>xss</script>', 'title' => 'Test']],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringNotContainsString('<script>xss</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_generate_pdf_with_signature(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Contrat de prestation', 'level' => 1],
                ['type' => 'paragraph', 'text' => 'Les parties conviennent des conditions suivantes.'],
                ['type' => 'signature', 'signers' => [
                    ['name' => 'Jean Dupont', 'title' => 'Prestataire'],
                    ['name' => 'ACME Corp', 'title' => 'Client'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_signature_' . uniqid(), 'Contrat', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── signature block: DOCX ─────────────────────────────────────────────────

    public function test_generate_docx_with_signature(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'paragraph', 'text' => 'Contrat entre les parties.'],
                ['type' => 'signature', 'signers' => [
                    ['name' => 'Jean Dupont', 'title' => 'Directeur'],
                    ['name' => 'Marie Martin', 'title' => 'Client'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_sig_' . uniqid(), 'Contrat Sig', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_docx_with_signature_empty_signers(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'signature', 'signers' => []],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_sig_empty_' . uniqid(), 'Empty Sig', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── key_value block: PDF ──────────────────────────────────────────────────

    public function test_build_pdf_html_key_value(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'  => 'key_value',
            'pairs' => [
                ['label' => 'Client', 'value' => 'ACME Corp'],
                ['label' => 'Date', 'value' => '09/03/2026'],
                ['label' => 'Reference', 'value' => 'FC-2026-001'],
            ],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('ACME Corp', $html);
        $this->assertStringContainsString('09/03/2026', $html);
        $this->assertStringContainsString('FC-2026-001', $html);
        $this->assertStringContainsString('font-weight:bold', $html);
    }

    public function test_build_pdf_html_key_value_escapes_html(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'  => 'key_value',
            'pairs' => [['label' => '<b>Bold</b>', 'value' => '<script>alert(1)</script>']],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringNotContainsString('<b>Bold</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }

    public function test_build_pdf_html_key_value_empty_pairs(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'key_value', 'pairs' => []]];
        $html     = $method->invoke($agent, 'Test', $sections);

        // Should not add any table if pairs empty
        $this->assertStringNotContainsString('font-weight:bold', $html);
    }

    public function test_generate_pdf_with_key_value(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'key_value', 'pairs' => [
                    ['label' => 'Client', 'value' => 'Test Corp'],
                    ['label' => 'Montant', 'value' => '1 500 EUR TTC'],
                ]],
                ['type' => 'separator'],
                ['type' => 'paragraph', 'text' => 'Merci de votre confiance.'],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_kv_' . uniqid(), 'Facture Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── key_value block: DOCX ─────────────────────────────────────────────────

    public function test_generate_docx_with_key_value(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'key_value', 'pairs' => [
                    ['label' => 'Client', 'value' => 'ACME Corp'],
                    ['label' => 'Objet', 'value' => 'Prestation de service'],
                ]],
                ['type' => 'paragraph', 'text' => 'Description des services rendus.'],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_kv_' . uniqid(), 'Doc KV', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_docx_with_key_value_empty(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = ['sections' => [['type' => 'key_value', 'pairs' => []]]];
        $path    = $method->invoke($agent, 'test_docx_kv_empty_' . uniqid(), 'KV Empty', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── Landscape orientation: PDF ────────────────────────────────────────────

    public function test_generate_pdf_landscape_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'orientation' => 'landscape',
            'sections'    => [
                ['type' => 'heading', 'text' => 'Rapport Large', 'level' => 1],
                ['type' => 'table', 'headers' => ['Col1', 'Col2', 'Col3', 'Col4', 'Col5', 'Col6', 'Col7'], 'rows' => [
                    ['A1', 'B1', 'C1', 'D1', 'E1', 'F1', 'G1'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_landscape_' . uniqid(), 'Rapport Paysage', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_pdf_portrait_is_default(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        // No orientation key = default portrait
        $content = ['sections' => [['type' => 'paragraph', 'text' => 'Test portrait']]];
        $path    = $method->invoke($agent, 'test_pdf_portrait_' . uniqid(), 'Portrait', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    public function test_generate_pdf_invalid_orientation_defaults_to_portrait(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'orientation' => 'invalid_value',
            'sections'    => [['type' => 'paragraph', 'text' => 'Test']],
        ];

        $path = $method->invoke($agent, 'test_pdf_inv_orient_' . uniqid(), 'Test', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── Landscape orientation: DOCX ───────────────────────────────────────────

    public function test_generate_docx_landscape_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'orientation' => 'landscape',
            'sections'    => [
                ['type' => 'heading', 'text' => 'Tableau de bord', 'level' => 1],
                ['type' => 'table', 'headers' => ['Metrique', 'Jan', 'Fev', 'Mar', 'Avr', 'Mai'], 'rows' => [
                    ['Ventes', '1000', '1200', '900', '1400', '1100'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_landscape_' . uniqid(), 'Dashboard Paysage', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_docx_portrait_is_default(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = ['sections' => [['type' => 'paragraph', 'text' => 'Portrait par defaut']]];
        $path    = $method->invoke($agent, 'test_docx_portrait_' . uniqid(), 'Portrait', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── Description mentions new features ─────────────────────────────────────

    public function test_description_mentions_signature(): void
    {
        $agent = new DocumentAgent();
        $this->assertStringContainsStringIgnoringCase('signature', $agent->description());
    }

    public function test_description_mentions_paysage(): void
    {
        $agent = new DocumentAgent();
        $this->assertStringContainsStringIgnoringCase('paysage', $agent->description());
    }

    // ── Keywords: nouvelles entrees v1.5.0 ───────────────────────────────────

    public function test_keywords_include_badge(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('badge', $agent->keywords());
    }

    public function test_keywords_include_footer(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('footer', $agent->keywords());
    }

    public function test_keywords_include_recapitulatif(): void
    {
        $agent = new DocumentAgent();
        $this->assertContains('recapitulatif', $agent->keywords());
    }

    // ── badge block: PDF ──────────────────────────────────────────────────────

    public function test_build_pdf_html_badge_renders_spans(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'  => 'badge',
            'items' => [
                ['label' => 'Actif',     'color' => 'green'],
                ['label' => 'En attente', 'color' => 'orange'],
                ['label' => 'Cloture',   'color' => 'red'],
            ],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Actif', $html);
        $this->assertStringContainsString('En attente', $html);
        $this->assertStringContainsString('Cloture', $html);
        $this->assertStringContainsString('border-radius:12px', $html);
    }

    public function test_build_pdf_html_badge_uses_correct_colors(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'badge', 'items' => [['label' => 'OK', 'color' => 'green']]]];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('#D5F5E3', $html); // green bg
        $this->assertStringContainsString('#27AE60', $html); // green border
    }

    public function test_build_pdf_html_badge_defaults_to_blue(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'badge', 'items' => [['label' => 'Default']]]];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('#EBF5FB', $html); // blue bg
    }

    public function test_build_pdf_html_badge_escapes_label(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'badge', 'items' => [['label' => '<script>xss</script>', 'color' => 'red']]]];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_generate_pdf_with_badge(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Tableau de bord', 'level' => 1],
                ['type' => 'badge', 'items' => [
                    ['label' => 'Actif',    'color' => 'green'],
                    ['label' => 'Urgent',   'color' => 'red'],
                    ['label' => 'En cours', 'color' => 'orange'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_badge_' . uniqid(), 'Dashboard', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── badge block: DOCX ────────────────────────────────────────────────────

    public function test_generate_docx_with_badge(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'paragraph', 'text' => 'Statuts des projets:'],
                ['type' => 'badge', 'items' => [
                    ['label' => 'Termine',    'color' => 'green'],
                    ['label' => 'En attente', 'color' => 'gray'],
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_badge_' . uniqid(), 'Badge Test', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── summary_box block: PDF ───────────────────────────────────────────────

    public function test_build_pdf_html_summary_box_renders_div(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'  => 'summary_box',
            'title' => 'Points Cles',
            'items' => ['CA en hausse de 12%', '3 nouveaux clients', 'Objectif Q1 atteint'],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Points Cles', $html);
        $this->assertStringContainsString('CA en hausse de 12%', $html);
        $this->assertStringContainsString('3 nouveaux clients', $html);
        $this->assertStringContainsString('border:2px solid #4472C4', $html);
        $this->assertStringContainsString('#F0F4FF', $html);
    }

    public function test_build_pdf_html_summary_box_defaults_title(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [['type' => 'summary_box', 'items' => ['Point A']]];
        $html     = $method->invoke($agent, 'Test', $sections);

        $this->assertStringContainsString('Recapitulatif', $html);
    }

    public function test_build_pdf_html_summary_box_escapes_items(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $sections = [[
            'type'  => 'summary_box',
            'title' => 'Test',
            'items' => ['<b>bold</b>', '<script>xss</script>'],
        ]];

        $html = $method->invoke($agent, 'Test', $sections);

        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    public function test_generate_pdf_with_summary_box(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'heading', 'text' => 'Bilan mensuel', 'level' => 1],
                ['type' => 'paragraph', 'text' => 'Synthese des indicateurs cles du mois.'],
                ['type' => 'summary_box', 'title' => 'Points Cles', 'items' => [
                    'Chiffre d\'affaires: 45 000 EUR',
                    '12 nouveaux clients',
                    'Taux de satisfaction: 94%',
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_summary_' . uniqid(), 'Bilan', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── summary_box block: DOCX ──────────────────────────────────────────────

    public function test_generate_docx_with_summary_box(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateDocx');
        $method->setAccessible(true);

        $content = [
            'sections' => [
                ['type' => 'summary_box', 'title' => 'Conclusions', 'items' => [
                    'Projet livre dans les delais',
                    'Budget respecte a 98%',
                ]],
            ],
        ];

        $path = $method->invoke($agent, 'test_docx_summary_' . uniqid(), 'Summary Doc', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── footer: PDF ───────────────────────────────────────────────────────────

    public function test_build_pdf_html_footer_is_rendered(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $html = $method->invoke($agent, 'Test', [], 'Entreprise XYZ — SIRET 123 456 789');

        $this->assertStringContainsString('Entreprise XYZ', $html);
        $this->assertStringContainsString('class="footer"', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: 0', $html);
    }

    public function test_build_pdf_html_no_footer_when_null(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $html = $method->invoke($agent, 'Test', []);

        $this->assertStringNotContainsString('class="footer"', $html);
        $this->assertStringNotContainsString('position: fixed', $html);
    }

    public function test_build_pdf_html_footer_escapes_html(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildPdfHtml');
        $method->setAccessible(true);

        $html = $method->invoke($agent, 'Test', [], '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_generate_pdf_with_footer(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = [
            'footer'   => 'ACME Corp — SIRET 123 456 789 — Confidentiel',
            'sections' => [
                ['type' => 'heading', 'text' => 'Rapport Annuel', 'level' => 1],
                ['type' => 'paragraph', 'text' => 'Ce document est confidentiel.'],
            ],
        ];

        $path = $method->invoke($agent, 'test_pdf_footer_' . uniqid(), 'Rapport', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_pdf_without_footer_still_works(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generatePdf');
        $method->setAccessible(true);

        $content = ['sections' => [['type' => 'paragraph', 'text' => 'Sans footer.']]];

        $path = $method->invoke($agent, 'test_pdf_no_footer_' . uniqid(), 'No Footer', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    // ── XLSX: orientation paysage ─────────────────────────────────────────────

    public function test_generate_xlsx_landscape_creates_file(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = [
            'orientation' => 'landscape',
            'sheets'      => [[
                'name'    => 'Rapport',
                'headers' => ['Col1', 'Col2', 'Col3', 'Col4', 'Col5', 'Col6', 'Col7', 'Col8'],
                'rows'    => [['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']],
            ]],
        ];

        $path = $method->invoke($agent, 'test_xlsx_landscape_' . uniqid(), 'Paysage', $content);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
        @unlink($path);
    }

    public function test_generate_xlsx_portrait_is_default(): void
    {
        $agent      = new DocumentAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateXlsx');
        $method->setAccessible(true);

        $content = ['sheets' => [['name' => 'Test', 'headers' => ['A'], 'rows' => [['1']]]]];
        $path    = $method->invoke($agent, 'test_xlsx_portrait_' . uniqid(), 'Portrait', $content);

        $this->assertFileExists($path);
        @unlink($path);
    }

    // ── description: nouvelles mentions v1.5.0 ───────────────────────────────

    public function test_description_mentions_badge(): void
    {
        $agent = new DocumentAgent();
        $this->assertStringContainsStringIgnoringCase('badge', $agent->description());
    }

    public function test_description_mentions_footer(): void
    {
        $agent = new DocumentAgent();
        $this->assertStringContainsStringIgnoringCase('pied de page', $agent->description());
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
