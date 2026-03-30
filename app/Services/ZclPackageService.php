<?php

namespace App\Services;

use App\Models\CustomAgent;
use App\Models\Agent;
use App\Jobs\ProcessCustomAgentDocumentJob;
use Illuminate\Support\Facades\Log;

class ZclPackageService
{
    private const MAGIC = "ZCL\x01";
    private const VERSION = 1;
    private const CIPHER = 'aes-256-cbc';
    private const PBKDF2_ITERATIONS = 100_000;
    private const SALT_LENGTH = 16;

    /**
     * Export a custom agent to encrypted .zcl binary.
     */
    public function export(CustomAgent $customAgent, string $password): string
    {
        $payload = $this->buildPayload($customAgent);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->encrypt($json, $password);
    }

    /**
     * Import a .zcl binary into a new custom agent.
     */
    public function import(string $binary, string $password, Agent $agent): CustomAgent
    {
        $json = $this->decrypt($binary, $password);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->validatePayload($payload);

        return $this->createFromPayload($payload, $agent);
    }

    /**
     * Preview the contents of a .zcl file without importing.
     */
    public function preview(string $binary, string $password): array
    {
        $json = $this->decrypt($binary, $password);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->validatePayload($payload);

        return [
            'name' => $payload['agent']['name'],
            'description' => $payload['agent']['description'] ?? '',
            'avatar' => $payload['agent']['avatar'] ?? '🤖',
            'model' => $payload['agent']['model'] ?? 'default',
            'documents_count' => count($payload['documents'] ?? []),
            'skills_count' => count($payload['skills'] ?? []),
            'scripts_count' => count($payload['scripts'] ?? []),
            'memories_count' => count($payload['memories'] ?? []),
            'has_system_prompt' => !empty($payload['agent']['system_prompt']),
            'enabled_tools' => $payload['agent']['enabled_tools'] ?? [],
        ];
    }

