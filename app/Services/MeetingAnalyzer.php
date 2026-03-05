<?php

namespace App\Services;

class MeetingAnalyzer
{
    private AnthropicClient $claude;

    public function __construct()
    {
        $this->claude = new AnthropicClient();
    }

    public function analyze(array $messages, string $groupName): array
    {
        if (empty($messages)) {
            return [
                'decisions' => [],
                'action_items' => [],
                'risks' => [],
                'next_steps' => [],
                'summary' => 'Aucun message capture pendant la reunion.',
            ];
        }

        $messagesText = $this->formatMessages($messages);

        $response = $this->claude->chat(
            "Reunion: \"{$groupName}\"\n\nMessages captures:\n{$messagesText}",
            'claude-sonnet-4-5-20241022',
            $this->buildPrompt()
        );

        return $this->parseResponse($response);
    }

    private function formatMessages(array $messages): string
    {
        $lines = [];
        foreach ($messages as $msg) {
            $time = isset($msg['timestamp']) ? substr($msg['timestamp'], 11, 5) : '??:??';
            $sender = $msg['sender'] ?? 'Inconnu';
            $content = $msg['content'] ?? '';
            $lines[] = "[{$time}] {$sender}: {$content}";
        }
        return implode("\n", $lines);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un analyste de reunions. Analyse les messages captures et extrais les informations cles.

Reponds UNIQUEMENT en JSON valide (sans markdown, sans backticks):
{
    "decisions": ["Decision 1", "Decision 2"],
    "action_items": [
        {"task": "Description de la tache", "assignee": "Nom ou null", "deadline": "Date ou null"}
    ],
    "risks": ["Risque ou blocker 1", "Risque 2"],
    "next_steps": ["Prochaine etape 1", "Prochaine etape 2"],
    "summary": "Resume concis de la reunion en 2-3 phrases"
}

REGLES:
- Extrais les DECISIONS prises explicitement ou implicitement
- Identifie les ACTION ITEMS avec responsable (si mentionne) et deadline (si mentionnee)
- Detecte les RISQUES et BLOCKERS mentionnes
- Liste les PROCHAINES ETAPES convenues
- Fais un RESUME factuel et concis
- Si une info n'est pas disponible, utilise un array vide ou null
- Reponds UNIQUEMENT avec le JSON
PROMPT;
    }

    private function parseResponse(?string $response): array
    {
        $default = [
            'decisions' => [],
            'action_items' => [],
            'risks' => [],
            'next_steps' => [],
            'summary' => 'Impossible d\'analyser la reunion.',
        ];

        if (!$response) return $default;

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);

        if (!$parsed) return $default;

        return [
            'decisions' => $parsed['decisions'] ?? [],
            'action_items' => $parsed['action_items'] ?? [],
            'risks' => $parsed['risks'] ?? [],
            'next_steps' => $parsed['next_steps'] ?? [],
            'summary' => $parsed['summary'] ?? $default['summary'],
        ];
    }
}
