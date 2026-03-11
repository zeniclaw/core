<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModelMirrorController extends Controller
{
    private function getExportDir(): string
    {
        return storage_path('app/ollama-exports');
    }

    /**
     * Public page listing available model exports.
     */
    public function index()
    {
        $dir = $this->getExportDir();
        $models = [];

        if (is_dir($dir)) {
            foreach (glob($dir . '/ollama-*.json') as $metaFile) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (!$meta) continue;

                $slug = str_replace(['/', '.', ':'], '-', $meta['model']);
                $tarFile = $dir . "/ollama-{$slug}.tar.gz";

                if (!file_exists($tarFile)) continue;

                $models[] = [
                    'model' => $meta['model'],
                    'name' => $meta['name'],
                    'tag' => $meta['tag'],
                    'size' => $meta['size'],
                    'size_mb' => round($meta['size'] / 1024 / 1024),
                    'layers' => $meta['layers'],
                    'exported_at' => $meta['exported_at'],
                    'slug' => $slug,
                ];
            }
        }

        // Sort by name
        usort($models, fn($a, $b) => strcmp($a['model'], $b['model']));

        return view('models.mirror', compact('models'));
    }

    /**
     * Download a model tar.gz.
     */
    public function download(string $slug): BinaryFileResponse
    {
        $file = $this->getExportDir() . "/ollama-{$slug}.tar.gz";

        if (!file_exists($file)) {
            abort(404, 'Model not found');
        }

        return response()->download($file, "ollama-{$slug}.tar.gz");
    }

    /**
     * API: list available models (for remote import from Settings).
     */
    public function apiList()
    {
        $dir = $this->getExportDir();
        $models = [];

        if (is_dir($dir)) {
            foreach (glob($dir . '/ollama-*.json') as $metaFile) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (!$meta) continue;

                $slug = str_replace(['/', '.', ':'], '-', $meta['model']);
                $tarFile = $dir . "/ollama-{$slug}.tar.gz";
                if (!file_exists($tarFile)) continue;

                $models[] = [
                    'model' => $meta['model'],
                    'size' => $meta['size'],
                    'slug' => $slug,
                    'exported_at' => $meta['exported_at'],
                ];
            }
        }

        return response()->json(['models' => $models]);
    }
}
