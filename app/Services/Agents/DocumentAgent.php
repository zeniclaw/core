<?php

namespace App\Services\Agents;

use App\Models\UserKnowledge;
use App\Services\AgentContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Style\ListItem as ListItemStyle;

class DocumentAgent extends BaseAgent
{
    /** Maximum characters for the filename slug (without extension). */
    private const FILENAME_MAX_LENGTH = 80;

    public function name(): string
    {
        return 'document';
    }

    public function description(): string
    {
        return 'Creation de documents: Excel (XLSX), PDF, Word (DOCX), CSV. Tableaux, rapports, lettres, factures, CV, contrats. Blocs visuels: callout, citation, surlignage, signature, paires cle/valeur, badge statut, encadre recapitulatif. Pied de page PDF. Orientation paysage.';
    }

    public function keywords(): array
    {
        return [
            'excel', 'xlsx', 'tableau', 'spreadsheet',
            'csv', 'export csv', 'exporte en csv', 'fichier csv',
            'pdf', 'document pdf', 'rapport pdf', 'genere pdf',
            'word', 'docx', 'lettre', 'document word',
            'facture', 'invoice', 'devis', 'quote',
            'cv', 'resume', 'curriculum',
            'rapport', 'report', 'bilan',
            'cree un fichier', 'genere un document', 'exporter',
            'tableau excel', 'feuille de calcul',
            'contrat', 'attestation', 'certificat',
            'liste numerotee', 'liste ordonnee',
            'note encadree', 'callout', 'encadre', 'avertissement', 'alerte document',
            'note importante', 'mise en evidence', 'encart',
            'citation', 'bloc citation', 'reference legale', 'article de loi',
            'texte surligne', 'surlignage', 'mise en valeur',
            'alerte critique', 'danger document', 'erreur critique',
            'signature', 'signer', 'contresigner', 'bon pour accord', 'signe par',
            'paysage', 'orientation paysage', 'mode paysage', 'landscape',
            'cle valeur', 'champ valeur', 'informations client', 'donnees facture',
            'badge', 'statut', 'tag statut', 'etiquette',
            'recapitulatif', 'synthese', 'resume document', 'points cles',
            'pied de page', 'footer', 'bas de page',
        ];
    }

    public function version(): string
    {
        return '1.5.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return (bool) $context->body;
    }

    public function handle(AgentContext $context): AgentResult
    {
        // If the request likely needs external data, use the agentic loop
        // so we get access to web_search, send_agent_message, etc.
        if ($this->needsExternalData($context->body ?? '')) {
            return $this->handleWithTools($context);
        }

        return $this->handleDirect($context);
    }