    private function buildPayload(CustomAgent $customAgent): array
    {
        $documents = $customAgent->documents()->get()->map(fn ($doc) => [
            'title' => $doc->title,
            'type' => $doc->type,
            'source' => $doc->source,
            'raw_content' => $doc->raw_content,
        ])->toArray();

        $skills = $customAgent->skills()->get()->map(fn ($skill) => [
            'name' => $skill->name,
            'description' => $skill->description,
            'trigger_phrase' => $skill->trigger_phrase,
            'routine' => $skill->routine,
            'is_active' => $skill->is_active,
        ])->toArray();

        $scripts = $customAgent->scripts()->get()->map(fn ($script) => [
            'name' => $script->name,
            'description' => $script->description,
            'language' => $script->language,
            'code' => $script->code,
            'is_active' => $script->is_active,
        ])->toArray();

        $memories = $customAgent->memories()->get()->map(fn ($mem) => [
            'category' => $mem->category,
            'content' => $mem->content,
            'source' => $mem->source,
        ])->toArray();

        return [
            'zcl_version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'agent' => [
                'name' => $customAgent->name,
                'description' => $customAgent->description,
                'system_prompt' => $customAgent->system_prompt,
                'model' => $customAgent->model,
                'avatar' => $customAgent->avatar,
                'settings' => $customAgent->settings,
                'enabled_tools' => $customAgent->enabled_tools,
            ],
            'documents' => $documents,
            'skills' => $skills,
            'scripts' => $scripts,
            'memories' => $memories,
        ];
    }

    private function createFromPayload(array $payload, Agent $agent): CustomAgent
    {
        $agentData = $payload['agent'];

        $customAgent = CustomAgent::create([
            'agent_id' => $agent->id,
            'name' => $agentData['name'] . ' (import)',
            'description' => $agentData['description'],
            'system_prompt' => $agentData['system_prompt'],
            'model' => $agentData['model'] ?? 'default',
            'avatar' => $agentData['avatar'] ?? '🤖',
            'settings' => $agentData['settings'] ?? null,
            'enabled_tools' => $agentData['enabled_tools'] ?? null,
            'is_active' => false, // imported agents start inactive
        ]);

        // Import documents and queue chunking
        foreach ($payload['documents'] ?? [] as $docData) {
            $document = $customAgent->documents()->create([
                'title' => $docData['title'],
                'type' => $docData['type'],
                'source' => $docData['source'],
                'raw_content' => $docData['raw_content'],
                'status' => 'pending',
                'chunk_count' => 0,
            ]);

            $this->processDocumentAsync($document);
        }

        // Import skills
        foreach ($payload['skills'] ?? [] as $skillData) {
            $customAgent->skills()->create([
                'name' => $skillData['name'],
                'description' => $skillData['description'] ?? null,
                'trigger_phrase' => $skillData['trigger_phrase'] ?? null,
                'routine' => $skillData['routine'],
                'is_active' => $skillData['is_active'] ?? true,
            ]);
        }

        // Import scripts
        foreach ($payload['scripts'] ?? [] as $scriptData) {
            $customAgent->scripts()->create([
                'name' => $scriptData['name'],
                'description' => $scriptData['description'] ?? null,
                'language' => $scriptData['language'],
                'code' => $scriptData['code'],
                'is_active' => $scriptData['is_active'] ?? true,
            ]);
        }

        // Import memories
        foreach ($payload['memories'] ?? [] as $memData) {
            $customAgent->memories()->create([
                'category' => $memData['category'] ?? 'general',
                'content' => $memData['content'],
                'source' => $memData['source'] ?? 'import',
            ]);
        }

        return $customAgent;
    }

    private function encrypt(string $plaintext, string $password): string
    {
        $salt = random_bytes(self::SALT_LENGTH);
        $key = hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true);

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        $body = self::MAGIC . $salt . $iv . $ciphertext;
        $hmac = hash_hmac('sha256', $body, $key, true);

        return $body . $hmac;
    }

    private function decrypt(string $binary, string $password): string
    {
        $magicLen = strlen(self::MAGIC);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $hmacLen = 32;
        $minLen = $magicLen + self::SALT_LENGTH + $ivLength + $hmacLen + 1;

        if (strlen($binary) < $minLen) {
            throw new \InvalidArgumentException('Fichier .zcl invalide ou corrompu.');
        }

        $magic = substr($binary, 0, $magicLen);
        if ($magic !== self::MAGIC) {
            throw new \InvalidArgumentException('Ce fichier n\'est pas un package .zcl valide.');
        }

        $offset = $magicLen;
        $salt = substr($binary, $offset, self::SALT_LENGTH);
        $offset += self::SALT_LENGTH;

        $iv = substr($binary, $offset, $ivLength);
        $offset += $ivLength;

        $storedHmac = substr($binary, -$hmacLen);
        $ciphertext = substr($binary, $offset, strlen($binary) - $offset - $hmacLen);

        $key = hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true);

        // Verify HMAC
        $body = substr($binary, 0, strlen($binary) - $hmacLen);
        $computedHmac = hash_hmac('sha256', $body, $key, true);

        if (!hash_equals($computedHmac, $storedHmac)) {
            throw new \InvalidArgumentException('Mot de passe incorrect ou fichier corrompu.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    private function validatePayload(array $payload): void
    {
        if (empty($payload['zcl_version'])) {
            throw new \InvalidArgumentException('Format de package .zcl invalide: version manquante.');
        }

        if (empty($payload['agent']['name'])) {
            throw new \InvalidArgumentException('Format de package .zcl invalide: nom d\'agent manquant.');
        }
    }

    private function processDocumentAsync($document): void
    {
        try {
            ProcessCustomAgentDocumentJob::dispatch($document->id);
        } catch (\Throwable $e) {
            Log::warning("Queue unavailable for imported doc, processing sync: " . $e->getMessage());
            try {
                (new KnowledgeChunker())->processDocument($document);
            } catch (\Throwable $e2) {
                Log::error("Imported document processing failed: " . $e2->getMessage());
                $document->update(['status' => 'failed', 'error_message' => mb_substr($e2->getMessage(), 0, 500)]);
            }
        }
    }
}
