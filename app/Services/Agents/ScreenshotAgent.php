<?php

namespace App\Services\Agents;

use App\Jobs\ProcessScreenshotJob;
use App\Services\AgentContext;
use App\Services\ImageProcessor;
use App\Services\ModelResolver;

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
        return 'Agent de traitement d\'images et OCR. Extraction de texte (OCR), analyse visuelle IA (Claude Vision), annotation (fleches, rectangles, cercles), comparaison d\'images, recadrage (crop), reglage luminosite/contraste, redimensionnement, rotation, miroir, noir et blanc, detection QR code.';
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
            'flip', 'miroir', 'mirror', 'retourner', 'inverser',
            'grayscale', 'noir et blanc', 'niveaux de gris', 'desaturer', 'monochrome',
            'qr code', 'qrcode', 'qr', 'code qr', 'lire qr', 'decoder qr',
            'crop', 'rogner', 'recadrer', 'recadrage', 'decouper image', 'couper image',
            'luminosite', 'luminosité', 'brightness', 'eclaircir', 'assombrir',
            'contraste', 'contrast', 'saturation',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body && !$context->hasMedia) {
            return false;
        }

        $body = mb_strtolower($context->body ?? '');

        // Explicit screenshot/OCR/annotation/compare/resize/rotate/flip/grayscale/qr/crop/brightness commands
        if (preg_match('/\b(screenshot|capture|annotate|ocr|extract[\s-]?text|compare[r]?|extraire[\s-]?texte|resize|redimensionn[e]?r?|rotation|rotate|pivoter|tourner|retailler|flip|miroir|mirror|inverser|grayscale|monochrome|qr[\s-]?code|qrcode|lire[\s-]?qr|decoder[\s-]?qr|code[\s-]?qr|crop|rogner|recadr[e]?r?|luminosit[eé]|brightness|eclaircir|assombrir|contraste?)\b/iu', $body)) {
            return true;
        }

        // "noir et blanc" or "niveaux de gris"
        if (preg_match('/\b(noir[\s-]et[\s-]blanc|niveaux[\s-]de[\s-]gris|desatur[e]?r?)\b/iu', $body)) {
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
            'flip'         => $this->handleFlip($context, $command),
            'grayscale'    => $this->handleGrayscale($context),
            'qr'           => $this->handleQrCode($context),
            'crop'         => $this->handleCrop($context, $command),
            'brightness'   => $this->handleBrightness($context, $command),
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

        // Flip / Mirror
        if (preg_match('/\b(flip|miroir|mirror|inverser)\b/iu', $lower)) {
            return $this->parseFlipCommand($body);
        }

        // Grayscale / Noir et blanc
        if (preg_match('/\b(grayscale|monochrome|desatur[e]?r?)\b/iu', $lower)
            || preg_match('/\b(noir[\s-]et[\s-]blanc|niveaux[\s-]de[\s-]gris)\b/iu', $lower)) {
            return ['action' => 'grayscale'];
        }

        // QR Code detection
        if (preg_match('/\b(qr[\s-]?code|qrcode|decoder[\s-]?qr|lire[\s-]?qr|code[\s-]?qr)\b/iu', $lower)) {
            return ['action' => 'qr'];
        }

        // Crop / recadrer
        if (preg_match('/\b(crop|rogner|recadr[e]?r?|recadrage|decouper|couper)\b/iu', $lower)) {
            return $this->parseCropCommand($body);
        }

        // Brightness / contrast adjustment
        if (preg_match('/\b(luminosit[eé]|brightness|eclaircir|assombrir|contraste?|saturation)\b/iu', $lower)) {
            return $this->parseBrightnessCommand($body);
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

    private function parseFlipCommand(string $body): array
    {
        $direction = 'horizontal'; // default

        if (preg_match('/\b(vertical|vertic|haut|bas|up|down)\b/iu', $body)) {
            $direction = 'vertical';
        } elseif (preg_match('/\b(horizontal|horiz|gauche|droite|left|right)\b/iu', $body)) {
            $direction = 'horizontal';
        }

        return ['action' => 'flip', 'direction' => $direction];
    }

    private function parseCropCommand(string $body): array
    {
        $params = [
            'action' => 'crop',
            'x'      => 0,
            'y'      => 0,
            'width'  => 200,
            'height' => 200,
        ];

        // Parse [x,y,w,h] format
        if (preg_match('/\[(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\]/', $body, $m)) {
            $params['x']      = (int) $m[1];
            $params['y']      = (int) $m[2];
            $params['width']  = max(1, (int) $m[3]);
            $params['height'] = max(1, (int) $m[4]);
        }
        // Parse x=N y=N w=N h=N style
        elseif (preg_match('/x[=:\s](\d+)/i', $body, $mx) && preg_match('/y[=:\s](\d+)/i', $body, $my)) {
            $params['x'] = (int) $mx[1];
            $params['y'] = (int) $my[1];
            if (preg_match('/w(?:idth)?[=:\s](\d+)/i', $body, $mw)) {
                $params['width'] = max(1, (int) $mw[1]);
            }
            if (preg_match('/h(?:eight)?[=:\s](\d+)/i', $body, $mh)) {
                $params['height'] = max(1, (int) $mh[1]);
            }
        }

        return $params;
    }

    private function parseBrightnessCommand(string $body): array
    {
        $params = [
            'action'     => 'brightness',
            'brightness' => 0,
            'contrast'   => 0,
        ];

        // Preset keywords
        if (preg_match('/\b(eclaircir|lighten)\b/iu', $body)) {
            $params['brightness'] = 60;
        } elseif (preg_match('/\b(assombrir|darken)\b/iu', $body)) {
            $params['brightness'] = -60;
        }

        // Explicit brightness value: luminosite +80 / brightness -50 / luminosite 100
        if (preg_match('/\b(?:luminosit[eé]|brightness)\s*([+-]?\d+)/iu', $body, $m)) {
            $params['brightness'] = max(-255, min(255, (int) $m[1]));
        }

        // Explicit contrast value: contraste +30 / contrast -20
        if (preg_match('/\b(?:contraste?)\s*([+-]?\d+)/iu', $body, $m)) {
            $params['contrast'] = max(-100, min(100, (int) $m[1]));
        }

        return $params;
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
            $model = ModelResolver::fast();
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
            . "- *Decoder QR code* : Image + 'qr code'\n"
            . "- *Annoter* : Image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "- *Comparer* : Image + 'compare'\n"
            . "- *Recadrer* : Image + 'crop [x,y,w,h]'\n"
            . "- *Luminosite* : Image + 'luminosite +80' ou 'eclaircir'\n"
            . "- *Redimensionner* : Image + 'resize 800x600'\n"
            . "- *Pivoter* : Image + 'rotate 90'\n"
            . "- *Miroir* : Image + 'flip' ou 'flip vertical'\n"
            . "- *Noir et blanc* : Image + 'grayscale'\n"
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
     * Flip image horizontally or vertically.
     */
    private function handleFlip(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Miroir / Retournement d'image*\n\n"
                . "Envoie-moi une image a retourner.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'flip' ou 'miroir'\n\n"
                . "*Options :*\n"
                . "- 'flip horizontal' (miroir gauche/droite) — *defaut*\n"
                . "- 'flip vertical' (miroir haut/bas)\n\n"
                . "*Exemples :*\n"
                . "- Image + 'flip'\n"
                . "- Image + 'miroir vertical'\n"
                . "- Image + 'inverser horizontalement'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour la retourner.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $direction = $command['direction'] ?? 'horizontal';

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $outputPath = $this->imageProcessor->flipImage($imagePath, $direction);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply(
                "Erreur lors du retournement de l'image.\n\n"
                . "Verifie que l'image est valide (JPG, PNG, WEBP)."
            );
        }

        $newInfo = $this->imageProcessor->getImageInfo($outputPath);
        $newW    = $newInfo['width']      ?? '?';
        $newH    = $newInfo['height']     ?? '?';
        $newSize = $newInfo['size_human'] ?? '?';

        $dirLabel = $direction === 'vertical' ? 'vertical (haut/bas)' : 'horizontal (gauche/droite)';
        $filename = "flipped_{$direction}.png";

        $this->sendFile($context->from, $outputPath, $filename, "*Image retournee* : miroir {$dirLabel} | {$newW}x{$newH}px");
        @unlink($outputPath);

        $this->log($context, 'Image flipped', ['direction' => $direction]);

        return AgentResult::reply(
            "*Retournement reussi !*\n\n"
            . "Miroir : *{$dirLabel}*\n"
            . "Dimensions : {$newW}x{$newH}px | {$newSize}"
        );
    }

    /**
     * Convert image to grayscale (noir et blanc).
     */
    private function handleGrayscale(AgentContext $context): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Noir et blanc / Niveaux de gris*\n\n"
                . "Envoie-moi une image a convertir en noir et blanc.\n\n"
                . "*Exemples :*\n"
                . "- Image + 'grayscale'\n"
                . "- Image + 'noir et blanc'\n"
                . "- Image + 'niveaux de gris'\n"
                . "- Image + 'monochrome'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour la convertir.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $originalInfo = $this->imageProcessor->getImageInfo($imagePath);
        $outputPath   = $this->imageProcessor->grayscaleImage($imagePath);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply(
                "Erreur lors de la conversion en noir et blanc.\n\n"
                . "Verifie que l'image est valide (JPG, PNG, WEBP)."
            );
        }

        $newInfo = $this->imageProcessor->getImageInfo($outputPath);
        $origSize = $originalInfo['size_human'] ?? '?';
        $newSize  = $newInfo['size_human']       ?? '?';
        $newW     = $newInfo['width']             ?? '?';
        $newH     = $newInfo['height']            ?? '?';

        $this->sendFile($context->from, $outputPath, 'grayscale.png', "*Image noir et blanc* | {$newW}x{$newH}px | {$newSize}");
        @unlink($outputPath);

        $this->log($context, 'Image converted to grayscale', ['original_size' => $origSize]);

        return AgentResult::reply(
            "*Conversion reussie !*\n\n"
            . "Format : *Noir et blanc (niveaux de gris)*\n"
            . "Dimensions : {$newW}x{$newH}px | {$newSize}"
        );
    }

    /**
     * Detect and decode QR code using Claude Vision.
     */
    private function handleQrCode(AgentContext $context): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Detection de QR Code*\n\n"
                . "Envoie-moi une image contenant un QR code pour le decoder.\n\n"
                . "*Exemples :*\n"
                . "- Image + 'qr code'\n"
                . "- Image + 'lire qr'\n"
                . "- Image + 'decoder qr'\n\n"
                . "_Je lirai le contenu du QR code (URL, texte, contact, etc.)_"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image contenant un QR code.");
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
        if (!str_starts_with($model, 'claude-')) {
            $model = ModelResolver::fast();
        }

        $systemPrompt = "Tu es un expert en detection et decodage de QR codes et codes-barres.\n"
            . "Analyse l'image fournie et :\n"
            . "1. Detecte si un QR code ou code-barre est present\n"
            . "2. Decode son contenu (URL, texte, contact vCard, WiFi, email, telephone, etc.)\n"
            . "3. Identifie le type de QR code (URL, WiFi, contact, SMS, email, geo, etc.)\n"
            . "4. Si plusieurs codes sont presents, decode-les tous\n"
            . "5. Si aucun code n'est detecte, dis-le clairement et suggere d'ameliorer la qualite de l'image\n"
            . "Reponds en francais. Utilise *gras* pour le contenu decode. Sois direct et precis.";

        $content = [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mimeType,
                    'data'       => $base64,
                ],
            ],
            [
                'type' => 'text',
                'text' => "Detecte et decode tous les QR codes ou codes-barres visibles dans cette image. "
                    . "Indique le type de contenu (URL, WiFi, contact, etc.) et reproduis exactement le contenu decode.",
            ],
        ];

        $response = $this->claude->chat($content, $model, $systemPrompt);

        if (!$response) {
            return AgentResult::reply(
                "Impossible d'analyser l'image avec Claude Vision.\n\n"
                . "Tu peux essayer :\n"
                . "- 'extract-text' pour lire le texte de l'image\n"
                . "- 'analyse' pour une description generale"
            );
        }

        $this->log($context, 'QR code detection completed', ['model' => $model]);

        return AgentResult::reply(
            "*Detection QR Code :*\n\n"
            . $response
            . ($sizeWarning ? "\n\n_Note : {$sizeWarning}_" : '')
        );
    }

    /**
     * NEW: Crop image to a specified region [x,y,w,h].
     */
    private function handleCrop(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Recadrage d'image (Crop)*\n\n"
                . "Envoie-moi une image a recadrer.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'crop [x,y,largeur,hauteur]'\n\n"
                . "*Exemples :*\n"
                . "- 'crop [0,0,400,300]' (region en haut a gauche, 400x300px)\n"
                . "- 'rogner [100,50,200,200]' (recadrer depuis (100,50) sur 200x200px)\n"
                . "- 'crop x=50 y=20 w=300 h=200'\n\n"
                . "_Conseil : utilise 'info' pour connaitre les dimensions de ton image._"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour la recadrer.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $origInfo = $this->imageProcessor->getImageInfo($imagePath);
        $origW    = $origInfo['width']  ?? 0;
        $origH    = $origInfo['height'] ?? 0;

        $x      = $command['x']      ?? 0;
        $y      = $command['y']      ?? 0;
        $width  = $command['width']  ?? 200;
        $height = $command['height'] ?? 200;

        // Guard: if no coordinates were parsed (default 0,0,200,200) and image is small,
        // tell user to specify coordinates
        if ($x === 0 && $y === 0 && $width === 200 && $height === 200 && ($origW <= 200 || $origH <= 200)) {
            @unlink($imagePath);
            return AgentResult::reply(
                "Precisez les coordonnees de recadrage.\n\n"
                . "Format : 'crop [x,y,largeur,hauteur]'\n"
                . "Exemple : 'crop [0,0,{$origW},{$origH}}]'\n\n"
                . "Dimensions actuelles : {$origW}x{$origH}px"
            );
        }

        $outputPath = $this->imageProcessor->cropImage($imagePath, $x, $y, $width, $height);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply(
                "Erreur lors du recadrage.\n\n"
                . "Verifie que les coordonnees sont dans les limites de l'image ({$origW}x{$origH}px)."
            );
        }

        $newInfo = $this->imageProcessor->getImageInfo($outputPath);
        $newW    = $newInfo['width']      ?? $width;
        $newH    = $newInfo['height']     ?? $height;
        $newSize = $newInfo['size_human'] ?? '?';

        $this->sendFile($context->from, $outputPath, "cropped_{$x}_{$y}_{$newW}x{$newH}.png",
            "*Image recadree* : depuis ({$x},{$y}) → {$newW}x{$newH}px | {$newSize}");
        @unlink($outputPath);

        $this->log($context, 'Image cropped', [
            'origin'   => "{$x},{$y}",
            'size'     => "{$newW}x{$newH}",
            'original' => "{$origW}x{$origH}",
        ]);

        return AgentResult::reply(
            "*Recadrage reussi !*\n\n"
            . "Image originale : {$origW}x{$origH}px\n"
            . "Region recadree : depuis ({$x},{$y})\n"
            . "Nouvelles dimensions : *{$newW}x{$newH}px* | {$newSize}"
        );
    }

    /**
     * NEW: Adjust brightness and/or contrast.
     */
    private function handleBrightness(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "*Luminosite & Contraste*\n\n"
                . "Envoie-moi une image a ajuster.\n\n"
                . "*Syntaxe :*\n"
                . "Image + 'luminosite <valeur>' (de -255 a +255)\n"
                . "Image + 'contraste <valeur>' (de -100 a +100)\n\n"
                . "*Exemples :*\n"
                . "- 'luminosite +80' (eclaircir)\n"
                . "- 'luminosite -60' (assombrir)\n"
                . "- 'eclaircir' (preset +60)\n"
                . "- 'assombrir' (preset -60)\n"
                . "- 'contraste -30' (augmenter le contraste)\n"
                . "- 'luminosite +50 contraste -20'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, WEBP...) pour l'ajuster.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        $brightness = $command['brightness'] ?? 0;
        $contrast   = $command['contrast']   ?? 0;

        if ($brightness === 0 && $contrast === 0) {
            return AgentResult::reply(
                "Precisez une valeur de luminosite ou de contraste.\n\n"
                . "Exemples :\n"
                . "- 'luminosite +80' (eclaircir)\n"
                . "- 'assombrir'\n"
                . "- 'contraste -30'"
            );
        }

        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);
        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image.");
        }

        $outputPath = $this->imageProcessor->adjustBrightness($imagePath, $brightness, $contrast);
        @unlink($imagePath);

        if (!$outputPath || !file_exists($outputPath)) {
            return AgentResult::reply("Erreur lors de l'ajustement de la luminosite/contraste.");
        }

        $newInfo = $this->imageProcessor->getImageInfo($outputPath);
        $newW    = $newInfo['width']      ?? '?';
        $newH    = $newInfo['height']     ?? '?';
        $newSize = $newInfo['size_human'] ?? '?';

        $brightnessLabel = $brightness > 0 ? "+{$brightness} (eclaircissement)" : ($brightness < 0 ? "{$brightness} (assombrissement)" : '');
        $contrastLabel   = $contrast > 0 ? "+{$contrast} (moins de contraste)" : ($contrast < 0 ? "{$contrast} (plus de contraste)" : '');

        $caption  = "*Luminosite/Contraste ajuste*";
        $caption .= $brightnessLabel ? " | Lum: {$brightness}" : '';
        $caption .= $contrastLabel   ? " | Cont: {$contrast}"  : '';
        $caption .= " | {$newW}x{$newH}px";

        $this->sendFile($context->from, $outputPath, 'adjusted.png', $caption);
        @unlink($outputPath);

        $this->log($context, 'Image brightness/contrast adjusted', [
            'brightness' => $brightness,
            'contrast'   => $contrast,
        ]);

        $lines = [];
        if ($brightnessLabel) {
            $lines[] = "Luminosite : *{$brightnessLabel}*";
        }
        if ($contrastLabel) {
            $lines[] = "Contraste : *{$contrastLabel}*";
        }
        $lines[] = "Dimensions : {$newW}x{$newH}px | {$newSize}";

        return AgentResult::reply("*Ajustement reussi !*\n\n" . implode("\n", $lines));
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
            "*Screenshot & Images — Traitement d'images IA*\n\n"
            . "*Analyser une image (IA) :*\n"
            . "Image + 'analyse' ou 'describe'\n\n"
            . "*Extraire du texte (OCR) :*\n"
            . "Image + 'extract-text' | Langue : 'lang:eng'\n\n"
            . "*Decoder un QR code :*\n"
            . "Image + 'qr code' ou 'lire qr'\n\n"
            . "*Annoter une image :*\n"
            . "Image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "Types : arrow, rectangle, circle, text\n\n"
            . "*Comparer deux images :*\n"
            . "Image 1 + 'compare' → Image 2 + 'compare'\n\n"
            . "*Recadrer (Crop) :*\n"
            . "Image + 'crop [x,y,largeur,hauteur]'\n\n"
            . "*Luminosite / Contraste :*\n"
            . "Image + 'luminosite +80' | 'eclaircir' | 'contraste -30'\n\n"
            . "*Redimensionner :*\n"
            . "Image + 'resize 800x600'\n\n"
            . "*Pivoter :*\n"
            . "Image + 'rotate 90' (ou 180, 270)\n\n"
            . "*Miroir / Retourner :*\n"
            . "Image + 'flip' | 'flip vertical'\n\n"
            . "*Noir et blanc :*\n"
            . "Image + 'grayscale' ou 'noir et blanc'\n\n"
            . "*Infos image :*\n"
            . "Image + 'info'\n\n"
            . "_Declencheurs : screenshot, ocr, analyse, qr code, crop, luminosite, flip, grayscale, compare, resize, rotate_"
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
