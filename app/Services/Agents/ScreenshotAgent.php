<?php

namespace App\Services\Agents;

use App\Jobs\ProcessScreenshotJob;
use App\Services\AgentContext;
use App\Services\ImageProcessor;

class ScreenshotAgent extends BaseAgent
{
    private ImageProcessor $imageProcessor;

    public function __construct()
    {
        parent::__construct();
        $this->imageProcessor = new ImageProcessor();
    }

    public function name(): string
    {
        return 'screenshot';
    }

    public function description(): string
    {
        return 'Agent de traitement d\'images et OCR. Extraction de texte (OCR) depuis des images, analyse visuelle avec IA (Claude Vision), annotation d\'images (fleches, rectangles, cercles), comparaison d\'images en 2 etapes, et informations detaillees sur les images.';
    }

    public function keywords(): array
    {
        return [
            'screenshot', 'capture', 'capture ecran', 'screen capture',
            'OCR', 'ocr', 'extract text', 'extract-text', 'extraire texte',
            'lire texte', 'lire image', 'read text', 'read image',
            'transcrire image', 'transcribe image',
            'annoter', 'annotate', 'annotation', 'marquer', 'surligner', 'highlight',
            'comparer images', 'compare images', 'difference images', 'diff images',
            'info image', 'image info', 'dimensions image', 'taille image',
            'fleche', 'arrow', 'rectangle', 'cercle', 'circle',
            'texte dans image', 'text in image',
            'analyser image', 'analyse image', 'analyze image', 'describe image',
            'decrire image', 'analyser', 'analyzer', 'describe', 'vision',
            'que vois-tu', 'qu est-ce que', 'identifie', 'reconnnais',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body && !$context->hasMedia) {
            return false;
        }

        $body = mb_strtolower($context->body ?? '');

        // Explicit screenshot/OCR/annotation/compare commands
        if (preg_match('/\b(screenshot|capture|annotate|ocr|extract[\s-]?text|compare[r]?|extraire[\s-]?texte)\b/i', $body)) {
            return true;
        }

        // Explicit analyze/describe commands
        if (preg_match('/\b(analys[ea]r?|describe|decri[rt]|vision|identifi[eé]|reconnai[st]|que[\s-]vois[\s-]tu)\b/iu', $body)) {
            return true;
        }

        // Image with extract-text intent
        if ($context->hasMedia && $this->isImageMedia($context->mimetype) &&
            preg_match('/\b(texte|text|lire|read|ocr|extraire|extract|transcrire|transcribe)\b/iu', $body)) {
            return true;
        }

        // Image with analysis intent
        if ($context->hasMedia && $this->isImageMedia($context->mimetype) &&
            preg_match('/\b(analys|decri|describe|montre|expliqu|qu.est|c.est|quoi|what|how|comment)\b/iu', $body)) {
            return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body) && !$context->hasMedia) {
            return $this->showHelp();
        }

        $command = $this->parseCommand($body);
        $this->log($context, 'Screenshot command received', ['command' => $command['action'], 'has_media' => $context->hasMedia]);

