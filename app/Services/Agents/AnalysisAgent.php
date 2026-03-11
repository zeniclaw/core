<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;
use App\Services\ModelResolver;
use Illuminate\Support\Facades\Log;

class AnalysisAgent extends BaseAgent
{
    private const SUPPORTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // Analysis type constants
    private const TYPE_SWOT       = 'swot';
    private const TYPE_PESTEL     = 'pestel';
    private const TYPE_PORTER     = 'porter';
    private const TYPE_COMPARISON = 'comparison';
    private const TYPE_DECISION   = 'decision';
    private const TYPE_DOCUMENT   = 'document';
    private const TYPE_GENERAL    = 'general';

    // Max file size for media downloads (5 MB)
    private const MAX_MEDIA_BYTES = 5 * 1024 * 1024;

    public function name(): string
    {
        return 'analysis';
    }

    public function description(): string
    {
        return 'Agent d\'analyse approfondie. Realise des analyses structurees de documents (images, PDFs), des comparaisons, des analyses SWOT/PESTEL/Porter/Matrice de decision, et repond aux demandes d\'analyse complexes avec argumentation.';
    }

    public function keywords(): array
    {
        return [
            'analyse', 'analyser', 'analysez', 'analysis', 'analyze', 'analyse approfondie',
            'compare', 'comparer', 'comparaison', 'comparison', 'vs', 'versus',
            'entre X et Y', 'différence entre', 'difference entre', 'quel est le meilleur',
            'SWOT', 'forces faiblesses', 'strengths weaknesses',
            'PESTEL', 'macro-environnement', 'macro environnement',
            'Porter', '5 forces', 'cinq forces', 'five forces',
            'avantages inconvenients', 'pros cons', 'pour et contre', 'pour contre',
            'étude de marche', 'etude de marche', 'market study', 'benchmark', 'benchmarking',
            'diagnostic', 'evaluation', 'evaluer', 'évaluer', 'evaluate',
            'rapport', 'report', 'bilan', 'synthese analytique', 'synthèse',
            'analyse document', 'analyser document', 'analyze document',
            'resume detaille', 'analyse detaillee', 'analyse détaillée', 'in-depth analysis',
            'points cles', 'points clés', 'key points', 'recommandations',
            'risques', 'risks', 'opportunites', 'opportunités', 'opportunities',
            'critique', 'review', 'avis detaille', 'avis détaillé',
            'decision', 'décision', 'matrice', 'priorite', 'priorité',
            'impact', 'aide decision', 'aide à la décision', 'scoring',
            'aide analyse', 'help analyse',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'analysis';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        // Help command
        if ($this->isHelpRequest($body)) {
            $help = $this->showHelp();
            $this->sendText($context->from, $help->reply);
            return $help;
        }

        $model        = $this->resolveModel($context);
        $analysisType = $this->detectAnalysisType($body, $context->hasMedia);
        $depth        = $this->detectAnalysisDepth($body);
        $maxTokens    = $this->resolveMaxTokens($context->complexity, $analysisType, $depth);
        $systemPrompt = $this->buildSystemPrompt($context, $analysisType, $depth);
        $messageContent = $this->buildMessageContent($context);

        $messages = [['role' => 'user', 'content' => $messageContent]];

        $this->log($context, 'Analysis request received', [
            'type'       => $analysisType,
            'depth'      => $depth,
            'model'      => $model,
            'complexity' => $context->complexity,
            'has_media'  => $context->hasMedia,
            'max_tokens' => $maxTokens,
        ]);

        $reply = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

        // Fallback to Haiku if the requested model fails
        if (!$reply && $model !== ModelResolver::fast()) {
            $reply = $this->claude->chatWithMessages(
                $messages,
                ModelResolver::fast(),
                $systemPrompt,
                min($maxTokens, 2048)
            );
            $model = ModelResolver::fast() . ' (fallback)';
        }

        if (!$reply) {
            $fallback = "Desolee, je n'ai pas pu generer l'analyse. Reessaie dans quelques instants.";
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }

        // If reply looks like clarification questions, keep pending context so follow-up is routed here
        if ($this->looksLikeClarificationQuestions($reply)) {
            $this->setPendingContext($context, 'awaiting_clarification', [
                'original_body' => $body,
                'analysis_type' => $analysisType,
                'depth'         => $depth,
            ], ttlMinutes: 10, expectRawInput: true);
        }

        $this->sendText($context->from, $reply);

        $this->log($context, 'Analysis reply sent', [
            'model'        => $model,
            'routed_agent' => $context->routedAgent,
            'type'         => $analysisType,
            'depth'        => $depth,
            'complexity'   => $context->complexity,
            'has_media'    => $context->hasMedia,
            'reply_length' => mb_strlen($reply),
        ]);

        return AgentResult::reply($reply, [
            'model'         => $model,
            'complexity'    => $context->complexity,
            'analysis_type' => $analysisType,
        ]);
    }

    /**
     * Handle follow-up after clarification questions were asked.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'awaiting_clarification') {
            return null;
        }

        $this->clearPendingContext($context);

        $originalBody = $pendingContext['data']['original_body'] ?? '';
        $analysisType = $pendingContext['data']['analysis_type'] ?? self::TYPE_GENERAL;
        $depth        = $pendingContext['data']['depth'] ?? 'standard';
        $followUp     = trim($context->body ?? '');

        $model     = $this->resolveModel($context);
        $maxTokens = $this->resolveMaxTokens($context->complexity, $analysisType, $depth);
        $systemPrompt = $this->buildSystemPrompt($context, $analysisType, $depth);

        // Combine original request + clarification answers into a single message
        $combinedMessage =
            "Demande initiale : {$originalBody}\n\n" .
            "Reponses aux questions de clarification : {$followUp}\n\n" .
            "Merci de produire maintenant l'analyse complete en tenant compte de ces precisions.";

        $messages = [['role' => 'user', 'content' => $combinedMessage]];

        $reply = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

        if (!$reply && $model !== ModelResolver::fast()) {
            $reply = $this->claude->chatWithMessages(
                $messages,
                ModelResolver::fast(),
                $systemPrompt,
                min($maxTokens, 2048)
            );
        }

        if (!$reply) {
            $fallback = "Desolee, je n'ai pas pu finaliser l'analyse. Reessaie dans quelques instants.";
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }

        $this->sendText($context->from, $reply);

        $this->log($context, 'Analysis follow-up reply sent', [
            'model' => $model,
            'type'  => $analysisType,
        ]);

        return AgentResult::reply($reply, ['model' => $model, 'analysis_type' => $analysisType]);
    }

    // ──────────────────────────────────────────────
    //  Analysis type, depth & token helpers
    // ──────────────────────────────────────────────

    private function detectAnalysisType(string $body, bool $hasMedia): string
    {
        if ($hasMedia) {
            return self::TYPE_DOCUMENT;
        }

        $lower = mb_strtolower($body);

        if (preg_match('/\bswot\b|forces.{0,15}faiblesses|strengths.{0,15}weaknesses/iu', $lower)) {
            return self::TYPE_SWOT;
        }

        if (preg_match('/\bpestel\b|macro.{0,8}environnement/iu', $lower)) {
            return self::TYPE_PESTEL;
        }

        if (preg_match('/\bporter\b|5\s*forces|cinq\s*forces|five\s*forces/iu', $lower)) {
            return self::TYPE_PORTER;
        }

        if (preg_match('/\b(vs\.?|versus|compar[eé]r?|comparaison|diff[eé]rence\s+entre|quel\s+est\s+le\s+meilleur|entre\s+\S.{1,30}\set\s+\S)/iu', $lower)) {
            return self::TYPE_COMPARISON;
        }

        if (preg_match('/\b(d[eé]cision|matrice|prioriser|priorit[eé]|choisir\s+entre|que\s+choisir|aide.{0,5}d[eé]cision|scoring|pondérer)\b/iu', $lower)) {
            return self::TYPE_DECISION;
        }

        if (preg_match('/\b(document|pdf|fichier|contrat|rapport|facture|image|photo|capture)\b/iu', $lower)) {
            return self::TYPE_DOCUMENT;
        }

        return self::TYPE_GENERAL;
    }

    private function detectAnalysisDepth(string $body): string
    {
        if (preg_match('/\b(détaillé|detaille|approfondi|complet|exhaustif|in.?depth|comprehensive|long)\b/iu', $body)) {
            return 'detailed';
        }

        if (preg_match('/\b(rapide|bref|court|brief|quick|résumé|resume|synthèse|synthese)\b/iu', $body)) {
            return 'brief';
        }

        return 'standard';
    }

    private function resolveMaxTokens(?string $complexity, string $analysisType, string $depth): int
    {
        $base = match ($depth) {
            'detailed' => 3072,
            'brief'    => 1024,
            default    => 2048,
        };

        // Framework analyses require more structure — ensure minimum
        if (in_array($analysisType, [self::TYPE_SWOT, self::TYPE_PESTEL, self::TYPE_PORTER, self::TYPE_DECISION])) {
            $base = max($base, 2048);
        }

        if ($complexity === 'complex') {
            $base = min($base + 1024, 4096);
        }

        return $base;
    }

    private function isHelpRequest(string $body): bool
    {
        return (bool) preg_match('/^[!\/]?\s*(aide|help|usage|commandes?|fonctions?)(\s+anal.+)?$/iu', $body);
    }

    /**
     * Returns true if the reply appears to be asking the user clarification questions
     * (short reply with multiple question marks).
     */
    private function looksLikeClarificationQuestions(string $reply): bool
    {
        $questionCount  = substr_count($reply, '?');
        $hasListedQs    = (bool) preg_match('/^\s*[-•\d\.]\s*.+\?/mu', $reply);
        return $questionCount >= 2 && $hasListedQs && mb_strlen($reply) < 700;
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
                // Download failed — continue with text-only fallback
                $message = ($message ? "{$message}\n\n" : '') .
                    "[Echec du telechargement du fichier. Analyse textuelle uniquement si possible.]";
            } else {
                // Unsupported media type — inform LLM politely
                $message = ($message ? "{$message}\n\n" : '') .
                    "[{$context->senderName} a envoye un fichier de type {$mimetype}. " .
                    "Tu ne peux pas le traiter directement. Dis-le poliment et propose une alternative.]";
            }
        }

