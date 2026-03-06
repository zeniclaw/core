<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class DocumentAgent extends BaseAgent
{
    public function name(): string
    {
        return 'document';
    }

    public function description(): string
    {
        return 'Creation de documents: Excel (XLSX), PDF, Word (DOCX). Tableaux, rapports, lettres, factures, CV.';
    }

    public function keywords(): array
    {
        return [
            'excel', 'xlsx', 'tableau', 'spreadsheet', 'csv',
            'pdf', 'document pdf', 'rapport pdf', 'genere pdf',
            'word', 'docx', 'lettre', 'document word',
            'facture', 'invoice', 'devis', 'quote',
            'cv', 'resume', 'curriculum',
            'rapport', 'report', 'bilan',
            'cree un fichier', 'genere un document', 'exporter',
            'tableau excel', 'feuille de calcul',
            'contrat', 'attestation', 'certificat',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return (bool) $context->body;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $systemPrompt = <<<'PROMPT'
Tu es un agent de creation de documents. Analyse la demande et genere le contenu structure.

Reponds UNIQUEMENT en JSON valide:
{
    "format": "xlsx" | "pdf" | "docx",
    "filename": "nom_du_fichier",
    "title": "Titre du document",
    "content": { ... }
}

REGLES PAR FORMAT:

**XLSX** (tableaux, listes, donnees):
{
    "format": "xlsx",
    "filename": "budget_2026",
    "title": "Budget 2026",
    "content": {
        "sheets": [
            {
                "name": "Feuille1",
                "headers": ["Colonne A", "Colonne B", "Colonne C"],
                "rows": [
                    ["valeur1", "valeur2", "valeur3"],
                    ["valeur4", "valeur5", "valeur6"]
                ]
            }
        ]
    }
}

**PDF** (rapports, factures, lettres):
{
    "format": "pdf",
    "filename": "facture_001",
    "title": "Facture #001",
    "content": {
        "sections": [
            {"type": "heading", "text": "Titre"},
            {"type": "paragraph", "text": "Texte du paragraphe..."},
            {"type": "table", "headers": ["Col1", "Col2"], "rows": [["a", "b"]]},
            {"type": "list", "items": ["Item 1", "Item 2"]},
            {"type": "separator"},
            {"type": "bold", "text": "Texte en gras"}
        ]
    }
}

**DOCX** (lettres, contrats, CV):
{
    "format": "docx",
    "filename": "lettre_motivation",
    "title": "Lettre de motivation",
    "content": {
        "sections": [
            {"type": "heading", "text": "Titre", "level": 1},
            {"type": "heading", "text": "Sous-titre", "level": 2},
            {"type": "paragraph", "text": "Texte..."},
            {"type": "list", "items": ["Item 1", "Item 2"]},
            {"type": "table", "headers": ["Col1", "Col2"], "rows": [["a", "b"]]}
        ]
    }
}

IMPORTANT:
- Choisis le format le plus adapte a la demande
- Genere du contenu realiste et complet (pas de placeholder)
- Pour les factures: inclus TVA, totaux, etc.
- Pour les CV: structure professionnelle
- filename sans extension (elle sera ajoutee)
PROMPT;

        $userContext = $this->formatContextMemoryForPrompt($context->from);
        $message = $context->body;
        if ($userContext) {
            $message = "{$userContext}\n\nDemande: {$message}";
        }

        try {
            $response = $this->claude->chat($message, $model, $systemPrompt);
            $spec = $this->parseJson($response);

            if (!$spec || !isset($spec['format'])) {
                $this->sendText($context->from, "Je n'ai pas compris quel document creer. Precise le type (Excel, PDF, Word) et le contenu souhaite.");
                return AgentResult::reply("Format non reconnu");
            }

            $format = strtolower($spec['format']);
            $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $spec['filename'] ?? 'document');
            $title = $spec['title'] ?? 'Document';
            $content = $spec['content'] ?? [];

            $filePath = match ($format) {
                'xlsx' => $this->generateXlsx($filename, $title, $content),
                'pdf' => $this->generatePdf($filename, $title, $content),
                'docx' => $this->generateDocx($filename, $title, $content),
                default => null,
            };

            if (!$filePath) {
                $this->sendText($context->from, "Format non supporte: {$format}. Formats disponibles: xlsx, pdf, docx.");
                return AgentResult::reply("Format non supporte");
            }

            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $finalName = "{$filename}.{$ext}";

            $this->sendFile($context->from, $filePath, $finalName, "📄 {$title}");
            @unlink($filePath);

            $this->log($context, "Document created", [
                'format' => $format,
                'filename' => $finalName,
                'title' => $title,
            ]);

            $this->sendText($context->from, "✅ Document *{$title}* ({$ext}) envoye !");
            return AgentResult::reply("Document {$finalName} cree et envoye");

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("DocumentAgent error: " . $e->getMessage());
            $this->sendText($context->from, "Erreur lors de la creation du document: " . $e->getMessage());
            return AgentResult::reply("Erreur: " . $e->getMessage());
        }
    }

    private function generateXlsx(string $filename, string $title, array $content): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setTitle($title);

        $sheets = $content['sheets'] ?? [['name' => 'Sheet1', 'headers' => [], 'rows' => []]];

        foreach ($sheets as $i => $sheetData) {
            if ($i > 0) {
                $spreadsheet->createSheet();
            }
            $sheet = $spreadsheet->setActiveSheetIndex($i);
            $sheet->setTitle(mb_substr($sheetData['name'] ?? "Feuille " . ($i + 1), 0, 31));

            $headers = $sheetData['headers'] ?? [];
            $rows = $sheetData['rows'] ?? [];

            // Write headers with bold style
            foreach ($headers as $col => $header) {
                $cell = $sheet->getCellByColumnAndRow($col + 1, 1);
                $cell->setValue($header);
                $cell->getStyle()->getFont()->setBold(true);
                $cell->getStyle()->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
                $cell->getStyle()->getFont()->getColor()->setRGB('FFFFFF');
            }

            // Write data rows
            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $col => $value) {
                    $cellValue = is_numeric(str_replace([',', ' '], ['.', ''], (string) $value))
                        ? (float) str_replace([',', ' '], ['.', ''], (string) $value)
                        : $value;
                    $sheet->setCellValueByColumnAndRow($col + 1, $rowIndex + 2, $cellValue);
                }
            }

            // Auto-size columns
            foreach (range(1, max(count($headers), 1)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path("app/{$filename}.xlsx");
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function generatePdf(string $filename, string $title, array $content): string
    {
        $sections = $content['sections'] ?? [];
        $html = $this->buildPdfHtml($title, $sections);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $path = storage_path("app/{$filename}.pdf");
        file_put_contents($path, $dompdf->output());

        return $path;
    }

    private function buildPdfHtml(string $title, array $sections): string
    {
        $body = '';

        foreach ($sections as $section) {
            $type = $section['type'] ?? 'paragraph';
            $text = htmlspecialchars($section['text'] ?? '', ENT_QUOTES, 'UTF-8');

            switch ($type) {
                case 'heading':
                    $level = $section['level'] ?? 1;
                    $body .= "<h{$level}>{$text}</h{$level}>";
                    break;

                case 'paragraph':
                    $body .= "<p>{$text}</p>";
                    break;

                case 'bold':
                    $body .= "<p><strong>{$text}</strong></p>";
                    break;

                case 'table':
                    $body .= '<table><thead><tr>';
                    foreach ($section['headers'] ?? [] as $h) {
                        $body .= '<th>' . htmlspecialchars($h) . '</th>';
                    }
                    $body .= '</tr></thead><tbody>';
                    foreach ($section['rows'] ?? [] as $row) {
                        $body .= '<tr>';
                        foreach ($row as $cell) {
                            $body .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
                        }
                        $body .= '</tr>';
                    }
                    $body .= '</tbody></table>';
                    break;

                case 'list':
                    $body .= '<ul>';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li>' . htmlspecialchars($item) . '</li>';
                    }
                    $body .= '</ul>';
                    break;

                case 'separator':
                    $body .= '<hr>';
                    break;
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #333; margin: 40px; line-height: 1.5; }
    h1 { font-size: 20pt; color: #1a1a2e; border-bottom: 2px solid #4472C4; padding-bottom: 8px; margin-bottom: 16px; }
    h2 { font-size: 14pt; color: #2d3748; margin-top: 20px; }
    h3 { font-size: 12pt; color: #4a5568; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    th { background: #4472C4; color: white; padding: 8px 12px; text-align: left; font-size: 10pt; }
    td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; font-size: 10pt; }
    tr:nth-child(even) { background: #f7fafc; }
    ul { margin: 8px 0; padding-left: 24px; }
    li { margin: 4px 0; }
    hr { border: none; border-top: 1px solid #e2e8f0; margin: 16px 0; }
    p { margin: 8px 0; }
</style>
</head>
<body>
<h1>{$title}</h1>
{$body}
</body>
</html>
HTML;
    }

    private function generateDocx(string $filename, string $title, array $content): string
    {
        $phpWord = new PhpWord();

        // Default styles
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection();

        // Title
        $section->addTitle($title, 1);

        $sections = $content['sections'] ?? [];

        foreach ($sections as $item) {
            $type = $item['type'] ?? 'paragraph';

            switch ($type) {
                case 'heading':
                    $level = min($item['level'] ?? 2, 3);
                    $section->addTitle($item['text'] ?? '', $level);
                    break;

                case 'paragraph':
                    $section->addText($item['text'] ?? '', ['size' => 11]);
                    break;

                case 'bold':
                    $section->addText($item['text'] ?? '', ['bold' => true, 'size' => 11]);
                    break;

                case 'list':
                    foreach ($item['items'] ?? [] as $li) {
                        $section->addListItem($li, 0);
                    }
                    break;

                case 'table':
                    $headers = $item['headers'] ?? [];
                    $rows = $item['rows'] ?? [];

                    $table = $section->addTable([
                        'borderSize' => 6,
                        'borderColor' => 'CCCCCC',
                        'cellMargin' => 80,
                    ]);

                    // Header row
                    $table->addRow();
                    foreach ($headers as $h) {
                        $cell = $table->addCell(2500, ['bgColor' => '4472C4']);
                        $cell->addText($h, ['bold' => true, 'color' => 'FFFFFF', 'size' => 10]);
                    }

                    // Data rows
                    foreach ($rows as $row) {
                        $table->addRow();
                        foreach ($row as $cellVal) {
                            $table->addCell(2500)->addText((string) $cellVal, ['size' => 10]);
                        }
                    }

                    $section->addTextBreak();
                    break;

                case 'separator':
                    $section->addTextBreak();
                    break;
            }
        }

        $path = storage_path("app/{$filename}.docx");
        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);

        return $path;
    }

    private function parseJson(string $response): ?array
    {
        // Extract JSON from response (may be wrapped in markdown code blocks)
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $m)) {
            $response = $m[1];
        }

        $response = trim($response);
        $decoded = json_decode($response, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to find JSON object in response
        if (preg_match('/\{.*\}/s', $response, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
