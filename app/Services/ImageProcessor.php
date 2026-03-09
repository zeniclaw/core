<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageProcessor
{
    private string $storagePath = 'screenshots';

    /**
     * Capture a screen region or full page (placeholder for server-side rendering).
     */
    public function captureScreen(?string $url = null, ?array $region = null): ?string
    {
        // Server-side screenshot not available in this context.
        // This method is a placeholder for future integration with headless browsers.
        Log::info('ImageProcessor: captureScreen called', ['url' => $url, 'region' => $region]);
        return null;
    }

    /**
     * Extract text from an image using Tesseract OCR.
     */
    public function extractText(string $imagePath, string $language = 'fra+eng'): ?string
    {
        if (!file_exists($imagePath)) {
            Log::warning('ImageProcessor: file not found', ['path' => $imagePath]);
            return null;
        }

        try {
            $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath);
            $ocr->lang($language);
            $ocr->psm(3); // Fully automatic page segmentation

            return trim($ocr->run());
        } catch (\Throwable $e) {
            Log::error('ImageProcessor OCR failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Add annotations to an image (arrows, highlights, text).
     */
    public function addAnnotations(string $imagePath, array $annotations): ?string
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        try {
            $image = imagecreatefromstring(file_get_contents($imagePath));
            if (!$image) {
                return null;
            }

            foreach ($annotations as $annotation) {
                $type = $annotation['type'] ?? 'rectangle';
                $color = $this->parseColor($image, $annotation['color'] ?? 'red');

                switch ($type) {
                    case 'rectangle':
                    case 'highlight':
                        $x1 = $annotation['x1'] ?? 0;
                        $y1 = $annotation['y1'] ?? 0;
                        $x2 = $annotation['x2'] ?? 100;
                        $y2 = $annotation['y2'] ?? 100;
                        imagesetthickness($image, 3);
                        imagerectangle($image, $x1, $y1, $x2, $y2, $color);
                        break;

                    case 'arrow':
                        $x1 = $annotation['x1'] ?? 0;
                        $y1 = $annotation['y1'] ?? 0;
                        $x2 = $annotation['x2'] ?? 100;
                        $y2 = $annotation['y2'] ?? 100;
                        imagesetthickness($image, 3);
                        imageline($image, $x1, $y1, $x2, $y2, $color);
                        $this->drawArrowHead($image, $x1, $y1, $x2, $y2, $color);
                        break;

                    case 'text':
                        $x = $annotation['x'] ?? 10;
                        $y = $annotation['y'] ?? 10;
                        $text = $annotation['text'] ?? '';
                        imagestring($image, 5, $x, $y, $text, $color);
                        break;

                    case 'circle':
                        $cx = $annotation['cx'] ?? 50;
                        $cy = $annotation['cy'] ?? 50;
                        $radius = $annotation['radius'] ?? 30;
                        imagesetthickness($image, 3);
                        imageellipse($image, $cx, $cy, $radius * 2, $radius * 2, $color);
                        break;
                }
            }

            $outputPath = $this->generateOutputPath('annotated', 'png');
            imagepng($image, $outputPath);
            imagedestroy($image);

            return $outputPath;
        } catch (\Throwable $e) {
            Log::error('ImageProcessor annotation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Compare two images and highlight differences.
     */
    public function compareImages(string $imagePath1, string $imagePath2): array
    {
        $result = [
            'similarity' => 0.0,
            'diff_image' => null,
            'description' => '',
        ];

        if (!file_exists($imagePath1) || !file_exists($imagePath2)) {
            $result['description'] = 'Un ou plusieurs fichiers introuvables.';
            return $result;
        }

        try {
            $img1 = imagecreatefromstring(file_get_contents($imagePath1));
            $img2 = imagecreatefromstring(file_get_contents($imagePath2));

            if (!$img1 || !$img2) {
                $result['description'] = 'Impossible de charger les images.';
                return $result;
            }

            $w1 = imagesx($img1);
            $h1 = imagesy($img1);
            $w2 = imagesx($img2);
            $h2 = imagesy($img2);

            // Resize img2 to match img1 dimensions if different
            if ($w1 !== $w2 || $h1 !== $h2) {
                $resized = imagecreatetruecolor($w1, $h1);
                imagecopyresampled($resized, $img2, 0, 0, 0, 0, $w1, $h1, $w2, $h2);
                imagedestroy($img2);
                $img2 = $resized;
            }

            // Create diff image
            $diff = imagecreatetruecolor($w1, $h1);
            $totalPixels = $w1 * $h1;
            $diffPixels = 0;
            $red = imagecolorallocate($diff, 255, 0, 0);

            for ($x = 0; $x < $w1; $x++) {
                for ($y = 0; $y < $h1; $y++) {
                    $c1 = imagecolorat($img1, $x, $y);
                    $c2 = imagecolorat($img2, $x, $y);

                    $r1 = ($c1 >> 16) & 0xFF;
                    $g1 = ($c1 >> 8) & 0xFF;
                    $b1 = $c1 & 0xFF;
                    $r2 = ($c2 >> 16) & 0xFF;
                    $g2 = ($c2 >> 8) & 0xFF;
                    $b2 = $c2 & 0xFF;

                    $distance = abs($r1 - $r2) + abs($g1 - $g2) + abs($b1 - $b2);

                    if ($distance > 30) {
                        $diffPixels++;
                        imagesetpixel($diff, $x, $y, $red);
                    } else {
                        $gray = (int) (($r1 + $g1 + $b1) / 3 * 0.3);
                        $grayColor = imagecolorallocate($diff, $gray, $gray, $gray);
                        imagesetpixel($diff, $x, $y, $grayColor);
                    }
                }
            }

            $similarity = round((1 - ($diffPixels / max($totalPixels, 1))) * 100, 2);
            $result['similarity'] = $similarity;

            $diffPath = $this->generateOutputPath('diff', 'png');
            imagepng($diff, $diffPath);
            $result['diff_image'] = $diffPath;

            imagedestroy($img1);
            imagedestroy($img2);
            imagedestroy($diff);

            $result['description'] = "Similarite: {$similarity}% | Pixels differents: {$diffPixels}/{$totalPixels}";

            return $result;
        } catch (\Throwable $e) {
            Log::error('ImageProcessor compare failed: ' . $e->getMessage());
            $result['description'] = 'Erreur lors de la comparaison: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Get image metadata (dimensions, format, size).
     */
    public function getImageInfo(string $imagePath): array
    {
        if (!file_exists($imagePath)) {
            return ['error' => 'Fichier introuvable'];
        }

        $info = getimagesize($imagePath);
        $fileSize = filesize($imagePath);

        return [
            'width' => $info[0] ?? 0,
            'height' => $info[1] ?? 0,
            'mime' => $info['mime'] ?? 'unknown',
            'size_bytes' => $fileSize,
            'size_human' => $this->humanFileSize($fileSize),
        ];
    }

    /**
     * Download a media file from WAHA and save locally.
     */
    public function downloadFromWaha(string $mediaUrl): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->get($mediaUrl);

            if (!$response->successful()) {
                Log::warning('ImageProcessor: failed to download from WAHA', ['url' => $mediaUrl, 'status' => $response->status()]);
                return null;
            }

            $content = $response->body();
            $mime = $response->header('Content-Type') ?? 'image/png';
            $ext = match (true) {
                str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'gif') => 'gif',
                str_contains($mime, 'webp') => 'webp',
                default => 'png',
            };

            $filename = 'download_' . Str::random(12) . '.' . $ext;
            $path = storage_path("app/{$this->storagePath}/{$filename}");

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $content);

            return $path;
        } catch (\Throwable $e) {
            Log::error('ImageProcessor download failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resize an image to the given dimensions, optionally keeping aspect ratio.
     */
    public function resizeImage(string $imagePath, int $targetWidth, int $targetHeight, bool $keepAspect = true): ?string
    {
        if (!file_exists($imagePath)) {
            Log::warning('ImageProcessor: file not found for resize', ['path' => $imagePath]);
            return null;
        }

        try {
            $src = imagecreatefromstring(file_get_contents($imagePath));
            if (!$src) {
                return null;
            }

            $origW = imagesx($src);
            $origH = imagesy($src);

            if ($keepAspect && $origW > 0 && $origH > 0) {
                $ratioW = $targetWidth / $origW;
                $ratioH = $targetHeight / $origH;
                $ratio  = min($ratioW, $ratioH);
                $targetWidth  = (int) round($origW * $ratio);
                $targetHeight = (int) round($origH * $ratio);
            }

            $dst = imagecreatetruecolor($targetWidth, $targetHeight);

            // Preserve transparency for PNG
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $origW, $origH);
            imagedestroy($src);

            $outputPath = $this->generateOutputPath('resized', 'png');
            imagepng($dst, $outputPath);
            imagedestroy($dst);

            return $outputPath;
        } catch (\Throwable $e) {
            Log::error('ImageProcessor resize failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Rotate an image by the given degrees (90, 180, 270 supported).
     */
    public function rotateImage(string $imagePath, int $degrees): ?string
    {
        if (!file_exists($imagePath)) {
            Log::warning('ImageProcessor: file not found for rotate', ['path' => $imagePath]);
            return null;
        }

        // Normalize to valid rotation
        $degrees = ((int) round($degrees / 90) * 90) % 360;
        if ($degrees <= 0) {
            $degrees += 360;
        }
        if ($degrees === 360) {
            $degrees = 0;
        }

        try {
            $src = imagecreatefromstring(file_get_contents($imagePath));
            if (!$src) {
                return null;
            }

            // imagerotate rotates counter-clockwise; negate to rotate clockwise
            $rotated = imagerotate($src, -$degrees, 0);
            imagedestroy($src);

            if (!$rotated) {
                return null;
            }

            $outputPath = $this->generateOutputPath('rotated', 'png');
            imagepng($rotated, $outputPath);
            imagedestroy($rotated);

            return $outputPath;
        } catch (\Throwable $e) {
            Log::error('ImageProcessor rotate failed: ' . $e->getMessage());
            return null;
        }
    }

    private function drawArrowHead($image, int $x1, int $y1, int $x2, int $y2, $color): void
    {
        $angle = atan2($y2 - $y1, $x2 - $x1);
        $arrowSize = 15;

        $ax = (int) ($x2 - $arrowSize * cos($angle - M_PI / 6));
        $ay = (int) ($y2 - $arrowSize * sin($angle - M_PI / 6));
        $bx = (int) ($x2 - $arrowSize * cos($angle + M_PI / 6));
        $by = (int) ($y2 - $arrowSize * sin($angle + M_PI / 6));

        imagefilledpolygon($image, [$x2, $y2, $ax, $ay, $bx, $by], $color);
    }

    private function parseColor($image, string $colorName)
    {
        return match (strtolower($colorName)) {
            'red' => imagecolorallocate($image, 255, 0, 0),
            'green' => imagecolorallocate($image, 0, 255, 0),
            'blue' => imagecolorallocate($image, 0, 0, 255),
            'yellow' => imagecolorallocate($image, 255, 255, 0),
            'orange' => imagecolorallocate($image, 255, 165, 0),
            'white' => imagecolorallocate($image, 255, 255, 255),
            'black' => imagecolorallocate($image, 0, 0, 0),
            'cyan' => imagecolorallocate($image, 0, 255, 255),
            'magenta' => imagecolorallocate($image, 255, 0, 255),
            default => imagecolorallocate($image, 255, 0, 0),
        };
    }

    private function generateOutputPath(string $prefix, string $extension): string
    {
        $dir = storage_path("app/{$this->storagePath}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return "{$dir}/{$prefix}_" . Str::random(12) . ".{$extension}";
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