        return $message;
    }

    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            $response = $this->waha(30)->get($mediaUrl);
            if ($response->successful()) {
                $body = $response->body();
                if (strlen($body) > self::MAX_MEDIA_BYTES) {
                    Log::warning('[analysis] Media file too large, skipping', [
                        'size' => strlen($body),
                        'limit' => self::MAX_MEDIA_BYTES,
                    ]);
                    return null;
                }
                return base64_encode($body);
            }
            Log::warning('[analysis] Media download HTTP error', ['status' => $response->status()]);
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
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mimetype,
                    'data'       => $base64Data,
                ],
            ];
        } elseif ($mimetype === 'application/pdf') {
            $blocks[] = [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $base64Data,
                ],
            ];
        }

        $text     = $caption ?: 'Analyse ce document en detail. Fournis un resume, les points cles, des recommandations et les risques identifies.';
        $blocks[] = ['type' => 'text', 'text' => $text];

        return $blocks;
    }

    // ──────────────────────────────────────────────
    //  System prompt
    // ──────────────────────────────────────────────

    private function buildSystemPrompt(AgentContext $context, string $analysisType, string $depth): string
    {
        $systemPrompt =
            "Tu es ZeniClaw, un assistant analytique expert. " .
            "Tu fournis des analyses approfondies, structurees et argumentees, optimisees pour WhatsApp. " .
            "FORMATAGE WHATSAPP : utilise *gras* (etoiles) et _italique_ (tirets bas). " .
            "N'utilise PAS de # pour les titres — ecris les titres en *MAJUSCULES AVEC ETOILES*. " .
            "Tu es precis, factuel et tu cites ton raisonnement. " .
            "Tu peux etre plus long qu'une reponse normale si l'analyse le demande. " .
            "Le message vient de {$context->senderName}.";

        // Type-specific instructions
        $systemPrompt .= $this->buildTypeInstructions($analysisType, $depth);

        // Clarification instructions
        $systemPrompt .= "\n\n" .
            "*CLARIFICATION PREALABLE*\n" .
            "Si la demande est vague, ambigue ou tres large, commence par poser 2-3 questions " .
            "de clarification (listes avec tirets) avant de produire l'analyse. " .
            "Questions courtes et precises : quel angle ? quel objectif ? quel contexte ?";

        // Project context
        $projectContext = $this->buildProjectContext($context);
        if ($projectContext) {
            $systemPrompt .= "\n\n" . $projectContext;
        }

        // Conversation memory
        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from);
        if ($memoryContext) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }

        return $systemPrompt;
    }

    private function buildTypeInstructions(string $analysisType, string $depth): string
    {
        $depthNote = match ($depth) {
            'detailed' => " L'utilisateur veut une analyse approfondie — sois exhaustif.",
            'brief'    => " L'utilisateur veut une synthese rapide — sois concis et impactant.",
            default    => '',
        };

        return match ($analysisType) {
            self::TYPE_DOCUMENT => "\n\n" .
                "*ANALYSE DE DOCUMENT*\n" .
                "Pour tout document (image, PDF, capture), structure ta reponse ainsi :\n" .
                "1. *RESUME* : synthese en quelques phrases\n" .
                "2. *POINTS CLES* : elements importants a retenir\n" .
                "3. *RECOMMANDATIONS* : actions suggerees basees sur le contenu\n" .
                "4. *RISQUES IDENTIFIES* : points d'attention ou problemes potentiels\n" .
                "Adapte la profondeur au type de document (contrat, rapport, facture, image, etc.)." .
                $depthNote,

            self::TYPE_COMPARISON => "\n\n" .
                "*ANALYSE COMPARATIVE*\n" .
                "Pour toute comparaison, structure ta reponse ainsi :\n" .
                "- Un tableau (| Critere | Option A | Option B |) avec les criteres les plus pertinents\n" .
                "- *Avantages* et *Inconvenients* de chaque option\n" .
                "- *CONCLUSION* : recommandation claire et argumentee\n" .
                "Choisis des criteres pertinents pour l'utilisateur (cout, performance, facilite, etc.)." .
                $depthNote,

            self::TYPE_SWOT => "\n\n" .
                "*ANALYSE SWOT*\n" .
                "Structure ta reponse avec ces 4 quadrants :\n" .
                "*FORCES (Strengths)* : atouts internes\n" .
                "*FAIBLESSES (Weaknesses)* : limites internes\n" .
                "*OPPORTUNITES (Opportunities)* : facteurs externes favorables\n" .
                "*MENACES (Threats)* : facteurs externes defavorables\n" .
                "Termine par une *SYNTHESE STRATEGIQUE* avec 2-3 recommandations cles." .
                $depthNote,

            self::TYPE_PESTEL => "\n\n" .
                "*ANALYSE PESTEL*\n" .
                "Structure ta reponse avec ces 6 dimensions (indique l'impact : fort/moyen/faible) :\n" .
                "*POLITIQUE* : reglementations, politiques gouvernementales\n" .
                "*ECONOMIQUE* : conjoncture, inflation, pouvoir d'achat\n" .
                "*SOCIAL* : tendances demographiques, comportements consommateurs\n" .
                "*TECHNOLOGIQUE* : innovations, transformation digitale, R&D\n" .
                "*ENVIRONNEMENTAL* : ecologie, durabilite, reglementations vertes\n" .
                "*LEGAL* : droit du travail, propriete intellectuelle, RGPD\n" .
                "Pour chaque dimension : impact observe + recommandation strategique." .
                $depthNote,

            self::TYPE_PORTER => "\n\n" .
                "*5 FORCES DE PORTER*\n" .
                "Analyse les 5 forces avec leur intensite (forte/moderee/faible) :\n" .
                "*MENACE NOUVEAUX ENTRANTS* : barrieres a l'entree, capital requis\n" .
                "*POUVOIR FOURNISSEURS* : concentration, alternatives disponibles\n" .
                "*POUVOIR CLIENTS* : sensibilite prix, cout de changement\n" .
                "*PRODUITS SUBSTITUTS* : alternatives indirectes, rapport qualite-prix\n" .
                "*RIVALITE CONCURRENTIELLE* : nombre d'acteurs, croissance du marche\n" .
                "Termine par une *EVALUATION GLOBALE* de l'attractivite du secteur." .
                $depthNote,

            self::TYPE_DECISION => "\n\n" .
                "*MATRICE DE DECISION*\n" .
                "Pour aider a prendre une decision, utilise ce format :\n" .
                "1. *CRITERES* : liste les criteres de decision avec leur poids (1-5)\n" .
                "2. *SCORING* : tableau evaluant chaque option sur chaque critere (note x poids)\n" .
                "3. *SCORE TOTAL* : classement des options par score pondere\n" .
                "4. *RISQUES* : risques et opportunites principaux de chaque option\n" .
                "5. *RECOMMANDATION* : option recommandee avec justification claire\n" .
                "6. *CONDITIONS DE SUCCES* : facteurs cles pour reussir l'option recommandee." .
                $depthNote,

            default => "\n\n" .
                "*ANALYSE GENERALE*\n" .
                "Adapte le format a la demande. Utilise des *TITRES EN GRAS*, des listes a puces. " .
                "Structure toujours : contexte, analyse principale, recommandations." .
                $depthNote,
        };
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

    // ──────────────────────────────────────────────
    //  Help message
    // ──────────────────────────────────────────────

    private function showHelp(): AgentResult
    {
        $help =
            "*Agent d'Analyse — ZeniClaw*\n\n" .
            "*Frameworks disponibles :*\n" .
            "- *SWOT* — Forces, Faiblesses, Opportunites, Menaces\n" .
            "- *PESTEL* — Macro-environnement (Politique, Economique...)\n" .
            "- *Porter* — 5 Forces concurrentielles\n" .
            "- *Comparaison* — Tableau comparatif entre options\n" .
            "- *Decision* — Matrice de decision avec scoring pondere\n" .
            "- *Document* — Analyse de PDF/image envoye\n\n" .
            "*Exemples :*\n" .
            "- _Fais une analyse SWOT de Tesla_\n" .
            "- _Compare React vs Vue pour mon projet_\n" .
            "- _Analyse PESTEL du marche de la livraison_\n" .
            "- _Aide-moi a choisir entre AWS et GCP_\n" .
            "- _5 Forces de Porter pour Spotify_\n" .
            "- _(Envoie une image ou un PDF pour l'analyser)_\n\n" .
            "*Profondeur d'analyse :*\n" .
            "- _rapide/bref_ : synthese concise\n" .
            "- _(defaut)_ : analyse standard\n" .
            "- _approfondi/detaille_ : analyse exhaustive";

        return AgentResult::reply($help);
    }
}
