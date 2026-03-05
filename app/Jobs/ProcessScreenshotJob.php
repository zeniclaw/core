<?php

namespace App\Jobs;

use App\Services\ImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessScreenshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        private string $chatId,
        private int $agentId,
        private string $mediaUrl,
        private string $action,
        private array $params = [],
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            $processor = new ImageProcessor();

            $imagePath = $processor->downloadFromWaha($this->mediaUrl);
            if (!$imagePath) {
                $this->sendReply("Erreur : impossible de telecharger l'image.");
                return;
            }

            $result = match ($this->action) {
                'annotate' => $this->processAnnotation($processor, $imagePath),
                'compare' => $this->processComparison($processor, $imagePath),
                'extract-text' => $this->processOcr($processor, $imagePath),
                default => "Action inconnue: {$this->action}",
            };

            @unlink($imagePath);

            if (is_string($result)) {
                $this->sendReply($result);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessScreenshotJob failed: ' . $e->getMessage(), [
                'action' => $this->action,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendReply("Erreur lors du traitement de l'image. Reessaie.");
        }
    }

    private function processAnnotation(ImageProcessor $processor, string $imagePath): string
    {
        $annotations = [];
        $type = $this->params['type'] ?? 'rectangle';
        $color = $this->params['color'] ?? 'red';
        $coords = $this->params['coordinates'] ?? [];

        if (empty($coords)) {
            // Auto-annotate: get image info and place annotation in center
            $info = $processor->getImageInfo($imagePath);
            $w = $info['width'] ?? 200;
            $h = $info['height'] ?? 200;
            $coords = [
                'x1' => (int) ($w * 0.1),
                'y1' => (int) ($h * 0.1),
                'x2' => (int) ($w * 0.9),
                'y2' => (int) ($h * 0.9),
            ];
        }

        $annotation = array_merge(['type' => $type, 'color' => $color], $coords);

        if ($type === 'text' && !empty($this->params['text'])) {
            $annotation['text'] = $this->params['text'];
            $annotation['x'] = $coords['x1'] ?? 10;
            $annotation['y'] = $coords['y1'] ?? 10;
        }

        if ($type === 'circle') {
            $annotation['cx'] = (int) (($coords['x1'] + $coords['x2']) / 2);
            $annotation['cy'] = (int) (($coords['y1'] + $coords['y2']) / 2);
            $annotation['radius'] = (int) (abs($coords['x2'] - $coords['x1']) / 2);
        }

        $annotations[] = $annotation;

        $outputPath = $processor->addAnnotations($imagePath, $annotations);

        if (!$outputPath || !file_exists($outputPath)) {
            return "Erreur lors de l'annotation de l'image.";
        }

        // Send annotated image via WAHA
        $this->sendImage($outputPath, "Image annotee ({$type}, {$color})");
        @unlink($outputPath);

        return "Image annotee avec succes ! Type: {$type}, Couleur: {$color}";
    }

    private function processComparison(ImageProcessor $processor, string $imagePath): string
    {
        // Store first image for later comparison
        $storePath = storage_path("app/screenshots/compare_{$this->chatId}.png");
        copy($imagePath, $storePath);

        return "Premiere image sauvegardee. Envoie la deuxieme image avec 'compare image 2'.";
    }

    private function processOcr(ImageProcessor $processor, string $imagePath): string
    {
        $language = $this->params['language'] ?? 'fra+eng';
        $text = $processor->extractText($imagePath, $language);

        if (empty($text)) {
            return "Aucun texte detecte dans l'image.";
        }

        return "*Texte extrait :*\n\n" . $text;
    }

    private function sendReply(string $text): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $this->chatId,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessScreenshotJob: failed to send reply: ' . $e->getMessage());
        }
    }

    private function sendImage(string $imagePath, string $caption = ''): void
    {
        try {
            $base64 = base64_encode(file_get_contents($imagePath));
            $mime = mime_content_type($imagePath) ?: 'image/png';

            Http::timeout(30)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendImage', [
                    'chatId' => $this->chatId,
                    'file' => [
                        'mimetype' => $mime,
                        'data' => $base64,
                        'filename' => 'annotated.png',
                    ],
                    'caption' => $caption,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessScreenshotJob: failed to send image: ' . $e->getMessage());
            $this->sendReply("Image annotee prete mais erreur d'envoi. Reessaie.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessScreenshotJob permanently failed: ' . $exception->getMessage());
        $this->sendReply("Le traitement de l'image a echoue. Reessaie avec une image plus petite.");
    }
}
