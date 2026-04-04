<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DocCreateCommand extends Command
{
    protected $signature = 'doc:create {json} {--chat-id= : WhatsApp chat ID to send the file to}';
    protected $description = 'Create a document (XLSX/CSV/PDF) with optional multiple sheets and send via WhatsApp';

    public function handle(): int
    {
        $spec = json_decode($this->argument('json'), true);
        if (!$spec) {
            $this->output->write(json_encode(['error' => 'Invalid JSON input']));
            return 1;
        }

        $format = $spec['format'] ?? 'xlsx';
        $title = $spec['title'] ?? 'Document';

        // Support both single-sheet (headers+rows) and multi-sheet (sheets array)
        $sheets = $spec['sheets'] ?? null;
        if (!$sheets) {
            $headers = $spec['headers'] ?? [];
            $rows = $spec['rows'] ?? [];
            if (empty($headers) || empty($rows)) {
                $this->output->write(json_encode(['error' => 'headers+rows or sheets array required']));
                return 1;
            }
            $sheets = [['name' => $title, 'headers' => $headers, 'rows' => $rows]];
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title));
        $filename = $slug . '.' . $format;
        $storagePath = storage_path('app/public/documents/' . $filename);

        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            if (in_array($format, ['xlsx', 'csv'])) {
                $this->buildSpreadsheet($sheets, $storagePath, $format);
            } elseif ($format === 'pdf') {
                $this->buildPdf($sheets, $title, $storagePath);
            } else {
                $this->output->write(json_encode(['error' => "Format '{$format}' not supported."]));
                return 1;
            }

            $chatId = $this->option('chat-id');
            if ($chatId) {
                $this->sendFileToWhatsApp($chatId, $storagePath, $filename, $title);
            }

            $totalRows = array_sum(array_map(fn($s) => count($s['rows'] ?? []), $sheets));

            $this->output->write(json_encode([
                'success' => true,
                'filename' => $filename,
                'format' => $format,
                'sheets_count' => count($sheets),
                'total_rows' => $totalRows,
                'sent_to_whatsapp' => (bool) $chatId,
                'message' => "Document '{$title}' created ({$format}, " . count($sheets) . " sheets, {$totalRows} rows).",
            ], JSON_UNESCAPED_UNICODE));

            return 0;
        } catch (\Exception $e) {
            Log::error('[doc:create] Error: ' . $e->getMessage());
            $this->output->write(json_encode(['error' => 'Document creation failed: ' . $e->getMessage()]));
            return 1;
        }
    }

    private function buildSpreadsheet(array $sheets, string $storagePath, string $format): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // remove default sheet

        foreach ($sheets as $idx => $sheetSpec) {
            $sheetName = mb_substr($sheetSpec['name'] ?? 'Sheet ' . ($idx + 1), 0, 31);
            $headers = $sheetSpec['headers'] ?? [];
            $rows = $sheetSpec['rows'] ?? [];

            $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $sheetName);
            $spreadsheet->addSheet($sheet, $idx);

            // Headers
            foreach ($headers as $col => $header) {
                $letter = $this->colLetter($col);
                $sheet->setCellValue("{$letter}1", $header);
                $sheet->getStyle("{$letter}1")->getFont()->setBold(true);
                $sheet->getStyle("{$letter}1")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
                $sheet->getStyle("{$letter}1")->getFont()->getColor()->setRGB('FFFFFF');
            }

            // Freeze header row
            if (!empty($headers)) {
                $sheet->freezePane('A2');
            }

            // Data rows
            foreach ($rows as $rowIdx => $row) {
                foreach ($row as $col => $value) {
                    $letter = $this->colLetter($col);
                    $cell = "{$letter}" . ($rowIdx + 2);
                    if (is_numeric($value) && !str_starts_with((string) $value, '0')) {
                        $sheet->setCellValue($cell, (float) $value);
                    } else {
                        $sheet->setCellValue($cell, $value);
                    }
                }
                // Alternating row colors
                if ($rowIdx % 2 === 0 && !empty($headers)) {
                    $lastCol = $this->colLetter(count($headers) - 1);
                    $sheet->getStyle("A" . ($rowIdx + 2) . ":{$lastCol}" . ($rowIdx + 2))
                        ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('D9E2F3');
                }
            }

            // Auto-size columns
            foreach (range(0, max(count($headers) - 1, 0)) as $col) {
                $sheet->getColumnDimension($this->colLetter($col))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = $format === 'xlsx' ? new Xlsx($spreadsheet) : new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->save($storagePath);
    }

    private function buildPdf(array $sheets, string $title, string $storagePath): void
    {
        $html = '<h1>' . e($title) . '</h1>';
        foreach ($sheets as $sheetSpec) {
            $html .= '<h2>' . e($sheetSpec['name'] ?? 'Data') . '</h2>';
            $html .= '<table border="1" cellpadding="5"><tr>';
            foreach ($sheetSpec['headers'] ?? [] as $h) {
                $html .= '<th>' . e($h) . '</th>';
            }
            $html .= '</tr>';
            foreach ($sheetSpec['rows'] ?? [] as $row) {
                $html .= '<tr>';
                foreach ($row as $val) {
                    $html .= '<td>' . e($val) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table><br>';
        }

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();
        file_put_contents($storagePath, $dompdf->output());
    }

    private function colLetter(int $col): string
    {
        $letter = '';
        while ($col >= 0) {
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = intdiv($col, 26) - 1;
        }
        return $letter;
    }

    private function sendFileToWhatsApp(string $chatId, string $filePath, string $filename, string $caption): void
    {
        try {
            $data = base64_encode(file_get_contents($filePath));
            $mimetype = mime_content_type($filePath) ?: 'application/octet-stream';

            Http::withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->timeout(30)
                ->post('http://waha:3000/api/sendFile', [
                    'chatId' => $chatId,
                    'file' => [
                        'data' => $data,
                        'filename' => $filename,
                        'mimetype' => $mimetype,
                    ],
                    'caption' => $caption,
                    'session' => 'default',
                ]);
        } catch (\Exception $e) {
            Log::error('[doc:create] sendFile failed: ' . $e->getMessage());
        }
    }
}
