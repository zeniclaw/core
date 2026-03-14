<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Image generation service (D9.2).
 * Supports OpenAI DALL-E API.
 */
class ImageGenerationService
{
    /**
     * Generate an image from a text prompt.
     *
     * @return array{success: bool, path: ?string, error: ?string, prompt: string}
     */
    public function generate(string $prompt, string $size = '1024x1024', string $quality = 'standard'): array
    {
        $apiKey = AppSetting::get('openai_api_key');
        if (!$apiKey) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Image generation non disponible. Configurez openai_api_key dans les parametres.',
                'prompt' => $prompt,
            ];
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => 'dall-e-3',
                    'prompt' => mb_substr($prompt, 0, 4000),
                    'n' => 1,
                    'size' => $size,
                    'quality' => $quality,
                    'response_format' => 'url',
                ]);

            if ($response->successful()) {
                $imageUrl = $response->json('data.0.url');
                $revisedPrompt = $response->json('data.0.revised_prompt');

                if ($imageUrl) {
                    // Download image
                    $imageData = Http::timeout(30)->get($imageUrl);
                    if ($imageData->successful()) {
                        $path = storage_path('app/generated/' . uniqid() . '.png');
                        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
                        file_put_contents($path, $imageData->body());

                        return [
                            'success' => true,
                            'path' => $path,
                            'error' => null,
                            'prompt' => $prompt,
                            'revised_prompt' => $revisedPrompt,
                        ];
                    }
                }
            }

            return [
                'success' => false,
                'path' => null,
                'error' => "DALL-E API error: HTTP {$response->status()} - " . substr($response->body(), 0, 200),
                'prompt' => $prompt,
            ];
        } catch (\Exception $e) {
            Log::error('ImageGenerationService failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'path' => null,
                'error' => $e->getMessage(),
                'prompt' => $prompt,
            ];
        }
    }

    /**
     * Check if image generation is available.
     */
    public static function isAvailable(): bool
    {
        return (bool) AppSetting::get('openai_api_key');
    }
}
