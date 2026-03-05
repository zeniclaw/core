<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadMediaCommand extends Command
{
    protected $signature = 'waha:download-media
        {url : The WAHA media URL to download}
        {--output= : Output file path (defaults to storage/app/media/)}
        {--timeout=30 : Download timeout in seconds}';

    protected $description = 'Download media files securely from WAHA';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): int
    {
        $url = $this->argument('url');
        $timeout = (int) $this->option('timeout');

        // Validate URL is a WAHA URL
        if (!$this->isValidWahaUrl($url)) {
            $this->error('Invalid URL. Only WAHA media URLs are accepted.');
            return self::FAILURE;
        }

        // Rewrite localhost to internal Docker hostname
        $url = str_replace('http://localhost:3000', $this->wahaBase, $url);

        $this->info("Downloading media from WAHA...");

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->get($url);

            if (!$response->successful()) {
                $this->error("Download failed with status {$response->status()}");
                return self::FAILURE;
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type', 'application/octet-stream');
            $extension = $this->guessExtension($contentType);

            $outputPath = $this->option('output');
            if (!$outputPath) {
                $filename = 'media_' . now()->format('Ymd_His') . '.' . $extension;
                $outputPath = "media/{$filename}";
            }

            Storage::disk('local')->put($outputPath, $body);
            $fullPath = Storage::disk('local')->path($outputPath);

            $this->info("Downloaded successfully:");
            $this->line("  Path: {$fullPath}");
            $this->line("  Size: " . number_format(strlen($body)) . " bytes");
            $this->line("  Type: {$contentType}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Download failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function isValidWahaUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://(localhost:3000|waha:3000)/#', $url);
    }

    private function guessExtension(string $contentType): string
    {
        $map = [
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/mp4' => 'm4a',
            'audio/webm' => 'webm',
            'audio/amr' => 'amr',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
        ];

        $base = explode(';', $contentType)[0];
        return $map[trim($base)] ?? 'bin';
    }
}
