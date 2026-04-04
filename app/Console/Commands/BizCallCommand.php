<?php

namespace App\Console\Commands;

use App\Models\CustomAgentEndpoint;
use App\Services\BusinessQueryService;
use Illuminate\Console\Command;

class BizCallCommand extends Command
{
    protected $signature = 'biz:call {endpoint_id} {params_json?}';
    protected $description = 'Execute a business API endpoint and return JSON data';

    public function handle(): int
    {
        $endpointId = (int) $this->argument('endpoint_id');
        $paramsJson = $this->argument('params_json') ?? '{}';

        $endpoint = CustomAgentEndpoint::find($endpointId);
        if (!$endpoint) {
            $this->output->write(json_encode(['error' => true, 'message' => "Endpoint {$endpointId} not found."]));
            return 1;
        }

        $params = json_decode($paramsJson, true) ?? [];

        $service = new BusinessQueryService();
        $result = $service->execute($endpoint, $params, '');

        if (!$result['success']) {
            $this->output->write(json_encode([
                'error' => true,
                'message' => $result['error'] ?? 'API error',
            ], JSON_UNESCAPED_UNICODE));
            return 1;
        }

        $this->output->write(json_encode([
            'success' => true,
            'data' => $result['raw_data'],
            'endpoint' => $endpoint->name,
            'records_count' => is_array($result['raw_data']) && isset($result['raw_data'][0])
                ? count($result['raw_data']) : 1,
        ], JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