        return match ($command['action']) {
            'extract-text' => $this->handleExtractText($context, $command),
            'annotate'     => $this->handleAnnotate($context, $command),
            'compare'      => $this->handleCompare($context, $command),
            'capture'      => $this->handleCapture($context, $command),
            'info'         => $this->handleImageInfo($context),
            'analyze'      => $this->handleAnalyze($context, $body),
            default        => $this->handleWithClaude($context, $body),
        };
    }

    /**
     * Handle pending context for multi-step flows (e.g. compare step 2).
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') === 'compare_step2') {
            return $this->handleCompareStep2($context, $pendingContext['data'] ?? []);
        }

        return null;
    }

    // ── Command parsing ────────────────────────────────────────────────────────

    private function parseCommand(string $body): array
    {
        $lower = mb_strtolower($body);

        // Extract text / OCR
        if (preg_match('/\b(extract[\s-]?text|ocr|extraire[\s-]?texte|lire[\s-]?(le\s+)?texte|transcrire)\b/iu', $lower)) {
            $lang = 'fra+eng';
            if (preg_match('/\blang(?:ue)?[:\s]+(\w+)/i', $body, $m)) {
                $lang = $m[1];
            }
            return ['action' => 'extract-text', 'language' => $lang];
        }

        // Analyze / describe — Claude Vision
        if (preg_match('/\b(analys[ea]r?|analyze|describe|decri[rt]|vision|identifi[eé]|reconnai[st]|que[\s-]vois[\s-]tu)\b/iu', $lower)) {
            return ['action' => 'analyze'];
        }

        // Annotate
        if (preg_match('/\b(annotate|annoter|marquer|highlight|surligner)\b/iu', $lower)) {
            return $this->parseAnnotateCommand($body);
        }

        // Compare
        if (preg_match('/\b(compare[r]?|diff|difference|comparer)\b/iu', $lower)) {
            return ['action' => 'compare'];
        }

        // Image info
        if (preg_match('/\b(info|details|metadata|taille|dimensions?|poids)\b/iu', $lower)) {
            return ['action' => 'info'];
        }

        // Capture (description-based)
        if (preg_match('/\b(capture|screenshot|screen)\b/iu', $lower)) {
            $description = preg_replace('/\b(capture|screenshot|screen|@screenshot)\b/iu', '', $body);
            return ['action' => 'capture', 'description' => trim($description)];
        }

        // Default: auto-detect by media presence
        return ['action' => 'auto'];
    }

    private function parseAnnotateCommand(string $body): array
    {
        $annotation = [
            'action'      => 'annotate',
            'type'        => 'rectangle',
            'color'       => 'red',
            'coordinates' => [],
            'text'        => '',
        ];

        // Detect annotation type
        if (preg_match('/\b(arrow|fleche)\b/iu', $body)) {
            $annotation['type'] = 'arrow';
        } elseif (preg_match('/\b(circle|cercle|rond)\b/iu', $body)) {
            $annotation['type'] = 'circle';
        } elseif (preg_match('/\b(text|texte|label)\b/iu', $body)) {
            $annotation['type'] = 'text';
        } elseif (preg_match('/\b(highlight|surlign)\b/iu', $body)) {
            $annotation['type'] = 'rectangle';
        }

        // Detect color
        if (preg_match('/\b(red|rouge|green|vert|blue|bleu|yellow|jaune|orange|white|blanc|black|noir|cyan|magenta|violet|purple)\b/iu', $body, $m)) {
            $colorMap = [
                'rouge' => 'red', 'vert' => 'green', 'bleu' => 'blue',
                'jaune' => 'yellow', 'blanc' => 'white', 'noir' => 'black',
                'violet' => 'magenta', 'purple' => 'magenta',
            ];
            $annotation['color'] = $colorMap[mb_strtolower($m[1])] ?? mb_strtolower($m[1]);
        }

        // Parse coordinates [x1,y1,x2,y2]
        if (preg_match('/\[(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\]/', $body, $m)) {
            $annotation['coordinates'] = [
                'x1' => (int) $m[1],
                'y1' => (int) $m[2],
                'x2' => (int) $m[3],
                'y2' => (int) $m[4],
            ];
        }

        // Parse text for text annotations
        if (preg_match('/text[e]?\s*[=:]\s*["\']([^"\']+)["\']/iu', $body, $m)) {
            $annotation['text'] = $m[1];
        }

        return $annotation;
    }

    // ── Handlers ───────────────────────────────────────────────────────────────

    private function handleExtractText(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "Envoie-moi une image avec ta demande d'extraction de texte.\n\n"
                . "Exemple : envoie une photo + 'extract-text'\n"
                . "Option langue : 'extract-text lang:eng' (fra, eng, deu, spa...)"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour l'OCR.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie dans quelques secondes.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image. Verifie ta connexion et reessaie.");
        }

        $language = $command['language'] ?? 'fra+eng';
        $extractedText = $this->imageProcessor->extractText($imagePath, $language);
        @unlink($imagePath);

        if (empty($extractedText)) {
            return AgentResult::reply(
                "Aucun texte detecte dans l'image.\n\n"
                . "*Conseils :*\n"
                . "- Verifie que l'image est nette et bien eclairee\n"
                . "- Le texte doit etre lisible et d'une taille suffisante\n"
                . "- Essaie une langue specifique : 'extract-text lang:eng' ou 'lang:fra'\n"
                . "- Ou utilise 'analyse' pour une description visuelle IA de l'image"
            );
        }

        $wordCount = str_word_count($extractedText);
        $this->log($context, 'OCR completed', ['text_length' => mb_strlen($extractedText), 'language' => $language, 'words' => $wordCount]);

        return AgentResult::reply(
            "*Texte extrait de l'image :*\n\n"
            . $extractedText . "\n\n"
            . "---\n"
            . "_Langue OCR : {$language} | ~{$wordCount} mots_"
        );
    }

    /**
     * NEW: Analyze image using Claude Vision API.
     */
    private function handleAnalyze(AgentContext $context, string $userMessage): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Analyse visuelle IA*\n\n"
                . "Envoie-moi une image et je l'analyserai en detail avec Claude Vision.\n\n"
                . "Exemples :\n"
                . "- Envoie une photo + 'analyse'\n"
                . "- Envoie un screenshot + 'decris ce que tu vois'\n"
                . "- Envoie une image + 'identifie les elements'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image pour l'analyser.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $base64 = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
        // Normalize MIME for Anthropic (only jpeg, png, gif, webp supported)
        $mimeType = $this->normalizeImageMime($mimeType);
        @unlink($imagePath);

        $model = $this->resolveModel($context);
        // Force a Claude model for vision (on-prem may not support multimodal)
        if (!str_starts_with($model, 'claude-')) {
            $model = 'claude-haiku-4-5-20251001';
        }

        $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);
        $systemPrompt = "Tu es un assistant expert en analyse d'images pour WhatsApp.\n"
            . "Analyse l'image fournie de maniere claire et structuree.\n"
            . "Identifie les elements visuels importants, le contexte, et toute information utile.\n"
            . "Sois concis mais precis. Reponds en francais avec du *gras* pour les points cles.";

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        // Build prompt from user message
        $cleanMsg = trim(preg_replace('/\b(analys[ea]r?|analyze|describe|decri[rt]|vision|identifi[eé]|reconnai[st]|que[\s-]vois[\s-]tu)\b/iu', '', $userMessage));
        $prompt = $cleanMsg
            ? "Analyse cette image. L'utilisateur demande specifiquement : {$cleanMsg}"
            : "Analyse et decris cette image en detail. Identifie les elements visuels, le contexte et toute information pertinente.";

        $content = [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mimeType,
                    'data'       => $base64,
                ],
            ],
            ['type' => 'text', 'text' => $prompt],
        ];

        $response = $this->claude->chat($content, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply(
                "Impossible d'analyser l'image avec Claude Vision.\n\n"
                . "Tu peux essayer :\n"
                . "- 'extract-text' pour extraire le texte\n"
                . "- 'info' pour les dimensions et format"
            );
        }

        $this->log($context, 'Vision analysis completed', ['model' => $model, 'prompt_length' => mb_strlen($prompt)]);

        return AgentResult::reply("*Analyse de l'image :*\n\n" . $response);
    }

    private function handleAnnotate(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Annotation d'image*\n\n"
                . "Envoie-moi une image a annoter.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'annotate <type> [x1,y1,x2,y2] <couleur>'\n\n"
                . "*Types disponibles :*\n"
                . "- arrow (fleche)\n"
                . "- rectangle\n"
                . "- circle (cercle)\n"
                . "- text (avec text='mon label')\n\n"
                . "*Couleurs :* red, green, blue, yellow, orange, cyan, magenta"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image pour l'annoter.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        ProcessScreenshotJob::dispatch(
            $context->from,
            $context->agent->id,
            $mediaUrl,
            'annotate',
            $command
        );

        $typeLabels = [
            'arrow'     => 'fleche',
            'rectangle' => 'rectangle',
            'circle'    => 'cercle',
            'text'      => 'texte',
        ];
        $typeLabel = $typeLabels[$command['type']] ?? $command['type'];

        return AgentResult::reply(
            "Annotation en cours...\n\n"
            . "Type: *{$typeLabel}* | Couleur: *{$command['color']}*\n"
            . "_Tu recevras l'image annotee dans quelques secondes._"
        );
    }

    /**
     * Compare — Step 1: store first image in pending context and wait for second.
     */
    private function handleCompare(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Comparaison d'images*\n\n"
                . "Envoie-moi la *premiere image* a comparer avec le mot 'compare'.\n\n"
                . "Puis envoie la *deuxieme image* avec 'compare' et je te montrerai\n"
                . "les differences avec le pourcentage de similarite."
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG...) pour la comparer.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        // Store first image URL in pending context (10 min TTL)
        $this->setPendingContext($context, 'compare_step2', [
            'image1_url' => $mediaUrl,
        ], 10);

        $this->log($context, 'Compare step 1 — waiting for second image');

        return AgentResult::reply(
            "*Comparaison d'images — Etape 1/2*\n\n"
            . "Premiere image enregistree!\n\n"
            . "Maintenant envoie la *deuxieme image* avec 'compare' pour lancer la comparaison."
        );
    }

    /**
     * Compare — Step 2: receive second image, compare both, send result.
     */
    private function handleCompareStep2(AgentContext $context, array $data): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            $this->clearPendingContext($context);
            return AgentResult::reply(
                "Comparaison annulee — aucune image recue.\n\n"
                . "Recommence en envoyant la premiere image + 'compare'."
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            $this->clearPendingContext($context);
            return AgentResult::reply("Ce fichier n'est pas une image. Comparaison annulee.");
        }

        $image1Url = $data['image1_url'] ?? null;
        $image2Url = $this->resolveMediaUrl($context);

        if (!$image1Url || !$image2Url) {
            $this->clearPendingContext($context);
            return AgentResult::reply("Erreur : impossible de recuperer les images. Recommence la comparaison.");
        }

        // Download both images
        $imagePath1 = $this->imageProcessor->downloadFromWaha($image1Url);
        $imagePath2 = $this->imageProcessor->downloadFromWaha($image2Url);

        if (!$imagePath1 || !$imagePath2) {
            @unlink($imagePath1 ?? '');
            @unlink($imagePath2 ?? '');
            $this->clearPendingContext($context);
            return AgentResult::reply("Erreur lors du telechargement des images. Reessaie.");
        }

        $this->clearPendingContext($context);

        // Dispatch comparison as background job (can be heavy for large images)
        ProcessScreenshotJob::dispatch(
            $context->from,
            $context->agent->id,
            $image2Url,
            'compare',
            ['image1_url' => $image1Url, 'image1_path' => $imagePath1]
        );

        @unlink($imagePath2);

        $this->log($context, 'Compare step 2 — dispatched comparison job');

        return AgentResult::reply(
            "*Comparaison d'images — En cours...*\n\n"
            . "Les deux images ont ete recues. Comparaison en cours...\n"
            . "_Tu recevras le resultat avec le pourcentage de similarite dans quelques secondes._"
        );
    }

    private function handleCapture(AgentContext $context, array $command): AgentResult
    {
        // If image is sent with 'capture', show its info
        if ($context->hasMedia && $this->isImageMedia($context->mimetype)) {
            return $this->handleImageInfo($context);
        }

        $description = $command['description'] ?? '';

        return AgentResult::reply(
            "*Capture d'ecran*\n\n"
            . "Je ne peux pas capturer ton ecran directement depuis WhatsApp, mais je peux traiter les images que tu m'envoies :\n\n"
            . "- *Extraire du texte* : Image + 'extract-text'\n"
            . "- *Analyser* : Image + 'analyse' (description IA)\n"
            . "- *Annoter* : Image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "- *Comparer* : Image + 'compare'\n"
            . "- *Infos* : Image + 'info'"
            . ($description ? "\n\n_Ta description : {$description}_" : '')
        );
    }

    private function handleImageInfo(AgentContext $context): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "Envoie-moi une image pour obtenir ses informations detaillees.\n\n"
                . "Exemple : envoie une image + 'info'"
            );
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $info = $this->imageProcessor->getImageInfo($imagePath);
        @unlink($imagePath);

        if (isset($info['error'])) {
            return AgentResult::reply("Impossible de lire les informations de cette image.");
        }

        $width  = $info['width'] ?? 0;
        $height = $info['height'] ?? 0;
        $ratio  = ($height > 0) ? round($width / $height, 2) : 'N/A';

        $orientation = match (true) {
            $width > $height => 'Paysage (Landscape)',
            $width < $height => 'Portrait',
            default          => 'Carre',
        };

        return AgentResult::reply(
            "*Informations sur l'image :*\n\n"
            . "Dimensions : *{$width} x {$height} px*\n"
            . "Ratio : {$ratio} ({$orientation})\n"
            . "Format : {$info['mime']}\n"
            . "Taille : *{$info['size_human']}*\n\n"
            . "_Conseil : utilise 'analyse' pour une description IA, ou 'extract-text' pour lire le texte._"
        );
    }

    /**
     * Fallback: analyze with Claude Vision if image sent, else show help.
     */
    private function handleWithClaude(AgentContext $context, string $body): AgentResult
    {
        if ($context->hasMedia && $this->isImageMedia($context->mimetype)) {
            // If image is sent without a recognized command, use Claude Vision to analyze it
            return $this->handleAnalyze($context, $body);
        }

        return $this->showHelp();
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*Screenshot & Annotate — Traitement d'images IA*\n\n"
            . "*Commandes disponibles :*\n\n"
            . "*Analyser une image (IA) :*\n"
            . "Image + 'analyse' ou 'describe'\n\n"
            . "*Extraire du texte (OCR) :*\n"
            . "Image + 'extract-text'\n"
            . "Avec langue : 'extract-text lang:eng'\n\n"
            . "*Annoter une image :*\n"
            . "Image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "Types : arrow, rectangle, circle, text\n"
            . "Couleurs : red, green, blue, yellow, orange, cyan\n\n"
            . "*Comparer deux images :*\n"
            . "Image 1 + 'compare' → puis Image 2 + 'compare'\n\n"
            . "*Infos image :*\n"
            . "Image + 'info'\n\n"
            . "*Declencheurs :* screenshot, capture, annotate, extract-text, ocr, compare, analyse"
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function isImageMedia(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }
        $base = explode(';', $mimetype)[0];
        return str_starts_with(trim($base), 'image/');
    }

    private function resolveMediaUrl(AgentContext $context): ?string
    {
        if ($context->mediaUrl) {
            return $context->mediaUrl;
        }

        $media = $context->media ?? [];
        return $media['url'] ?? $media['directPath'] ?? null;
    }

    /**
     * Normalize MIME type to one supported by Anthropic Vision API.
     */
    private function normalizeImageMime(string $mime): string
    {
        $base = strtolower(explode(';', $mime)[0]);
        return match (true) {
            str_contains($base, 'jpeg'), str_contains($base, 'jpg') => 'image/jpeg',
            str_contains($base, 'png')  => 'image/png',
            str_contains($base, 'gif')  => 'image/gif',
            str_contains($base, 'webp') => 'image/webp',
            default                     => 'image/jpeg',
        };
    }
}
