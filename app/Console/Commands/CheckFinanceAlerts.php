<?php

namespace App\Console\Commands;

use App\Models\Budget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckFinanceAlerts extends Command
{
    protected $signature = 'finance:check-alerts';
    protected $description = 'Check budget thresholds and send alerts to users';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): void
    {
        $alerts = DB::table('finances_alerts')
            ->where('enabled', true)
            ->get();

        $sent = 0;

        foreach ($alerts as $alert) {
            $budget = Budget::where('user_phone', $alert->user_phone)
                ->where('category', $alert->category)
                ->first();

            if (!$budget) continue;

            $check = $budget->checkBudgetThreshold(null, $alert->threshold_percentage);

            if ($check['threshold_reached'] || $check['exceeded']) {
                $this->sendAlert($alert->user_phone, $check);
                $sent++;
            }
        }

        $this->info("Checked {$alerts->count()} alerts, sent {$sent} notifications.");
    }

    private function sendAlert(string $userPhone, array $check): void
    {
        if ($check['exceeded']) {
            $text = "🚨 *Alerte budget depasse !*\n"
                . "Categorie: *{$check['category']}*\n"
                . "Depense: {$check['spent']}€ / {$check['limit']}€ ({$check['percentage']}%)\n"
                . "Depassement: " . abs($check['remaining']) . "€";
        } else {
            $text = "⚠️ *Alerte budget*\n"
                . "Categorie: *{$check['category']}*\n"
                . "Depense: {$check['spent']}€ / {$check['limit']}€ ({$check['percentage']}%)\n"
                . "Restant: {$check['remaining']}€";
        }

        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $userPhone,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to send finance alert to {$userPhone}: " . $e->getMessage());
        }
    }
}
