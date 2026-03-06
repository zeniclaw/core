<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('peer_id');
        });

        // Backfill display_name from existing webhook logs (pushName)
        $logs = DB::table('agent_logs')
            ->where('message', 'WhatsApp message received')
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['context']);

        $names = [];
        foreach ($logs as $log) {
            $context = json_decode($log->context, true);
            $payload = $context['payload'] ?? [];
            $from = $payload['from'] ?? null;
            $pushName = $payload['_data']['pushName'] ?? $payload['_data']['notifyName'] ?? null;
            if ($from && $pushName && !isset($names[$from])) {
                $names[$from] = $pushName;
            }
        }

        foreach ($names as $peerId => $name) {
            DB::table('agent_sessions')
                ->where('peer_id', $peerId)
                ->whereNull('display_name')
                ->update(['display_name' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