    /**
     * Detect if the document request needs external data (web search, API, etc.).
     * Returns true when the user asks for real-world data the LLM doesn't have.
     */
    private function needsExternalData(string $body): bool
    {
        $lower = mb_strtolower($body);

        // Patterns indicating the user wants real data from the web
        $needsDataPatterns = [
            'liste des ', 'list of ', 'toutes les ', 'tous les ',
            'brasseries', 'restaurants', 'hotels', 'entreprises', 'societes',
            'en wallonie', 'en belgique', 'en france', 'a bruxelles', 'a paris',
            'trouve', 'cherche', 'recherche', 'find',
            'actuelles', 'actuel', 'recentes', 'a jour',
            'comparatif', 'classement', 'top ', 'meilleur',
        ];

        foreach ($needsDataPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle document creation WITH tools (agentic loop).
     * Used when external data is needed (web search, agent collaboration, etc.).
     */
    private function handleWithTools(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $userContext         = $this->formatContextMemoryForPrompt($context->from);
        $knowledgeData       = $this->getStoredKnowledge($context);

        $systemPrompt = <<<PROMPT
Tu es un agent expert en creation de documents professionnels.
Tu as acces a des outils pour collecter des donnees REELLES avant de creer le document.

WORKFLOW OBLIGATOIRE:
1. D'abord, utilise web_search (plusieurs recherches si necessaire) pour collecter les donnees reelles
2. Optionnellement, utilise web_fetch pour lire les pages les plus pertinentes en detail
3. Enfin, utilise create_document pour generer le fichier avec les VRAIES donnees collectees

{$userContext}
{$knowledgeData}

REGLES:
- Ne genere JAMAIS de donnees inventees quand tu peux chercher les vraies
- Fais PLUSIEURS recherches web pour etre exhaustif
- Le document final doit contenir des donnees REELLES, pas des exemples
- Utilise le format le plus adapte (xlsx pour des listes/tableaux, pdf pour des rapports)
PROMPT;

        $result = $this->runWithTools(
            userMessage: $context->body ?? '',
            systemPrompt: $systemPrompt,
            context: $context,
            model: $model,
            maxIterations: 12,
        );

        $reply = $result->reply;

        if (!$reply) {
            $reply = "Le document a ete genere avec les outils utilises: " . implode(', ', array_unique($result->toolsUsed));
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
        ]);
    }

    /**
     * Handle document creation directly (one-shot JSON generation).
     * Used when the user provides all data or the request is self-contained.
     */
    private function handleDirect(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $systemPrompt = <<<'PROMPT'
Tu es un agent expert en creation de documents professionnels. Analyse la demande et genere une structure JSON complete et realiste.

Reponds UNIQUEMENT avec du JSON valide (sans markdown, sans explication):
{
    "format": "xlsx" | "pdf" | "docx" | "csv",
    "filename": "nom_sans_extension",
    "title": "Titre complet du document",
    "content": { ... }
}

---
FORMAT XLSX (tableaux, budgets, inventaires, donnees chiffrees):
{
    "format": "xlsx",
    "filename": "budget_2026",
    "title": "Budget Previsionnel 2026",
    "content": {
        "sheets": [
            {
                "name": "Revenus",
                "headers": ["Categorie", "Jan", "Fev", "Mar", "Total"],
                "rows": [
                    ["Ventes produits", 12000, 15000, 11000, 38000],
                    ["Services", 5000, 4500, 6000, 15500],
                    ["TOTAL", 17000, 19500, 17000, 53500]
                ]
            },
            {
                "name": "Depenses",
                "headers": ["Poste", "Montant mensuel", "Annuel"],
                "rows": [
                    ["Loyer", 2000, 24000],
                    ["Salaires", 8000, 96000]
                ]
            }
        ]
    }
}

---
FORMAT CSV (export simple, listes, donnees brutes):
{
    "format": "csv",
    "filename": "clients_2026",
    "title": "Export clients 2026",
    "content": {
        "headers": ["Nom", "Email", "Telephone", "Ville", "Statut"],
        "rows": [
            ["Alice Dupont", "alice@exemple.com", "0612345678", "Paris", "Actif"],
            ["Bob Martin", "bob@exemple.com", "0698765432", "Lyon", "Inactif"]
        ]
    }
}

---
FORMAT PDF (rapports, factures, lettres formelles):
{
    "format": "pdf",
    "filename": "facture_2026_001",
    "title": "Facture #2026-001",
    "content": {
        "orientation": "portrait",
        "sections": [
            {"type": "key_value", "pairs": [
                {"label": "Client", "value": "ACME Corp — 12 rue de la Paix, 75001 Paris"},
                {"label": "Date", "value": "09/03/2026"},
                {"label": "Reference", "value": "FC-2026-001"},
                {"label": "IBAN", "value": "FR76 3000 6000 0112 3456 7890 189"}
            ]},
            {"type": "separator"},
            {"type": "table", "headers": ["Designation", "Qte", "PU HT", "Total HT"], "rows": [
                ["Developpement web", "10h", "150 EUR", "1 500 EUR"],
                ["Design UI/UX", "5h", "120 EUR", "600 EUR"]
            ]},
            {"type": "separator"},
            {"type": "bold", "text": "Sous-total HT: 2 100 EUR"},
            {"type": "bold", "text": "TVA 20%: 420 EUR"},
            {"type": "highlight", "text": "TOTAL TTC: 2 520 EUR", "color": "yellow"},
            {"type": "ordered_list", "items": ["Paiement sous 30 jours", "Penalites de retard: 3x le taux legal en vigueur"]},
            {"type": "page_break"},
            {"type": "heading", "text": "Conditions Generales", "level": 2},
            {"type": "paragraph", "text": "..."},
            {"type": "signature", "signers": [
                {"name": "Jean Dupont", "title": "Directeur General"},
                {"name": "Marie Martin", "title": "Client — ACME Corp"}
            ]}
        ]
    }
}

---
FORMAT DOCX (lettres, contrats, CV, rapports editables):
{
    "format": "docx",
    "filename": "cv_jean_dupont",
    "title": "CV - Jean Dupont",
    "content": {
        "sections": [
            {"type": "heading", "text": "Experience Professionnelle", "level": 1},
            {"type": "heading", "text": "Developpeur Senior — ACME Corp (2022-2026)", "level": 2},
            {"type": "list", "items": ["Developpement d'API REST avec Laravel 12", "Management d'une equipe de 3 developpeurs"]},
            {"type": "callout", "text": "Disponible immediatement — Permis B — Mobilite nationale", "style": "info"},
            {"type": "separator"},
            {"type": "heading", "text": "Competences Techniques", "level": 1},
            {"type": "ordered_list", "items": ["PHP / Laravel (Expert)", "JavaScript / Vue.js (Avance)", "Docker / CI-CD (Intermediaire)"]},
            {"type": "table", "headers": ["Competence", "Niveau", "Annees exp."], "rows": [
                ["PHP Laravel", "Expert", "6 ans"],
                ["Python", "Intermediaire", "3 ans"]
            ]},
            {"type": "page_break"},
            {"type": "heading", "text": "Formation", "level": 1},
            {"type": "paragraph", "text": "Master Informatique — Universite Paris XI (2018-2020)"}
        ]
    }
}

---
TYPE CALLOUT (encart visuel — fonctionne dans PDF et DOCX):
{"type": "callout", "text": "Texte de la note ou de l'avertissement", "style": "info"}
  style: "info" (bleu), "warning" (orange), "success" (vert), "danger" (rouge — erreurs critiques, clauses risquees)
  Utilise pour: conditions importantes dans les contrats, notes legales dans les factures, conseils dans les rapports, points cles dans les CV.

---
TYPE QUOTE (bloc citation — fonctionne dans PDF et DOCX):
{"type": "quote", "text": "Article L.1234-56 du Code du travail: Le contrat de travail..."}
  Utilise pour: citations legales, references reglementaires, extraits de jurisprudence, temoignages, conditions generales citees.

---
TYPE HIGHLIGHT (texte surligne — fonctionne dans PDF et DOCX):
{"type": "highlight", "text": "Montant total TTC: 2 520 EUR", "color": "yellow"}
  color: "yellow" (defaut), "green", "cyan", "pink"
  Utilise pour: chiffres cles, montants importants, dates critiques, termes essentiels dans les contrats.

---
TYPE SIGNATURE (bloc de signature — fonctionne dans PDF et DOCX):
{"type": "signature", "signers": [{"name": "Jean Dupont", "title": "Directeur General"}, {"name": "Marie Martin", "title": "Client"}]}
  Utilise pour: fins de contrats, lettres officielles, bons de commande, accords. Peut inclure 1 a 4 signataires.
  Chaque signataire affiche une ligne de signature avec son nom et sa fonction.

---
TYPE KEY_VALUE (paires cle/valeur — fonctionne dans PDF et DOCX):
{"type": "key_value", "pairs": [{"label": "Client", "value": "ACME Corp"}, {"label": "Date", "value": "09/03/2026"}, {"label": "Reference", "value": "FC-2026-001"}]}
  Utilise pour: en-tetes de factures, informations client/fournisseur, metadata de documents, recapitulatif de commande, fiches contact.

---
TYPE BADGE (pastille de statut — fonctionne dans PDF et DOCX):
{"type": "badge", "items": [{"label": "Actif", "color": "green"}, {"label": "En attente", "color": "orange"}, {"label": "Cloture", "color": "red"}]}
  color: "green", "orange", "red", "blue", "gray"
  Utilise pour: statuts de projets, etiquettes de priorite, indicateurs dans des rapports, syntheses de tableaux de bord.

---
TYPE SUMMARY_BOX (encadre recapitulatif — fonctionne dans PDF et DOCX):
{"type": "summary_box", "title": "Points Cles", "items": ["Chiffre d'affaires en hausse de 12%", "3 nouveaux clients signes", "Objectif Q1 atteint a 95%"]}
  Utilise pour: recapitulatifs de rapports, points cles de reunions, syntheses de projets, conclusions de bilans.

---
PIED DE PAGE PDF (footer — s'affiche en bas de chaque page):
Pour ajouter un pied de page a un PDF, ajoute "footer" dans content (en dehors de sections):
{"format": "pdf", ..., "content": {"footer": "Entreprise XYZ — SIRET 123 456 789 — contact@xyz.fr | Confidentiel", "sections": [...]}}
  Utilise pour: coordonnees entreprise, mentions legales, numero de document, confidentialite.

---
ORIENTATION PAYSAGE (PDF, DOCX et XLSX):
Pour des tableaux larges ou des rapports necessitant plus d'espace horizontal, ajoute "orientation": "landscape" dans content:
{"format": "pdf", ..., "content": {"orientation": "landscape", "sections": [...]}}
{"format": "xlsx", ..., "content": {"orientation": "landscape", "sheets": [...]}}
  Par defaut: "portrait". Utilise "landscape" uniquement si le document contient des tableaux tres larges (> 6 colonnes) ou si l'utilisateur le demande explicitement.

---
REGLES STRICTES:
- Reponds UNIQUEMENT avec le JSON brut (jamais de ```json```, jamais de commentaire avant/apres)
- Genere du contenu COMPLET et REALISTE (jamais de placeholder "Item 1", "Valeur X", etc.)
- Pour les factures: inclure references, dates, TVA, totaux TTC, IBAN, et bloc signature
- Pour les CV: structure professionnelle complete (experience, formation, competences, langues, contact)
- Pour les rapports: introduction, corps, conclusion
- filename: uniquement lettres, chiffres, tirets, underscores — PAS d'espaces, PAS d'extension
- Si des DONNEES STRUCTUREES sont fournies, utilise-les EN TOTALITE (inclure toutes les entrees, pas un resume)
- Si l'utilisateur mentionne "cette liste", "ces clients", etc., cherche en priorite dans les donnees structurees
- N'invente JAMAIS de donnees non mentionnees par l'utilisateur
PROMPT;

        $userContext         = $this->formatContextMemoryForPrompt($context->from);
        $knowledgeData       = $this->getStoredKnowledge($context);
        $conversationHistory = $this->getRecentConversationHistory($context);
        $message             = $context->body;

        $preamble = '';
        if ($userContext) {
            $preamble .= "{$userContext}\n\n";
        }
        if ($knowledgeData) {
            $preamble .= "DONNEES STRUCTUREES DISPONIBLES (donnees brutes API/stockees — utilise-les en priorite):\n{$knowledgeData}\n\n";
        }
        if ($conversationHistory) {
            $preamble .= "HISTORIQUE RECENT DE LA CONVERSATION:\n{$conversationHistory}\n\n";
        }
        if ($preamble) {
            $message = "{$preamble}Demande: {$message}";
        }

        try {
            $response = $this->claude->chat($message, $model, $systemPrompt);

            if (!$response) {
                $this->sendText($context->from, "⚠️ Le modele IA n'a pas repondu. Verifie qu'un modele est telecharge dans Parametres > On-Prem / Ollama.");
                return AgentResult::reply("LLM returned null — model may not be downloaded");
            }

            $spec = $this->parseJson($response);

            if (!$spec || !isset($spec['format'])) {
                $this->sendText($context->from, "❓ Je n'ai pas compris quel document creer. Precise le type souhaite (Excel, PDF, Word ou CSV) et le contenu voulu.");
                return AgentResult::reply("Format non reconnu dans la reponse LLM");
            }

            $format   = strtolower(trim($spec['format']));
            $rawName  = $spec['filename'] ?? 'document';
            $filename = mb_substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rawName), 0, self::FILENAME_MAX_LENGTH);
            $filename = $filename ?: 'document';
            $title    = $spec['title'] ?? 'Document';
            $content  = $spec['content'] ?? [];

            $filePath = match ($format) {
                'xlsx' => $this->generateXlsx($filename, $title, $content),
                'csv'  => $this->generateCsv($filename, $content),
                'pdf'  => $this->generatePdf($filename, $title, $content),
                'docx' => $this->generateDocx($filename, $title, $content),
                default => null,
            };

            if (!$filePath) {
                $this->sendText($context->from, "❌ Format non supporte: *{$format}*. Formats disponibles: xlsx, csv, pdf, docx.");
                return AgentResult::reply("Format non supporte: {$format}");
            }

            // Validate the file was actually created and is not empty
            if (!file_exists($filePath) || filesize($filePath) === 0) {
                @unlink($filePath);
                $this->sendText($context->from, "❌ Erreur lors de la generation du document. Veuillez reformuler votre demande.");
                return AgentResult::reply("Generated file is empty or missing");
            }

            $ext       = pathinfo($filePath, PATHINFO_EXTENSION);
            $finalName = "{$filename}.{$ext}";
            $isWeb     = str_starts_with($context->from, 'web-');
            $fileUrl   = null;

            if ($isWeb) {
                $docDir = storage_path('app/public/documents');
                if (!is_dir($docDir)) {
                    mkdir($docDir, 0775, true);
                }
                $uniqueName = uniqid() . '_' . $finalName;
                $destPath   = "{$docDir}/{$uniqueName}";
                // copy+unlink instead of rename for cross-filesystem safety
                copy($filePath, $destPath);
                @unlink($filePath);
                $fileUrl = url("storage/documents/{$uniqueName}");
            } else {
                $this->sendFile($context->from, $filePath, $finalName, $title);
                @unlink($filePath);
            }

            $this->log($context, "Document created", [
                'format'   => $format,
                'filename' => $finalName,
                'title'    => $title,
            ]);

            $replyText = "Document *{$title}* ({$ext}) cree avec succes !";
            if (!$isWeb) {
                $this->sendText($context->from, "✅ {$replyText}");
            }

            return AgentResult::reply($replyText, [
                'files' => $fileUrl ? [[
                    'url'    => $fileUrl,
                    'name'   => $finalName,
                    'format' => $format,
                    'title'  => $title,
                ]] : [],
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("DocumentAgent error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'from'  => $context->from,
            ]);
            $this->sendText($context->from, "❌ Une erreur est survenue lors de la creation du document. Veuillez reformuler votre demande ou reessayer.");
            return AgentResult::reply("Erreur interne: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // XLSX
    // ─────────────────────────────────────────────────────────────────────────

    private function generateXlsx(string $filename, string $title, array $content): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setTitle($title);

        $isLandscape = ($content['orientation'] ?? 'portrait') === 'landscape';
        $sheets      = $content['sheets'] ?? [['name' => 'Sheet1', 'headers' => [], 'rows' => []]];

        foreach ($sheets as $i => $sheetData) {
            if ($i > 0) {
                $spreadsheet->createSheet();
            }
            $sheet = $spreadsheet->setActiveSheetIndex($i);
            $sheet->setTitle(mb_substr($sheetData['name'] ?? "Feuille " . ($i + 1), 0, 31));

            if ($isLandscape) {
                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            }

            $headers = $sheetData['headers'] ?? [];
            $rows    = $sheetData['rows'] ?? [];

            // Detect monetary columns by header keywords
            $currencyKeywords = ['montant', 'prix', 'total', 'ht', 'ttc', 'tva', 'salaire',
                                 'budget', 'cout', 'coût', 'tarif', 'revenue', 'revenu',
                                 'depense', 'dépense', 'amount', 'price', 'cost', 'salary'];
            $currencyColumns = [];
            foreach ($headers as $col => $header) {
                $headerLower = strtolower((string) $header);
                foreach ($currencyKeywords as $kw) {
                    if (str_contains($headerLower, $kw)) {
                        $currencyColumns[] = $col;
                        break;
                    }
                }
            }

            // Write headers with bold + blue background
            foreach ($headers as $col => $header) {
                $colLetter = Coordinate::stringFromColumnIndex($col + 1);
                $cell      = $sheet->getCell("{$colLetter}1");
                $cell->setValue($header);
                $style = $cell->getStyle();
                $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $style->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
            }

            // Freeze top row so headers stay visible when scrolling
            if (!empty($headers)) {
                $sheet->freezePane('A2');
            }

            // Write data rows with alternating background + auto-bold for TOTAL rows
            foreach ($rows as $rowIndex => $row) {
                $firstCellValue = strtoupper(trim((string) ($row[0] ?? '')));
                $isTotalRow     = str_starts_with($firstCellValue, 'TOTAL')
                    || str_starts_with($firstCellValue, 'SOUS-TOTAL')
                    || str_starts_with($firstCellValue, 'SUBTOTAL')
                    || str_starts_with($firstCellValue, 'GRAND TOTAL');
                $bgColor = $isTotalRow ? 'D9E1F2' : (($rowIndex % 2 === 1) ? 'EEF2FB' : 'FFFFFF');

                foreach ($row as $col => $value) {
                    $colLetter = Coordinate::stringFromColumnIndex($col + 1);
                    $cellRef   = "{$colLetter}" . ($rowIndex + 2);
                    $cell      = $sheet->getCell($cellRef);

                    // Convert to float only for pure numeric values (avoids mangling dates, codes, phone numbers)
                    $strValue = trim((string) $value);
                    if (preg_match('/^-?\d+([.,]\d+)?$/', $strValue)) {
                        $cell->setValue((float) str_replace(',', '.', $strValue));
                    } else {
                        $cell->setValue($strValue);
                    }

                    $style = $cell->getStyle();

                    // Bold total rows
                    if ($isTotalRow) {
                        $style->getFont()->setBold(true);
                    }

                    // Apply currency number format to monetary columns
                    if (in_array($col, $currencyColumns, true) && is_numeric(str_replace(',', '.', trim((string) $value)))) {
                        $style->getNumberFormat()->setFormatCode('#,##0.00');
                    }

                    // Alternating row background (or total row highlight)
                    $style->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($bgColor);
                }
            }

            // Auto-size all columns (based on headers AND row data)
            $maxCols = max(
                count($headers),
                !empty($rows) ? max(array_map('count', $rows)) : 0,
                1
            );
            foreach (range(1, $maxCols) as $col) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path   = storage_path("app/{$filename}.xlsx");
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV  (new in v1.1.0)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a CSV file with UTF-8 BOM so Excel opens it correctly.
     * Uses semicolon delimiter (standard in France / Europe).
     */
    private function generateCsv(string $filename, array $content): string
    {
        $headers = $content['headers'] ?? [];
        $rows    = $content['rows'] ?? [];

        $path = storage_path("app/{$filename}.csv");
        $fh   = fopen($path, 'w');

        // UTF-8 BOM — required for correct accented-character display in Excel
        fwrite($fh, "\xEF\xBB\xBF");

        if (!empty($headers)) {
            fputcsv($fh, $headers, ';');
        }

        foreach ($rows as $row) {
            fputcsv($fh, array_map('strval', (array) $row), ';');
        }

        fclose($fh);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF
    // ─────────────────────────────────────────────────────────────────────────

    private function generatePdf(string $filename, string $title, array $content): string
    {
        $sections    = $content['sections'] ?? [];
        $orientation = ($content['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
        $footer      = isset($content['footer']) ? (string) $content['footer'] : null;
        $html        = $this->buildPdfHtml($title, $sections, $footer);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        $path = storage_path("app/{$filename}.pdf");
        file_put_contents($path, $dompdf->output());

        return $path;
    }

    private function buildPdfHtml(string $title, array $sections, ?string $footer = null): string
    {
        $body = '';

        foreach ($sections as $section) {
            $type = $section['type'] ?? 'paragraph';
            $text = htmlspecialchars($section['text'] ?? '', ENT_QUOTES, 'UTF-8');

            switch ($type) {
                case 'heading':
                    $level = max(1, min(6, (int) ($section['level'] ?? 1)));
                    $body .= "<h{$level}>{$text}</h{$level}>";
                    break;

                case 'paragraph':
                    $body .= "<p>{$text}</p>";
                    break;

                case 'bold':
                    $body .= "<p><strong>{$text}</strong></p>";
                    break;

                case 'italic':
                    $body .= "<p><em>{$text}</em></p>";
                    break;

                case 'table':
                    $body .= '<table><thead><tr>';
                    foreach ($section['headers'] ?? [] as $h) {
                        $body .= '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
                    }
                    $body .= '</tr></thead><tbody>';
                    foreach ($section['rows'] ?? [] as $rowIdx => $row) {
                        $rowClass = ($rowIdx % 2 === 1) ? ' class="alt"' : '';
                        $body .= "<tr{$rowClass}>";
                        foreach ($row as $cell) {
                            $body .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                        }
                        $body .= '</tr>';
                    }
                    $body .= '</tbody></table>';
                    break;

                case 'list':
                    $body .= '<ul>';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $body .= '</ul>';
                    break;

                case 'ordered_list':
                    $body .= '<ol>';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $body .= '</ol>';
                    break;

                case 'callout':
                    $style = $section['style'] ?? 'info';
                    $bgColors     = ['info' => '#EBF5FB', 'warning' => '#FEF9E7', 'success' => '#EAFAF1', 'danger' => '#FDEDEC'];
                    $borderColors = ['info' => '#3498DB', 'warning' => '#F39C12', 'success' => '#27AE60', 'danger' => '#E74C3C'];
                    $icons        = ['info' => 'i', 'warning' => '!', 'success' => 'v', 'danger' => 'X'];
                    $bg           = $bgColors[$style]     ?? '#EBF5FB';
                    $border       = $borderColors[$style] ?? '#3498DB';
                    $icon         = $icons[$style]        ?? 'i';
                    $body .= "<div style=\"background:{$bg};border-left:4px solid {$border};"
                           . "padding:10px 14px;margin:10px 0;border-radius:2px;\">"
                           . "<strong>[{$icon}]</strong> {$text}</div>";
                    break;

                case 'quote':
                    $body .= "<blockquote style=\"border-left:3px solid #718096;padding:8px 16px;"
                           . "margin:12px 0 12px 8px;color:#4a5568;font-style:italic;"
                           . "background:#F8F9FA;\">{$text}</blockquote>";
                    break;

                case 'highlight':
                    $colorMap = ['yellow' => '#FFF9C4', 'green' => '#C8F7C5', 'cyan' => '#D1F2EB', 'pink' => '#FDDDE6'];
                    $hlColor  = $colorMap[$section['color'] ?? 'yellow'] ?? '#FFF9C4';
                    $body .= "<p style=\"background:{$hlColor};padding:4px 8px;border-radius:3px;"
                           . "display:inline-block;\">{$text}</p>";
                    break;

                case 'separator':
                    $body .= '<hr>';
                    break;

                case 'page_break':
                    $body .= '<div class="page-break"></div>';
                    break;

                case 'signature':
                    $signers = $section['signers'] ?? [];
                    if (!empty($signers)) {
                        $cols  = max(1, count($signers));
                        $width = (int) (100 / $cols);
                        $body .= '<table style="width:100%;border:none;border-collapse:collapse;margin-top:30px;">';
                        $body .= '<tr>';
                        foreach ($signers as $signer) {
                            $signerName  = htmlspecialchars((string) ($signer['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $signerTitle = htmlspecialchars((string) ($signer['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $body .= "<td style=\"width:{$width}%;vertical-align:bottom;padding:0 20px 0 0;border:none;\">";
                            $body .= '<div style="margin-top:48px;border-top:1px solid #333;padding-top:6px;">';
                            if ($signerName) {
                                $body .= "<strong>{$signerName}</strong><br>";
                            }
                            if ($signerTitle) {
                                $body .= "<span style=\"font-size:9pt;color:#555;\">{$signerTitle}</span>";
                            }
                            $body .= '</div></td>';
                        }
                        $body .= '</tr></table>';
                    }
                    break;

                case 'key_value':
                    $pairs = $section['pairs'] ?? [];
                    if (!empty($pairs)) {
                        $body .= '<table style="width:100%;border:none;border-collapse:collapse;margin:8px 0;">';
                        foreach ($pairs as $pair) {
                            $label = htmlspecialchars((string) ($pair['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $value = htmlspecialchars((string) ($pair['value'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $body .= '<tr>'
                                   . "<td style=\"width:30%;font-weight:bold;padding:3px 12px 3px 0;color:#2d3748;border:none;\">{$label}</td>"
                                   . "<td style=\"padding:3px 0;border:none;\">{$value}</td>"
                                   . '</tr>';
                        }
                        $body .= '</table>';
                    }
                    break;

                case 'badge':
                    $badgeColorMap = [
                        'green'  => ['bg' => '#D5F5E3', 'border' => '#27AE60', 'text' => '#1E8449'],
                        'orange' => ['bg' => '#FEF9E7', 'border' => '#F39C12', 'text' => '#B7770D'],
                        'red'    => ['bg' => '#FDEDEC', 'border' => '#E74C3C', 'text' => '#C0392B'],
                        'blue'   => ['bg' => '#EBF5FB', 'border' => '#3498DB', 'text' => '#1A6FA1'],
                        'gray'   => ['bg' => '#F2F3F4', 'border' => '#95A5A6', 'text' => '#566573'],
                    ];
                    $body .= '<p style="margin:8px 0;">';
                    foreach ($section['items'] ?? [] as $badge) {
                        $badgeLabel  = htmlspecialchars((string) ($badge['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $badgeColor  = $badge['color'] ?? 'blue';
                        $colors      = $badgeColorMap[$badgeColor] ?? $badgeColorMap['blue'];
                        $body .= "<span style=\"background:{$colors['bg']};border:1px solid {$colors['border']};"
                               . "color:{$colors['text']};padding:2px 10px;border-radius:12px;"
                               . "font-size:9pt;font-weight:bold;margin-right:6px;\">{$badgeLabel}</span>";
                    }
                    $body .= '</p>';
                    break;

                case 'summary_box':
                    $boxTitle = htmlspecialchars((string) ($section['title'] ?? 'Recapitulatif'), ENT_QUOTES, 'UTF-8');
                    $body .= '<div style="border:2px solid #4472C4;border-radius:4px;padding:14px 18px;margin:16px 0;background:#F0F4FF;">';
                    $body .= "<p style=\"font-weight:bold;color:#1a1a2e;margin:0 0 8px 0;font-size:12pt;\">{$boxTitle}</p>";
                    $body .= '<ul style="margin:0;padding-left:20px;">';
                    foreach ($section['items'] ?? [] as $item) {
                        $body .= '<li style="margin:4px 0;">' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $body .= '</ul></div>';
                    break;
            }
        }

        $footerHtml = '';
        if ($footer) {
            $escapedFooter = htmlspecialchars($footer, ENT_QUOTES, 'UTF-8');
            $footerHtml    = "<div class=\"footer\">{$escapedFooter}</div>";
        }
        $footerCss = $footer
            ? '.footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8pt; color: #718096; border-top: 1px solid #e2e8f0; padding-top: 4px; text-align: center; } body { margin-bottom: 60px; }'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #333; margin: 40px; line-height: 1.6; }
    h1 { font-size: 20pt; color: #1a1a2e; border-bottom: 2px solid #4472C4; padding-bottom: 8px; margin-bottom: 16px; margin-top: 24px; }
    h2 { font-size: 14pt; color: #2d3748; margin-top: 20px; margin-bottom: 8px; }
    h3 { font-size: 12pt; color: #4a5568; margin-top: 14px; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 10pt; }
    th { background: #4472C4; color: white; padding: 8px 12px; text-align: left; }
    td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; }
    tr.alt td { background: #f7fafc; }
    ul, ol { margin: 8px 0; padding-left: 24px; }
    li { margin: 4px 0; }
    hr { border: none; border-top: 1px solid #cbd5e0; margin: 16px 0; }
    p { margin: 8px 0; }
    .page-break { page-break-after: always; }
    {$footerCss}
</style>
</head>
<body>
<h1>{$title}</h1>
{$body}
{$footerHtml}
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOCX
    // ─────────────────────────────────────────────────────────────────────────

    private function generateDocx(string $filename, string $title, array $content): string
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $orientation = ($content['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';

        if ($orientation === 'landscape') {
            $section = $phpWord->addSection([
                'orientation' => 'landscape',
                'pageSizeW'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(29.7),
                'pageSizeH'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(21.0),
                'marginLeft'  => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
                'marginRight' => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
                'marginTop'   => \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
                'marginBottom'=> \PhpOffice\PhpWord\Shared\Converter::cmToTwip(2.0),
            ]);
        } else {
            $section = $phpWord->addSection();
        }

        $section->addTitle($title, 1);

        $sections = $content['sections'] ?? [];

        foreach ($sections as $item) {
            $type = $item['type'] ?? 'paragraph';

            switch ($type) {
                case 'heading':
                    $level = min((int) ($item['level'] ?? 2), 3);
                    $section->addTitle($item['text'] ?? '', $level);
                    break;

                case 'paragraph':
                    $section->addText($item['text'] ?? '', ['size' => 11]);
                    break;

                case 'bold':
                    $section->addText($item['text'] ?? '', ['bold' => true, 'size' => 11]);
                    break;

                case 'italic':
                    $section->addText($item['text'] ?? '', ['italic' => true, 'size' => 11]);
                    break;

                case 'list':
                    foreach ($item['items'] ?? [] as $li) {
                        $section->addListItem((string) $li, 0);
                    }
                    break;

                case 'ordered_list':
                    foreach ($item['items'] ?? [] as $li) {
                        $section->addListItem((string) $li, 0, null, ['listType' => ListItemStyle::TYPE_NUMBER]);
                    }
                    break;

                case 'table':
                    $headers = $item['headers'] ?? [];
                    $rows    = $item['rows'] ?? [];

                    $table = $section->addTable([
                        'borderSize'  => 6,
                        'borderColor' => 'CCCCCC',
                        'cellMargin'  => 80,
                    ]);

                    if (!empty($headers)) {
                        $table->addRow();
                        foreach ($headers as $h) {
                            $cell = $table->addCell(2500, ['bgColor' => '4472C4']);
                            $cell->addText((string) $h, ['bold' => true, 'color' => 'FFFFFF', 'size' => 10]);
                        }
                    }

                    foreach ($rows as $row) {
                        $table->addRow();
                        foreach ($row as $cellVal) {
                            $table->addCell(2500)->addText((string) $cellVal, ['size' => 10]);
                        }
                    }

                    $section->addTextBreak();
                    break;

                case 'callout':
                    $callStyle = $item['style'] ?? 'info';
                    $bgMap     = ['info' => 'D6EAF8', 'warning' => 'FEF9E7', 'success' => 'D5F5E3', 'danger' => 'FADBD8'];
                    $bgColor   = $bgMap[$callStyle] ?? 'D6EAF8';
                    $callTable = $section->addTable([
                        'borderSize'  => 8,
                        'borderColor' => 'AAAAAA',
                        'cellMargin'  => 120,
                        'width'       => 100 * 50,
                        'unit'        => 'pct',
                    ]);
                    $callTable->addRow();
                    $callCell = $callTable->addCell(null, ['bgColor' => $bgColor]);
                    $callCell->addText((string) ($item['text'] ?? ''), ['size' => 11, 'italic' => true]);
                    $section->addTextBreak();
                    break;

                case 'quote':
                    $quoteTable = $section->addTable([
                        'borderSize'   => 0,
                        'borderColor'  => 'FFFFFF',
                        'cellMargin'   => 80,
                        'width'        => 100 * 50,
                        'unit'         => 'pct',
                    ]);
                    $quoteTable->addRow();
                    $quoteCell = $quoteTable->addCell(null, [
                        'bgColor'         => 'F8F9FA',
                        'borderLeftColor' => 'A0AEC0',
                        'borderLeftSize'  => 18,
                    ]);
                    $quoteCell->addText((string) ($item['text'] ?? ''), ['size' => 10, 'italic' => true, 'color' => '4A5568']);
                    $section->addTextBreak();
                    break;

                case 'highlight':
                    $hlColorMap = ['yellow' => 'yellow', 'green' => 'green', 'cyan' => 'cyan', 'pink' => 'magenta'];
                    $hlColor    = $hlColorMap[$item['color'] ?? 'yellow'] ?? 'yellow';
                    $section->addText((string) ($item['text'] ?? ''), ['size' => 11, 'highlight' => $hlColor]);
                    break;

                case 'separator':
                    $section->addTextBreak(1);
                    break;

                case 'page_break':
                    $section->addText('', null, ['pageBreakBefore' => true]);
                    break;

                case 'signature':
                    $signers = $item['signers'] ?? [];
                    if (!empty($signers)) {
                        $section->addTextBreak(2);
                        $sigTable = $section->addTable([
                            'borderSize'  => 0,
                            'borderColor' => 'FFFFFF',
                            'cellMargin'  => 120,
                            'width'       => 100 * 50,
                            'unit'        => 'pct',
                        ]);
                        $sigTable->addRow();
                        foreach ($signers as $signer) {
                            $cell = $sigTable->addCell(null, [
                                'borderTopColor' => '333333',
                                'borderTopSize'  => 12,
                                'borderTopStyle' => 'single',
                            ]);
                            if (!empty($signer['name'])) {
                                $cell->addText((string) $signer['name'], ['bold' => true, 'size' => 11]);
                            }
                            if (!empty($signer['title'])) {
                                $cell->addText((string) $signer['title'], ['size' => 9, 'italic' => true, 'color' => '555555']);
                            }
                        }
                        $section->addTextBreak();
                    }
                    break;

                case 'key_value':
                    $pairs = $item['pairs'] ?? [];
                    if (!empty($pairs)) {
                        $kvTable = $section->addTable([
                            'borderSize'  => 0,
                            'borderColor' => 'FFFFFF',
                            'cellMargin'  => 60,
                            'width'       => 100 * 50,
                            'unit'        => 'pct',
                        ]);
                        foreach ($pairs as $pair) {
                            $kvTable->addRow();
                            $labelCell = $kvTable->addCell(2500);
                            $labelCell->addText((string) ($pair['label'] ?? ''), ['bold' => true, 'size' => 11]);
                            $valueCell = $kvTable->addCell(5000);
                            $valueCell->addText((string) ($pair['value'] ?? ''), ['size' => 11]);
                        }
                        $section->addTextBreak();
                    }
                    break;

                case 'badge':
                    $badgeDocxColors = [
                        'green'  => ['bg' => 'D5F5E3', 'text' => '1E8449'],
                        'orange' => ['bg' => 'FEF9E7', 'text' => 'B7770D'],
                        'red'    => ['bg' => 'FADBD8', 'text' => 'C0392B'],
                        'blue'   => ['bg' => 'EBF5FB', 'text' => '1A6FA1'],
                        'gray'   => ['bg' => 'F2F3F4', 'text' => '566573'],
                    ];
                    $badgeTable = $section->addTable([
                        'borderSize'  => 0,
                        'borderColor' => 'FFFFFF',
                        'cellMargin'  => 60,
                    ]);
                    $badgeTable->addRow();
                    foreach ($item['items'] ?? [] as $badge) {
                        $badgeColor  = $badge['color'] ?? 'blue';
                        $badgeColors = $badgeDocxColors[$badgeColor] ?? $badgeDocxColors['blue'];
                        $badgeCell   = $badgeTable->addCell(1500, ['bgColor' => $badgeColors['bg']]);
                        $badgeCell->addText(
                            (string) ($badge['label'] ?? ''),
                            ['bold' => true, 'size' => 9, 'color' => $badgeColors['text']]
                        );
                    }
                    $section->addTextBreak();
                    break;

                case 'summary_box':
                    $boxTable = $section->addTable([
                        'borderSize'  => 12,
                        'borderColor' => '4472C4',
                        'cellMargin'  => 150,
                        'width'       => 100 * 50,
                        'unit'        => 'pct',
                    ]);
                    $boxTable->addRow();
                    $boxCell = $boxTable->addCell(null, ['bgColor' => 'EEF2FF']);
                    $boxCell->addText(
                        (string) ($item['title'] ?? 'Recapitulatif'),
                        ['bold' => true, 'size' => 12, 'color' => '1a1a2e']
                    );
                    foreach ($item['items'] ?? [] as $bullet) {
                        $boxCell->addListItem((string) $bullet, 0, ['size' => 11]);
                    }
                    $section->addTextBreak();
                    break;
            }
        }

        $path   = storage_path("app/{$filename}.docx");
        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get stored structured data (UserKnowledge) — raw API data from other agents.
     * This is the primary data source for document generation.
     */
    private function getStoredKnowledge(AgentContext $context): string
    {
        $entries = UserKnowledge::allFor($context->from);

        if ($entries->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($entries->take(10) as $entry) {
            $label    = $entry->label ?? $entry->topic_key;
            $source   = $entry->source ? " (source: {$entry->source})" : '';
            $dataJson = json_encode($entry->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $dataJson = mb_substr($dataJson, 0, 4000);
            $lines[]  = "--- [{$entry->topic_key}] {$label}{$source} ---\n{$dataJson}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Get recent conversation history so the DocumentAgent can reference data
     * produced by previous agents in the same session.
     */
    private function getRecentConversationHistory(AgentContext $context): string
    {
        $memoryData = $this->memory->read($context->agent->id, $context->from);
        $entries    = $memoryData['entries'] ?? [];

        if (empty($entries)) {
            return '';
        }

        $recent = array_slice($entries, -5);
        $lines  = [];

        foreach ($recent as $entry) {
            $sender = $entry['sender'] ?? 'Utilisateur';
            $msg    = $entry['sender_message'] ?? '';
            $reply  = $entry['agent_reply'] ?? '';

            if ($msg) {
                $lines[] = "{$sender}: {$msg}";
            }
            if ($reply) {
                $lines[] = "ZeniClaw: {$reply}";
            }
        }

        return implode("\n", $lines);
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        // Strip markdown code blocks if present
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $m)) {
            $response = $m[1];
        }

        $response = trim($response);
        $decoded  = json_decode($response, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to extract a JSON object from the response
        if (preg_match('/\{.*\}/s', $response, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    // ── ToolProviderInterface ──────────────────────────────────────

    public function tools(): array
    {
        return array_merge(parent::tools(), [
            [
                'name' => 'create_document',
                'description' => 'Create and send a document file (XLSX, CSV, PDF, DOCX) to the user. Use this when the user asks to create a file, spreadsheet, report, or export data. You MUST provide the data — collect it first via web_search or other tools if needed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'format' => ['type' => 'string', 'enum' => ['xlsx', 'csv', 'pdf', 'docx'], 'description' => 'File format'],
                        'title' => ['type' => 'string', 'description' => 'Document title (used as filename and header)'],
                        'headers' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Column headers for the table (e.g. ["Ville", "Temperature", "Conditions"])',
                        ],
                        'rows' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'description' => 'Data rows as arrays of strings (e.g. [["Paris", "18°C", "Ensoleillé"], ["Lyon", "15°C", "Nuageux"]])',
                        ],
                    ],
                    'required' => ['format', 'title', 'headers', 'rows'],
                ],
            ],
        ]);
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        if ($name !== 'create_document') {
            return parent::executeTool($name, $input, $context);
        }

        $format = $input['format'] ?? 'xlsx';
        $title = $input['title'] ?? 'Document';
        $headers = $input['headers'] ?? [];
        $rows = $input['rows'] ?? [];

        if (empty($headers) || empty($rows)) {
            return json_encode(['error' => 'headers and rows are required']);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title));
        $filename = $slug . '.' . $format;
        $storagePath = storage_path('app/public/documents/' . $filename);

        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            if (in_array($format, ['xlsx', 'csv'])) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle(mb_substr($title, 0, 31));

                foreach ($headers as $col => $header) {
                    $letter = chr(65 + $col);
                    $sheet->setCellValue("{$letter}1", $header);
                    $sheet->getStyle("{$letter}1")->getFont()->setBold(true);
                }

                foreach ($rows as $rowIdx => $row) {
                    foreach ($row as $col => $value) {
                        $letter = chr(65 + $col);
                        $sheet->setCellValue("{$letter}" . ($rowIdx + 2), $value);
                    }
                }

                foreach (range(0, count($headers) - 1) as $col) {
                    $sheet->getColumnDimension(chr(65 + $col))->setAutoSize(true);
                }

                if ($format === 'xlsx') {
                    $writer = new Xlsx($spreadsheet);
                } else {
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
                }
                $writer->save($storagePath);
            } elseif ($format === 'pdf') {
                // Use dompdf for PDF
                $html = '<h1>' . e($title) . '</h1><table border="1" cellpadding="5"><tr>';
                foreach ($headers as $h) {
                    $html .= '<th>' . e($h) . '</th>';
                }
                $html .= '</tr>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($row as $val) {
                        $html .= '<td>' . e($val) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';

                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', true);
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->render();
                file_put_contents($storagePath, $dompdf->output());
            } elseif ($format === 'docx') {
                $phpWord = new \PhpOffice\PhpWord\PhpWord();
                $section = $phpWord->addSection();
                $section->addTitle($title, 1);
                $table = $section->addTable();
                $table->addRow();
                foreach ($headers as $h) {
                    $table->addCell(2000)->addText($h, ['bold' => true]);
                }
                foreach ($rows as $row) {
                    $table->addRow();
                    foreach ($row as $val) {
                        $table->addCell(2000)->addText($val);
                    }
                }
                $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
                $writer->save($storagePath);
            } else {
                return json_encode(['error' => "Format '{$format}' not supported."]);
            }

            $url = url("storage/documents/{$filename}");
            $this->sendFile($context->from, $storagePath, $filename, $title);

            return json_encode([
                'success' => true,
                'filename' => $filename,
                'format' => $format,
                'rows_count' => count($rows),
                'columns_count' => count($headers),
                'url' => $url,
                'message' => "Document '{$title}' created and sent ({$format}, " . count($rows) . " rows).",
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[create_document] Error: ' . $e->getMessage());
            return json_encode(['error' => 'Document creation failed: ' . $e->getMessage()]);
        }
    }
}
