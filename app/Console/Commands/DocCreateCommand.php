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
    protected $description = 'Create a document (XLSX/CSV/PDF) and optionally send via WhatsApp';

    public function handle(): int
    {
        $spec = json_decode($this->argument('json'), true);
        if (!$spec) {
            $this->output->write(json_encode(['error' => 'Invalid JSON input']));
            return 1;
        }

        $format = $spec['format'] ?? 'xlsx';
        $title = $spec['title'] ?? 'Document';
        $headers = $spec['headers'] ?? [];
        $rows = $spec['rows'] ?? [];

        if (empty($headers) || empty($rows)) {
            $this->output->write(json_encode(['error' => 'headers and rows are required']));
            return 1;
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
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle(mb_substr($title, 0, 31));

                foreach ($headers as $col => $header) {
                    $letter = chr(65 + $col);
                    $sheet->setCellValue("{$letter}1", $header);
                    $sheet->getStyle("{$letter}1")->getFont()->setBold(true);
                }

                foreach ($rows as $rowIdx => $row) {
                    foreach ($row as $col => $value) {
                        $letter = chr(65 + $col);
                        $sheet->setCellValue("{$letter}" . ($rowIdx + 2), $value);
                    }
                }

                foreach (range(0, count($headers) - 1) as $col) {
                    $sheet->getColumnDimension(chr(65 + $col))->setAutoSize(true);
                }

                $writer = $format === 'xlsx' ? new Xlsx($spreadsheet) : new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
                $writer->save($storagePath);
            } elseif ($format === 'pdf') {
                $html = '<h1>' . e($title) . '</h1><table border="1" cellpadding="5"><tr>';
                foreach ($headers as $h) {
                    $html .= '<th>' . e($h) . '</th>';
                }
                $html .= '</tr>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($row as $val) {
                        $html .= '<td>' . e($val) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';

                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', true);
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->render();
                file_put_contents($storagePath, $dompdf->output());
            } else {
                $this->output->write(json_encode(['error' => "Format '{$format}' not supported. Use xlsx, csv, or pdf."]));
                return 1;
            }

            // Send via WhatsApp if chat ID provided
            $chatId = $this->option('chat-id');
            if ($chatId) {
                $this->sendFileToWhatsApp($chatId, $storagePath, $filename, $title);
            }

            $this->output->write(json_encode([
                'success' => true,
                'filename' => $filename,
                'format' => $format,
                'rows_count' => count($rows),
                'columns_count' => count($headers),
                'sent_to_whatsapp' => (bool) $chatId,
                'message' => "Document '{$title}' created ({$format}, " . count($rows) . " rows).",
            ], JSON_UNESCAPED_UNICODE));

            return 0;
        } catch (\Exception $e) {
            Log::error('[doc:create] Error: ' . $e->getMessage());
            $this->output->write(json_encode(['error' => 'Document creation failed: ' . $e->getMessage()]));
            return 1;
        }
    }

    private function sendFileToWhatsApp(string $chatId, string $filePath, string $filename, string $caption): void
    {
        try {
            $data = base64_encode(file_get_contents($filePath));
            $mimetype = mime_content_type($filePath) ?: 'application/octet-stream';

            Http::withHeaders(['Authorization' => 'Bearer zeniclaw-waha-2026'])
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
