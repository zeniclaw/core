<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\VoiceTranscriber;
use Illuminate\Support\Facades\Log;

class VoiceCommandAgent extends BaseAgent
{
    public function name(): string
    {
        return 'voice_command';
    }

    public function description(): string
    {
        return 'Agent interne de traitement des messages vocaux. Telecharge et transcrit les messages audio via Whisper, puis retransmet le texte a l\'orchestrateur pour routage vers l\'agent appropriate. Gere la confiance de transcription.';
    }

    public function keywords(): array
    {
        return ['vocal', 'voice', 'audio'];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $mimetype = $context->mimetype ?? ($context->media['mimetype'] ?? null);
        return $context->hasMedia && $this->isAudioMimetype($mimetype);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $this->log($context, 'Processing voice command', [
            'mimetype' => $context->mimetype,
            'hasMedia' => $context->hasMedia,
        ]);

        // Download audio from WAHA
        $audioBytes = $this->downloadAudio($context);
        if (!$audioBytes) {
            $this->log($context, 'Failed to download audio', [], 'warning');
            $reply = "Je n'ai pas pu telecharger ton message vocal. Peux-tu reessayer ou m'ecrire en texte ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Transcribe audio
        $transcriber = new VoiceTranscriber();
        $effectiveMimetype = $context->mimetype ?? ($context->media['mimetype'] ?? 'audio/ogg');
        $result = $transcriber->transcribe($audioBytes, $effectiveMimetype);

        if (!$result || !$result['text']) {
            $this->log($context, 'Transcription failed', [], 'warning');
            $reply = "Je n'ai pas reussi a transcrire ton message vocal. Peux-tu reessayer ou m'ecrire en texte ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $transcript = $result['text'];
        $confidence = $result['confidence'] ?? 1.0;
        $language = $result['language'] ?? 'fr';
        $minConfidence = config('voice_command.min_confidence', 0.8);

        $this->log($context, 'Transcription successful', [
            'transcript' => mb_substr($transcript, 0, 200),
            'confidence' => $confidence,
            'language' => $language,
        ]);

        // If confidence is too low, ask user to confirm
        if ($confidence < $minConfidence) {
            $reply = "J'ai compris ca de ton vocal (confiance faible) :\n\n"
                . "\"{$transcript}\"\n\n"
                . "C'est bien ca ? Si oui, renvoie le message en texte et je m'en occupe.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, [
                'transcript' => $transcript,
                'confidence' => $confidence,
                'low_confidence' => true,
            ]);
        }

        // Return transcript with handoff metadata so the orchestrator can re-route
        return AgentResult::reply($transcript, [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'language' => $language,
            'source' => 'voice',
        ]);
    }

    private function downloadAudio(AgentContext $context): ?string
    {
        $mediaUrl = $context->mediaUrl ?? ($context->media['url'] ?? null);
        if (!$mediaUrl) {
            return null;
        }

        try {
            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                return $response->body();
            }

            Log::warning('[voice_command] Download failed', [
                'status' => $response->status(),
                'url' => $mediaUrl,
            ]);
        } catch (\Exception $e) {
            Log::warning('[voice_command] Download exception: ' . $e->getMessage());
        }

        return null;
    }

    private function isAudioMimetype(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }

        $baseMime = explode(';', $mimetype)[0];
        return str_starts_with(trim($baseMime), 'audio/');
    }
}
