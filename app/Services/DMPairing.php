<?php

namespace App\Services;

use App\Models\AgentSession;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DM Pairing service (D7 enhanced) — approval-based contact verification.
 * Unknown contacts must provide a pairing code before interacting.
 * This prevents unauthorized access when whitelist is not enabled.
 */
class DMPairing
{
    private const CODE_TTL = 300; // 5 minutes to enter code
    private const MAX_ATTEMPTS = 3;

    /**
     * Check if DM pairing is enabled globally.
     */
    public static function isEnabled(): bool
    {
        return AppSetting::get('dm_pairing_enabled') === 'true';
    }

    /**
     * Check if a peer is paired (verified).
     */
    public static function isPaired(int $agentId, string $peerId): bool
    {
        $session = AgentSession::where('agent_id', $agentId)
            ->where('peer_id', $peerId)
            ->first();

        if (!$session) return false;

        return $session->whitelisted || ($session->paired_at !== null);
    }

    /**
     * Generate a pairing code for a new contact.
     * Returns the code that should be shown to the admin.
     */
    public static function generateCode(int $agentId, string $peerId): string
    {
        $code = strtoupper(Str::random(6));

        Cache::put("dm_pair:{$agentId}:{$peerId}", [
            'code' => $code,
            'attempts' => 0,
            'created_at' => now()->toIso8601String(),
        ], self::CODE_TTL);

        Log::info("DM Pairing: code generated for {$peerId}", ['agent_id' => $agentId, 'code' => $code]);

        return $code;
    }

    /**
     * Verify a pairing code submitted by a contact.
     * Returns: 'paired', 'invalid', 'expired', 'blocked'
     */
    public static function verifyCode(int $agentId, string $peerId, string $submittedCode): string
    {
        $key = "dm_pair:{$agentId}:{$peerId}";
        $data = Cache::get($key);

        if (!$data) {
            return 'expired';
        }

        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return 'blocked';
        }

        if (strtoupper(trim($submittedCode)) === $data['code']) {
            // Pairing successful
            Cache::forget($key);

            // Mark session as paired
            $sessionKey = AgentSession::keyFor($agentId, 'whatsapp', $peerId);
            AgentSession::updateOrCreate(
                ['session_key' => $sessionKey],
                [
                    'agent_id' => $agentId,
                    'channel' => 'whatsapp',
                    'peer_id' => $peerId,
                    'paired_at' => now(),
                    'last_message_at' => now(),
                ]
            );

            Log::info("DM Pairing: {$peerId} successfully paired");
            return 'paired';
        }

        // Wrong code — increment attempts
        $data['attempts']++;
        Cache::put($key, $data, self::CODE_TTL);

        return 'invalid';
    }

    /**
     * Get the challenge message for an unpaired contact.
     */
    public static function getChallengeMessage(string $code): string
    {
        return "🔐 *Verification requise*\n\n"
            . "Tu n'es pas encore autorise a utiliser ce bot.\n"
            . "Demande le code d'acces a l'administrateur.\n\n"
            . "Code de reference: *{$code}*\n"
            . "(l'admin doit entrer ce code dans le dashboard)\n\n"
            . "Reponds avec le code d'acces pour continuer.";
    }

    /**
     * Get the admin notification about a new pairing request.
     */
    public static function getAdminNotification(string $peerId, string $code, ?string $pushName = null): string
    {
        $name = $pushName ? " ({$pushName})" : '';
        return "🔔 *Nouvelle demande d'acces*\n\n"
            . "Contact: {$peerId}{$name}\n"
            . "Code: *{$code}*\n\n"
            . "Pour autoriser, communiquez le code d'acces a ce contact.";
    }
}
