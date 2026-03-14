<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Video processing service (D9.4).
 * Extracts key frames from video for Vision analysis.
 * Uses ffmpeg (available in Docker) for frame extraction.
 */
class VideoService
{
    private const MAX_FRAMES = 8;
    private const FRAME_FORMAT = 'jpg';
    private const MAX_VIDEO_SIZE = 50 * 1024 * 1024; // 50MB

    /**
     * Extract key frames from a video file.
     *
     * @return array{success: bool, frames: string[], error: ?string, duration: ?float}
     */
    public function extractFrames(string $videoPath, int $numFrames = 4): array
    {
        if (!file_exists($videoPath)) {
            return ['success' => false, 'frames' => [], 'error' => 'Video file not found', 'duration' => null];
        }

        if (filesize($videoPath) > self::MAX_VIDEO_SIZE) {
            return ['success' => false, 'frames' => [], 'error' => 'Video trop volumineux (max 50MB)', 'duration' => null];
        }

        $numFrames = min($numFrames, self::MAX_FRAMES);

        // Get video duration
        $duration = $this->getVideoDuration($videoPath);
        if (!$duration || $duration <= 0) {
            return ['success' => false, 'frames' => [], 'error' => 'Impossible de lire la duree de la video', 'duration' => null];
        }

        $outputDir = storage_path('app/video_frames/' . uniqid());
        if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

        try {
            // Extract evenly-spaced frames
            $interval = $duration / ($numFrames + 1);
            $frames = [];

            for ($i = 1; $i <= $numFrames; $i++) {
                $timestamp = $interval * $i;
                $outputFile = "{$outputDir}/frame_{$i}.jpg";

                $cmd = sprintf(
                    'ffmpeg -ss %f -i %s -vframes 1 -q:v 2 -y %s 2>/dev/null',
                    $timestamp,
                    escapeshellarg($videoPath),
                    escapeshellarg($outputFile)
                );

                $result = Process::timeout(10)->run($cmd);

                if (file_exists($outputFile) && filesize($outputFile) > 0) {
                    $frames[] = [
                        'path' => $outputFile,
                        'timestamp' => round($timestamp, 1),
                        'base64' => base64_encode(file_get_contents($outputFile)),
                    ];
                }
            }

            if (empty($frames)) {
                $this->cleanup($outputDir);
                return ['success' => false, 'frames' => [], 'error' => 'Echec extraction de frames (ffmpeg)', 'duration' => $duration];
            }

            return [
                'success' => true,
                'frames' => $frames,
                'error' => null,
                'duration' => $duration,
                'frame_count' => count($frames),
            ];
        } catch (\Exception $e) {
            $this->cleanup($outputDir);
            Log::error('VideoService: frame extraction failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'frames' => [], 'error' => $e->getMessage(), 'duration' => $duration];
        }
    }

    /**
     * Build Claude Vision content blocks from extracted frames.
     */
    public function buildVisionBlocks(array $frames, ?string $question = null): array
    {
        $blocks = [];

        foreach ($frames as $frame) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => $frame['base64'],
                ],
            ];
        }

        $prompt = $question ?? "Analyse cette video (images extraites a intervalles reguliers). Decris ce qui se passe, les changements entre les frames, et toute information utile.";
        $prompt .= "\n\nFrames extraites aux timestamps : " . implode('s, ', array_column($frames, 'timestamp')) . 's.';

        $blocks[] = ['type' => 'text', 'text' => $prompt];

        return $blocks;
    }

    /**
     * Get video duration in seconds using ffprobe.
     */
    private function getVideoDuration(string $path): ?float
    {
        try {
            $cmd = sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
                escapeshellarg($path)
            );
            $result = Process::timeout(5)->run($cmd);
            $output = trim($result->output());
            return is_numeric($output) ? (float) $output : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if video processing is available.
     */
    public static function isAvailable(): bool
    {
        try {
            $result = Process::timeout(3)->run('which ffmpeg 2>/dev/null');
            return $result->exitCode() === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            foreach (glob("{$dir}/*") as $file) @unlink($file);
            @rmdir($dir);
        }
    }
}
