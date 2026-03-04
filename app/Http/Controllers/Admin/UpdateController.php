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
    private string $gitlabProject = 'zenibiz%2Fzeniclaw';

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
            $outputBuffer = new \Symfony\Component\Console\Output\BufferedOutput();
            $exitCode = Artisan::call('zeniclaw:update', [], $outputBuffer);
            $output = $outputBuffer->fetch();

            $newVersion = trim(file_get_contents(storage_path('app/version.txt')) ?: '1.0.0');

            if ($exitCode !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La mise à jour a échoué',
                    'output' => $output,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Mise à jour effectuée',
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
}
