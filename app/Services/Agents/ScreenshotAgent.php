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
        return 'Agent de traitement d\'images et OCR. Extraction de texte (OCR) depuis des images, annotation d\'images (fleches, rectangles, cercles), comparaison d\'images, et informations sur les images (dimensions, format, taille).';
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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body && !$context->hasMedia) {
            return false;
        }

        $body = mb_strtolower($context->body ?? '');

        // Explicit screenshot/OCR commands
        if (preg_match('/\b(screenshot|capture|annotate|ocr|extract[\s-]?text|compare.*image)\b/i', $body)) {
            return true;
        }

        // Image with extract-text intent
        if ($context->hasMedia && $this->isImageMedia($context->mimetype) &&
            preg_match('/\b(texte|text|lire|read|ocr|extraire|extract|transcrire|transcribe)\b/iu', $body)) {
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
            'annotate' => $this->handleAnnotate($context, $command),
            'compare' => $this->handleCompare($context, $command),
            'capture' => $this->handleCapture($context, $command),
            'info' => $this->handleImageInfo($context),
            default => $this->handleWithClaude($context, $body),
        };
    }

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

        // Annotate
        if (preg_match('/\b(annotate|annoter|marquer|highlight|surligner)\b/iu', $lower)) {
            return $this->parseAnnotateCommand($body);
        }

        // Compare
        if (preg_match('/\b(compare[r]?|diff|difference|comparer)\b/iu', $lower)) {
            return ['action' => 'compare'];
        }

        // Image info
        if (preg_match('/\b(info|details|metadata|taille|dimensions?)\b/iu', $lower)) {
            return ['action' => 'info'];
        }

        // Capture (description-based)
        if (preg_match('/\b(capture|screenshot|screen)\b/iu', $lower)) {
            $description = preg_replace('/\b(capture|screenshot|screen|@screenshot)\b/iu', '', $body);
            return ['action' => 'capture', 'description' => trim($description)];
        }

        // Default: if media is present, extract text; otherwise show help
        return ['action' => 'auto'];
    }

    private function parseAnnotateCommand(string $body): array
    {
        $annotation = [
            'action' => 'annotate',
            'type' => 'rectangle',
            'color' => 'red',
            'coordinates' => [],
            'text' => '',
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
        if (preg_match('/\b(red|rouge|green|vert|blue|bleu|yellow|jaune|orange|white|blanc|black|noir|cyan|magenta)\b/iu', $body, $m)) {
            $colorMap = [
                'rouge' => 'red', 'vert' => 'green', 'bleu' => 'blue',
                'jaune' => 'yellow', 'blanc' => 'white', 'noir' => 'black',
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

    private function handleExtractText(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "Envoie-moi une image avec ta demande d'extraction de texte.\n\n"
                . "Exemple : envoie une photo + 'extract-text'"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image (JPG, PNG, etc.) pour l'OCR.");
        }

        // Dispatch heavy processing as a job
        $mediaUrl = $this->resolveMediaUrl($context);

        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        // For quick processing, handle synchronously
        $imagePath = $this->imageProcessor->downloadFromWaha($mediaUrl);

        if (!$imagePath) {
            return AgentResult::reply("Erreur lors du telechargement de l'image. Reessaie.");
        }

        $language = $command['language'] ?? 'fra+eng';
        $extractedText = $this->imageProcessor->extractText($imagePath, $language);

        // Clean up downloaded file
        @unlink($imagePath);

        if (empty($extractedText)) {
            return AgentResult::reply(
                "Aucun texte detecte dans l'image.\n\n"
                . "Conseils :\n"
                . "- Assure-toi que l'image est nette et bien eclairee\n"
                . "- Le texte doit etre lisible et pas trop petit\n"
                . "- Essaie avec une langue specifique : 'extract-text lang:eng'"
            );
        }

        $this->log($context, 'OCR completed', ['text_length' => mb_strlen($extractedText), 'language' => $language]);

        return AgentResult::reply(
            "*Texte extrait de l'image :*\n\n"
            . $extractedText . "\n\n"
            . "_Langue OCR : {$language}_"
        );
    }

    private function handleAnnotate(AgentContext $context, array $command): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply(
                "Envoie-moi une image a annoter.\n\n"
                . "Syntaxe : envoie une image + 'annotate arrow [x1,y1,x2,y2] red'\n"
                . "Types : arrow, rectangle, circle, text\n"
                . "Couleurs : red, green, blue, yellow, orange, cyan"
            );
        }

        if (!$this->isImageMedia($context->mimetype)) {
            return AgentResult::reply("Ce fichier n'est pas une image. Envoie une image pour l'annoter.");
        }

        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            return AgentResult::reply("Impossible de recuperer l'image. Reessaie.");
        }

        // For heavy images, dispatch as job
        ProcessScreenshotJob::dispatch(
            $context->from,
            $context->agent->id,
            $mediaUrl,
            'annotate',
            $command
        );

        return AgentResult::reply(
            "Annotation en cours... Tu recevras l'image annotee dans quelques secondes.\n"
            . "Type: {$command['type']} | Couleur: {$command['color']}"
        );
    }

    private function handleCompare(AgentContext $context, array $command): AgentResult
    {
        return AgentResult::reply(
            "*Comparaison d'images*\n\n"
            . "Pour comparer deux images :\n"
            . "1. Envoie la premiere image avec 'compare image 1'\n"
            . "2. Envoie la deuxieme image avec 'compare image 2'\n\n"
            . "Je comparerai les deux et te montrerai les differences avec le pourcentage de similarite."
        );
    }

    private function handleCapture(AgentContext $context, array $command): AgentResult
    {
        $description = $command['description'] ?? '';

        if ($context->hasMedia && $this->isImageMedia($context->mimetype)) {
            return $this->handleImageInfo($context);
        }

        return AgentResult::reply(
            "*Capture d'ecran*\n\n"
            . "Je ne peux pas capturer ton ecran directement, mais je peux :\n\n"
            . "- *Extraire du texte* : Envoie une image + 'extract-text'\n"
            . "- *Annoter* : Envoie une image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "- *Comparer* : Envoie deux images + 'compare'\n"
            . "- *Analyser* : Envoie une image + 'info'"
            . ($description ? "\n\nTa description : _{$description}_" : '')
        );
    }

    private function handleImageInfo(AgentContext $context): AgentResult
    {
        if (!$context->hasMedia || !$context->mediaUrl) {
            return AgentResult::reply("Envoie-moi une image pour obtenir ses informations.");
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

        return AgentResult::reply(
            "*Informations sur l'image :*\n\n"
            . "Dimensions : {$info['width']} x {$info['height']} px\n"
            . "Format : {$info['mime']}\n"
            . "Taille : {$info['size_human']}"
        );
    }

    private function handleWithClaude(AgentContext $context, string $body): AgentResult
    {
        // If image is sent with general screenshot intent, use Claude to analyze
        if ($context->hasMedia && $this->isImageMedia($context->mimetype)) {
            $model = $this->resolveModel($context);
            $memoryPrompt = $this->formatContextMemoryForPrompt($context->from);

            $systemPrompt = "Tu es un assistant specialise dans l'analyse d'images.\n"
                . "L'utilisateur t'envoie une image via WhatsApp avec une demande.\n"
                . "Aide-le a comprendre, extraire ou annoter le contenu de l'image.\n"
                . "Si l'image contient du texte, propose de l'extraire via OCR.\n"
                . "Reponds en francais de maniere concise.";

            if ($memoryPrompt) {
                $systemPrompt .= "\n\n" . $memoryPrompt;
            }

            $response = $this->claude->chat(
                "L'utilisateur a envoie une image avec le message: \"{$body}\"\n"
                . "Propose les actions possibles : OCR (extract-text), annotation, comparaison, info.",
                $model,
                $systemPrompt
            );

            return AgentResult::reply($response ?: $this->showHelp()->reply);
        }

        return $this->showHelp();
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*Screenshot & Annotate — Traitement d'images intelligent*\n\n"
            . "*Commandes disponibles :*\n\n"
            . "*Extraire du texte (OCR) :*\n"
            . "Envoie une image + 'extract-text'\n"
            . "Option langue : 'extract-text lang:eng'\n\n"
            . "*Annoter une image :*\n"
            . "Envoie une image + 'annotate arrow [x1,y1,x2,y2] red'\n"
            . "Types : arrow, rectangle, circle, text\n"
            . "Couleurs : red, green, blue, yellow, orange, cyan\n\n"
            . "*Comparer des images :*\n"
            . "'compare' + envoie 2 images\n\n"
            . "*Info image :*\n"
            . "Envoie une image + 'info'\n\n"
            . "*Declencheurs :* screenshot, capture, annotate, extract-text, ocr, compare"
        );
    }

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
}
