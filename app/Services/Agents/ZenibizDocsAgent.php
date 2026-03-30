<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ZenibizDocsAgent extends BaseAgent
{
    private const API_BASE = 'https://docs-main-jcjrnz.laravel.cloud/api/v1';

    /** Seconds to wait for more photos before considering the batch complete. */
    private const PHOTO_BATCH_TIMEOUT = 120;

    public function name(): string
    {
        return 'zenibiz_docs';
    }

    public function description(): string
    {
        return 'Agent prive ZENIBIZ DOCS: gestion documentaire via API REST. Categories, documents, sous-categories, recherche, statistiques. CRUD complet. Recoit des photos pour les convertir en PDF et les pousser sur la plateforme docs.';
    }

    public function keywords(): array
    {
        return [
            'zenibiz docs', 'docs zenibiz', 'documentation zenibiz',
            'categories docs', 'sous-categories', 'documents zenibiz',
            'recherche docs', 'stats docs', 'statistiques docs',
            'creer categorie', 'creer document', 'upload document',
            'liste documents', 'liste categories',
            'reordonner', 'reorder categories',
            'scanner', 'scan document', 'photo document', 'ajouter page',
        ];
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function requiredSecrets(): array
    {
        return [
            [
                "key" => "ZENIBIZ_DOCS_API_KEY",
                "label" => "Cle API Zenibiz Docs",
                "description" => "Token d'authentification pour l'API REST docs-main",
            ],
        ];
    }

    public function isPrivate(): bool
    {
        return true;
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->body || $context->hasMedia;
    }

    public function tools(): array
    {
        return array_merge(parent::tools(), [
            [
                'name' => 'docs_api_call',
                'description' => 'Effectue un appel API sur la plateforme ZENIBIZ Docs. Endpoints disponibles:
- GET /categories (lister categories)
- POST /categories (creer categorie: name, description, icon, color)
- GET /categories/{id} (detail categorie avec sous-categories)
- PUT /categories/{id} (modifier categorie)
- DELETE /categories/{id} (supprimer categorie)
- POST /categories/reorder (reordonner: ordered_ids[])
- GET /categories/{id}/subcategories (lister sous-categories)
- POST /categories/{id}/subcategories (creer sous-categorie: name, description)
- PUT /categories/{catId}/subcategories/{subId} (modifier sous-categorie)
- DELETE /categories/{catId}/subcategories/{subId} (supprimer sous-categorie)
- GET /documents (lister documents, params: category_id, subcategory_id, search, per_page)
- POST /documents (creer document: title, category_id, subcategory_id, content, tags[], is_active)
- GET /documents/{id} (detail document)
- PUT /documents/{id} (modifier document)
- DELETE /documents/{id} (supprimer document)
- POST /documents/{id}/upload (upload fichier: file)
- GET /documents/{id}/download (telecharger fichier)
- GET /search?q=terme (recherche full-text)
- GET /statistics (statistiques globales)',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'method' => [
                            'type' => 'string',
                            'enum' => ['GET', 'POST', 'PUT', 'DELETE'],
                            'description' => 'Methode HTTP',
                        ],
                        'endpoint' => [
                            'type' => 'string',
                            'description' => 'Endpoint relatif (ex: /categories, /documents/5, /search?q=test)',
                        ],
                        'body' => [
                            'type' => 'object',
                            'description' => 'Corps de la requete pour POST/PUT',
                        ],
                    ],
                    'required' => ['method', 'endpoint'],
                ],
            ],
        ]);
    }

    // ─── Main handler ───────────────────────────────────────────────

    public function handle(AgentContext $context): AgentResult
    {
        // Photo received → photo collection flow
        if ($context->hasMedia && $this->isImage($context->mimetype ?? ($context->media['mimetype'] ?? ''))) {
            return $this->handlePhotoReceived($context);
        }

        // Text message while collecting photos → handle commands (done, cancel, add title, etc.)
        $pending = $context->session->pending_agent_context;
        if ($pending && ($pending['agent'] ?? '') === 'zenibiz_docs' && ($pending['type'] ?? '') === 'photo_collection') {
            return $this->handlePhotoCollectionCommand($context, $pending);
        }

        // Regular text command → agentic loop for API operations
        return $this->handleWithAgenticLoop($context);
    }

    /**
     * Called by the orchestrator when there's a pending context for this agent.
     */
    public function handlePendingContext(AgentContext $context, array $pendingCtx): ?AgentResult
    {
        $type = $pendingCtx['type'] ?? '';

        // Photo received during any collection phase
        if ($context->hasMedia && $this->isImage($context->mimetype ?? ($context->media['mimetype'] ?? ''))) {
            if (in_array($type, ['photo_collection', 'photo_confirm', 'photo_subcat', 'photo_auto_confirm', 'photo_change_cat', 'photo_change_subcat'])) {
                // User sends more photos during confirm → go back to collection
                $pendingCtx['type'] = 'photo_collection';
                $context->session->update(['pending_agent_context' => $pendingCtx]);
                return $this->handlePhotoReceived($context);
            }
        }

        return match ($type) {
            'photo_collection' => $this->handlePhotoCollectionCommand($context, $pendingCtx),
            'photo_confirm' => $this->handlePendingConfirm($context, $pendingCtx),
            'photo_subcat' => $this->handlePendingSubcat($context, $pendingCtx),
            'photo_auto_confirm' => $this->handleAutoConfirm($context, $pendingCtx),
            'photo_change_cat' => $this->handleChangeCat($context, $pendingCtx),
            'photo_change_subcat' => $this->handleChangeSubcat($context, $pendingCtx),
            default => null,
        };
    }

    // ─── Photo collection flow ──────────────────────────────────────

    private function handlePhotoReceived(AgentContext $context): AgentResult
    {
        $pending = $context->session->pending_agent_context;
        $isExistingBatch = $pending
            && ($pending['agent'] ?? '') === 'zenibiz_docs'
            && ($pending['type'] ?? '') === 'photo_collection';

        // Download the image
        $mediaUrl = $this->resolveMediaUrl($context);
        if (!$mediaUrl) {
            $msg = "Impossible de recuperer l'image. Renvoie-la stp.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $imagePath = $this->downloadMedia($mediaUrl);
        if (!$imagePath) {
            $msg = "Erreur lors du telechargement de l'image. Renvoie-la stp.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        if ($isExistingBatch) {
            // Add to existing batch
            $photos = $pending['photos'] ?? [];
            $photos[] = $imagePath;
            $pending['photos'] = $photos;
            $pending['expires_at'] = now()->addSeconds(self::PHOTO_BATCH_TIMEOUT)->toIso8601String();

            $context->session->update(['pending_agent_context' => $pending]);

            $count = count($photos);
            $caption = $context->body ?? '';
            $msg = "📷 *Page {$count}* ajoutee au document";
            if ($caption) {
                $msg .= " (_{$caption}_)";
            }
            $msg .= ".\n\nEnvoie d'autres photos ou reponds:\n"
                . "• *ok* / *c'est tout* — finaliser le document\n"
                . "• *annuler* — abandonner";

            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Start new batch
        $caption = $context->body ?? '';
        $pendingData = [
            'agent' => 'zenibiz_docs',
            'type' => 'photo_collection',
            'photos' => [$imagePath],
            'title' => $caption ?: null,
            'expect_raw_input' => true,
            'expires_at' => now()->addSeconds(self::PHOTO_BATCH_TIMEOUT)->toIso8601String(),
        ];

        $context->session->update(['pending_agent_context' => $pendingData]);

        $msg = "📷 *1 photo recue*";
        if ($caption) {
            $msg .= " — titre provisoire : _{$caption}_";
        }
        $msg .= "\n\n📄 Je vais creer un document PDF.\n"
            . "• Envoie d'autres photos pour les ajouter au meme document\n"
            . "• *ok* / *c'est tout* — finaliser\n"
            . "• *annuler* — abandonner";

        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    private function handlePhotoCollectionCommand(AgentContext $context, array $pending): AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        $photos = $pending['photos'] ?? [];

        // Cancel
        if (in_array($body, ['annuler', 'cancel', 'stop', 'non'])) {
            $this->cleanupPhotos($photos);
            $context->session->update(['pending_agent_context' => null]);
            $msg = "❌ Document annule. Les photos ont ete supprimees.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Finalize
        $finalizePatterns = ['ok', 'oui', "c'est tout", 'cest tout', 'fini', 'done', 'go', 'envoie',
            'valide', 'confirme', 'termine', 'finalise', 'push', 'pousse'];
        if (in_array($body, $finalizePatterns) || str_contains($body, "c'est tout") || str_contains($body, 'cest tout')) {
            return $this->startFinalization($context, $pending);
        }

        // Set title
        if (str_starts_with($body, 'titre:') || str_starts_with($body, 'titre ')) {
            $title = trim(mb_substr($context->body, mb_stripos($context->body, ':') !== false ? mb_stripos($context->body, ':') + 1 : 6));
            $pending['title'] = $title;
            $context->session->update(['pending_agent_context' => $pending]);
            $msg = "✏️ Titre mis a jour : *{$title}*\n\nEnvoie d'autres photos ou reponds *ok* pour finaliser.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // If user sends text that looks like a title (no special command), treat as title update
        if (mb_strlen($body) < 100 && !str_contains($body, '?')) {
            $pending['title'] = $context->body;
            $pending['expires_at'] = now()->addSeconds(self::PHOTO_BATCH_TIMEOUT)->toIso8601String();
            $context->session->update(['pending_agent_context' => $pending]);
            $msg = "✏️ Titre : *{$context->body}*\n📷 " . count($photos) . " page(s)\n\nEnvoie d'autres photos ou *ok* pour finaliser.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Unknown command
        $msg = "📷 *" . count($photos) . " page(s)* en attente.\n\n"
            . "• Envoie une photo pour ajouter une page\n"
            . "• *ok* — finaliser le document\n"
            . "• *titre: Mon document* — changer le titre\n"
            . "• *annuler* — abandonner";
        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    private function startFinalization(AgentContext $context, array $pending): AgentResult
    {
        $photos = $pending['photos'] ?? [];
        $userTitle = $pending['title'] ?? null;
        $count = count($photos);

        if ($count === 0) {
            $context->session->update(['pending_agent_context' => null]);
            $msg = "Aucune photo a traiter.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $this->sendText($context->from, "🔍 Analyse du document en cours...");

        // Use Claude Vision to analyze the first image and auto-classify
        $apiKey = $this->getApiKey($context);
        $categories = $this->fetchCategories($apiKey);
        $subcategories = $this->fetchAllSubcategories($apiKey, $categories);
        $classification = $this->classifyDocument($photos[0], $userTitle, $categories, $subcategories, $context);

        $title = $classification['title'] ?? $userTitle ?? 'Document_' . now()->format('Y-m-d_H-i');
        $categoryId = $classification['category_id'] ?? ($categories[0]['id'] ?? 1);
        $subcategoryId = $classification['subcategory_id'] ?? null;
        $catName = $classification['category_name'] ?? '?';
        $subcatName = $classification['subcategory_name'] ?? null;
        $tags = $classification['tags'] ?? ['scan', 'whatsapp'];

        $location = $catName;
        if ($subcatName) $location .= " > {$subcatName}";

        $pending['type'] = 'photo_auto_confirm';
        $pending['title'] = $title;
        $pending['category_id'] = $categoryId;
        $pending['subcategory_id'] = $subcategoryId;
        $pending['category_name'] = $catName;
        $pending['subcategory_name'] = $subcatName;
        $pending['tags'] = $tags;
        $pending['expect_raw_input'] = true;
        $context->session->update(['pending_agent_context' => $pending]);

        $msg = "📄 *Document analyse*\n\n"
            . "• Titre : *{$title}*\n"
            . "• Pages : *{$count}*\n"
            . "• Categorie : *{$location}*\n"
            . "• Tags : " . implode(', ', $tags) . "\n\n"
            . "*Que faire ?*\n"
            . "1️⃣ *oui* — valider et pousser sur Docs\n"
            . "2️⃣ *categorie* — changer la categorie\n"
            . "✏️ Envoie un texte pour modifier le titre\n"
            . "❌ *annuler* — abandonner";

        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    // ─── Finalization (after category selection) ────────────────────

    /**
     * Also handle the confirmation step via pending context.
     */
    public function handlePendingConfirm(AgentContext $context, array $pending): ?AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if (in_array($body, ['annuler', 'cancel'])) {
            $this->cleanupPhotos($pending['photos'] ?? []);
            $context->session->update(['pending_agent_context' => null]);
            $msg = "❌ Document annule.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $categories = $pending['categories'] ?? [];
        $selection = intval($body);

        if ($selection < 1 || $selection > count($categories)) {
            $msg = "Reponds avec un numero entre 1 et " . count($categories) . ", ou *annuler*.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $category = $categories[$selection - 1];
        $photos = $pending['photos'] ?? [];
        $title = $pending['title'] ?? 'Document_' . now()->format('Y-m-d_H-i');

        $context->session->update(['pending_agent_context' => null]);

        // Check for subcategories
        $apiKey = $this->getApiKey($context);
        $subcategories = $this->fetchSubcategories($apiKey, $category['id']);

        if (!empty($subcategories)) {
            // Ask for subcategory
            $pending['type'] = 'photo_subcat';
            $pending['selected_category'] = $category;
            $pending['subcategories'] = $subcategories;
            $context->session->update(['pending_agent_context' => $pending]);

            $subList = '';
            foreach ($subcategories as $i => $sub) {
                $subList .= ($i + 1) . ". {$sub['name']}\n";
            }
            $subList .= (count($subcategories) + 1) . ". _(aucune sous-categorie)_";

            $msg = "📂 *{$category['icon']} {$category['name']}*\n\nSous-categorie ?\n{$subList}";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // No subcategories → generate and push
        return $this->generateAndPush($context, $photos, $title, $category['id'], null, $apiKey);
    }

    public function handlePendingSubcat(AgentContext $context, array $pending): ?AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if (in_array($body, ['annuler', 'cancel'])) {
            $this->cleanupPhotos($pending['photos'] ?? []);
            $context->session->update(['pending_agent_context' => null]);
            $msg = "❌ Document annule.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $subcategories = $pending['subcategories'] ?? [];
        $category = $pending['selected_category'] ?? [];
        $selection = intval($body);
        $maxChoice = count($subcategories) + 1;

        if ($selection < 1 || $selection > $maxChoice) {
            $msg = "Reponds avec un numero entre 1 et {$maxChoice}.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $subcatId = null;
        if ($selection <= count($subcategories)) {
            $subcatId = $subcategories[$selection - 1]['id'];
        }

        $photos = $pending['photos'] ?? [];
        $title = $pending['title'] ?? 'Document_' . now()->format('Y-m-d_H-i');
        $apiKey = $this->getApiKey($context);

        $context->session->update(['pending_agent_context' => null]);

        return $this->generateAndPush($context, $photos, $title, $category['id'], $subcatId, $apiKey);
    }

    // ─── PDF generation & API push ──────────────────────────────────

    private function generateAndPush(AgentContext $context, array $photos, string $title, int $categoryId, ?int $subcatId, string $apiKey, array $tags = ['scan', 'whatsapp']): AgentResult
    {
        $this->sendText($context->from, "⏳ Generation du PDF (" . count($photos) . " pages)...");

        try {
            $pdfPath = $this->generatePdfFromPhotos($photos, $title);
        } catch (\Throwable $e) {
            Log::error("ZenibizDocsAgent PDF generation failed: {$e->getMessage()}");
            $this->cleanupPhotos($photos);
            $msg = "❌ Erreur lors de la generation du PDF : {$e->getMessage()}";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Create document with file in a single multipart request
        try {
            $pdfFilename = Str::slug($title) . '.pdf';
            $content = "Document PDF genere depuis " . count($photos) . " photo(s) via WhatsApp le " . now()->format('d/m/Y a H:i');

            $request = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->attach('file', file_get_contents($pdfPath), $pdfFilename, ['Content-Type' => 'application/pdf']);

            $formData = [
                'title' => $title,
                'category_id' => $categoryId,
                'content' => $content,
                'tags' => json_encode($tags),
                'is_active' => '1',
            ];
            if ($subcatId) {
                $formData['subcategory_id'] = $subcatId;
            }

            $createResponse = $request->post(self::API_BASE . '/documents', $formData);

            if (!$createResponse->successful()) {
                $error = $createResponse->json('message') ?? $createResponse->body();
                $this->cleanupPhotos($photos);
                @unlink($pdfPath);
                $msg = "❌ Erreur API : {$error}";
                $this->sendText($context->from, $msg);
                return AgentResult::reply($msg);
            }

            $doc = $createResponse->json('data') ?? $createResponse->json();
            $docId = $doc['id'] ?? null;

            $this->cleanupPhotos($photos);
            @unlink($pdfPath);

            $msg = "✅ *Document cree avec succes !*\n\n"
                . "📄 *{$title}*\n"
                . "📁 ID: #{$docId}\n"
                . "📷 " . count($photos) . " page(s)\n"
                . "🔗 https://docs-main-jcjrnz.laravel.cloud/documents/{$docId}";

            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);

        } catch (\Throwable $e) {
            Log::error("ZenibizDocsAgent push failed: {$e->getMessage()}");
            $this->cleanupPhotos($photos);
            @unlink($pdfPath);
            $msg = "❌ Erreur : {$e->getMessage()}";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }
    }

    private function generatePdfFromPhotos(array $photoPaths, string $title): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('tempDir', storage_path('app/temp'));

        $dompdf = new Dompdf($options);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<style>'
            . 'body { margin: 0; padding: 0; }'
            . '.page { page-break-after: always; text-align: center; padding: 10px; }'
            . '.page:last-child { page-break-after: auto; }'
            . '.page img { max-width: 100%; max-height: 95vh; object-fit: contain; }'
            . '.header { font-family: Helvetica, sans-serif; font-size: 14px; color: #333; margin-bottom: 10px; text-align: center; }'
            . '</style></head><body>';

        foreach ($photoPaths as $i => $path) {
            if (!file_exists($path)) continue;

            $imageData = base64_encode(file_get_contents($path));
            $mime = mime_content_type($path) ?: 'image/jpeg';

            $html .= '<div class="page">';
            if ($i === 0) {
                $html .= '<div class="header">' . htmlspecialchars($title) . '</div>';
            }
            $html .= '<img src="data:' . $mime . ';base64,' . $imageData . '" />';
            $html .= '</div>';
        }

        $html .= '</body></html>';

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $outputPath = storage_path('app/temp/' . Str::slug($title) . '_' . Str::random(8) . '.pdf');
        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        file_put_contents($outputPath, $dompdf->output());

        return $outputPath;
    }

    private function handleAutoConfirm(AgentContext $context, array $pending): AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        // Cancel
        if (in_array($body, ['annuler', 'cancel', 'non'])) {
            $this->cleanupPhotos($pending['photos'] ?? []);
            $context->session->update(['pending_agent_context' => null]);
            $msg = "❌ Document annule.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Validate → push
        if (in_array($body, ['oui', 'ok', 'yes', 'go', 'valide', 'confirme', 'envoie', 'push', '1'])) {
            $context->session->update(['pending_agent_context' => null]);
            $apiKey = $this->getApiKey($context);

            // Learn this classification for future documents
            $this->learnClassification($context, $pending);

            return $this->generateAndPush(
                $context,
                $pending['photos'] ?? [],
                $pending['title'] ?? 'Document',
                $pending['category_id'],
                $pending['subcategory_id'] ?? null,
                $apiKey,
                $pending['tags'] ?? ['scan', 'whatsapp'],
            );
        }

        // Change category → show category list
        if (str_contains($body, 'categorie') || str_contains($body, 'catégorie') || $body === '2' || str_contains($body, 'changer cat')) {
            $apiKey = $this->getApiKey($context);
            $categories = $this->fetchCategories($apiKey);
            $pending['type'] = 'photo_change_cat';
            $pending['categories'] = $categories;
            $context->session->update(['pending_agent_context' => $pending]);

            $catList = '';
            foreach ($categories as $i => $cat) {
                $catList .= ($i + 1) . ". {$cat['icon']} {$cat['name']}\n";
            }
            $msg = "📂 *Changer la categorie*\n\n{$catList}\nReponds avec le numero.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // Anything else = new title
        $pending['title'] = $context->body;
        $context->session->update(['pending_agent_context' => $pending]);

        $location = $pending['category_name'] ?? '?';
        if (!empty($pending['subcategory_name'])) $location .= " > {$pending['subcategory_name']}";

        $msg = "✏️ Titre : *{$context->body}*\n"
            . "📂 Categorie : *{$location}*\n\n"
            . "1️⃣ *oui* — valider\n"
            . "2️⃣ *categorie* — changer la categorie\n"
            . "❌ *annuler*";
        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    /**
     * Handle category change during confirmation.
     */
    public function handleChangeCat(AgentContext $context, array $pending): ?AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if (in_array($body, ['annuler', 'cancel'])) {
            $this->cleanupPhotos($pending['photos'] ?? []);
            $context->session->update(['pending_agent_context' => null]);
            $msg = "❌ Document annule.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $categories = $pending['categories'] ?? [];
        $selection = intval($body);

        if ($selection < 1 || $selection > count($categories)) {
            $msg = "Reponds avec un numero entre 1 et " . count($categories) . ".";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $category = $categories[$selection - 1];
        $pending['category_id'] = $category['id'];
        $pending['category_name'] = $category['name'];

        // Check subcategories
        $apiKey = $this->getApiKey($context);
        $subcategories = $this->fetchSubcategories($apiKey, $category['id']);

        if (!empty($subcategories)) {
            $pending['type'] = 'photo_change_subcat';
            $pending['subcategories'] = $subcategories;
            $context->session->update(['pending_agent_context' => $pending]);

            $subList = '';
            foreach ($subcategories as $i => $sub) {
                $subList .= ($i + 1) . ". {$sub['name']}\n";
            }
            $subList .= (count($subcategories) + 1) . ". _(aucune)_";

            $msg = "📂 *{$category['icon']} {$category['name']}*\n\nSous-categorie ?\n{$subList}";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        // No subcategories → back to confirm
        $pending['subcategory_id'] = null;
        $pending['subcategory_name'] = null;
        $pending['type'] = 'photo_auto_confirm';
        unset($pending['categories'], $pending['subcategories']);
        $context->session->update(['pending_agent_context' => $pending]);

        $msg = "📄 *Mis a jour*\n\n"
            . "• Titre : *{$pending['title']}*\n"
            . "• Categorie : *{$category['icon']} {$category['name']}*\n\n"
            . "1️⃣ *oui* — valider\n"
            . "2️⃣ *categorie* — changer\n"
            . "❌ *annuler*";
        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    /**
     * Handle subcategory change.
     */
    public function handleChangeSubcat(AgentContext $context, array $pending): ?AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        $subcategories = $pending['subcategories'] ?? [];
        $selection = intval($body);
        $maxChoice = count($subcategories) + 1;

        if ($selection < 1 || $selection > $maxChoice) {
            $msg = "Reponds avec un numero entre 1 et {$maxChoice}.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        if ($selection <= count($subcategories)) {
            $pending['subcategory_id'] = $subcategories[$selection - 1]['id'];
            $pending['subcategory_name'] = $subcategories[$selection - 1]['name'];
        } else {
            $pending['subcategory_id'] = null;
            $pending['subcategory_name'] = null;
        }

        $pending['type'] = 'photo_auto_confirm';
        unset($pending['categories'], $pending['subcategories']);
        $context->session->update(['pending_agent_context' => $pending]);

        $location = $pending['category_name'] ?? '?';
        if (!empty($pending['subcategory_name'])) $location .= " > {$pending['subcategory_name']}";

        $msg = "📄 *Mis a jour*\n\n"
            . "• Titre : *{$pending['title']}*\n"
            . "• Categorie : *{$location}*\n\n"
            . "1️⃣ *oui* — valider\n"
            . "2️⃣ *categorie* — changer\n"
            . "❌ *annuler*";
        $this->sendText($context->from, $msg);
        return AgentResult::reply($msg);
    }

    // ─── Learning: remember doc type → category mapping ─────────────

    private function learnClassification(AgentContext $context, array $pending): void
    {
        try {
            $tags = $pending['tags'] ?? [];
            $categoryId = $pending['category_id'] ?? null;
            $subcategoryId = $pending['subcategory_id'] ?? null;
            $categoryName = $pending['category_name'] ?? '';
            $subcategoryName = $pending['subcategory_name'] ?? '';

            if (!$categoryId || empty($tags)) return;

            // Store in agent memory as a classification rule
            $rules = $this->getLearnedRules($context);

            // Build a key from the most distinctive tags
            $sortedTags = $tags;
            sort($sortedTags);
            $ruleKey = implode('|', array_slice($sortedTags, 0, 3));

            $rules[$ruleKey] = [
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'category_name' => $categoryName,
                'subcategory_name' => $subcategoryName,
                'tags' => $tags,
                'learned_at' => now()->toIso8601String(),
            ];

            $this->saveLearnedRules($context, $rules);
        } catch (\Throwable $e) {
            Log::warning('ZenibizDocsAgent learn failed: ' . $e->getMessage());
        }
    }

    private function getLearnedRules(AgentContext $context): array
    {
        $path = storage_path("app/zenibiz_docs_rules/{$context->agent->id}.json");
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function saveLearnedRules(AgentContext $context, array $rules): void
    {
        $dir = storage_path('app/zenibiz_docs_rules');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents("{$dir}/{$context->agent->id}.json", json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Check learned rules to override classification.
     */
    private function applyLearnedRules(array $classification, AgentContext $context): array
    {
        $rules = $this->getLearnedRules($context);
        if (empty($rules)) return $classification;

        $tags = $classification['tags'] ?? [];
        $sortedTags = $tags;
        sort($sortedTags);

        // Try to match by tags overlap
        $bestMatch = null;
        $bestScore = 0;

        foreach ($rules as $ruleKey => $rule) {
            $ruleTags = explode('|', $ruleKey);
            $overlap = count(array_intersect($sortedTags, $ruleTags));
            if ($overlap > $bestScore) {
                $bestScore = $overlap;
                $bestMatch = $rule;
            }
        }

        // Need at least 2 matching tags to apply a learned rule
        if ($bestMatch && $bestScore >= 2) {
            $classification['category_id'] = $bestMatch['category_id'];
            $classification['subcategory_id'] = $bestMatch['subcategory_id'];
            $classification['category_name'] = $bestMatch['category_name'];
            $classification['subcategory_name'] = $bestMatch['subcategory_name'];
        }

        return $classification;
    }

    private function classifyDocument(string $imagePath, ?string $userHint, array $categories, array $subcategories, AgentContext $context): array
    {
        $catTree = '';
        foreach ($categories as $cat) {
            $catTree .= "- id:{$cat['id']} {$cat['icon']} {$cat['name']} ({$cat['description']})\n";
            foreach ($subcategories[$cat['id']] ?? [] as $sub) {
                $catTree .= "  - subcat_id:{$sub['id']} {$sub['name']}\n";
            }
        }

        $textPrompt = "Analyse cette image de document. Fais l'OCR du texte visible, puis classifie le document.\n"
            . ($userHint ? "Indication de l'utilisateur: {$userHint}\n" : '')
            . "\nCategories disponibles:\n{$catTree}\n"
            . "Reponds UNIQUEMENT en JSON valide (sans markdown, sans ```), exactement ce format:\n"
            . '{"title": "titre court et descriptif base sur le contenu", "category_id": N, "subcategory_id": N_ou_null, "category_name": "nom", "subcategory_name": "nom_ou_null", "tags": ["tag1", "tag2"], "ocr_text": "texte extrait du document"}';

        try {
            // Build multimodal message with base64 image
            $imageData = @file_get_contents($imagePath);
            if (!$imageData) {
                Log::warning('ZenibizDocsAgent: cannot read image at ' . $imagePath);
                throw new \RuntimeException('Cannot read image file');
            }

            $base64 = base64_encode($imageData);
            $mime = mime_content_type($imagePath) ?: 'image/jpeg';

            $message = [
                ['type' => 'image', 'source' => [
                    'type' => 'base64',
                    'media_type' => $mime,
                    'data' => $base64,
                ]],
                ['type' => 'text', 'text' => $textPrompt],
            ];

            $claude = new \App\Services\AnthropicClient();
            $model = \App\Services\ModelResolver::balanced();
            $text = $claude->chat($message, $model);

            Log::info('ZenibizDocsAgent classification', ['result' => mb_substr($text ?? '', 0, 500)]);

            // Parse JSON from response
            $json = json_decode($text ?? '', true);
            if ($json && isset($json['category_id'])) {
                return $this->applyLearnedRules($json, $context);
            }
            // Try to extract JSON from mixed text
            if (preg_match('/\{[^{}]*"category_id"[^{}]*\}/s', $text ?? '', $matches)) {
                $json = json_decode($matches[0], true);
                if ($json && isset($json['category_id'])) return $this->applyLearnedRules($json, $context);
            }
        } catch (\Throwable $e) {
            Log::warning('ZenibizDocsAgent classification failed: ' . $e->getMessage());
        }

        return [
            'title' => $userHint ?? 'Document_' . now()->format('Y-m-d_H-i'),
            'category_id' => $categories[0]['id'] ?? 1,
            'subcategory_id' => null,
            'category_name' => $categories[0]['name'] ?? 'General',
            'subcategory_name' => null,
            'tags' => ['scan', 'whatsapp', 'non-classifie'],
        ];
    }

    private function fetchAllSubcategories(string $apiKey, array $categories): array
    {
        $result = [];
        foreach ($categories as $cat) {
            $result[$cat['id']] = $this->fetchSubcategories($apiKey, $cat['id']);
        }
        return $result;
    }

    // ─── Agentic loop for text commands ─────────────────────────────

    private function handleWithAgenticLoop(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);
        $apiKey = $this->getApiKey($context);

        if (!$apiKey) {
            $msg = "Cle API ZENIBIZ Docs non configuree. Ajoutez le secret `ZENIBIZ_DOCS_API_KEY` dans les secrets de l'agent.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg);
        }

        $systemPrompt = <<<PROMPT
Tu es l'agent ZENIBIZ DOCS, specialise dans la gestion documentaire de la plateforme Zenibiz.

BASE URL API: https://docs-main-jcjrnz.laravel.cloud/api/v1
Tu as acces a l'outil docs_api_call pour interagir avec l'API.

CAPACITES:
- Lister, creer, modifier, supprimer des categories et sous-categories
- Lister, creer, modifier, supprimer des documents
- Rechercher dans les documents (full-text)
- Consulter les statistiques
- Generer et uploader des fichiers (PDF, XLSX, DOCX)

REGLES:
- Reponds toujours en francais
- Formate les resultats de maniere lisible (*gras* pour les titres, listes)
- Pour les listes longues, resume et propose de voir les details
- Si une operation echoue, explique l'erreur clairement
- Utilise les bons endpoints selon l'action demandee
PROMPT;

        $result = $this->runWithTools(
            userMessage: $context->body ?? '',
            systemPrompt: $systemPrompt,
            context: $context,
            model: $model,
            maxIterations: 10,
        );

        $reply = $result->reply ?: "Operation effectuee.";
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, [
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
        ]);
    }

    // ─── Tool execution ─────────────────────────────────────────────

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'docs_api_call' => json_encode($this->handleApiCall($input, $context)),
            default => parent::executeTool($name, $input, $context),
        };
    }

    private function handleApiCall(array $input, AgentContext $context): array
    {
        $apiKey = $this->getApiKey($context);
        $method = strtoupper($input['method'] ?? 'GET');
        $endpoint = ltrim($input['endpoint'] ?? '', '/');
        $body = $input['body'] ?? [];
        $url = self::API_BASE . '/' . $endpoint;

        try {
            $request = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ]);

            $response = match ($method) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $body),
                'PUT' => $request->put($url, $body),
                'DELETE' => $request->delete($url),
                default => $request->get($url),
            };

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error("ZenibizDocsAgent API call failed: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function fetchCategories(string $apiKey): array
    {
        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->get(self::API_BASE . '/categories');

            return $response->json('data') ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fetchSubcategories(string $apiKey, int $categoryId): array
    {
        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->get(self::API_BASE . "/categories/{$categoryId}/subcategories");

            return $response->json('data') ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function resolveMediaUrl(AgentContext $context): ?string
    {
        if ($context->mediaUrl) {
            return $context->mediaUrl;
        }
        $media = $context->media ?? [];
        $url = $media['url'] ?? $media['directPath'] ?? null;

        if (!$url) {
            $this->log($context, 'resolveMediaUrl: no URL found', [
                'hasMedia' => $context->hasMedia,
                'mediaUrl' => $context->mediaUrl,
                'media_keys' => is_array($media) ? array_keys($media) : 'not_array',
            ], 'warn');
        }

        return $url;
    }

    private function downloadMedia(string $mediaUrl): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->get($mediaUrl);

            if (!$response->successful()) {
                return null;
            }

            $mime = $response->header('Content-Type') ?? 'image/jpeg';
            $ext = match (true) {
                str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'webp') => 'webp',
                default => 'jpg',
            };

            $dir = storage_path('app/temp/docs_photos');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filename = 'photo_' . Str::random(12) . '.' . $ext;
            $path = $dir . '/' . $filename;
            file_put_contents($path, $response->body());

            return $path;
        } catch (\Throwable $e) {
            Log::error("ZenibizDocsAgent download failed: {$e->getMessage()}");
            return null;
        }
    }

    private function isImage(?string $mimetype): bool
    {
        if (!$mimetype) return false;
        return str_starts_with($mimetype, 'image/');
    }

    private function cleanupPhotos(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    private function getApiKey(AgentContext $context): ?string
    {
        return $this->getCredential($context, 'ZENIBIZ_DOCS_API_KEY');
    }
}

