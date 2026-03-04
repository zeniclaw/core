<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;
use Illuminate\Support\Facades\Log;

class AnalysisAgent extends BaseAgent
{
    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function name(): string
    {
        return 'analysis';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'analysis';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);
        $systemPrompt = $this->buildSystemPrompt($context);
        $claudeMessage = $this->buildMessageContent($context);

        $reply = $this->claude->chat($claudeMessage, $model, $systemPrompt);

        // Fallback to Haiku if the requested model fails
        if (!$reply && $model !== 'claude-haiku-4-5-20251001') {
            $reply = $this->claude->chat($claudeMessage, 'claude-haiku-4-5-20251001', $systemPrompt);
            $model = 'claude-haiku-4-5-20251001 (fallback)';
        }

        if (!$reply) {
            $fallback = 'Désolé, je n\'ai pas pu générer l\'analyse. Réessaie !';
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }

        $this->sendText($context->from, $reply);

        $this->log($context, 'Analysis reply sent', [
            'model' => $model,
            'complexity' => $context->complexity,
            'has_media' => $context->hasMedia,
            'reply_length' => mb_strlen($reply),
        ]);

        return AgentResult::reply($reply, ['model' => $model, 'complexity' => $context->complexity]);
    }

    // ──────────────────────────────────────────────
    //  Message content (text or multimodal)
    // ──────────────────────────────────────────────

    private function buildMessageContent(AgentContext $context): string|array
    {
        $message = $context->body ?? '';

        if ($context->hasMedia && $context->mediaUrl) {
            $mimetype = $context->mimetype ?? '';

            if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES) || $mimetype === 'application/pdf') {
                $base64Data = $this->downloadMedia($context->mediaUrl);
                if ($base64Data) {
                    return $this->buildMediaContentBlocks($mimetype, $base64Data, $context->body);
                }
            }

            // Unsupported media – tell the user
            $message = ($message ? "{$message}\n\n" : '') .
                "[{$context->senderName} a envoyé un fichier de type {$mimetype}. " .
                "Tu ne peux pas le traiter, dis-le poliment et propose de continuer.]";
        }

        return $message;
    }

    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Exception $e) {
            Log::warning('[analysis] Failed to download media: ' . $e->getMessage());
        }
        return null;
    }

    private function buildMediaContentBlocks(string $mimetype, string $base64Data, ?string $caption): array
    {
        $blocks = [];

        if (in_array($mimetype, self::SUPPORTED_IMAGE_TYPES)) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimetype,
                    'data' => $base64Data,
                ],
            ];
        } elseif ($mimetype === 'application/pdf') {
            $blocks[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $base64Data,
                ],
            ];
        }

        $text = $caption ?: 'Analyse ce document en detail.';
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }

    // ──────────────────────────────────────────────
    //  System prompt
    // ──────────────────────────────────────────────

    private function buildSystemPrompt(AgentContext $context): string
    {
        $systemPrompt =
            "Tu es ZeniClaw, un assistant analytique expert. " .
            "Tu fournis des analyses approfondies, structurees et argumentees. " .
            "Tu utilises des listes, des titres et une organisation claire. " .
            "Tu cites tes sources de raisonnement. " .
            "Tu es precis et factuel. " .
            "Tu peux etre plus long que pour une conversation normale si l'analyse le demande. " .
            "Le message vient de {$context->senderName}.";

        // ── Document analysis instructions ──
        $systemPrompt .= "\n\n" .
            "## Analyse de documents\n" .
            "Si l'utilisateur envoie un document (image ou PDF), produis une analyse structuree :\n" .
            "1. **Resume** : synthese en quelques phrases\n" .
            "2. **Points cles** : les elements importants a retenir\n" .
            "3. **Recommandations** : actions suggerees basees sur le document\n" .
            "4. **Risques identifies** : points d'attention ou problemes potentiels\n" .
            "Adapte la profondeur de l'analyse au type de document (contrat, rapport, facture, image, etc.).";

        // ── Comparative analysis instructions ──
        $systemPrompt .= "\n\n" .
            "## Analyse comparative\n" .
            "Si l'utilisateur demande une comparaison (mots-cles : \"compare\", \"vs\", \"versus\", " .
            "\"entre X et Y\", \"difference entre\", \"quel est le meilleur\"), " .
            "reponds avec un tableau structure :\n" .
            "- Un tableau avec les criteres en lignes et les options en colonnes\n" .
            "- Avantages et inconvenients de chaque option\n" .
            "- Une conclusion claire avec une recommandation argumentee\n" .
            "Exemple de format :\n" .
            "| Critere | Option A | Option B |\n" .
            "|---------|----------|----------|\n" .
            "| Prix | ... | ... |\n" .
            "| Performance | ... | ... |\n" .
            "Puis une section **Conclusion** avec ta recommandation.";

        // ── Analysis frameworks instructions ──
        $systemPrompt .= "\n\n" .
            "## Frameworks d'analyse\n" .
            "Si l'utilisateur demande explicitement un framework, structure ta reponse selon ce framework :\n\n" .
            "**SWOT** (mots-cles : \"SWOT\", \"forces faiblesses\") :\n" .
            "- Forces (Strengths)\n" .
            "- Faiblesses (Weaknesses)\n" .
            "- Opportunites (Opportunities)\n" .
            "- Menaces (Threats)\n\n" .
            "**PESTEL** (mots-cles : \"PESTEL\", \"macro-environnement\") :\n" .
            "- Politique\n" .
            "- Economique\n" .
            "- Social\n" .
            "- Technologique\n" .
            "- Environnemental\n" .
            "- Legal\n\n" .
            "**5 Forces de Porter** (mots-cles : \"Porter\", \"5 forces\", \"cinq forces\") :\n" .
            "- Menace des nouveaux entrants\n" .
            "- Pouvoir de negociation des fournisseurs\n" .
            "- Pouvoir de negociation des clients\n" .
            "- Menace des produits de substitution\n" .
            "- Intensite concurrentielle\n\n" .
            "Si aucun framework n'est demande, utilise le format libre le plus adapte a la question.";

        // ── Multi-step clarification ──
        $systemPrompt .= "\n\n" .
            "## Clarification prealable\n" .
            "Si la demande d'analyse est vague, ambigue ou tres large, " .
            "commence par poser 2-3 questions de clarification avant de produire l'analyse. " .
            "Par exemple : quel angle d'analyse ? quel objectif ? quel contexte specifique ? " .
            "Cela te permettra de fournir une analyse beaucoup plus pertinente et personnalisee.";

        // Add project context if relevant
        $projectContext = $this->buildProjectContext($context);
        if ($projectContext) {
            $systemPrompt .= "\n\n" . $projectContext;
        }

        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from);
        if ($memoryContext) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }

        return $systemPrompt;
    }

    private function buildProjectContext(AgentContext $context): ?string
    {
        $phone = $context->from;

        $projects = Project::where('requester_phone', $phone)
            ->whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        $lines = ["PROJETS DE L'UTILISATEUR:"];
        foreach ($projects as $project) {
            $lines[] = "- {$project->name} ({$project->gitlab_url})";
        }

        if ($context->session->active_project_id) {
            $activeProject = $projects->firstWhere('id', $context->session->active_project_id);
            if ($activeProject) {
                $lines[] = "\nPROJET ACTIF: {$activeProject->name}";
            }
        }

        return implode("\n", $lines);
    }
}
