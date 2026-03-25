<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class UpdateController extends Controller
{
    private string $gitlabProject = 'zenidev%2Fzeniclaw';

    public function index()
    {
        $currentVersion = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');
        $token = AppSetting::get('gitlab_access_token');
        $headers = $token ? ['PRIVATE-TOKEN' => $token] : [];

        // Fetch latest tags
        $latestVersion = $currentVersion;
        $tags = [];
        try {
            $tagsResp = Http::timeout(8)->withHeaders($headers)->get("https://gitlab.com/api/v4/projects/{$this->gitlabProject}/repository/tags");
            if ($tagsResp->successful()) {
                $tags = $tagsResp->json();
                $latestVersion = $tags[0]['name'] ?? $currentVersion;
            }
        } catch (\Exception $e) {
            // GitLab unreachable — continue with current
        }

        // Fetch last 5 commits
        $commits = [];
        try {
            $commitsResp = Http::timeout(8)->withHeaders($headers)->get("https://gitlab.com/api/v4/projects/{$this->gitlabProject}/repository/commits", [
                'ref_name' => 'main',
                'per_page' => 5,
            ]);
            if ($commitsResp->successful()) {
                $commits = $commitsResp->json();
            }
        } catch (\Exception $e) {
            // ignore
        }

        $upToDate = ltrim($currentVersion, 'v') === ltrim($latestVersion, 'v');

        return view('admin.update', compact('currentVersion', 'latestVersion', 'tags', 'commits', 'upToDate'));
    }

    public function update(Request $request): JsonResponse
    {
        try {
            // Clear previous rebuild log
            $rebuildLog = storage_path('app/update-rebuild.log');
            if (file_exists($rebuildLog)) {
                @unlink($rebuildLog);
            }

            $outputBuffer = new \Symfony\Component\Console\Output\BufferedOutput();
            $exitCode = Artisan::call('zeniclaw:update', [], $outputBuffer);
            $output = $outputBuffer->fetch();

            $newVersion = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');

            if ($exitCode !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Update failed',
                    'output' => $output,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Update completed',
                'version' => $newVersion,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Poll the rebuild log to track Docker rebuild progress.
     */
    public function rebuildStatus(): JsonResponse
    {
        // The rebuild log is written by the update helper in the repo dir
        // Find the rebuild log — repo may be mounted at any path
        $possiblePaths = [storage_path('app/update-rebuild.log')];
        foreach (['/opt/zeniclaw-repo', '/opt/zeniclaw', '/home/zeniclaw', '/srv/zeniclaw'] as $dir) {
            if (is_dir($dir)) {
                array_unshift($possiblePaths, "$dir/storage/app/update-rebuild.log");
            }
        }

        $content = '';
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && filesize($path) > 0) {
                $content = file_get_contents($path);
                break;
            }
        }

        $finished = false;
        $success = false;

        if (!empty($content)) {
            // Check the last few lines for definitive status markers
            $lastLines = implode("\n", array_slice(explode("\n", trim($content)), -5));

            // Detect successful completion: last line is "Started" (written by update-helper)
            $finished = str_contains($content, 'Successfully built') && str_contains($lastLines, 'Started');
            $success = $finished;

            // Detect explicit failure markers (only from our script, not package names)
            if (str_contains($content, 'ERROR: rebuild failed') || str_contains($content, 'failed to build')) {
                $finished = true;
                $success = false;
            }
        }

        return response()->json([
            'log' => $content,
            'finished' => $finished,
            'success' => $success,
        ]);
    }
}
