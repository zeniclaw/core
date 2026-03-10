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
                'participants' => [],
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
            'claude-sonnet-4-20250514',
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
            $type = $msg['type'] ?? 'message';

            $prefix = match ($type) {
                'decision'     => '[DECISION CONFIRMEE] ',
                'note'         => '[NOTE IMPORTANTE] ',
                'agenda'       => '[AGENDA] ',
                'participants' => '[PARTICIPANTS DECLARES] ',
                default        => '',
            };

            $lines[] = "[{$time}] {$sender}: {$prefix}{$content}";
        }
        return implode("\n", $lines);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un analyste expert de reunions professionnelles. Analyse les messages captures et extrais les informations cles avec precision.

Reponds UNIQUEMENT en JSON valide (sans markdown, sans backticks, sans commentaires):
{
    "participants": ["Nom1", "Nom2"],
    "decisions": ["Decision claire et actionnable 1", "Decision 2"],
    "action_items": [
        {"task": "Description precise de la tache", "assignee": "Prenom ou null", "deadline": "JJ/MM ou 'semaine prochaine' ou null"}
    ],
    "risks": ["Risque ou blocker identifie 1", "Risque 2"],
    "next_steps": ["Prochaine etape concrete 1", "Prochaine etape 2"],
    "summary": "Resume factuel de la reunion en 2-3 phrases: objectif, resultat principal, et etat d'avancement."
}

REGLES D'EXTRACTION:
- PARTICIPANTS: liste tous les expéditeurs uniques des messages (champ "sender")
- DECISIONS: uniquement les decisions actees, pas les suggestions. Formule en phrase affirmative ("On deploie vendredi", "Le budget est valide a 50k")
- ACTION ITEMS: chaque tache doit etre specifique et assignable. Si pas d'assignee mentionne, mets null. Si la deadline est implicite ("avant la prochaine reunion"), indique-la
- RISQUES: blockers techniques, dependances externes, risques de delai, desaccords non resolus
- PROCHAINES ETAPES: etapes concretes post-reunion, ordonnees par priorite
- RESUME: objectif de la reunion + decisions cles + etat final en 2-3 phrases maximum
- Si une information n'est pas disponible ou mentionnee, utilise [] ou null selon le type
- Reponds UNIQUEMENT avec le JSON, rien d'autre

EXEMPLE DE SORTIE:
{
    "participants": ["Alice", "Bob", "Charlie"],
    "decisions": ["Le module paiement sera livre vendredi", "Budget API etendu a 500€/mois"],
    "action_items": [
        {"task": "Implementer le webhook Stripe", "assignee": "Bob", "deadline": "vendredi"},
        {"task": "Rediger les specs techniques", "assignee": "Alice", "deadline": null}
    ],
    "risks": ["Dependance API tierce non testee en prod", "Risque de retard si tests echouent"],
    "next_steps": ["Bob livre le webhook mercredi pour review", "Reunion de validation vendredi 14h"],
    "summary": "Reunion de planification sprint 3. L'equipe a valide la roadmap de livraison du module paiement pour vendredi. Les principales actions ont ete reparties entre Bob (technique) et Alice (specs)."
}
PROMPT;
    }

    private function parseResponse(?string $response): array
    {
        $default = [
            'participants' => [],
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
            'participants' => $parsed['participants'] ?? [],
            'decisions' => $parsed['decisions'] ?? [],
            'action_items' => $parsed['action_items'] ?? [],
            'risks' => $parsed['risks'] ?? [],
            'next_steps' => $parsed['next_steps'] ?? [],
            'summary' => $parsed['summary'] ?? $default['summary'],
        ];
    }
}
