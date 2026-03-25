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
    private const TYPE_TREND      = 'trend';
    private const TYPE_RISK         = 'risk';
    private const TYPE_COST_BENEFIT = 'cost_benefit';
    private const TYPE_STAKEHOLDER  = 'stakeholder';
    private const TYPE_CANVAS       = 'canvas';
    private const TYPE_SCENARIO     = 'scenario';
    private const TYPE_PARETO       = 'pareto';
    private const TYPE_GENERAL      = 'general';

    // Max file size for media downloads (5 MB)
    private const MAX_MEDIA_BYTES = 5 * 1024 * 1024;

    // Max input length before truncation (chars) — prevents token waste on huge pastes
    private const MAX_INPUT_LENGTH = 12000;

    // Analysis type labels for response headers
    private const TYPE_LABELS = [
        self::TYPE_SWOT          => ['📊', 'Analyse SWOT'],
        self::TYPE_PESTEL        => ['🌍', 'Analyse PESTEL'],
        self::TYPE_PORTER        => ['🏭', 'Analyse 5 Forces de Porter'],
        self::TYPE_COMPARISON    => ['⚖️', 'Analyse Comparative'],
        self::TYPE_DECISION      => ['🎯', 'Matrice de Decision'],
        self::TYPE_DOCUMENT      => ['📄', 'Analyse de Document'],
        self::TYPE_TREND         => ['📈', 'Analyse de Tendances'],
        self::TYPE_RISK          => ['⚠️', 'Matrice de Risques'],
        self::TYPE_COST_BENEFIT  => ['💰', 'Analyse Cout-Benefice / ROI'],
        self::TYPE_STAKEHOLDER   => ['👥', 'Cartographie Parties Prenantes'],
        self::TYPE_CANVAS        => ['🧩', 'Business Model Canvas'],
        self::TYPE_SCENARIO      => ['🔮', 'Analyse de Scenarios'],
        self::TYPE_PARETO        => ['📊', 'Analyse Pareto (80/20)'],
        self::TYPE_GENERAL       => ['🔍', 'Analyse'],
    ];

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
            'tendance', 'tendances', 'trend', 'trends', 'evolution', 'évolution', 'projection',
            'matrice risque', 'risk matrix', 'cartographie risques', 'mapping risques',
            'probabilite impact', 'probabilité impact', 'risk assessment',
            'cout benefice', 'coût bénéfice', 'cost benefit', 'ROI', 'retour sur investissement',
            'rentabilite', 'rentabilité', 'return on investment', 'investissement',
            'parties prenantes', 'stakeholder', 'stakeholders', 'acteurs cles', 'acteurs clés',
            'mapping acteurs', 'cartographie acteurs', 'influence', 'pouvoir interet',
            'business model', 'business model canvas', 'BMC', 'modele economique', 'modèle économique',
            'proposition valeur', 'value proposition', 'canvas', 'lean canvas',
            'scenario', 'scénario', 'scenarios', 'scénarios', 'what if', 'hypothese', 'hypothèse',
            'simulation', 'planification scenario', 'scenario planning', 'et si', 'que se passe',
            'pareto', '80/20', '80 20', 'loi de pareto', 'diagramme pareto', 'priorisation',
            'vital few', 'principaux facteurs', 'causes principales',
            'export', 'exporte', 'exportable', 'a partager',
        ];
    }

    public function version(): string
    {
        return '1.7.0';
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

        // Empty body without media — guide user
        if ($body === '' && !$context->hasMedia) {
            $hint = "Envoie-moi un sujet ou un document a analyser.\nTape *aide analyse* pour voir les frameworks disponibles.";
            $this->sendText($context->from, $hint);
            return AgentResult::reply($hint);
        }

        // Input quality guard — reject meaningless or too-short input (< 3 real words)
        if (!$context->hasMedia && $this->isMeaninglessInput($body)) {
            $hint = "Ta demande est trop courte pour une analyse.\n" .
                "Precise ton sujet, par exemple :\n" .
                "- _Analyse SWOT de mon entreprise X_\n" .
                "- _Compare React vs Vue_";
            $this->sendText($context->from, $hint);
            return AgentResult::reply($hint);
        }

        // Truncate very long input to prevent token waste
        $truncated = false;
        if (mb_strlen($body) > self::MAX_INPUT_LENGTH) {
            $body = mb_substr($body, 0, self::MAX_INPUT_LENGTH) . "\n\n[... texte tronque, trop long pour une analyse complete]";
            $truncated = true;
        }

        // Warn user that input was truncated
        if ($truncated) {
            $this->sendText($context->from, "⚠️ _Ton message est tres long — j'analyse les premiers ~12 000 caracteres._");
        }

        try {
            $model        = $this->resolveModel($context);
            $analysisType = $this->detectAnalysisType($body, $context->hasMedia);
            $depth        = $this->detectAnalysisDepth($body);
            $lang         = $this->detectLanguage($body);

            // Comparison guard: detect missing second option
            if ($analysisType === self::TYPE_COMPARISON && !$this->hasMultipleComparisonOptions($body)) {
                $hint = "Tu veux comparer... mais avec quoi ? 🤔\nPrecise les options a comparer, par exemple :\n" .
                    "- _Compare *React* vs *Vue* pour mon projet_\n" .
                    "- _*AWS* ou *GCP* pour du serverless_";
                $this->setPendingContext($context, 'awaiting_clarification', [
                    'original_body' => $body,
                    'analysis_type' => $analysisType,
                    'depth'         => $depth,
                ], ttlMinutes: 10, expectRawInput: true);
                $this->sendText($context->from, $hint);
                return AgentResult::reply($hint);
            }
            $maxTokens    = $this->resolveMaxTokens($context->complexity, $analysisType, $depth);
            $systemPrompt = $this->buildSystemPrompt($context, $analysisType, $depth, $lang);
            $messageContent = $this->buildMessageContent($context);

            $messages = [['role' => 'user', 'content' => $messageContent]];

            $this->log($context, 'Analysis request received', [
                'type'       => $analysisType,
                'depth'      => $depth,
                'lang'       => $lang,
                'model'      => $model,
                'complexity' => $context->complexity,
                'has_media'  => $context->hasMedia,
                'max_tokens' => $maxTokens,
                'truncated'  => $truncated,
            ]);

            $reply = $this->claude->chatWithMessages($messages, $model, $systemPrompt, $maxTokens);

            // Fallback to Haiku if the requested model fails
            if (!$reply && $model !== ModelResolver::fast()) {
                $this->log($context, 'Primary model failed, falling back to fast model', [
                    'original_model' => $model,
                ]);
                $reply = $this->claude->chatWithMessages(
                    $messages,
                    ModelResolver::fast(),
                    $systemPrompt,
                    min($maxTokens, 2048)
                );
                $model = ModelResolver::fast() . ' (fallback)';
            }

            if (!$reply) {
                $label = self::TYPE_LABELS[$analysisType][1] ?? 'Analyse';
                $fallback = "Desolee, je n'ai pas pu generer l'{$label}. Reessaie dans quelques instants.";
                $this->sendText($context->from, $fallback);
                return AgentResult::reply($fallback);
            }

            // Sanitize: trim excessive whitespace but preserve WhatsApp formatting
            $reply = $this->sanitizeReply($reply);

            // Prefix with analysis type header (skip for general/document or clarification replies)
            if ($analysisType !== self::TYPE_GENERAL && !$this->looksLikeClarificationQuestions($reply)) {
                $reply = $this->buildAnalysisHeader($analysisType, $depth) . $reply;
            }

            // If reply looks like clarification questions, keep pending context so follow-up is routed here
            if ($this->looksLikeClarificationQuestions($reply)) {
                $this->setPendingContext($context, 'awaiting_clarification', [
                    'original_body' => $body,
                    'analysis_type' => $analysisType,
                    'depth'         => $depth,
                ], ttlMinutes: 10, expectRawInput: true);
            } elseif ($depth !== 'export') {
                // Append follow-up suggestions (skip for export format and clarifications)
                $reply .= $this->buildFollowUpSuggestions($analysisType);
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
        } catch (\Exception $e) {
            Log::error('[analysis] Unhandled error in handle()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'from'  => $context->from,
                'body'  => mb_substr($body, 0, 200),
            ]);
            $fallback = $this->buildErrorMessage($e);
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }
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

        try {
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

            $reply = $this->sanitizeReply($reply);

            // Add analysis header on follow-up too
            if ($analysisType !== self::TYPE_GENERAL && !$this->looksLikeClarificationQuestions($reply)) {
                $reply = $this->buildAnalysisHeader($analysisType, $depth) . $reply;
            }

            $this->sendText($context->from, $reply);

            $this->log($context, 'Analysis follow-up reply sent', [
                'model'        => $model,
                'type'         => $analysisType,
                'reply_length' => mb_strlen($reply),
            ]);

            return AgentResult::reply($reply, ['model' => $model, 'analysis_type' => $analysisType]);
        } catch (\Exception $e) {
            Log::error('[analysis] Error in handlePendingContext()', [
                'error' => $e->getMessage(),
                'from'  => $context->from,
            ]);
            $fallback = "⚠️ Une erreur est survenue. Reessaie dans quelques instants.";
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
        }
    }

    // ──────────────────────────────────────────────
    //  Analysis header & comparison helpers
    // ──────────────────────────────────────────────

    /**
     * Build a WhatsApp-formatted header line for the analysis type.
     */
    private function buildAnalysisHeader(string $analysisType, string $depth): string
    {
        $label = self::TYPE_LABELS[$analysisType] ?? null;
        if (!$label) {
            return '';
        }

        $depthLabel = match ($depth) {
            'detailed' => ' (approfondie)',
            'brief'    => ' (synthese)',
            'export'   => ' (export)',
            default    => '',
        };

        return "{$label[0]} *{$label[1]}{$depthLabel}*\n\n";
    }

    /**
     * Check if a comparison request mentions at least two options.
     * Looks for patterns like "X vs Y", "X ou Y", "entre X et Y".
     */
    private function hasMultipleComparisonOptions(string $body): bool
    {
        $lower = mb_strtolower($body);

        // "X vs Y", "X versus Y"
        if (preg_match('/\S+\s+(?:vs\.?|versus)\s+\S+/iu', $lower)) {
            return true;
        }

        // "entre X et Y", "X ou Y" (with enough context around)
        if (preg_match('/entre\s+\S.{1,40}\s+et\s+\S/iu', $lower)) {
            return true;
        }

        // "comparer X et Y", "comparaison X Y"
        if (preg_match('/compar\S*\s+\S.{1,40}\s+(?:et|avec|a)\s+\S/iu', $lower)) {
            return true;
        }

        // "X ou Y" where both sides have substance (avoid "analyse ou pas")
        if (preg_match('/\b(\S{2,})\s+ou\s+(\S{2,})\b/iu', $lower, $m)) {
            $noise = ['pas', 'non', 'oui', 'bien', 'mal', 'plus', 'moins'];
            if (!in_array($m[2], $noise)) {
                return true;
            }
        }

        return false;
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

        if (preg_match('/\b(d[eé]cision|matrice(?!\s*risque)|prioriser|priorit[eé]|choisir\s+entre|que\s+choisir|aide.{0,5}d[eé]cision|scoring|pondérer)\b/iu', $lower)) {
            return self::TYPE_DECISION;
        }

        if (preg_match('/\b(tendance|tendances|trend|trends|[eé]volution|projection|forecast|pr[eé]vision|prospective)\b/iu', $lower)) {
            return self::TYPE_TREND;
        }

        if (preg_match('/\b(matrice\s*risque|risk\s*matrix|cartographie\s*risques?|mapping\s*risques?|risk\s*assessment|probabilit[eé]\s*impact)\b/iu', $lower)) {
            return self::TYPE_RISK;
        }

        if (preg_match('/\b(co[uû]t.{0,5}b[eé]n[eé]fice|cost.{0,3}benefit|ROI|retour\s+sur\s+investissement|rentabilit[eé]|return\s+on\s+investment)\b/iu', $lower)) {
            return self::TYPE_COST_BENEFIT;
        }

        if (preg_match('/\b(parties?\s*prenantes?|stakeholders?|mapping\s*acteurs|cartographie\s*acteurs|pouvoir.{0,5}int[eé]r[eê]t|influence.{0,5}impact)\b/iu', $lower)) {
            return self::TYPE_STAKEHOLDER;
        }

        if (preg_match('/\b(business\s*model\s*canvas|BMC|mod[eè]le\s*[eé]conomique|lean\s*canvas|proposition\s*(?:de\s*)?valeur|value\s*proposition)\b/iu', $lower)) {
            return self::TYPE_CANVAS;
        }

        if (preg_match('/\b(sc[eé]narios?|scenario\s*planning|planification\s*sc[eé]nario|what\s*if|et\s+si\b|que\s+se\s+passe|hypoth[eè]se|simulation\s+strat[eé]gique)\b/iu', $lower)) {
            return self::TYPE_SCENARIO;
        }

        if (preg_match('/\b(pareto|80[\s\/]?20|loi\s*de\s*pareto|diagramme\s*pareto|vital\s*few|priorisation|causes?\s*principales?|principaux\s*facteurs)\b/iu', $lower)) {
            return self::TYPE_PARETO;
        }

        if (preg_match('/\b(document|pdf|fichier|contrat|rapport|facture|image|photo|capture)\b/iu', $lower)) {
            return self::TYPE_DOCUMENT;
        }

        return self::TYPE_GENERAL;
    }

    private function detectAnalysisDepth(string $body): string
    {
        if (preg_match('/\b(export|exporte|exportable|forwarding|transferable|a\s+partager|partager)\b/iu', $body)) {
            return 'export';
        }

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
            'export'   => 1536,
            default    => 2048,
        };

        // Framework analyses require more structure — ensure minimum
        if (in_array($analysisType, [self::TYPE_SWOT, self::TYPE_PESTEL, self::TYPE_PORTER, self::TYPE_DECISION, self::TYPE_TREND, self::TYPE_RISK, self::TYPE_COST_BENEFIT, self::TYPE_STAKEHOLDER, self::TYPE_CANVAS, self::TYPE_SCENARIO, self::TYPE_PARETO])) {
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

    private function buildSystemPrompt(AgentContext $context, string $analysisType, string $depth, string $lang = 'fr'): string
    {
        $langInstruction = $lang === 'en'
            ? "LANGUAGE: The user wrote in English. Respond entirely in English.\n\n"
            : '';

        $systemPrompt =
            $langInstruction .
            "Tu es ZeniClaw, un assistant analytique expert. " .
            "Tu fournis des analyses approfondies, structurees et argumentees, optimisees pour WhatsApp. " .
            "FORMATAGE WHATSAPP STRICT :\n" .
            "- Titres : *MAJUSCULES AVEC ETOILES* (jamais de # markdown)\n" .
            "- Gras : *texte* | Italique : _texte_ | Barre : ~texte~\n" .
            "- Listes : tirets (-) ou puces (•), avec retrait pour sous-listes\n" .
            "- Tableaux : utilise | Colonne | Colonne | avec separateurs\n" .
            "- Emojis : utilise des emojis pertinents pour les sections (📊 📈 ⚠️ ✅ ❌ 💡)\n" .
            "- Pas de blocs de code markdown (```), pas de liens cliquables\n\n" .
            "Tu es precis, factuel et tu cites ton raisonnement. " .
            "Tu peux etre plus long qu'une reponse normale si l'analyse le demande. " .
            "Le message vient de {$context->senderName}.";

        // Type-specific instructions
        $systemPrompt .= $this->buildTypeInstructions($analysisType, $depth);

        // Depth-specific global instructions
        if ($depth === 'detailed') {
            $systemPrompt .= "\n\n" .
                "*SYNTHESE EXECUTIF*\n" .
                "Puisque l'utilisateur demande une analyse approfondie, termine TOUJOURS par une section " .
                "'💡 *SYNTHESE EXECUTIVE*' de 3-5 lignes qui resume les conclusions cles et la recommandation " .
                "principale. Un decideur presse doit pouvoir ne lire que cette section.";
        } elseif ($depth === 'brief') {
            $systemPrompt .= "\n\n" .
                "*FORMAT CONCIS*\n" .
                "L'utilisateur veut une synthese rapide. Limite-toi a l'essentiel : " .
                "pas plus de 5-8 points. Termine par une *CONCLUSION* en 1-2 phrases.";
        } elseif ($depth === 'export') {
            $systemPrompt .= "\n\n" .
                "*FORMAT EXPORT (A PARTAGER)*\n" .
                "L'utilisateur veut un document pret a etre transfere/partage. Regles :\n" .
                "- Commence par un titre clair et une date (aujourd'hui)\n" .
                "- Ton professionnel et neutre (pas de tutoiement, pas de 'tu')\n" .
                "- Structure avec sections numerotees\n" .
                "- Termine par *CONCLUSION* et *PROCHAINES ETAPES* (actions concretes)\n" .
                "- Pas d'emojis excessifs, garde un ton formel\n" .
                "- Le document doit etre comprehensible sans contexte additionnel";
        }

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

        // Learned skills
        $skills = $this->getSkillsForPrompt($context);
        if ($skills) {
            $systemPrompt .= "\n\n" . $skills;
        }

        // Anti-hallucination
        $systemPrompt .= "\n\n" . $this->getAntiHallucinationRule();

        return $systemPrompt;
    }

    private function buildTypeInstructions(string $analysisType, string $depth): string
    {
        $depthNote = match ($depth) {
            'detailed' => " L'utilisateur veut une analyse approfondie — sois exhaustif.",
            'brief'    => " L'utilisateur veut une synthese rapide — sois concis et impactant.",
            'export'   => " Format export : ton professionnel, structure claire, pret a partager.",
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

            self::TYPE_TREND => "\n\n" .
                "*ANALYSE DE TENDANCES*\n" .
                "Structure ta reponse ainsi :\n" .
                "1. *ETAT ACTUEL* : situation presente, chiffres cles si disponibles\n" .
                "2. *TENDANCES IDENTIFIEES* : mouvements majeurs observes (haussier/baissier/stable)\n" .
                "3. *FACTEURS MOTEURS* : ce qui pousse chaque tendance\n" .
                "4. *PROJECTIONS* : scenarios probable/optimiste/pessimiste\n" .
                "5. *RECOMMANDATIONS* : actions a entreprendre selon chaque scenario\n" .
                "Utilise des indicateurs temporels (court/moyen/long terme) quand pertinent." .
                $depthNote,

            self::TYPE_RISK => "\n\n" .
                "*MATRICE DE RISQUES*\n" .
                "Cartographie les risques avec ce format :\n" .
                "1. *IDENTIFICATION* : liste tous les risques identifies\n" .
                "2. *EVALUATION* : pour chaque risque, indique :\n" .
                "   - _Probabilite_ : faible / moyenne / elevee\n" .
                "   - _Impact_ : faible / moyen / eleve / critique\n" .
                "   - _Niveau_ : P x I (score global)\n" .
                "3. *MATRICE VISUELLE* : tableau Impact (colonnes) vs Probabilite (lignes)\n" .
                "4. *PLAN D'ATTENUATION* : mesures preventives pour les risques eleves/critiques\n" .
                "5. *RISQUES RESIDUELS* : risques acceptables apres attenuation\n" .
                "Priorise les risques du plus critique au plus faible." .
                $depthNote,

            self::TYPE_COST_BENEFIT => "\n\n" .
                "*ANALYSE COUT-BENEFICE / ROI*\n" .
                "Structure ta reponse ainsi :\n" .
                "1. *COUTS IDENTIFIES* : investissement initial, couts recurrents, couts caches\n" .
                "2. *BENEFICES ATTENDUS* : gains directs (revenus, economies) et indirects (image, satisfaction)\n" .
                "3. *CALCUL ROI* : formule simplifiee et estimation (ROI = (Benefices - Couts) / Couts x 100)\n" .
                "4. *DELAI DE RENTABILITE* : point d'equilibre estime (break-even)\n" .
                "5. *ANALYSE SENSIBILITE* : scenarios optimiste / realiste / pessimiste\n" .
                "6. *RECOMMANDATION* : verdict clair avec conditions de succes\n" .
                "Utilise des chiffres concrets quand possible, sinon des fourchettes estimees." .
                $depthNote,

            self::TYPE_STAKEHOLDER => "\n\n" .
                "*CARTOGRAPHIE DES PARTIES PRENANTES*\n" .
                "Structure ta reponse ainsi :\n" .
                "1. *IDENTIFICATION* : liste toutes les parties prenantes (internes et externes)\n" .
                "2. *CLASSIFICATION* : pour chaque partie prenante, indique :\n" .
                "   - _Pouvoir_ : faible / moyen / fort\n" .
                "   - _Interet_ : faible / moyen / fort\n" .
                "   - _Attitude_ : favorable / neutre / hostile\n" .
                "3. *MATRICE POUVOIR-INTERET* : tableau classant les acteurs en 4 quadrants :\n" .
                "   - Fort pouvoir + fort interet → *Gerer de pres*\n" .
                "   - Fort pouvoir + faible interet → *Satisfaire*\n" .
                "   - Faible pouvoir + fort interet → *Informer*\n" .
                "   - Faible pouvoir + faible interet → *Surveiller*\n" .
                "4. *STRATEGIE D'ENGAGEMENT* : actions cles pour chaque groupe prioritaire\n" .
                "5. *RISQUES RELATIONNELS* : conflits potentiels et mesures preventives" .
                $depthNote,

            self::TYPE_CANVAS => "\n\n" .
                "*BUSINESS MODEL CANVAS*\n" .
                "Structure ta reponse avec les 9 blocs du Canvas :\n" .
                "*SEGMENTS CLIENTS* : qui sont les clients cibles ?\n" .
                "*PROPOSITION DE VALEUR* : quelle valeur unique est offerte ?\n" .
                "*CANAUX* : comment le produit/service atteint les clients ?\n" .
                "*RELATIONS CLIENTS* : quel type de relation est maintenu ?\n" .
                "*SOURCES DE REVENUS* : comment le business genere de l'argent ?\n" .
                "*RESSOURCES CLES* : quels actifs sont indispensables ?\n" .
                "*ACTIVITES CLES* : quelles activites essentielles ?\n" .
                "*PARTENAIRES CLES* : quels partenaires strategiques ?\n" .
                "*STRUCTURE DE COUTS* : quels sont les principaux postes de depenses ?\n" .
                "Termine par une *COHERENCE GLOBALE* evaluant la viabilite du modele et 2-3 pistes d'amelioration." .
                $depthNote,

            self::TYPE_SCENARIO => "\n\n" .
                "*ANALYSE DE SCENARIOS*\n" .
                "Structure ta reponse ainsi :\n" .
                "1. *SITUATION DE REFERENCE* : contexte actuel et hypotheses de base\n" .
                "2. *VARIABLES CLES* : facteurs ayant le plus d'influence sur l'issue\n" .
                "3. *SCENARIOS* : presente 3 scenarios contrastes :\n" .
                "   - 🟢 *Optimiste* : conditions favorables, probabilite, consequences\n" .
                "   - 🟡 *Realiste* : evolution la plus probable\n" .
                "   - 🔴 *Pessimiste* : conditions defavorables, risques, consequences\n" .
                "4. *PLAN D'ACTION* : strategie adaptee a chaque scenario\n" .
                "5. *SIGNAUX A SURVEILLER* : indicateurs pour detecter vers quel scenario on se dirige\n" .
                "Utilise des probabilites estimees (%) pour chaque scenario quand possible." .
                $depthNote,

            self::TYPE_PARETO => "\n\n" .
                "*ANALYSE PARETO (80/20)*\n" .
                "Applique le principe de Pareto pour identifier les facteurs les plus impactants :\n" .
                "1. *INVENTAIRE* : liste tous les facteurs/causes/elements identifies\n" .
                "2. *QUANTIFICATION* : estime l'impact de chaque facteur (en %, volume ou valeur)\n" .
                "3. *CLASSEMENT* : trie par impact decroissant avec cumul\n" .
                "4. *ZONE 80/20* : identifie les ~20% de facteurs qui causent ~80% de l'effet\n" .
                "   Separe clairement : 🔴 *Facteurs vitaux* vs ⚪ *Facteurs secondaires*\n" .
                "5. *PLAN D'ACTION* : actions prioritaires sur les facteurs vitaux\n" .
                "6. *GAINS ATTENDUS* : estimation de l'amelioration si les facteurs vitaux sont traites\n" .
                "Presente un tableau recapitulatif : | Facteur | Impact | Cumul | Zone |" .
                $depthNote,

            default => "\n\n" .
                "*ANALYSE GENERALE*\n" .
                "Adapte le format a la demande. Utilise des *TITRES EN GRAS*, des listes a puces. " .
                "Structure toujours : contexte, analyse principale, recommandations." .
                $depthNote,
        };
    }

    /**
     * Suggest related analysis frameworks based on the current analysis type.
     */
    private function buildFollowUpSuggestions(string $analysisType): string
    {
        $suggestions = match ($analysisType) {
            self::TYPE_SWOT => [
                'Analyse PESTEL pour le macro-environnement',
                'Matrice de decision pour choisir ta strategie',
                'Analyse de scenarios pour anticiper les evolutions',
            ],
            self::TYPE_PESTEL => [
                'SWOT pour completer avec les forces internes',
                '5 Forces de Porter pour l\'analyse sectorielle',
                'Analyse de tendances pour les projections',
            ],
            self::TYPE_PORTER => [
                'SWOT pour integrer les forces internes',
                'Business Model Canvas pour structurer ta strategie',
                'Analyse de risques pour les menaces identifiees',
            ],
            self::TYPE_COMPARISON => [
                'Matrice de decision avec scoring pondere',
                'Analyse cout-benefice pour chiffrer les options',
                'Analyse de risques pour chaque option',
            ],
            self::TYPE_DECISION => [
                'Analyse de risques pour l\'option choisie',
                'Analyse cout-benefice pour valider le ROI',
                'Cartographie parties prenantes pour l\'implementation',
            ],
            self::TYPE_RISK => [
                'Analyse de scenarios pour les risques majeurs',
                'Analyse cout-benefice des mesures d\'attenuation',
                'Cartographie parties prenantes impactees',
            ],
            self::TYPE_COST_BENEFIT => [
                'Analyse de risques pour securiser l\'investissement',
                'Analyse de scenarios optimiste/pessimiste',
                'Analyse Pareto des postes de couts',
            ],
            self::TYPE_CANVAS => [
                'Analyse SWOT du business model',
                'Analyse cout-benefice du modele',
                '5 Forces de Porter pour le secteur',
            ],
            self::TYPE_PARETO => [
                'Analyse de risques sur les facteurs vitaux',
                'Analyse cout-benefice des actions prioritaires',
                'Matrice de decision pour prioriser les actions',
            ],
            default => [
                'Analyse SWOT pour une vue strategique',
                'Comparaison d\'options alternatives',
                'Matrice de decision pour trancher',
            ],
        };

        $lines = "\n\n💡 *Pour aller plus loin :*";
        foreach (array_slice($suggestions, 0, 2) as $s) {
            $lines .= "\n- _{$s}_";
        }

        return $lines;
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
            $url = $project->github_url ?? $project->gitlab_url ?? '';
            $lines[] = "- {$project->name}" . ($url ? " ({$url})" : '');
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
    //  Reply sanitization
    // ──────────────────────────────────────────────

    private function sanitizeReply(string $reply): string
    {
        // Remove markdown headers (# Title) — not supported on WhatsApp
        $reply = preg_replace('/^#{1,6}\s+(.+)$/mu', '*$1*', $reply);

        // Convert **bold** to *bold* (WhatsApp format)
        $reply = preg_replace('/\*\*(.+?)\*\*/u', '*$1*', $reply);

        // Remove code blocks (```lang ... ```) — flatten to plain text
        $reply = preg_replace('/```\w*\n?/u', '', $reply);

        // Remove inline code backticks
        $reply = preg_replace('/`([^`]+)`/u', '$1', $reply);

        // Convert markdown links [text](url) to "text (url)"
        $reply = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1 ($2)', $reply);

        // Convert markdown numbered lists (1. ) to plain numbered (1) )
        $reply = preg_replace('/^(\d+)\.\s/mu', '$1) ', $reply);

        // Remove horizontal rules (---, ***, ___)
        $reply = preg_replace('/^[-*_]{3,}\s*$/mu', '', $reply);

        // Clean up stray double-bold markers (**** or ***text***)
        $reply = preg_replace('/\*{3,}([^*]+)\*{3,}/u', '*$1*', $reply);

        // Convert markdown blockquotes (> text) to italic on WhatsApp
        $reply = preg_replace('/^>\s*(.+)$/mu', '_$1_', $reply);

        // Convert __underline__ to _italic_ (WhatsApp has no underline)
        $reply = preg_replace('/__(.+?)__/u', '_$1_', $reply);

        // Convert ~~strikethrough~~ to ~strikethrough~ (WhatsApp uses single ~)
        $reply = preg_replace('/~~(.+?)~~/u', '~$1~', $reply);

        // Remove HTML entities that may leak from LLM output
        $reply = str_replace(['&amp;', '&lt;', '&gt;', '&nbsp;', '&quot;', '&#39;'], ['&', '<', '>', ' ', '"', "'"], $reply);

        // Remove stray HTML tags that LLMs sometimes produce
        $reply = preg_replace('/<\/?(?:br|p|div|span|strong|em|b|i|ul|ol|li|h[1-6])\s*\/?>/iu', '', $reply);

        // Fix orphaned bold markers (odd number of *) — remove unpaired ones
        $reply = preg_replace('/(?<!\*)\*(?!\*)(?=[^*]*$)/u', '', $reply);

        // Collapse 3+ consecutive newlines into 2
        $reply = preg_replace('/\n{3,}/', "\n\n", $reply);

        // Trim trailing whitespace on each line
        $reply = preg_replace('/[ \t]+$/mu', '', $reply);

        return trim($reply);
    }

    // ──────────────────────────────────────────────
    //  Input quality & language detection
    // ──────────────────────────────────────────────

    /**
     * Reject meaningless input: fewer than 3 real words (ignoring emojis, punctuation).
     */
    private function isMeaninglessInput(string $body): bool
    {
        // Strip emojis and punctuation, count remaining words
        $cleaned = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27BF}]|[\x{FE00}-\x{FE0F}]|[\x{1F900}-\x{1F9FF}]/u', '', $body);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', '', $cleaned);
        $words   = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);

        return count($words) < 3;
    }

    /**
     * Simple language detection: returns 'en' if input looks English, 'fr' otherwise.
     */
    private function detectLanguage(string $body): string
    {
        $lower = mb_strtolower($body);

        // Count English indicator words
        $enWords = preg_match_all('/\b(the|is|are|was|were|have|has|will|would|could|should|this|that|with|from|for|and|but|not|what|how|why|which|their|about|into|your|make|analysis|compare|between|market|business|please)\b/iu', $lower);

        // Count French indicator words
        $frWords = preg_match_all('/\b(le|la|les|un|une|des|est|sont|avec|dans|pour|que|qui|sur|par|pas|mon|ton|son|cette|faire|analyse|comparer|entre|marche|comment|pourquoi|quel|quelle)\b/iu', $lower);

        // Default to French; switch to English only if clearly English
        if ($enWords > $frWords && $enWords >= 3) {
            return 'en';
        }

        return 'fr';
    }

    /**
     * Build a user-friendly error message based on exception type.
     */
    private function buildErrorMessage(\Exception $e): string
    {
        $msg = $e->getMessage();

        if (stripos($msg, 'timeout') !== false || stripos($msg, 'timed out') !== false) {
            return "⏱️ L'analyse a pris trop de temps.\n" .
                "💡 _Essaie avec un texte plus court ou ajoute *bref* a ta demande._";
        }

        if (stripos($msg, 'rate limit') !== false || stripos($msg, '429') !== false) {
            return "🚦 Trop de demandes en ce moment.\n" .
                "_Reessaie dans 30 secondes._";
        }

        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return "🔑 Probleme d'authentification API. Contacte l'administrateur.";
        }

        if (stripos($msg, '500') !== false || stripos($msg, 'internal server') !== false ||
            stripos($msg, '503') !== false || stripos($msg, 'service unavailable') !== false) {
            return "🔧 Le service d'analyse est temporairement indisponible.\n" .
                "_Reessaie dans quelques minutes._";
        }

        if (stripos($msg, 'too large') !== false || stripos($msg, 'content_too_large') !== false ||
            stripos($msg, 'max_tokens') !== false) {
            return "📏 Ton contenu est trop volumineux pour etre analyse en une fois.\n" .
                "💡 _Essaie de decouper en parties ou ajoute *bref* a ta demande._";
        }

        return "⚠️ Une erreur est survenue lors de l'analyse. Reessaie dans quelques instants.\n" .
            "💡 _Astuce : tape *aide analyse* pour voir les commandes disponibles._";
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
            "- *Tendances* — Analyse de tendances et projections\n" .
            "- *Risques* — Matrice de risques avec plan d'attenuation\n" .
            "- *Cout-Benefice* — Analyse ROI et rentabilite\n" .
            "- *Parties prenantes* — Cartographie pouvoir/interet\n" .
            "- *Business Model Canvas* — 9 blocs du modele economique\n" .
            "- *Scenarios* — Analyse what-if avec 3 scenarios\n" .
            "- *Pareto (80/20)* — Identifier les facteurs les plus impactants\n" .
            "- *Document* — Analyse de PDF/image envoye\n\n" .
            "*Exemples :*\n" .
            "- _Fais une analyse SWOT de Tesla_\n" .
            "- _Compare React vs Vue pour mon projet_\n" .
            "- _Analyse PESTEL du marche de la livraison_\n" .
            "- _Aide-moi a choisir entre AWS et GCP_\n" .
            "- _5 Forces de Porter pour Spotify_\n" .
            "- _Tendances du marche SaaS en 2026_\n" .
            "- _Matrice de risques pour un lancement produit_\n" .
            "- _Analyse cout-benefice d'un CRM_\n" .
            "- _Cartographie parties prenantes projet X_\n" .
            "- _Business model canvas pour une app de covoiturage_\n" .
            "- _Scenarios : et si le prix du petrole double ?_\n" .
            "- _Analyse Pareto des reclamations clients_\n" .
            "- _(Envoie une image ou un PDF pour l'analyser)_\n\n" .
            "*Profondeur d'analyse :*\n" .
            "- _rapide/bref_ : synthese concise avec conclusion directe\n" .
            "- _(defaut)_ : analyse standard structuree\n" .
            "- _approfondi/detaille_ : analyse exhaustive avec synthese executive\n" .
            "- _export/partager_ : format professionnel pret a transferer\n\n" .
            "*Nouveautes v1.7 :*\n" .
            "- 📊 Nouveau framework *Pareto (80/20)* pour la priorisation\n" .
            "- 💡 Suggestions de suivi apres chaque analyse\n" .
            "- 🔧 Meilleure gestion d'erreurs (service indisponible, contenu trop large)\n" .
            "- ✨ Sanitisation amelioree du formatage WhatsApp";

        return AgentResult::reply($help);
    }
}
