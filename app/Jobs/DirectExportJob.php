<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\CustomAgent;
use App\Services\BusinessQueryService;
use App\Services\CustomAgentRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DirectExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 min — exports can be heavy
    public int $tries = 1;
    public int $backoff = 0;

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(12);
    }

    public function __construct(
        private int $customAgentId,
        private int $agentId,
        private string $chatId,
        private string $body,
        private string $format,
        private bool $isMulti,
        private array $params = [],
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $customAgent = CustomAgent::find($this->customAgentId);
        if (!$customAgent) return;

        $service = new BusinessQueryService();

        try {
            if ($this->isMulti) {
                $sheets = $this->buildMultiSheets($customAgent, $service);
                $title = "Export complet - " . date('d/m/Y');
            } else {
                $sheets = $this->buildSingleSheet($customAgent, $service);
                $title = $sheets[0]['name'] ?? 'Export';
            }

            if (empty($sheets)) {
                $this->sendText("❌ Aucune donnee trouvee pour l'export.");
                return;
            }

            $spec = json_encode([
                'format' => $this->format,
                'title' => $title,
                'sheets' => $sheets,
            ], JSON_UNESCAPED_UNICODE);

            $cmd = 'php /var/www/html/artisan doc:create '
                . escapeshellarg($spec)
                . ' --chat-id=' . escapeshellarg($this->chatId);

            $process = Process::timeout(120)->run($cmd);
            $output = json_decode($process->output(), true);

            if (!$process->successful() || empty($output['success'])) {
                Log::error('[DirectExportJob] doc:create failed', [
                    'output' => mb_substr($process->output(), 0, 500),
                    'err' => mb_substr($process->errorOutput(), 0, 500),
                ]);
                $this->sendText("❌ Erreur lors de la generation du fichier. Reessayez.");
                return;
            }

            $totalRows = $output['total_rows'] ?? 0;
            $sheetsCount = $output['sheets_count'] ?? count($sheets);

            $replyText = "✅ *{$title}*\n\n";
            $replyText .= "📊 {$sheetsCount} onglet(s), {$totalRows} lignes\n";
            $replyText .= "📁 Format: {$this->format}\n\n";
            $replyText .= "*Onglets :*\n";
            foreach ($sheets as $s) {
                $replyText .= "- {$s['name']} (" . count($s['rows']) . " lignes)\n";
            }

            $this->sendText($replyText);

        } catch (\Throwable $e) {
            Log::error('[DirectExportJob] Exception', ['error' => $e->getMessage()]);
            $this->sendText("❌ Erreur lors de l'export : " . $e->getMessage());
        }
    }

    private function buildSingleSheet(CustomAgent $customAgent, BusinessQueryService $service): array
    {
        $match = $service->tryMatch($customAgent, $this->body);
        if (!$match || !$match['matched']) return [];

        $params = $this->params;
        if (empty($params) && !empty($match['endpoint']->parameters)) {
            $runner = new CustomAgentRunner($customAgent);
            $params = $this->callExtractSmartParams($runner, $this->body, $match['endpoint']);
        }

        $result = $service->execute($match['endpoint'], $params, $this->body);
        if (!$result['success'] || empty($result['raw_data'])) return [];

        return [$this->buildSheetFromData($match['endpoint']->name, $result['raw_data'])];
    }

    private function buildMultiSheets(CustomAgent $customAgent, BusinessQueryService $service): array
    {
        $allEndpoints = $customAgent->endpoints()->where('is_active', true)->get();
        if ($allEndpoints->isEmpty()) return [];

        $scored = $this->scoreEndpoints($allEndpoints);
        if (empty($scored)) return [];

        $sheets = [];
        $runner = new CustomAgentRunner($customAgent);

        foreach ($scored as $ep) {
            try {
                $params = [];
                if (!empty($ep->parameters)) {
                    $params = $this->callExtractSmartParams($runner, $this->body, $ep);
                }

                $result = $service->execute($ep, $params, $this->body);
                if (!$result['success'] || empty($result['raw_data'])) continue;

                $rawData = $result['raw_data'];
                if (!isset($rawData[0])) $rawData = [$rawData];

                $sheets[] = $this->buildSheetFromData($ep->name, $rawData);
            } catch (\Throwable $e) {
                Log::warning("[DirectExportJob] Endpoint {$ep->name} failed", ['error' => $e->getMessage()]);
            }
        }

        return $sheets;
    }

    private function scoreEndpoints(\Illuminate\Database\Eloquent\Collection $endpoints): array
    {
        $bodyWords = preg_split('/\s+/', mb_strtolower($this->body));
        $scored = [];

        foreach ($endpoints as $ep) {
            if ($ep->method !== 'GET') continue;
            if (preg_match('/\{[^}]+\}/', $ep->url)) continue;

            $score = 0;
            $triggers = $ep->trigger_phrases ?? [];
            $epWords = preg_split('/\s+/', mb_strtolower($ep->name . ' ' . ($ep->description ?? '') . ' ' . implode(' ', $triggers)));

            foreach ($bodyWords as $bw) {
                if (mb_strlen($bw) < 3) continue;
                foreach ($epWords as $ew) {
                    if (str_contains($ew, $bw) || str_contains($bw, $ew)) $score++;
                }
            }

            $nameLower = mb_strtolower($ep->name);
            if (str_contains($nameLower, 'lister') || str_contains($nameLower, 'liste') || str_contains($nameLower, 'statistique') || str_contains($nameLower, 'rapport') || str_contains($nameLower, 'dashboard')) {
                $score += 3;
            }

            if ($score > 0) $scored[] = ['ep' => $ep, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_map(fn($s) => $s['ep'], array_slice($scored, 0, 8));
    }

    private function buildSheetFromData(string $name, array $rawData): array
    {
        $fieldMap = $this->buildFieldMap($rawData);
        $headers = array_values($fieldMap);
        $rows = [];
        foreach ($rawData as $record) {
            $row = [];
            foreach (array_keys($fieldMap) as $path) {
                $row[] = $this->extractField($record, $path);
            }
            $rows[] = $row;
        }
        return ['name' => mb_substr($name, 0, 31), 'headers' => $headers, 'rows' => $rows];
    }

    private function buildFieldMap(array $data): array
    {
        if (empty($data) || !is_array($data[0])) return [];

        $sample = $data[0];
        $map = [];
        $skipPatterns = ['_id', '_path', '_hash', 'signed_', 'signature', 'verified_at', 'peppol_', 'cegid_', 'dunning_', 'financing_', 'template_id'];

        foreach ($sample as $key => $value) {
            if ($key === 'id') continue;

            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (str_ends_with($key, $pattern) || str_starts_with($key, $pattern)) { $skip = true; break; }
            }
            if ($skip) continue;

            if (is_scalar($value) || is_null($value)) {
                $map[$key] = ucfirst(str_replace('_', ' ', $key));
            } elseif (is_array($value) && !empty($value) && !isset($value[0])) {
                foreach ($value as $childKey => $childValue) {
                    if (is_scalar($childValue) && $childKey !== 'id' && !str_ends_with($childKey, '_id')) {
                        $map["{$key}.{$childKey}"] = ucfirst(str_replace('_', ' ', $key)) . ' - ' . ucfirst(str_replace('_', ' ', $childKey));
                    }
                }
            }
        }

        return $map;
    }

    private function extractField(array $record, string $path): string
    {
        if (str_contains($path, '.')) {
            [$parent, $child] = explode('.', $path, 2);
            $value = $record[$parent][$child] ?? '';
        } else {
            $value = $record[$path] ?? '';
        }
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE);
        if (is_null($value)) return '';
        return (string) $value;
    }

    private function callExtractSmartParams(CustomAgentRunner $runner, string $body, $endpoint): array
    {
        try {
            $method = new \ReflectionMethod($runner, 'extractSmartParams');
            $method->setAccessible(true);
            return $method->invoke($runner, $body, $endpoint) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function sendText(string $text): void
    {
        try {
            Http::withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->timeout(15)
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $this->chatId,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::error('[DirectExportJob] sendText failed', ['error' => $e->getMessage()]);
        }
    }
}
