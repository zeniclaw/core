<?php

namespace App\Console\Commands;

use App\Models\SelfImprovement;
use App\Services\AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoSuggestCommand extends Command
{
    protected $signature = 'zeniclaw:auto-suggest';
    protected $description = 'Auto-suggest a new feature idea to the WhatsApp autoupdate group';

    private string $groupId = '120363407463497155@g.us';
    private string $cacheKey = 'auto_suggest_history';
    private int $maxHistory = 50;

    public function handle(): int
    {
        $this->info('🔍 Generating auto-suggestion...');

        // 1. Get existing SelfImprovement titles
        $existingTitles = SelfImprovement::whereNotNull('improvement_title')
            ->pluck('improvement_title')
            ->toArray();

        // 2. Get recent suggestions from cache
        $history = Cache::get($this->cacheKey, []);

        // 3. Build exclusion list
        $exclusions = array_merge($existingTitles, $history);
        $exclusionText = !empty($exclusions)
            ? "SUGGESTIONS DÉJÀ FAITES (ne PAS répéter) :\n- " . implode("\n- ", array_slice($exclusions, -50))
            : '';

        // 4. Call Claude Haiku
        $claude = new AnthropicClient();
        $message = $claude->chat(
            "Génère UNE SEULE suggestion de fonctionnalité pour ZeniClaw.\n\n{$exclusionText}",
            'claude-haiku-4-5-20251001',
            $this->buildSystemPrompt()
        );

        if (!$message) {
            $this->error('Failed to generate suggestion from Claude.');
            return self::FAILURE;
        }

        $message = trim($message);
        $this->info("💡 Suggestion: {$message}");

        // 5. Send to WhatsApp group
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $this->groupId,
                    'text' => $message,
                    'session' => 'default',
                ]);

            if (!$response->successful()) {
                $this->error("WAHA sendText failed: {$response->status()}");
                Log::error('AutoSuggest WAHA failed', ['status' => $response->status(), 'body' => $response->body()]);
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("WAHA error: {$e->getMessage()}");
            Log::error('AutoSuggest WAHA error', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        // 6. Store in cache history
        $history[] = $message;
        if (count($history) > $this->maxHistory) {
            $history = array_slice($history, -$this->maxHistory);
        }
        Cache::put($this->cacheKey, $history);

        $this->info('✅ Suggestion sent to autoupdate group.');
        return self::SUCCESS;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es l'Improvement Scout de ZeniClaw, un assistant WhatsApp intelligent.

CAPACITÉS ACTUELLES DE ZENICLAW (6 agents) :
- ChatAgent : conversation générale, questions-réponses, humour, culture
- DevAgent : gestion de projets de développement, création de tâches, sous-agents autonomes
- ReminderAgent : rappels, alarmes, récurrences
- TodoAgent : listes de tâches, courses, checklists
- AnalysisAgent : analyse d'images et de PDF
- ProjectAgent : gestion et suivi de projets

FONCTIONNALITÉS DES ASSISTANTS CONCURRENTS À EXPLORER :
- Recherche web en temps réel (Google, Bing, Perplexity)
- Génération d'images (DALL-E, Midjourney, Stable Diffusion)
- Text-to-Speech et Speech-to-Text
- Traduction multilingue instantanée
- Intégration calendrier (Google Calendar, Outlook)
- Gestion d'emails (lecture, rédaction, envoi)
- Météo et prévisions
- Domotique (lumières, thermostat, serrures)
- Finance personnelle (budget, dépenses, crypto)
- Santé et fitness (calories, exercices, sommeil)
- Navigation et itinéraires
- Musique et podcasts (recherche, recommandations)
- Résumé de vidéos YouTube
- Actualités personnalisées
- Recettes de cuisine
- Contrôle d'appareils connectés (IoT)
- Calculs et conversions avancés
- Prise de notes vocales
- Intégration réseaux sociaux
- Automatisations et workflows (style IFTTT/Zapier)

RÈGLES :
- Écris UN SEUL message court, naturel, style WhatsApp (comme si un utilisateur demandait vraiment cette fonctionnalité)
- Le message doit être en français, informel, 1-3 phrases max
- Ne mets PAS de guillemets autour du message
- Ne mets PAS de préfixe comme "Suggestion:" ou "Idée:"
- Écris directement comme un utilisateur qui demande une feature
- Sois créatif et varié dans les formulations
- Exemples de style : "Ce serait cool si tu pouvais me donner la météo quand je te demande", "Hey ZeniClaw, tu pourrais me résumer des vidéos YouTube ?"
PROMPT;
    }
}
