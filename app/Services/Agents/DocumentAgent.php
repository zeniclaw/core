<?php

namespace App\Services\Agents;

use App\Models\UserKnowledge;
use App\Services\AgentContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Style\ListItem as ListItemStyle;

class DocumentAgent extends BaseAgent
{
    /** Maximum characters for the filename slug (without extension). */
    private const FILENAME_MAX_LENGTH = 80;

    public function name(): string
    {
        return 'document';
    }

    public function description(): string
    {
        return 'Creation de documents: Excel (XLSX), PDF, Word (DOCX), CSV. Tableaux, rapports, lettres, factures, CV.';
    }

    public function keywords(): array
    {
        return [
            'excel', 'xlsx', 'tableau', 'spreadsheet',
            'csv', 'export csv', 'exporte en csv', 'fichier csv',
            'pdf', 'document pdf', 'rapport pdf', 'genere pdf',
            'word', 'docx', 'lettre', 'document word',
            'facture', 'invoice', 'devis', 'quote',
            'cv', 'resume', 'curriculum',
            'rapport', 'report', 'bilan',
            'cree un fichier', 'genere un document', 'exporter',
            'tableau excel', 'feuille de calcul',
            'contrat', 'attestation', 'certificat',
            'liste numerotee', 'liste ordonnee',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return (bool) $context->body;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $systemPrompt = <<<'PROMPT'
Tu es un agent expert en creation de documents professionnels. Analyse la demande et genere une structure JSON complete et realiste.

Reponds UNIQUEMENT avec du JSON valide (sans markdown, sans explication):
{
    "format": "xlsx" | "pdf" | "docx" | "csv",
    "filename": "nom_sans_extension",
    "title": "Titre complet du document",
    "content": { ... }
}

---
FORMAT XLSX (tableaux, budgets, inventaires, donnees chiffrees):
{
    "format": "xlsx",
    "filename": "budget_2026",
    "title": "Budget Previsionnel 2026",
    "content": {
        "sheets": [
            {
                "name": "Revenus",
                "headers": ["Categorie", "Jan", "Fev", "Mar", "Total"],
                "rows": [
                    ["Ventes produits", 12000, 15000, 11000, 38000],
                    ["Services", 5000, 4500, 6000, 15500],
                    ["TOTAL", 17000, 19500, 17000, 53500]
                ]
            },
            {
                "name": "Depenses",
                "headers": ["Poste", "Montant mensuel", "Annuel"],
                "rows": [
                    ["Loyer", 2000, 24000],
                    ["Salaires", 8000, 96000]
                ]
            }
        ]
    }
}

---
FORMAT CSV (export simple, listes, donnees brutes):
{
    "format": "csv",
    "filename": "clients_2026",
    "title": "Export clients 2026",
    "content": {
        "headers": ["Nom", "Email", "Telephone", "Ville", "Statut"],
        "rows": [
            ["Alice Dupont", "alice@exemple.com", "0612345678", "Paris", "Actif"],
            ["Bob Martin", "bob@exemple.com", "0698765432", "Lyon", "Inactif"]
        ]
    }
}

---
FORMAT PDF (rapports, factures, lettres formelles):
{
    "format": "pdf",
    "filename": "facture_2026_001",
    "title": "Facture #2026-001",
    "content": {
        "sections": [
            {"type": "heading", "text": "Informations client", "level": 2},
            {"type": "paragraph", "text": "Client: ACME Corp — 12 rue de la Paix, 75001 Paris"},
            {"type": "separator"},
            {"type": "table", "headers": ["Designation", "Qte", "PU HT", "Total HT"], "rows": [
                ["Developpement web", "10h", "150 EUR", "1 500 EUR"],
                ["Design UI/UX", "5h", "120 EUR", "600 EUR"]
            ]},
            {"type": "separator"},
            {"type": "bold", "text": "Sous-total HT: 2 100 EUR"},
            {"type": "bold", "text": "TVA 20%: 420 EUR"},
            {"type": "bold", "text": "TOTAL TTC: 2 520 EUR"},
            {"type": "ordered_list", "items": ["Paiement sous 30 jours", "Penalites de retard: 3x le taux legal en vigueur"]},
            {"type": "page_break"},
            {"type": "heading", "text": "Conditions Generales", "level": 2},
            {"type": "paragraph", "text": "..."}
        ]
    }
}

---
FORMAT DOCX (lettres, contrats, CV, rapports editables):
{
    "format": "docx",
    "filename": "cv_jean_dupont",
    "title": "CV - Jean Dupont",
    "content": {
        "sections": [
            {"type": "heading", "text": "Experience Professionnelle", "level": 1},
            {"type": "heading", "text": "Developpeur Senior — ACME Corp (2022-2026)", "level": 2},
            {"type": "list", "items": ["Developpement d'API REST avec Laravel 12", "Management d'une equipe de 3 developpeurs"]},
            {"type": "separator"},
            {"type": "heading", "text": "Competences Techniques", "level": 1},
            {"type": "ordered_list", "items": ["PHP / Laravel (Expert)", "JavaScript / Vue.js (Avance)", "Docker / CI-CD (Intermediaire)"]},
            {"type": "table", "headers": ["Competence", "Niveau", "Annees exp."], "rows": [
                ["PHP Laravel", "Expert", "6 ans"],
                ["Python", "Intermediaire", "3 ans"]
            ]},
            {"type": "page_break"},
            {"type": "heading", "text": "Formation", "level": 1},
            {"type": "paragraph", "text": "Master Informatique — Universite Paris XI (2018-2020)"}
        ]
    }
}

---
REGLES STRICTES:
- Reponds UNIQUEMENT avec le JSON brut (jamais de ```json```, jamais de commentaire avant/apres)
- Genere du contenu COMPLET et REALISTE (jamais de placeholder "Item 1", "Valeur X", etc.)
- Pour les factures: inclure references, dates, TVA, totaux TTC, IBAN
- Pour les CV: structure professionnelle complete (experience, formation, competences, langues, contact)
- Pour les rapports: introduction, corps, conclusion
- filename: uniquement lettres, chiffres, tirets, underscores — PAS d'espaces, PAS d'extension
- Si des DONNEES STRUCTUREES sont fournies, utilise-les EN TOTALITE (inclure toutes les entrees, pas un resume)
- Si l'utilisateur mentionne "cette liste", "ces clients", etc., cherche en priorite dans les donnees structurees
- N'invente JAMAIS de donnees non mentionnees par l'utilisateur
PROMPT;

        $userContext         = $this->formatContextMemoryForPrompt($context->from);
        $knowledgeData       = $this->getStoredKnowledge($context);
        $conversationHistory = $this->getRecentConversationHistory($context);
        $message             = $context->body;

        $preamble = '';
        if ($userContext) {
            $preamble .= "{$userContext}\n\n";
        }
        if ($knowledgeData) {
            $preamble .= "DONNEES STRUCTUREES DISPONIBLES (donnees brutes API/stockees — utilise-les en priorite):\n{$knowledgeData}\n\n";
        }
        if ($conversationHistory) {
            $preamble .= "HISTORIQUE RECENT DE LA CONVERSATION:\n{$conversationHistory}\n\n";
        }
        if ($preamble) {
            $message = "{$preamble}Demande: {$message}";
        }

        try {
            $response = $this->claude->chat($message, $model, $systemPrompt);

            if (!$response) {
                $this->sendText($context->from, "⚠️ Le modele IA n'a pas repondu. Verifie qu'un modele est telecharge dans Parametres > On-Prem / Ollama.");
                return AgentResult::reply("LLM returned null — model may not be downloaded");
            }

            $spec = $this->parseJson($response);

            if (!$spec || !isset($spec['format'])) {
                $this->sendText($context->from, "❓ Je n'ai pas compris quel document creer. Precise le type souhaite (Excel, PDF, Word ou CSV) et le contenu voulu.");
                return AgentResult::reply("Format non reconnu dans la reponse LLM");
            }

            $format   = strtolower(trim($spec['format']));
            $rawName  = $spec['filename'] ?? 'document';
            $filename = mb_substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName), 0, self::FILENAME_MAX_LENGTH);
            $filename = $filename ?: 'document';
            $title    = $spec['title'] ?? 'Document';
            $content  = $spec['content'] ?? [];

            $filePath = match ($format) {
                'xlsx' => $this->generateXlsx($filename, $title, $content),
                'csv'  => $this->generateCsv($filename, $content),
                'pdf'  => $this->generatePdf($filename, $title, $content),
                'docx' => $this->generateDocx($filename, $title, $content),
                default => null,
            };

            if (!$filePath) {
                $this->sendText($context->from, "❌ Format non supporte: *{$format}*. Formats disponibles: xlsx, csv, pdf, docx.");
                return AgentResult::reply("Format non supporte: {$format}");
            }

            // Validate the file was actually created and is not empty
            if (!file_exists($filePath) || filesize($filePath) === 0) {
                @unlink($filePath);
                $this->sendText($context->from, "❌ Erreur lors de la generation du document. Veuillez reformuler votre demande.");
                return AgentResult::reply("Generated file is empty or missing");
            }

            $ext       = pathinfo($filePath, PATHINFO_EXTENSION);
            $finalName = "{$filename}.{$ext}";
            $isWeb     = str_starts_with($context->from, 'web-');
            $fileUrl   = null;

            if ($isWeb) {
                $docDir = storage_path('app/public/documents');
                if (!is_dir($docDir)) {
                    mkdir($docDir, 0775, true);
                }
                $uniqueName = uniqid() . '_' . $finalName;
                $destPath   = "{$docDir}/{$uniqueName}";
                // copy+unlink instead of rename for cross-filesystem safety
                copy($filePath, $destPath);
                @unlink($filePath);
                $fileUrl = url("storage/documents/{$uniqueName}");
            } else {
                $this->sendFile($context->from, $filePath, $finalName, $title);
                @unlink($filePath);
            }

            $this->log($context, "Document created", [
                'format'   => $format,
                'filename' => $finalName,
                'title'    => $title,
            ]);

            $replyText = "Document *{$title}* ({$ext}) cree avec succes !";
            if (!$isWeb) {
                $this->sendText($context->from, "✅ {$replyText}");
            }

            return AgentResult::reply($replyText, [
                'files' => $fileUrl ? [[
                    'url'    => $fileUrl,
                    'name'   => $finalName,
                    'format' => $format,
                    'title'  => $title,
                ]] : [],
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("DocumentAgent error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'from'  => $context->from,
            ]);
            $this->sendText($context->from, "❌ Une erreur est survenue lors de la creation du document. Veuillez reformuler votre demande ou reessayer.");
            return AgentResult::reply("Erreur interne: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // XLSX
    // ─────────────────────────────────────────────────────────────────────────

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
            $rows    = $sheetData['rows'] ?? [];

            // Write headers with bold + blue background
            foreach ($headers as $col => $header) {
                $colLetter = Coordinate::stringFromColumnIndex($col + 1);
                $cell      = $sheet->getCell("{$colLetter}1");
                $cell->setValue($header);
                $style = $cell->getStyle();
                $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $style->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
            }

            // Write data rows with alternating background
            foreach ($rows as $rowIndex => $row) {
                $bgColor = ($rowIndex % 2 === 1) ? 'EEF2FB' : 'FFFFFF';
                foreach ($row as $col => $value) {
                    $colLetter = Coordinate::stringFromColumnIndex($col + 1);
                    $cellRef   = "{$colLetter}" . ($rowIndex + 2);
                    $cell      = $sheet->getCell($cellRef);

                    // Convert to float only for pure numeric values (avoids mangling dates, codes, phone numbers)
                    $strValue = trim((string) $value);
                    if (preg_match('/^-?\d+([.,]\d+)?$/', $strValue)) {
                        $cell->setValue((float) str_replace(',', '.', $strValue));
                    } else {
                        $cell->setValue($strValue);
                    }

                    // Alternating row background
                    $cell->getStyle()->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($bgColor);
                }
            }

            // Auto-size all columns (based on headers AND row data)
            $maxCols = max(
                count($headers),
                !empty($rows) ? max(array_map('count', $rows)) : 0,
                1
            );
            foreach (range(1, $maxCols) as $col) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path   = storage_path("app/{$filename}.xlsx");
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV  (new in v1.1.0)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a CSV file with UTF-8 BOM so Excel opens it correctly.
     * Uses semicolon delimiter (standard in France / Europe).
     */
    private function generateCsv(string $filename, array $content): string
    {
        $headers = $content['headers'] ?? [];
        $rows    = $content['rows'] ?? [];

        $path = storage_path("app/{$filename}.csv");
        $fh   = fopen($path, 'w');

        // UTF-8 BOM — required for correct accented-character display in Excel
        fwrite($fh, "\xEF\xBB\xBF");

        if (!empty($headers)) {
            fputcsv($fh, $headers, ';');
        }

        foreach ($rows as $row) {
            fputcsv($fh, array_map('strval', (array) $row), ';');
        }

        fclose($fh);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF
    // ─────────────────────────────────────────────────────────────────────────

    private function generatePdf(string $filename, string $title, array $content): string
    {
        $sections = $content['sections'] ?? [];
        $html     = $this->buildPdfHtml($title, $sections);

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
                    $level = max(1, min(6, (int) ($section['level'] ?? 1)));
                    $body .= "<h{$level}>{$text}</h{$level}>";
                    break;

                case 'paragraph':
                    $body .= "<p>{$text}</p>";
                    break;

                case 'bold':
                    $body .= "<p><strong>{$text}</strong></p>";
                    break;

                case 'italic':
                    $body .= "<p><em>{$text}</em></p>";
                    break;

                case 'table':
                    $body .= '<table><thead><tr>';
                    foreach ($section['headers'] ?? [] as $h) {
                        $body .= '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
                    }
                    $body .= '</tr></thead><tbody>';
                    foreach ($section['rows'] ?? [] as $rowIdx => $row) {
                        $rowClass = ($rowIdx % 2 === 1) ? ' class="alt"' : '';
                        $body .= "<tr{$rowClass}>";
                        foreach ($row as $cell) {
                            $body .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                        }
                        $body .= '</tr>';
                    }
                    $body .= '</tbody></table>';
                    break;

                case 'list':
                    $body .= '<ul>';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $body .= '</ul>';
                    break;

                case 'ordered_list':
                    $body .= '<ol>';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $body .= '</ol>';
                    break;

                case 'separator':
                    $body .= '<hr>';
                    break;

                case 'page_break':
                    $body .= '<div class="page-break"></div>';
                    break;
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #333; margin: 40px; line-height: 1.6; }
    h1 { font-size: 20pt; color: #1a1a2e; border-bottom: 2px solid #4472C4; padding-bottom: 8px; margin-bottom: 16px; margin-top: 24px; }
    h2 { font-size: 14pt; color: #2d3748; margin-top: 20px; margin-bottom: 8px; }
    h3 { font-size: 12pt; color: #4a5568; margin-top: 14px; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 10pt; }
    th { background: #4472C4; color: white; padding: 8px 12px; text-align: left; }
    td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; }
    tr.alt td { background: #f7fafc; }
    ul, ol { margin: 8px 0; padding-left: 24px; }
    li { margin: 4px 0; }
    hr { border: none; border-top: 1px solid #cbd5e0; margin: 16px 0; }
    p { margin: 8px 0; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
<h1>{$title}</h1>
{$body}
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOCX
    // ─────────────────────────────────────────────────────────────────────────

    private function generateDocx(string $filename, string $title, array $content): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection();
        $section->addTitle($title, 1);

        $sections = $content['sections'] ?? [];

        foreach ($sections as $item) {
            $type = $item['type'] ?? 'paragraph';

            switch ($type) {
                case 'heading':
                    $level = min((int) ($item['level'] ?? 2), 3);
                    $section->addTitle($item['text'] ?? '', $level);
                    break;

                case 'paragraph':
                    $section->addText($item['text'] ?? '', ['size' => 11]);
                    break;

                case 'bold':
                    $section->addText($item['text'] ?? '', ['bold' => true, 'size' => 11]);
                    break;

                case 'italic':
                    $section->addText($item['text'] ?? '', ['italic' => true, 'size' => 11]);
                    break;

                case 'list':
                    foreach ($item['items'] ?? [] as $li) {
                        $section->addListItem((string) $li, 0);
                    }
                    break;

                case 'ordered_list':
                    foreach ($item['items'] ?? [] as $li) {
                        $section->addListItem((string) $li, 0, null, ['listType' => ListItemStyle::TYPE_NUMBER]);
                    }
                    break;

                case 'table':
                    $headers = $item['headers'] ?? [];
                    $rows    = $item['rows'] ?? [];

                    $table = $section->addTable([
                        'borderSize'  => 6,
                        'borderColor' => 'CCCCCC',
                        'cellMargin'  => 80,
                    ]);

                    if (!empty($headers)) {
                        $table->addRow();
                        foreach ($headers as $h) {
                            $cell = $table->addCell(2500, ['bgColor' => '4472C4']);
                            $cell->addText((string) $h, ['bold' => true, 'color' => 'FFFFFF', 'size' => 10]);
                        }
                    }

                    foreach ($rows as $row) {
                        $table->addRow();
                        foreach ($row as $cellVal) {
                            $table->addCell(2500)->addText((string) $cellVal, ['size' => 10]);
                        }
                    }

                    $section->addTextBreak();
                    break;

                case 'separator':
                    $section->addTextBreak(1);
                    break;

                case 'page_break':
                    $section->addText('', null, ['pageBreakBefore' => true]);
                    break;
            }
        }

        $path   = storage_path("app/{$filename}.docx");
        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get stored structured data (UserKnowledge) — raw API data from other agents.
     * This is the primary data source for document generation.
     */
    private function getStoredKnowledge(AgentContext $context): string
    {
        $entries = UserKnowledge::allFor($context->from);

        if ($entries->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($entries->take(10) as $entry) {
            $label    = $entry->label ?? $entry->topic_key;
            $source   = $entry->source ? " (source: {$entry->source})" : '';
            $dataJson = json_encode($entry->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $dataJson = mb_substr($dataJson, 0, 4000);
            $lines[]  = "--- [{$entry->topic_key}] {$label}{$source} ---\n{$dataJson}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Get recent conversation history so the DocumentAgent can reference data
     * produced by previous agents in the same session.
     */
    private function getRecentConversationHistory(AgentContext $context): string
    {
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $entries    = $memoryData['entries'] ?? [];

        if (empty($entries)) {
            return '';
        }

        $recent = array_slice($entries, -5);
        $lines  = [];

        foreach ($recent as $entry) {
            $sender = $entry['sender'] ?? 'Utilisateur';
            $msg    = $entry['sender_message'] ?? '';
            $reply  = $entry['agent_reply'] ?? '';

            if ($msg) {
                $lines[] = "{$sender}: {$msg}";
            }
            if ($reply) {
                $lines[] = "ZeniClaw: {$reply}";
            }
        }

        return implode("\n", $lines);
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        // Strip markdown code blocks if present
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $m)) {
            $response = $m[1];
        }

        $response = trim($response);
        $decoded  = json_decode($response, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to extract a JSON object from the response
        if (preg_match('/\{.*\}/s', $response, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
