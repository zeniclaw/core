<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitLabService
{
    private string $host;
    private string $token;

    public function __construct(?string $host = null, ?string $token = null)
    {
        $this->token = $token ?? AppSetting::get('gitlab_access_token') ?? '';
        $this->host = $host ?? $this->detectHost();
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * List projects the user has access to.
     */
    public function listProjects(string $search = '', int $perPage = 50): ?array
    {
        return $this->get('/projects', [
            'membership' => 'true',
            'per_page' => $perPage,
            'order_by' => 'last_activity_at',
            'search' => $search ?: null,
        ]);
    }

    /**
     * Get a single project by ID or URL-encoded path.
     */
    public function getProject(string $projectId): ?array
    {
        return $this->get("/projects/{$projectId}");
    }

    /**
     * List branches for a project.
     */
    public function listBranches(string $projectId, int $perPage = 20): ?array
    {
        return $this->get("/projects/{$projectId}/repository/branches", [
            'per_page' => $perPage,
        ]);
    }

    /**
     * List recent commits on a branch.
     */
    public function listCommits(string $projectId, string $branch = 'main', int $perPage = 10): ?array
    {
        return $this->get("/projects/{$projectId}/repository/commits", [
            'ref_name' => $branch,
            'per_page' => $perPage,
        ]);
    }

    /**
     * List merge requests for a project.
     */
    public function listMergeRequests(string $projectId, string $state = 'opened', int $perPage = 10): ?array
    {
        return $this->get("/projects/{$projectId}/merge_requests", [
            'state' => $state,
            'per_page' => $perPage,
            'order_by' => 'updated_at',
        ]);
    }

    /**
     * List pipelines for a project.
     */
    public function listPipelines(string $projectId, int $perPage = 5): ?array
    {
        return $this->get("/projects/{$projectId}/pipelines", [
            'per_page' => $perPage,
            'order_by' => 'updated_at',
            'sort' => 'desc',
        ]);
    }

    /**
     * Get the file tree of a project.
     */
    public function listTree(string $projectId, string $path = '', string $ref = 'main', int $perPage = 50): ?array
    {
        return $this->get("/projects/{$projectId}/repository/tree", [
            'path' => $path ?: null,
            'ref' => $ref,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Read a file from the repository.
     */
    public function readFile(string $projectId, string $filePath, string $ref = 'main'): ?array
    {
        $encoded = urlencode($filePath);
        return $this->get("/projects/{$projectId}/repository/files/{$encoded}", [
            'ref' => $ref,
        ]);
    }

    /**
     * List issues for a project.
     */
    public function listIssues(string $projectId, string $state = 'opened', int $perPage = 10): ?array
    {
        return $this->get("/projects/{$projectId}/issues", [
            'state' => $state,
            'per_page' => $perPage,
            'order_by' => 'updated_at',
        ]);
    }

    /**
     * Create an issue.
     */
    public function createIssue(string $projectId, string $title, string $description = '', array $labels = []): ?array
    {
        return $this->post("/projects/{$projectId}/issues", [
            'title' => $title,
            'description' => $description,
            'labels' => implode(',', $labels),
        ]);
    }

    /**
     * Search code in a project.
     */
    public function searchCode(string $projectId, string $query): ?array
    {
        return $this->get("/projects/{$projectId}/search", [
            'scope' => 'blobs',
            'search' => $query,
            'per_page' => 10,
        ]);
    }

    /**
     * Compare two branches/refs.
     */
    public function compareBranches(string $projectId, string $from, string $to): ?array
    {
        return $this->get("/projects/{$projectId}/repository/compare", [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Get latest deployment/environment info.
     */
    public function listEnvironments(string $projectId): ?array
    {
        return $this->get("/projects/{$projectId}/environments", [
            'per_page' => 5,
        ]);
    }

    /**
     * Encode a project path for the API (namespace/project → namespace%2Fproject).
     */
    public static function encodeProjectPath(string $gitlabUrl): string
    {
        $path = parse_url($gitlabUrl, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path);
        return urlencode($path);
    }

    /**
     * Extract host from a GitLab URL.
     */
    public static function extractHost(string $gitlabUrl): string
    {
        return parse_url($gitlabUrl, PHP_URL_HOST) ?? 'gitlab.com';
    }

    // ── Private helpers ─────────────────────────────────────────────

    private function get(string $endpoint, array $params = []): ?array
    {
        $params = array_filter($params, fn($v) => $v !== null);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['PRIVATE-TOKEN' => $this->token])
                ->get("https://{$this->host}/api/v4{$endpoint}", $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("GitLabService GET {$endpoint} failed", ['status' => $response->status()]);
            return null;
        } catch (\Exception $e) {
            Log::warning("GitLabService GET {$endpoint} error", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function post(string $endpoint, array $data = []): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['PRIVATE-TOKEN' => $this->token])
                ->post("https://{$this->host}/api/v4{$endpoint}", $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("GitLabService POST {$endpoint} failed", ['status' => $response->status()]);
            return null;
        } catch (\Exception $e) {
            Log::warning("GitLabService POST {$endpoint} error", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function detectHost(): string
    {
        $project = \App\Models\Project::whereNotNull('gitlab_url')->first();
        if ($project) {
            $host = parse_url($project->gitlab_url, PHP_URL_HOST);
            if ($host) return $host;
        }
        return 'gitlab.com';
    }
}
