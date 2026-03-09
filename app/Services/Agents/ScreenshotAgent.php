<?php

namespace App\Services\Agents;

use App\Jobs\ProcessScreenshotJob;
use App\Services\AgentContext;
use App\Services\ImageProcessor;

class ScreenshotAgent extends BaseAgent
{
    private ImageProcessor $imageProcessor;

    /** Maximum accepted image size in bytes (10 MB). */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

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
        return 'Agent de traitement d\'images et OCR. Extraction de texte (OCR) depuis des images, analyse visuelle avec IA (Claude Vision), annotation d\'images (fleches, rectangles, cercles), comparaison d\'images en 2 etapes, redimensionnement, rotation, et informations detaillees sur les images.';
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
            'resize', 'redimensionner', 'redimensionne', 'retailler',
            'rotate', 'rotation', 'pivoter', 'tourner', 'retourner',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body && !$context->hasMedia) {
            return false;
        }

        $body = mb_strtolower($context->body ?? '');

        // Explicit screenshot/OCR/annotation/compare/resize/rotate commands
        if (preg_match('/\b(screenshot|capture|annotate|ocr|extract[\s-]?text|compare[r]?|extraire[\s-]?texte|resize|redimensionn[e]?r?|rotation|rotate|pivoter|tourner|retailler)\b/i', $body)) {
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
            'resize'       => $this->handleResize($context, $command),
            'rotate'       => $this->handleRotate($context, $command),
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

        // Resize
        if (preg_match('/\b(resize|redimensionn[e]?r?|retailler)\b/iu', $lower)) {
            return $this->parseResizeCommand($body);
        }

        // Rotate
        if (preg_match('/\b(rotat[e]?r?|rotation|pivoter|tourner)\b/iu', $lower)) {
            return $this->parseRotateCommand($body);
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

    private function parseResizeCommand(string $body): array
    {
        $params = [
            'action'      => 'resize',
            'width'       => 800,
            'height'      => 600,
            'keep_aspect' => true,
        ];

        // Parse WxH format (e.g. 1280x720, 800x600)
        if (preg_match('/(\d+)\s*[x×]\s*(\d+)/i', $body, $m)) {
            $params['width']  = (int) $m[1];
            $params['height'] = (int) $m[2];
        } elseif (preg_match('/\b(\d{3,4})\b/', $body, $m)) {
            // Single dimension: resize width, keep aspect
            $params['width']  = (int) $m[1];
            $params['height'] = (int) $m[1];
        }

        // Disable aspect-ratio if "exact" or "force" is mentioned
        if (preg_match('/\b(exact|force|stretch|etirer)\b/i', $body)) {
            $params['keep_aspect'] = false;
        }

        // Clamp dimensions to reasonable bounds
        $params['width']  = max(10, min(4096, $params['width']));
        $params['height'] = max(10, min(4096, $params['height']));

        return $params;
    }

    private function parseRotateCommand(string $body): array
    {
        $degrees = 90; // default

        if (preg_match('/\b(180|180°)\b/', $body)) {
            $degrees = 180;
        } elseif (preg_match('/\b(270|270°|gauche|left|counter|ccw)\b/i', $body)) {
            $degrees = 270;
        } elseif (preg_match('/\b(90|90°|droite|right|cw)\b/i', $body)) {
            $degrees = 90;
        } elseif (preg_match('/\b(\d{1,3})\s*°?/', $body, $m)) {
            $degrees = (int) $m[1];
        }

        return ['action' => 'rotate', 'degrees' => $degrees];
    }

    // ── Handlers ───────────────────────────────────────────────────────────────

    private function handleExtractText(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "Envoie-moi une image avec ta demande d'extraction de texte.\n\n"
                . "Exemple : envoie une photo + 'extract-text'\n"
                . "Option langue : 'extract-text lang:eng' (fra, eng, deu, spa, ita...)"
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

        // Size guard
        $sizeWarning = $this->checkImageSize($imagePath);

        $language = $command['language'] ?? 'fra+eng';
        $extractedText = $this->imageProcessor->extractText($imagePath, $language);

        // Fallback: if no text found with default language, retry with eng only
        if (empty($extractedText) && $language === 'fra+eng') {
            $extractedText = $this->imageProcessor->extractText($imagePath, 'eng');
            if (!empty($extractedText)) {
                $language = 'eng (fallback)';
            }
        }

        @unlink($imagePath);

        if (empty($extractedText)) {
            return AgentResult::reply(
                "Aucun texte detecte dans l'image.\n\n"
                . "*Conseils :*\n"
                . "- Verifie que l'image est nette et bien eclairee\n"
                . "- Le texte doit etre lisible et de taille suffisante\n"
                . "- Essaie une langue specifique : 'extract-text lang:eng' ou 'lang:fra'\n"
                . "- Ou utilise 'analyse' pour une description visuelle IA de l'image"
                . ($sizeWarning ? "\n\n_Note : {$sizeWarning}_" : '')
            );
        }

        $wordCount = str_word_count($extractedText);
        $lineCount = substr_count($extractedText, "\n") + 1;
        $this->log($context, 'OCR completed', ['text_length' => mb_strlen($extractedText), 'language' => $language, 'words' => $wordCount]);

        return AgentResult::reply(
            "*Texte extrait de l'image :*\n\n"
            . $extractedText . "\n\n"
            . "---\n"
            . "_Langue OCR : {$language} | ~{$wordCount} mots | {$lineCount} lignes_"
            . ($sizeWarning ? "\n_Note : {$sizeWarning}_" : '')
        );
    }

    /**
     * Analyze image using Claude Vision API.
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
                . "- Envoie une image + 'identifie les elements'\n"
                . "- Envoie une image + 'que contient ce document ?'"
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

        if (!file_exists($imagePath)) {
            return AgentResult::reply("Erreur : fichier image introuvable apres telechargement.");
        }

        $sizeWarning = $this->checkImageSize($imagePath);
        $base64   = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
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
            . "- Decris les elements visuels principaux (objets, personnes, texte, couleurs)\n"
            . "- Indique le contexte general (photo, screenshot, document, schema, etc.)\n"
            . "- Releve toute information utile ou actionnable\n"
            . "- Si du texte est visible, reproduis les parties importantes\n"
            . "Reponds en francais. Utilise *gras* pour les points cles et les titres de section.\n"
            . "Sois precis mais concis (max 300 mots sauf si demande specifique).";

        if ($memoryPrompt) {
            $systemPrompt .= "\n\n" . $memoryPrompt;
        }

        // Build prompt from user message, removing the trigger keyword
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

        return AgentResult::reply(
            "*Analyse de l'image :*\n\n"
            . $response
            . ($sizeWarning ? "\n\n_Note : {$sizeWarning}_" : '')
        );
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
                . "*Couleurs :* red, green, blue, yellow, orange, cyan, magenta\n\n"
                . "*Exemples :*\n"
                . "- 'annotate arrow [10,10,200,200] red'\n"
                . "- 'annotate circle [50,50,150,150] blue'\n"
                . "- 'annotate text [20,20] text=\"Important\"'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image pour l'annoter.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        if (empty($command['coordinates']) && $command['type'] !== 'text') {
            return AgentResult::reply(
                "Coordonnees manquantes pour l'annotation.\n\n"
                . "Format : 'annotate {$command['type']} [x1,y1,x2,y2] {$command['color']}'\n"
                . "Exemple : 'annotate {$command['type']} [10,10,200,200] {$command['color']}'"
            );
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
                . "les differences avec le pourcentage de similarite.\n\n"
                . "_La premiere image est conservee 10 minutes._"
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
            . "Maintenant envoie la *deuxieme image* avec 'compare' pour lancer la comparaison.\n"
            . "_Tu as 10 minutes. Tape 'annuler' pour annuler._"
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
            . "- *Redimensionner* : Image + 'resize 800x600'\n"
            . "- *Pivoter* : Image + 'rotate 90'\n"
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
        $sizeWarning = $this->checkImageSize($imagePath);
        @unlink($imagePath);

        if (isset($info['error'])) {
            return AgentResult::reply("Impossible de lire les informations de cette image. Verifie que le fichier est valide.");
        }

        $width  = $info['width'] ?? 0;
        $height = $info['height'] ?? 0;
        $ratio  = ($height > 0) ? round($width / $height, 2) : 'N/A';

        $orientation = match (true) {
            $width > $height => 'Paysage (Landscape)',
            $width < $height => 'Portrait',
            default          => 'Carre',
        };

        $megapixels = ($width > 0 && $height > 0) ? round(($width * $height) / 1_000_000, 2) : 0;

        return AgentResult::reply(
            "*Informations sur l'image :*\n\n"
            . "Dimensions : *{$width} x {$height} px*\n"
            . "Megapixels : {$megapixels} MP\n"
            . "Ratio : {$ratio} ({$orientation})\n"
            . "Format : {$info['mime']}\n"
            . "Taille : *{$info['size_human']}*\n\n"
            . "_Conseils : 'analyse' pour description IA | 'extract-text' pour lire le texte | 'resize WxH' pour redimensionner_"
            . ($sizeWarning ? "\n_Note : {$sizeWarning}_" : '')
        );
    }

    /**
     * NEW: Resize image to specified dimensions.
     */
    private function handleResize(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Redimensionnement d'image*\n\n"
                . "Envoie-moi une image a redimensionner.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'resize <largeur>x<hauteur>'\n\n"
                . "*Exemples :*\n"
                . "- 'resize 800x600' (garde les proportions)\n"
                . "- 'resize 1280x720'\n"
                . "- 'resize 512' (carre 512x512)\n"
                . "- 'resize 400x300 exact' (force les dimensions exactes)\n\n"
                . "_L'image est retournee avec les nouvelles dimensions._"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour la redimensionner.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $targetW    = $command['width']       ?? 800;
        $targetH    = $command['height']      ?? 600;
        $keepAspect = $command['keep_aspect'] ?? true;

        $originalInfo = $this->imageProcessor->getImageInfo($imagePath);
        $outputPath   = $this->imageProcessor->resizeImage($imagePath, $targetW, $targetH, $keepAspect);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply(
                "Erreur lors du redimensionnement de l'image.\n\n"
                . "Verifie que l'image est valide (JPG, PNG, WEBP)."
            );
        }

        $newInfo = $this->imageProcessor->getImageInfo($outputPath);
        $origW   = $originalInfo['width']      ?? '?';
        $origH   = $originalInfo['height']     ?? '?';
        $newW    = $newInfo['width']            ?? $targetW;
        $newH    = $newInfo['height']           ?? $targetH;
        $newSize = $newInfo['size_human']       ?? '?';

        $this->sendFile($context->from, $outputPath, "resized_{$newW}x{$newH}.png", "*Image redimensionnee* : {$newW}x{$newH}px | {$newSize}");
        @unlink($outputPath);

        $this->log($context, 'Image resized', [
            'original' => "{$origW}x{$origH}",
            'target'   => "{$targetW}x{$targetH}",
            'result'   => "{$newW}x{$newH}",
        ]);

        return AgentResult::reply(
            "*Redimensionnement reussi !*\n\n"
            . "Avant : {$origW}x{$origH}px\n"
            . "Apres : *{$newW}x{$newH}px* | {$newSize}\n"
            . ($keepAspect ? "_Proportions conservees._" : "_Dimensions forcees (stretch)._")
        );
    }

    /**
     * NEW: Rotate image by specified degrees.
     */
    private function handleRotate(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Rotation d'image*\n\n"
                . "Envoie-moi une image a faire pivoter.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'rotate <degres>'\n\n"
                . "*Exemples :*\n"
                . "- 'rotate 90' (90° horaire)\n"
                . "- 'rotate 180' (retournement)\n"
                . "- 'rotate 270' (90° anti-horaire)\n"
                . "- 'rotate gauche' (270° = 90° CCW)\n"
                . "- 'rotate droite' (90° CW)"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour la faire pivoter.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $degrees = $command['degrees'] ?? 90;
        if (!in_array($degrees, [90, 180, 270], true)) {
            // Normalize to nearest valid rotation
            $degrees = (int) round($degrees / 90) * 90 % 360;
            if ($degrees <= 0) {
                $degrees = 90;
            }
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $outputPath = $this->imageProcessor->rotateImage($imagePath, $degrees);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply(
                "Erreur lors de la rotation de l'image.\n\n"
                . "Verifie que l'image est valide (JPG, PNG, WEBP)."
            );
        }

        $newInfo  = $this->imageProcessor->getImageInfo($outputPath);
        $newW     = $newInfo['width']      ?? '?';
        $newH     = $newInfo['height']     ?? '?';
        $newSize  = $newInfo['size_human'] ?? '?';

        $dirLabel = match ($degrees) {
            90  => '90° horaire',
            180 => '180° (retourne)',
            270 => '270° (90° anti-horaire)',
            default => "{$degrees}°",
        };

        $this->sendFile($context->from, $outputPath, "rotated_{$degrees}deg.png", "*Image pivotee* : {$dirLabel} | {$newW}x{$newH}px");
        @unlink($outputPath);

        $this->log($context, 'Image rotated', ['degrees' => $degrees]);

        return AgentResult::reply(
            "*Rotation reussie !*\n\n"
            . "Rotation : *{$dirLabel}*\n"
            . "Nouvelles dimensions : {$newW}x{$newH}px | {$newSize}"
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
            . "*Redimensionner :*\n"
            . "Image + 'resize 800x600'\n\n"
            . "*Pivoter :*\n"
            . "Image + 'rotate 90' (ou 180, 270)\n\n"
            . "*Infos image :*\n"
            . "Image + 'info'\n\n"
            . "*Declencheurs :* screenshot, capture, annotate, extract-text, ocr, compare, analyse, resize, rotate"
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
     * Check image size and return a warning string if too large, null otherwise.
     */
    private function checkImageSize(string $imagePath): ?string
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        $size = filesize($imagePath);
        if ($size > self::MAX_IMAGE_SIZE) {
            $sizeHuman = round($size / (1024 * 1024), 1);
            return "Image de {$sizeHuman} Mo — le traitement peut etre plus lent pour les grandes images.";
        }

        return null;
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
