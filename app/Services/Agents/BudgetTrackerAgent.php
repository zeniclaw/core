<?php

namespace App\Services\Agents;

use App\Models\BudgetCategory;
use App\Models\BudgetExpense;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetTrackerAgent extends BaseAgent
{
    public function name(): string
    {
        return 'budget_tracker';
    }

    public function description(): string
    {
        return 'Suivi intelligent des depenses et budgets mensuels avec categories automatiques, alertes de depassement, previsions et rapports';
    }

    public function keywords(): array
    {
        return [
            'depense', 'budget', 'cout', 'prix', 'je paie', 'je paye',
            'j\'ai depense', 'j\'ai paye', 'achat', 'achete',
            'resume budget', 'categories', 'reset budget',
            'budget mois', 'limite budget', 'set budget',
            'mes depenses', 'combien', 'reste', 'solde',
            'annuler depense', 'supprimer depense', 'effacer depense',
            'prevision', 'projection budget', 'fin de mois',
            'voir depenses', 'depenses par',
            'top depenses', 'plus grosses', 'grosses depenses',
            'comparer mois', 'vs mois', 'mois dernier', 'evolution budget',
            'supprimer limite', 'enlever budget', 'retirer budget', 'retirer limite',
            'bilan semaine', 'stats semaine', 'cette semaine', 'semaine en cours', 'depenses semaine',
            'budget global', 'objectif mois', 'objectif mensuel', 'plafond mensuel',
            'chercher depense', 'trouver depense', 'recherche depense', 'rechercher depense',
            'budget journalier', 'combien par jour', 'reste par jour', 'budget par jour', 'budget quotidien',
            'stats mois', 'statistiques mois', 'analyse budget', 'analyse depenses',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle follow-up messages for reset confirmation.
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        if (($pendingContext['type'] ?? '') !== 'reset_confirmation') {
            return null;
        }

        $body = mb_strtolower(trim($context->body ?? ''));
        $this->clearPendingContext($context);

        if (preg_match('/\b(oui|yes|ok|confirmer?|confirme)\b/iu', $body)) {
            return $this->executeReset($context);
        }

        $reply = "↩️ *Reset annule.* Tes depenses sont conservees.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reset_cancelled']);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Bilan hebdomadaire
        if (preg_match('/\b(bilan\s+semaine|stats?\s+semaine|d[eé]penses?\s+(?:de\s+(?:la\s+)?)?semaine|cette\s+semaine|semaine\s+en\s+cours)\b/iu', $lower)) {
            return $this->handleWeeklySummary($context);
        }

        // Budget journalier conseille
        if (preg_match('/\b(budget\s+journalier|combien\s+par\s+jour|reste\s+par\s+jour|budget\s+par\s+jour|budget\s+quotidien)\b/iu', $lower)) {
            return $this->handleDailyBudget($context);
        }

        // Statistiques detaillees du mois
        if (preg_match('/\b(stats?\s+(?:du\s+)?mois|statistiques?\s+(?:du\s+)?mois|analyse\s+budget|analyse\s+d[eé]penses?)\b/iu', $lower)) {
            return $this->handleMonthStats($context);
        }

        // Resume / bilan
        if (preg_match('/\b(r[eé]sum[eé]\s+budget|bilan\s+budget|rapport\s+budget|budget\s+summary)\b/iu', $lower)) {
            return $this->handleSummary($context);
        }

        // Comparaison mois precedent
        if (preg_match('/\b(comparer?\s+mois|vs\s+mois\s+dernier|mois\s+dernier|[eé]volution\s+budget|comparaison\s+budget)\b/iu', $lower)) {
            return $this->handleMonthComparison($context);
        }

        // Top depenses
        if (preg_match('/\b(top\s+d[eé]penses?|plus\s+grosses?\s+d[eé]penses?|grosses?\s+d[eé]penses?)\b/iu', $lower)) {
            return $this->handleTopExpenses($context);
        }

        // Categories list
        if (preg_match('/\b(cat[eé]gories|mes\s+cat[eé]gories|list\s+categories)\b/iu', $lower)) {
            return $this->handleCategories($context);
        }

        // Reset budget (with confirmation)
        if (preg_match('/\b(reset\s+budget|reinitialiser|r[eé]initialiser\s+budget)\b/iu', $lower)) {
            return $this->handleReset($context);
        }

        // Supprimer limite budget
        if (preg_match('/\b(?:supprimer|enlever|retirer|effacer)\s+(?:la\s+)?(?:limite|budget)\s+(.+)/iu', $lower, $m)) {
            return $this->handleRemoveBudgetLimit($context, trim($m[1]));
        }
        if (preg_match('/\b(?:supprimer|enlever|retirer|effacer)\s+(.+?)\s+(?:limite|budget)\b/iu', $lower, $m)) {
            return $this->handleRemoveBudgetLimit($context, trim($m[1]));
        }

        // Annuler / supprimer dernière dépense
        if (preg_match('/\b(annuler|supprimer|effacer)\s+(derni[eè]re\s+)?d[eé]pense\b/iu', $lower)) {
            return $this->handleDeleteLastExpense($context);
        }

        // Prévision fin de mois
        if (preg_match('/\b(pr[eé]vision|projection|fin\s+de\s+mois|rythme\s+depense)\b/iu', $lower)) {
            return $this->handleForecast($context);
        }

        // Voir dépenses d'une catégorie: "depenses restaurant", "voir courses", "depenses par transport"
        if (preg_match('/\b(?:voir\s+d[eé]penses?|d[eé]penses?\s+(?:par\s+)?|historique\s+)([\p{L}\s\-]+)$/iu', $lower, $m)) {
            $cat = trim($m[1]);
            if (!empty($cat) && !preg_match('/^(ce\s+mois|recent|derni[eè]re|tout|toutes?)$/', $cat)) {
                return $this->handleCategoryDrillDown($context, $cat);
            }
        }

        // Recent expenses (generic)
        if (preg_match('/\b(mes\s+d[eé]penses|derni[eè]res?\s+d[eé]penses?|historique\s+d[eé]penses?|recent\s+expenses)\b/iu', $lower)) {
            return $this->handleRecentExpenses($context);
        }

        // Recherche par mot-cle dans les depenses
        if (preg_match('/\b(?:chercher?|trouver?|rechercher?)\s+(?:d[eé]penses?\s+)?(.+)/iu', $lower, $m)) {
            $term = trim($m[1]);
            if (!empty($term) && mb_strlen($term) >= 2) {
                return $this->handleSearchExpenses($context, $term);
            }
        }

        // Budget global mensuel
        if (preg_match('/\b(?:budget\s+global|objectif\s+(?:mois|mensuel)|plafond\s+mensuel)\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)?/iu', $lower, $m)) {
            return $this->handleSetGlobalBudget($context, $m[1]);
        }

        // Set budget limit: "budget 500 courses" or "budget mois 500 courses" or "set budget courses 500"
        if (preg_match('/\b(?:budget|set\s+budget|limite)\s+(?:mois\s+)?(\d+(?:[.,]\d{1,2})?)\s*([^\d\s].+)/iu', $body, $m)) {
            return $this->handleSetBudget($context, $m[1], trim($m[2]));
        }
        if (preg_match('/\b(?:budget|set\s+budget|limite)\s+(.+?)\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)?$/iu', $body, $m)) {
            return $this->handleSetBudget($context, $m[2], trim($m[1]));
        }

        // Add expense
        $expenseMatch = $this->parseExpense($body);
        if ($expenseMatch) {
            return $this->handleAddExpense($context, $expenseMatch);
        }

        // Fallback: help
        return $this->handleHelp($context);
    }

    // ─── EXPENSE PARSING ──────────────────────────────────────────────────────

    private function parseExpense(string $body): ?array
    {
        $patterns = [
            // "depense 25 restaurant diner" / "paye 30 courses"
            '/(?:d[eé]pense|d[eé]pens[eé]|spent|expense|pay[eé]|paye|achat|achet[eé])\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)?\s+(.+)/iu',
            // "j'ai depense/paye 25 en courses"
            "/j['\x{2019}]ai\\s+(?:d[eé]pens[eé]|pay[eé]|achet[eé])\\s+(\\d+(?:[.,]\\d{1,2})?)\\s*(?:€|eur(?:os?)?)?\\s+(?:en\\s+|pour\\s+|au?\\s+)?(.+)/iu",
            // "25€ restaurant" or "25.50€ resto"
            '/^(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)\s+(.+)/iu',
            // "restaurant 25€"
            '/^(.+?)\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)\s*$/iu',
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $body, $m)) {
                if ($i === 3) {
                    $amount = str_replace(',', '.', $m[2]);
                    $description = trim($m[1]);
                } else {
                    $amount = str_replace(',', '.', $m[1]);
                    $description = trim($m[2]);
                }

                $amount = (float) $amount;

                if ($amount <= 0) {
                    continue;
                }

                $suspicious = $amount > 9999;

                // Detection de date relative dans la description
                $expenseDate = $this->parseDateFromDescription($description);
                $cleanDescription = $expenseDate !== null
                    ? $this->stripDateHintFromDescription($description)
                    : $description;

                return [
                    'amount' => $amount,
                    'description' => $cleanDescription,
                    'currency' => 'EUR',
                    'suspicious' => $suspicious,
                    'expense_date' => $expenseDate,
                ];
            }
        }

        return null;
    }

    /**
     * Detecte une date relative dans la description ("hier", "avant-hier", jour de la semaine).
     * Retourne une date Carbon ou null si aucune date detectee.
     */
    private function parseDateFromDescription(string $description): ?Carbon
    {
        $lower = mb_strtolower(trim($description));

        if (preg_match('/\bavant[- ]?hier\b/iu', $lower)) {
            return now()->subDays(2)->startOfDay();
        }

        if (preg_match('/\bhier\b/iu', $lower)) {
            return now()->subDay()->startOfDay();
        }

        $dayMap = [
            'lundi' => Carbon::MONDAY,
            'mardi' => Carbon::TUESDAY,
            'mercredi' => Carbon::WEDNESDAY,
            'jeudi' => Carbon::THURSDAY,
            'vendredi' => Carbon::FRIDAY,
            'samedi' => Carbon::SATURDAY,
            'dimanche' => Carbon::SUNDAY,
        ];

        foreach ($dayMap as $dayName => $dayNumber) {
            if (preg_match('/\b' . $dayName . '\b/iu', $lower)) {
                $date = now()->previous($dayNumber);
                // Si "lundi" et on est lundi, on veut le lundi precedent (pas aujourd'hui)
                if ($date->isToday()) {
                    $date = $date->subWeek();
                }
                return $date->startOfDay();
            }
        }

        return null;
    }

    /**
     * Retire le hint de date de la description pour ne garder que la vraie description.
     */
    private function stripDateHintFromDescription(string $description): string
    {
        $dateHints = [
            '/\bavant[- ]?hier\b\s*/iu',
            '/\bhier\b\s*/iu',
            '/\blundi\b\s*/iu',
            '/\bmardi\b\s*/iu',
            '/\bmercredi\b\s*/iu',
            '/\bjeudi\b\s*/iu',
            '/\bvendredi\b\s*/iu',
            '/\bsamedi\b\s*/iu',
            '/\bdimanche\b\s*/iu',
        ];

        $cleaned = $description;
        foreach ($dateHints as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        return trim($cleaned) ?: $description;
    }

    private function detectCategory(string $description): string
    {
        $lower = mb_strtolower($description);

        $categoryMap = [
            'restaurant' => ['resto', 'restaurant', 'diner', 'dejeuner', 'brunch', 'pizz', 'sushi', 'burger', 'kebab', 'mcdo', 'mcdonald', 'kfc', 'fast food', 'cantine', 'brasserie', 'bistro', 'snack', 'tacos', 'ramen', 'noodle', 'wok', 'bento', 'traiteur', 'buffet', 'gastronomie'],
            'courses' => ['courses', 'supermarche', 'carrefour', 'leclerc', 'auchan', 'lidl', 'aldi', 'monoprix', 'franprix', 'epicerie', 'marche', 'primeur', 'casino', 'intermarche', 'biocoop', 'market', 'spar', 'netto', 'simply', 'g20', 'ed discount'],
            'transport' => ['essence', 'carburant', 'metro', 'bus', 'uber', 'taxi', 'train', 'sncf', 'parking', 'peage', 'transport', 'vtc', 'bolt', 'trottinette', 'velo', 'ratp', 'tgv', 'rer', 'autoroute', 'station service', 'free now', 'heetch', 'blablacar', 'velib'],
            'loisirs' => ['cinema', 'film', 'concert', 'spectacle', 'musee', 'theatre', 'bowling', 'escape', 'parc', 'sortie', 'bar', 'boite', 'club', 'loisir', 'jeu', 'jeux', 'sport', 'gym', 'piscine', 'karting', 'laser game', 'aquaparc', 'zoo', 'cirque', 'festival', 'match', 'stade', 'accrobranche'],
            'sante' => ['pharmacie', 'medecin', 'docteur', 'dentiste', 'hopital', 'sante', 'ordonnance', 'medicament', 'lunettes', 'optique', 'kine', 'psychologue', 'infirmier', 'cabinet medical', 'dermatologue', 'cardiologue', 'ophtalmo', 'radiologue', 'analyse', 'labo', 'secu', 'mutuelle', 'complement'],
            'shopping' => ['vetement', 'chaussure', 'zara', 'hm', 'nike', 'adidas', 'amazon', 'achat', 'shopping', 'cadeau', 'bijou', 'accessoire', 'mode', 'primark', 'fnac', 'decathlon', 'galerie', 'shein', 'asos', 'vinted', 'leboncoin', 'montre', 'sac', 'ceinture', 'lunettes de soleil'],
            'logement' => ['loyer', 'electricite', 'edf', 'gaz', 'eau', 'assurance', 'charges', 'copropriete', 'bricolage', 'meuble', 'ikea', 'leroy', 'castorama', 'brico depot', 'syndic', 'conciergerie', 'travaux', 'renovation', 'peinture', 'plombier', 'electricien', 'serrurier'],
            'abonnement' => ['netflix', 'spotify', 'disney', 'prime', 'abonnement', 'subscription', 'forfait', 'telephone', 'internet', 'box', 'free', 'sfr', 'orange', 'bouygues', 'youtube', 'apple', 'canal', 'deezer', 'hulu', 'gaming', 'playstation', 'xbox', 'nintendo', 'adobe', 'chatgpt', 'midjourney'],
            'education' => ['livre', 'cours', 'formation', 'ecole', 'universite', 'fourniture', 'cahier', 'stylo', 'diplome', 'certification', 'udemy', 'coursera', 'openclassroom', 'bts', 'master', 'licence', 'prepa', 'soutien scolaire', 'tutorat', 'stage'],
            'cafe' => ['cafe', 'coffee', 'starbucks', 'the', 'boisson', 'expresso', 'cappuccino', 'latte', 'nespresso', 'petit dejeuner', 'boulangerie', 'viennoiserie', 'croissant', 'paul', 'brioche', 'maison du cafe'],
            'voyage' => ['hotel', 'airbnb', 'avion', 'vol', 'billet', 'voyage', 'vacances', 'sejour', 'reservation', 'booking', 'hostel', 'camping', 'location voiture', 'visa', 'passeport', 'assurance voyage', 'excursion', 'croisiere', 'gite'],
            'banque' => ['frais bancaires', 'commission', 'agios', 'cotisation carte', 'assurance vie', 'interet', 'virement', 'retrait', 'frais'],
            'animaux' => ['veterinaire', 'veto', 'croquette', 'animaux', 'animal', 'chat', 'chien', 'clinique veterinaire', 'nourriture animal', 'litiere', 'gamelle', 'laisse', 'jouet animal', 'pension animaux'],
            'beaute' => ['coiffeur', 'coiffure', 'salon', 'manucure', 'pedicure', 'esthetique', 'wax', 'epilation', 'massage', 'spa', 'pressing', 'nettoyage', 'teinture', 'coloration', 'soin visage', 'parfum', 'maquillage', 'cosmetique'],
            'enfants' => ['creche', 'garderie', 'baby', 'nourrice', 'jouet', 'vetement enfant', 'doudou', 'ecole maternelle', 'activite enfant', 'puericulture', 'couche', 'lait maternite', 'pediatre'],
        ];

        foreach ($categoryMap as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $category;
                }
            }
        }

        return 'autre';
    }

    private function sanitizeCategoryName(string $raw): string
    {
        $clean = mb_strtolower(trim($raw));
        $clean = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return mb_substr(trim($clean), 0, 50);
    }

    // ─── HANDLERS ─────────────────────────────────────────────────────────────

    private function handleAddExpense(AgentContext $context, array $data): AgentResult
    {
        $category = $this->detectCategory($data['description']);

        $expenseDate = $data['expense_date'] ?? null;
        $expenseDateStr = $expenseDate instanceof Carbon
            ? $expenseDate->toDateString()
            : now()->toDateString();
        $monthKey = substr($expenseDateStr, 0, 7);

        try {
            $expense = BudgetExpense::create([
                'user_phone' => $context->from,
                'agent_id' => $context->agent->id,
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'category' => $category,
                'description' => $data['description'],
                'expense_date' => $expenseDateStr,
            ]);

            $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, $category, $monthKey);
            $budgetCat->calculateMonthlySpent();
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] handleAddExpense failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de l'enregistrement de la depense.*\nVeuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'expense_error']);
        }

        $amountFmt = number_format($data['amount'], 2, ',', ' ');
        $dateFmt = Carbon::parse($expenseDateStr)->format('d/m/Y');
        $isBackdated = $expenseDateStr !== now()->toDateString();

        $reply = "✅ *Depense enregistree*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if (!empty($data['suspicious'])) {
            $reply .= "⚠️ _Montant inhabituel — verifie que c'est correct._\n\n";
        }

        $reply .= "💸 Montant : *{$amountFmt} €*\n";
        $reply .= "📁 Categorie : *{$category}*\n";
        $reply .= "📝 Description : {$data['description']}\n";
        $reply .= "📅 Date : {$dateFmt}";
        $reply .= $isBackdated ? " _(saisie retroactive)_\n" : "\n";

        // Budget alert
        if ($budgetCat->monthly_limit > 0) {
            $percent = $budgetCat->usagePercent();
            $remainFmt = number_format($budgetCat->remainingBudget(), 2, ',', ' ');
            $limitFmt = number_format($budgetCat->monthly_limit, 2, ',', ' ');

            if ($budgetCat->isOverBudget()) {
                $overAmount = number_format($budgetCat->spent_this_month - $budgetCat->monthly_limit, 2, ',', ' ');
                $reply .= "\n🚨 *ALERTE : Budget {$category} depasse !*\n";
                $reply .= "Depense ce mois : " . number_format($budgetCat->spent_this_month, 2, ',', ' ') . " / {$limitFmt} €\n";
                $reply .= "Depassement : +{$overAmount} €\n";
            } elseif ($percent >= 80) {
                $reply .= "\n⚠️ *Attention : Budget {$category} a {$percent}%*\n";
                $reply .= "Reste : {$remainFmt} € sur {$limitFmt} €\n";
            } else {
                $bar = $this->progressBar($percent);
                $reply .= "\n📊 Budget {$category} : {$bar} {$percent}% ({$remainFmt} € restants)\n";
            }
        }

        $monthTotal = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
        $monthTotalFmt = number_format($monthTotal, 2, ',', ' ');
        $daysLeft = now()->daysInMonth - now()->day;

        $reply .= "\n💰 Total ce mois : *{$monthTotalFmt} €*";
        if ($daysLeft > 0) {
            $reply .= " ({$daysLeft}j restants)";
        }
        $reply .= "\n\n↩️ _annuler depense — pour annuler cette saisie_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Expense added', ['amount' => $data['amount'], 'category' => $category, 'id' => $expense->id]);

        return AgentResult::reply($reply, ['action' => 'expense_added', 'amount' => $data['amount'], 'category' => $category]);
    }

    private function handleDeleteLastExpense(AgentContext $context): AgentResult
    {
        $expense = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $expense) {
            $reply = "ℹ️ *Aucune depense a annuler.*\n\n_Tu n'as aucune depense enregistree._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'delete_last_none']);
        }

        $amountFmt = number_format($expense->amount, 2, ',', ' ');
        $date = $expense->expense_date->format('d/m/Y');
        $category = $expense->category;
        $description = $expense->description;
        $expenseMonthKey = $expense->expense_date->format('Y-m');

        try {
            $expense->delete();

            $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, $category, $expenseMonthKey);
            $budgetCat->calculateMonthlySpent();
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] handleDeleteLastExpense failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de la suppression.* Veuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'delete_error']);
        }

        $reply = "↩️ *Depense annulee*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "💸 Montant : *{$amountFmt} €*\n";
        $reply .= "📁 Categorie : {$category}\n";
        $reply .= "📝 Description : {$description}\n";
        $reply .= "📅 Date : {$date}\n\n";

        $monthTotal = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, now()->format('Y-m'));
        $reply .= "💰 Nouveau total ce mois : *" . number_format($monthTotal, 2, ',', ' ') . " €*";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Last expense deleted', ['amount' => $expense->amount, 'category' => $category]);

        return AgentResult::reply($reply, ['action' => 'expense_deleted']);
    }

    private function handleSetBudget(AgentContext $context, string $amount, string $categoryName): AgentResult
    {
        $amount = (float) str_replace(',', '.', $amount);

        if ($amount <= 0) {
            $reply = "❌ *Montant invalide.* Exemple : _budget 500 courses_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'budget_invalid']);
        }

        if ($amount > 999999) {
            $reply = "❌ *Montant trop eleve.* Maximum : 999 999 €";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'budget_invalid']);
        }

        $detectedCategory = $this->detectCategory($categoryName);
        $category = $detectedCategory === 'autre'
            ? $this->sanitizeCategoryName($categoryName)
            : $detectedCategory;

        if (empty($category)) {
            $reply = "❌ *Nom de categorie invalide.* Exemple : _budget 500 courses_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'budget_invalid_category']);
        }

        $monthKey = now()->format('Y-m');

        try {
            $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, $category, $monthKey);
            $budgetCat->update(['monthly_limit' => $amount]);
            $budgetCat->calculateMonthlySpent();
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] handleSetBudget failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de la definition du budget.* Veuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'budget_error']);
        }

        $amountFmt = number_format($amount, 2, ',', ' ');
        $spentFmt = number_format($budgetCat->spent_this_month, 2, ',', ' ');
        $bar = $this->progressBar($budgetCat->usagePercent());

        $reply = "✅ *Budget defini*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📁 Categorie : *{$category}*\n";
        $reply .= "💰 Limite mensuelle : *{$amountFmt} €*\n";
        $reply .= "📊 Deja depense : {$spentFmt} € {$bar} {$budgetCat->usagePercent()}%\n";
        $reply .= "💡 Reste : " . number_format($budgetCat->remainingBudget(), 2, ',', ' ') . " €\n";
        $reply .= "\n_Ajoutez des depenses avec : depense [montant] [description]_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget set', ['category' => $category, 'limit' => $amount]);

        return AgentResult::reply($reply, ['action' => 'budget_set', 'category' => $category, 'limit' => $amount]);
    }

    private function handleRemoveBudgetLimit(AgentContext $context, string $categoryName): AgentResult
    {
        $detectedCategory = $this->detectCategory($categoryName);
        $category = $detectedCategory === 'autre'
            ? $this->sanitizeCategoryName($categoryName)
            : $detectedCategory;

        if (empty($category)) {
            $reply = "❌ *Categorie non reconnue.* Exemple : _supprimer limite courses_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'remove_limit_invalid']);
        }

        $monthKey = now()->format('Y-m');
        $budgetCat = BudgetCategory::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $category)
            ->where('month_key', $monthKey)
            ->first();

        if (! $budgetCat || $budgetCat->monthly_limit <= 0) {
            $reply = "ℹ️ *Aucune limite de budget definie pour {$category}.*\n\n_Utilisez : budget [montant] {$category}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'remove_limit_none']);
        }

        try {
            $oldLimit = $budgetCat->monthly_limit;
            $budgetCat->update(['monthly_limit' => 0]);
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] handleRemoveBudgetLimit failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de la suppression de la limite.* Veuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'remove_limit_error']);
        }

        $oldLimitFmt = number_format($oldLimit, 2, ',', ' ');
        $reply = "🗑️ *Limite de budget supprimee*\n\n";
        $reply .= "📁 Categorie : *{$category}*\n";
        $reply .= "💰 Ancienne limite : {$oldLimitFmt} €\n\n";
        $reply .= "_Les depenses {$category} continuent d'etre comptabilisees._\n";
        $reply .= "\n💡 _budget [montant] {$category}_ — Redefinir une limite";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget limit removed', ['category' => $category, 'old_limit' => $oldLimit]);

        return AgentResult::reply($reply, ['action' => 'remove_limit', 'category' => $category]);
    }

    private function handleSummary(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $totalSpent = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
        $byCategory = BudgetExpense::getMonthlyByCategory($context->from, $context->agent->id, $monthKey);
        $categories = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey);

        $expenseCount = (int) $byCategory->sum('count');
        $dayOfMonth = (int) now()->format('j');
        $dailyAverage = ($dayOfMonth > 0 && $totalSpent > 0)
            ? number_format($totalSpent / $dayOfMonth, 2, ',', ' ')
            : null;

        $globalBudget = BudgetCategory::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', '__global__')
            ->where('month_key', $monthKey)
            ->first();

        $reply = "📊 *Resume Budget — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $totalFmt = number_format($totalSpent, 2, ',', ' ');
        $reply .= "💰 Total depense : *{$totalFmt} €*";
        if ($expenseCount > 0) {
            $reply .= " ({$expenseCount} op.)";
        }
        $reply .= "\n";

        if ($dailyAverage) {
            $reply .= "📅 Moyenne / jour : *{$dailyAverage} €*\n";
        }

        if ($globalBudget && $globalBudget->monthly_limit > 0) {
            $globalBudget->calculateMonthlySpent();
            $globalPct = $globalBudget->usagePercent();
            $globalBar = $this->progressBar($globalPct);
            $globalLimitFmt = number_format($globalBudget->monthly_limit, 2, ',', ' ');
            $globalRemainFmt = number_format($globalBudget->remainingBudget(), 2, ',', ' ');
            $globalStatus = $globalBudget->isOverBudget() ? '🚨' : ($globalPct >= 80 ? '⚠️' : '');
            $reply .= "🎯 Budget global : {$globalBar} {$globalPct}% de {$globalLimitFmt} € (reste {$globalRemainFmt} €) {$globalStatus}\n";
        }

        $reply .= "\n";

        if ($byCategory->isEmpty()) {
            $reply .= "_Aucune depense ce mois._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 restaurant_";
        } else {
            $reply .= "*Depenses par categorie :*\n";
            foreach ($byCategory as $cat) {
                $catTotal = number_format($cat->total, 2, ',', ' ');
                $budgetCat = $categories->firstWhere('name', $cat->category);
                $limitInfo = '';

                if ($budgetCat && $budgetCat->monthly_limit > 0) {
                    $percent = round(($cat->total / $budgetCat->monthly_limit) * 100, 1);
                    $bar = $this->progressBar($percent);
                    $limitFmt = number_format($budgetCat->monthly_limit, 2, ',', ' ');
                    $status = $percent >= 100 ? '🚨' : ($percent >= 80 ? '⚠️' : '');
                    $limitInfo = " {$bar} {$percent}% de {$limitFmt} {$status}";
                }

                $reply .= "  📁 {$cat->category} : *{$catTotal} €* ({$cat->count} ops){$limitInfo}\n";
            }

            $alerts = $categories->filter(fn ($c) => $c->monthly_limit > 0 && $c->isOverBudget());
            if ($alerts->isNotEmpty()) {
                $reply .= "\n🚨 *Alertes de depassement :*\n";
                foreach ($alerts as $alert) {
                    $over = number_format($alert->spent_this_month - $alert->monthly_limit, 2, ',', ' ');
                    $reply .= "  ⚠️ {$alert->name} : +{$over} € au-dessus du budget\n";
                }
            }
        }

        $reply .= "\n📋 _categories_ — Voir les budgets\n";
        $reply .= "📝 _mes depenses_ — Historique recent\n";
        $reply .= "📈 _prevision_ — Projection fin de mois\n";
        $reply .= "🔄 _comparer mois_ — Evolution vs mois dernier";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget summary viewed');

        return AgentResult::reply($reply, ['action' => 'summary']);
    }

    private function handleCategories(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $categories = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey)
            ->filter(fn ($c) => $c->name !== '__global__')
            ->values();

        $reply = "📁 *Mes Categories Budget*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($categories->isEmpty()) {
            $reply .= "_Aucune categorie definie._\n";
            $reply .= "\n💡 Definissez un budget : _budget 500 courses_";
        } else {
            foreach ($categories as $cat) {
                $cat->calculateMonthlySpent();
                $spentFmt = number_format($cat->spent_this_month, 2, ',', ' ');

                if ($cat->monthly_limit > 0) {
                    $limitFmt = number_format($cat->monthly_limit, 2, ',', ' ');
                    $percent = $cat->usagePercent();
                    $bar = $this->progressBar($percent);
                    $status = $cat->isOverBudget() ? '🚨' : ($percent >= 80 ? '⚠️' : '✅');
                    $reply .= "{$status} *{$cat->name}* : {$spentFmt} / {$limitFmt} € {$bar} {$percent}%\n";
                } else {
                    $reply .= "📁 *{$cat->name}* : {$spentFmt} € (pas de limite)\n";
                }
            }
        }

        $reply .= "\n💡 _budget [montant] [categorie]_ — Definir une limite\n";
        $reply .= "🗑️ _supprimer limite [categorie]_ — Retirer une limite\n";
        $reply .= "📊 _resume budget_ — Rapport complet\n";
        $reply .= "📈 _prevision_ — Projection fin de mois";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'categories']);
    }

    private function handleRecentExpenses(AgentContext $context): AgentResult
    {
        $expenses = BudgetExpense::getRecent($context->from, $context->agent->id, 10);

        $reply = "📝 *Dernieres Depenses*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($expenses->isEmpty()) {
            $reply .= "_Aucune depense enregistree._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 restaurant_";
        } else {
            $total = $expenses->sum('amount');
            $reply .= "_({$expenses->count()} dernieres depenses — total : " . number_format($total, 2, ',', ' ') . " €)_\n\n";

            foreach ($expenses as $exp) {
                $amountFmt = number_format($exp->amount, 2, ',', ' ');
                $date = $exp->expense_date->format('d/m');
                $reply .= "  💸 {$date} — *{$amountFmt} €* [{$exp->category}] {$exp->description}\n";
            }
            $reply .= "\n↩️ _annuler depense_ — Annuler la derniere saisie";
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'recent_expenses']);
    }

    private function handleCategoryDrillDown(AgentContext $context, string $rawCategory): AgentResult
    {
        $detected = $this->detectCategory($rawCategory);
        $categoryName = $detected === 'autre' ? $this->sanitizeCategoryName($rawCategory) : $detected;

        if (empty($categoryName)) {
            return $this->handleHelp($context);
        }

        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $expenses = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('category', $categoryName)
            ->whereYear('expense_date', substr($monthKey, 0, 4))
            ->whereMonth('expense_date', substr($monthKey, 5, 2))
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at')
            ->get();

        $budgetCat = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey)
            ->firstWhere('name', $categoryName);

        $reply = "📁 *Depenses {$categoryName} — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($expenses->isEmpty()) {
            $reply .= "_Aucune depense en {$categoryName} ce mois._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 {$categoryName}_";
        } else {
            $total = $expenses->sum('amount');
            $totalFmt = number_format($total, 2, ',', ' ');
            $avgFmt = number_format($total / $expenses->count(), 2, ',', ' ');
            $reply .= "💰 Total : *{$totalFmt} €* ({$expenses->count()} depense(s))\n";
            $reply .= "📊 Moyenne : {$avgFmt} € / depense\n";

            if ($budgetCat && $budgetCat->monthly_limit > 0) {
                $percent = $budgetCat->usagePercent();
                $bar = $this->progressBar($percent);
                $limitFmt = number_format($budgetCat->monthly_limit, 2, ',', ' ');
                $reply .= "🎯 Budget : {$bar} {$percent}% de {$limitFmt} €\n";
            }

            $reply .= "\n*Detail :*\n";
            foreach ($expenses as $exp) {
                $amountFmt = number_format($exp->amount, 2, ',', ' ');
                $date = $exp->expense_date->format('d/m');
                $reply .= "  • {$date} — *{$amountFmt} €* {$exp->description}\n";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Category drill-down', ['category' => $categoryName]);

        return AgentResult::reply($reply, ['action' => 'category_drilldown', 'category' => $categoryName]);
    }

    private function handleForecast(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $today = now();
        $dayOfMonth = (int) $today->format('j');
        $daysInMonth = (int) $today->daysInMonth;
        $daysRemaining = $daysInMonth - $dayOfMonth;

        $totalSpent = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);

        $reply = "📈 *Prevision Budget — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($totalSpent <= 0) {
            $reply .= "_Aucune depense ce mois, impossible de projeter._\n";
            $reply .= "\n💡 Ajoutez des depenses avec : _depense 25 restaurant_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'forecast_empty']);
        }

        $dailyAverage = $dayOfMonth > 0 ? $totalSpent / $dayOfMonth : 0;
        $projectedTotal = $dailyAverage * $daysInMonth;
        $projectedRemaining = $dailyAverage * $daysRemaining;

        $globalBudget = BudgetCategory::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', '__global__')
            ->where('month_key', $monthKey)
            ->first();

        $reply .= "📅 Jour {$dayOfMonth} / {$daysInMonth} ({$daysRemaining} jours restants)\n";
        $reply .= "💸 Depense actuelle : *" . number_format($totalSpent, 2, ',', ' ') . " €*\n";
        $reply .= "📊 Moyenne / jour : *" . number_format($dailyAverage, 2, ',', ' ') . " €*\n";
        $reply .= "🔮 Projection fin de mois : *" . number_format($projectedTotal, 2, ',', ' ') . " €*\n";
        $reply .= "⏭️ A depenser d'ici la fin : ~" . number_format($projectedRemaining, 2, ',', ' ') . " €\n";

        if ($globalBudget && $globalBudget->monthly_limit > 0) {
            $globalLimitFmt = number_format($globalBudget->monthly_limit, 2, ',', ' ');
            $globalPct = round($projectedTotal / $globalBudget->monthly_limit * 100, 1);
            if ($projectedTotal > $globalBudget->monthly_limit) {
                $overFmt = number_format($projectedTotal - $globalBudget->monthly_limit, 2, ',', ' ');
                $reply .= "🚨 *Depassement budget global prevu : +{$overFmt} € au-dessus de {$globalLimitFmt}*\n";
            } elseif ($projectedTotal >= $globalBudget->monthly_limit * 0.8) {
                $reply .= "⚠️ *Budget global a {$globalPct}% en projection (limite {$globalLimitFmt})*\n";
            } else {
                $reply .= "✅ *Budget global OK — projection {$globalPct}% de {$globalLimitFmt}*\n";
            }
        }

        $reply .= "\n";

        // Per-category projections with budgets
        $categories = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey)
            ->filter(fn ($c) => $c->monthly_limit > 0);

        if ($categories->isNotEmpty()) {
            $alertLines = [];

            foreach ($categories as $cat) {
                $cat->calculateMonthlySpent();
                if ($cat->spent_this_month <= 0) {
                    continue;
                }
                $catDaily = $dayOfMonth > 0 ? $cat->spent_this_month / $dayOfMonth : 0;
                $catProjected = $catDaily * $daysInMonth;
                $limitFmt = number_format($cat->monthly_limit, 2, ',', ' ');
                $projFmt = number_format($catProjected, 2, ',', ' ');

                if ($catProjected > $cat->monthly_limit) {
                    $overFmt = number_format($catProjected - $cat->monthly_limit, 2, ',', ' ');
                    $alertLines[] = "  🚨 {$cat->name} : {$projFmt} € projeté (+{$overFmt} au-dessus de {$limitFmt})";
                } elseif ($catProjected >= $cat->monthly_limit * 0.8) {
                    $alertLines[] = "  ⚠️ {$cat->name} : {$projFmt} € projeté (limite {$limitFmt})";
                }
            }

            if (!empty($alertLines)) {
                $reply .= "*Alertes budgets projetees :*\n";
                $reply .= implode("\n", $alertLines) . "\n";
            } else {
                $reply .= "✅ *Tous les budgets sont dans les limites projetees.*\n";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet\n";
        $reply .= "📁 _categories_ — Voir tous les budgets";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Forecast viewed', ['total_spent' => $totalSpent, 'projected' => $projectedTotal]);

        return AgentResult::reply($reply, ['action' => 'forecast', 'projected_total' => $projectedTotal]);
    }

    private function handleReset(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $count = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereYear('expense_date', substr($monthKey, 0, 4))
            ->whereMonth('expense_date', substr($monthKey, 5, 2))
            ->count();

        if ($count === 0) {
            $reply = "ℹ️ *Aucune depense a reinitialiser* pour {$monthLabel}.\n\n_Ton budget de ce mois est deja vide._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reset_empty']);
        }

        $totalSpent = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
        $totalFmt = number_format($totalSpent, 2, ',', ' ');

        $this->setPendingContext($context, 'reset_confirmation', ['month_key' => $monthKey], 5, true);

        $reply = "⚠️ *Confirmation requise*\n\n";
        $reply .= "Tu es sur le point de supprimer *{$count} depense(s)* ({$totalFmt} €) pour {$monthLabel}.\n\n";
        $reply .= "Les limites de budget par categorie seront conservees.\n\n";
        $reply .= "Reponds *OUI* pour confirmer, ou *NON* pour annuler.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reset confirmation requested', ['count' => $count, 'total' => $totalSpent]);

        return AgentResult::reply($reply, ['action' => 'reset_pending']);
    }

    private function executeReset(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');

        try {
            $deleted = BudgetExpense::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->whereYear('expense_date', substr($monthKey, 0, 4))
                ->whereMonth('expense_date', substr($monthKey, 5, 2))
                ->delete();

            BudgetCategory::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('month_key', $monthKey)
                ->update(['spent_this_month' => 0]);
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] executeReset failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de la reinitialisation.* Veuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reset_error']);
        }

        $reply = "🔄 *Budget du mois reinitialise*\n\n";
        $reply .= "{$deleted} depense(s) supprimee(s).\n";
        $reply .= "Les limites de budget par categorie sont conservees.\n";
        $reply .= "\n📊 _resume budget_ — Voir le bilan";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget reset executed', ['deleted' => $deleted]);

        return AgentResult::reply($reply, ['action' => 'reset']);
    }

    private function handleTopExpenses(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $topExpenses = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereYear('expense_date', substr($monthKey, 0, 4))
            ->whereMonth('expense_date', substr($monthKey, 5, 2))
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $reply = "🏆 *Top 5 Depenses — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($topExpenses->isEmpty()) {
            $reply .= "_Aucune depense ce mois._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 restaurant_";
        } else {
            $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
            foreach ($topExpenses as $i => $exp) {
                $amountFmt = number_format($exp->amount, 2, ',', ' ');
                $date = $exp->expense_date->format('d/m');
                $medal = $medals[$i] ?? '  •';
                $reply .= "{$medal} *{$amountFmt} €* — {$exp->description} [{$exp->category}] ({$date})\n";
            }

            $totalMonth = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
            if ($totalMonth > 0) {
                $topTotal = $topExpenses->sum('amount');
                $topPct = round(($topTotal / $totalMonth) * 100, 1);
                $reply .= "\n_Ces {$topExpenses->count()} depenses representent {$topPct}% du total du mois._";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Top expenses viewed');

        return AgentResult::reply($reply, ['action' => 'top_expenses']);
    }

    private function handleMonthComparison(AgentContext $context): AgentResult
    {
        $currentMonthKey = now()->format('Y-m');
        $prevMonth = now()->subMonth();
        $prevMonthKey = $prevMonth->format('Y-m');
        $currentMonthLabel = now()->translatedFormat('F Y');
        $prevMonthLabel = $prevMonth->translatedFormat('F Y');

        $currentTotal = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $currentMonthKey);
        $prevTotal = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $prevMonthKey);

        $reply = "🔄 *Comparaison Mensuelle*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $currentFmt = number_format($currentTotal, 2, ',', ' ');
        $prevFmt = number_format($prevTotal, 2, ',', ' ');

        $reply .= "📅 *{$prevMonthLabel}* : {$prevFmt} €\n";
        $reply .= "📅 *{$currentMonthLabel}* : {$currentFmt} €\n\n";

        if ($prevTotal > 0) {
            $diff = $currentTotal - $prevTotal;
            $diffPct = round(abs($diff) / $prevTotal * 100, 1);
            $diffFmt = number_format(abs($diff), 2, ',', ' ');

            if ($diff > 0) {
                $reply .= "📈 *+{$diffFmt} € (+{$diffPct}%)* vs mois dernier\n";
            } elseif ($diff < 0) {
                $reply .= "📉 *-{$diffFmt} € (-{$diffPct}%)* vs mois dernier ✅\n";
            } else {
                $reply .= "➡️ *Identique* au mois dernier\n";
            }
        } elseif ($currentTotal > 0) {
            $reply .= "_Pas de donnees pour {$prevMonthLabel}._\n";
        } else {
            $reply .= "_Aucune donnee disponible pour la comparaison._\n";
        }

        // Category breakdown comparison
        $currentByCategory = BudgetExpense::getMonthlyByCategory($context->from, $context->agent->id, $currentMonthKey);
        $prevByCategory = BudgetExpense::getMonthlyByCategory($context->from, $context->agent->id, $prevMonthKey);

        if ($currentByCategory->isNotEmpty() || $prevByCategory->isNotEmpty()) {
            $prevMap = $prevByCategory->keyBy('category');
            $currMap = $currentByCategory->keyBy('category');
            $allCategories = $currentByCategory->pluck('category')
                ->merge($prevByCategory->pluck('category'))
                ->unique()
                ->filter(fn ($c) => $c !== '__global__')
                ->sort();

            if ($allCategories->isNotEmpty()) {
                $reply .= "\n*Variation par categorie :*\n";
                foreach ($allCategories as $catName) {
                    $currCat = $currMap->get($catName);
                    $prevCat = $prevMap->get($catName);
                    $currAmt = $currCat ? (float) $currCat->total : 0;
                    $prevAmt = $prevCat ? (float) $prevCat->total : 0;
                    $catCurrentFmt = number_format($currAmt, 2, ',', ' ');

                    if ($currAmt > 0 && $prevAmt > 0) {
                        $catDiff = $currAmt - $prevAmt;
                        $catDiffPct = round(abs($catDiff) / $prevAmt * 100, 1);
                        $arrow = $catDiff > 0 ? "📈 +{$catDiffPct}%" : ($catDiff < 0 ? "📉 -{$catDiffPct}%" : "➡️");
                        $reply .= "  {$arrow} *{$catName}* : {$catCurrentFmt} €\n";
                    } elseif ($currAmt > 0) {
                        $reply .= "  🆕 *{$catName}* : {$catCurrentFmt} € (nouveau ce mois)\n";
                    } else {
                        $prevFmt = number_format($prevAmt, 2, ',', ' ');
                        $reply .= "  ✅ *{$catName}* : 0 € (etait {$prevFmt} € le mois dernier)\n";
                    }
                }
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport du mois actuel";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Month comparison viewed', ['current' => $currentTotal, 'prev' => $prevTotal]);

        return AgentResult::reply($reply, ['action' => 'month_comparison', 'current_total' => $currentTotal, 'prev_total' => $prevTotal]);
    }

    private function handleDailyBudget(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $today = now();
        $dayOfMonth = (int) $today->format('j');
        $daysInMonth = (int) $today->daysInMonth;
        $daysRemaining = $daysInMonth - $dayOfMonth;

        $totalSpent = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);

        $globalBudget = BudgetCategory::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', '__global__')
            ->where('month_key', $monthKey)
            ->first();

        $reply = "📆 *Budget Journalier — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📅 Jour {$dayOfMonth} / {$daysInMonth} ({$daysRemaining} jours restants)\n";
        $reply .= "💸 Depense actuelle : *" . number_format($totalSpent, 2, ',', ' ') . " €*\n\n";

        if ($dayOfMonth > 0 && $totalSpent > 0) {
            $currentDailyAvg = $totalSpent / $dayOfMonth;
            $reply .= "📊 Moyenne actuelle : *" . number_format($currentDailyAvg, 2, ',', ' ') . " € / jour*\n";
        }

        if ($globalBudget && $globalBudget->monthly_limit > 0) {
            $globalBudget->calculateMonthlySpent();
            $remaining = max(0, $globalBudget->monthly_limit - $totalSpent);
            $globalLimitFmt = number_format($globalBudget->monthly_limit, 2, ',', ' ');
            $remainFmt = number_format($remaining, 2, ',', ' ');

            $reply .= "🎯 Budget global : *{$globalLimitFmt} €* (reste {$remainFmt} €)\n\n";

            if ($daysRemaining > 0) {
                $dailyAllowed = $remaining / $daysRemaining;
                $dailyAllowedFmt = number_format($dailyAllowed, 2, ',', ' ');

                if ($globalBudget->isOverBudget()) {
                    $overFmt = number_format($totalSpent - $globalBudget->monthly_limit, 2, ',', ' ');
                    $reply .= "🚨 *Budget global depasse de {$overFmt} €*\n";
                    $reply .= "_Essaie de limiter tes depenses pour le reste du mois._\n";
                } elseif ($dailyAllowed <= 0) {
                    $reply .= "⚠️ *Plus de budget disponible pour ce mois.*\n";
                } else {
                    $pct = round($totalSpent / $globalBudget->monthly_limit * 100, 1);
                    $bar = $this->progressBar($pct);
                    $reply .= "{$bar} {$pct}% du budget global utilise\n\n";
                    $reply .= "💡 *Budget journalier conseille : *{$dailyAllowedFmt} €** / jour\n";
                    $reply .= "  _(sur les {$daysRemaining} jours restants)_\n";

                    if (isset($currentDailyAvg)) {
                        if ($currentDailyAvg > $dailyAllowed * 1.2) {
                            $reply .= "\n⚠️ _Ton rythme actuel (" . number_format($currentDailyAvg, 2, ',', ' ') . " €/j) depasse le budget conseille._\n";
                        } elseif ($currentDailyAvg <= $dailyAllowed) {
                            $reply .= "\n✅ _Tu es dans les clous ! Continue comme ca._\n";
                        }
                    }
                }
            } else {
                $reply .= "_C'est le dernier jour du mois._\n";
            }
        } else {
            if ($daysRemaining > 0 && $dayOfMonth > 0 && $totalSpent > 0) {
                $dailyAvg = $totalSpent / $dayOfMonth;
                $reply .= "💡 A ce rythme, tu depenseras environ *"
                    . number_format($dailyAvg * $daysInMonth, 2, ',', ' ')
                    . " €* ce mois.\n\n";
                $reply .= "🎯 _Definissez un budget global pour avoir un conseille journalier :_\n";
                $reply .= "  _budget global 2000_\n";
            } else {
                $reply .= "_Aucune depense ce mois, rien a calculer._\n";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet\n";
        $reply .= "📈 _prevision_ — Projection fin de mois";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Daily budget consulted', ['day' => $dayOfMonth, 'total' => $totalSpent]);

        return AgentResult::reply($reply, ['action' => 'daily_budget', 'day' => $dayOfMonth, 'total_spent' => $totalSpent]);
    }

    private function handleMonthStats(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');
        $year = substr($monthKey, 0, 4);
        $month = substr($monthKey, 5, 2);

        $expenses = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->orderByDesc('amount')
            ->get();

        $reply = "📊 *Statistiques — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($expenses->isEmpty()) {
            $reply .= "_Aucune depense ce mois._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 restaurant_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'month_stats_empty']);
        }

        $total = $expenses->sum('amount');
        $count = $expenses->count();
        $dayOfMonth = (int) now()->format('j');
        $dailyAvg = $dayOfMonth > 0 ? $total / $dayOfMonth : 0;

        // Mediane
        $sorted = $expenses->pluck('amount')->sort()->values();
        $mid = intdiv($count, 2);
        $median = $count % 2 === 0
            ? (($sorted[$mid - 1] + $sorted[$mid]) / 2)
            : $sorted[$mid];

        $reply .= "💰 Total : *" . number_format($total, 2, ',', ' ') . " €* ({$count} depenses)\n";
        $reply .= "📊 Moyenne / depense : *" . number_format($total / $count, 2, ',', ' ') . " €*\n";
        $reply .= "📈 Mediane : *" . number_format($median, 2, ',', ' ') . " €*\n";
        $reply .= "📅 Moyenne / jour : *" . number_format($dailyAvg, 2, ',', ' ') . " €*\n\n";

        // Plus grosse depense
        $biggest = $expenses->first();
        $reply .= "🏆 Plus grosse depense : *" . number_format($biggest->amount, 2, ',', ' ')
            . " €* — {$biggest->description} ({$biggest->expense_date->format('d/m')})\n";

        // Plus petite depense
        $smallest = $expenses->last();
        $reply .= "🔹 Plus petite depense : *" . number_format($smallest->amount, 2, ',', ' ')
            . " €* — {$smallest->description} ({$smallest->expense_date->format('d/m')})\n\n";

        // Top 3 categories
        $byCategory = $expenses->groupBy('category')
            ->map(fn ($group) => ['total' => $group->sum('amount'), 'count' => $group->count()])
            ->sortByDesc('total')
            ->take(3);

        if ($byCategory->isNotEmpty()) {
            $reply .= "*Top 3 categories :*\n";
            $medals = ['🥇', '🥈', '🥉'];
            $i = 0;
            foreach ($byCategory as $catName => $catData) {
                $pct = round(($catData['total'] / $total) * 100, 1);
                $catFmt = number_format($catData['total'], 2, ',', ' ');
                $reply .= "  {$medals[$i]} *{$catName}* : {$catFmt} € ({$pct}% du total)\n";
                $i++;
            }
        }

        // Jour le plus depensier
        $byDay = $expenses->groupBy(fn ($e) => $e->expense_date->format('Y-m-d'))
            ->map(fn ($group) => $group->sum('amount'))
            ->sortDesc();

        if ($byDay->isNotEmpty()) {
            $worstDay = $byDay->keys()->first();
            $worstDayFmt = Carbon::parse($worstDay)->translatedFormat('l d/m');
            $worstDayTotal = number_format($byDay->first(), 2, ',', ' ');
            $reply .= "\n📆 Jour le plus depensier : *{$worstDayFmt}* ({$worstDayTotal} €)\n";
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet\n";
        $reply .= "🏆 _top depenses_ — Top 5 depenses\n";
        $reply .= "📈 _prevision_ — Projection fin de mois";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Month stats viewed', ['total' => $total, 'count' => $count]);

        return AgentResult::reply($reply, ['action' => 'month_stats', 'total' => $total, 'count' => $count]);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "💰 *Budget Tracker — Commandes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📝 *Ajouter une depense :*\n";
        $reply .= "  _depense 25 restaurant diner_\n";
        $reply .= "  _45,50€ courses carrefour_\n";
        $reply .= "  _j'ai paye 12 cafe hier_\n";
        $reply .= "  _depense 30 uber vendredi_\n\n";
        $reply .= "💰 *Gerer les budgets :*\n";
        $reply .= "  _budget 500 courses_ — Limite mensuelle par categorie\n";
        $reply .= "  _budget global 2000_ — Plafond total du mois\n";
        $reply .= "  _supprimer limite courses_ — Retirer une limite\n\n";
        $reply .= "📊 *Consulter :*\n";
        $reply .= "  _resume budget_ — Rapport mensuel complet\n";
        $reply .= "  _bilan semaine_ — Depenses de la semaine\n";
        $reply .= "  _stats mois_ — Statistiques detaillees (mediane, top jour...)\n";
        $reply .= "  _budget journalier_ — Budget conseille par jour\n";
        $reply .= "  _categories_ — Budgets par categorie\n";
        $reply .= "  _mes depenses_ — Historique recent\n";
        $reply .= "  _depenses restaurant_ — Detail par categorie\n";
        $reply .= "  _top depenses_ — Top 5 plus grosses depenses\n";
        $reply .= "  _prevision_ — Projection fin de mois\n";
        $reply .= "  _comparer mois_ — Evolution vs mois dernier\n";
        $reply .= "  _chercher resto_ — Recherche par mot-cle\n\n";
        $reply .= "🔄 *Gestion :*\n";
        $reply .= "  _annuler depense_ — Supprimer la derniere saisie\n";
        $reply .= "  _reset budget_ — Reinitialiser le mois\n";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'help']);
    }

    private function handleWeeklySummary(AgentContext $context): AgentResult
    {
        $weekStart = now()->startOfWeek(); // lundi
        $weekEnd   = now()->endOfWeek();   // dimanche

        $expenses = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereBetween('expense_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at')
            ->get();

        $weekLabel = $weekStart->format('d/m') . ' — ' . $weekEnd->format('d/m/Y');
        $reply = "📅 *Bilan Semaine — {$weekLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($expenses->isEmpty()) {
            $reply .= "_Aucune depense enregistree cette semaine._\n";
            $reply .= "\n💡 Ajoutez avec : _depense 25 restaurant_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'weekly_summary_empty']);
        }

        $total = $expenses->sum('amount');
        $daysElapsed = max(1, now()->dayOfWeek === 0 ? 7 : now()->dayOfWeek);
        $dailyAvg = $total / $daysElapsed;

        $reply .= "💰 Total semaine : *" . number_format($total, 2, ',', ' ') . " €*";
        $reply .= " ({$expenses->count()} depenses)\n";
        $reply .= "📊 Moyenne / jour : *" . number_format($dailyAvg, 2, ',', ' ') . " €*\n\n";

        // Par categorie
        $byCategory = $expenses->groupBy('category');
        $reply .= "*Par categorie :*\n";
        foreach ($byCategory->sortByDesc(fn ($g) => $g->sum('amount')) as $cat => $items) {
            $catTotal = number_format($items->sum('amount'), 2, ',', ' ');
            $reply .= "  📁 {$cat} : *{$catTotal} €* ({$items->count()} op.)\n";
        }

        // Comparaison avec semaine precedente
        $prevWeekStart = (clone $weekStart)->subWeek();
        $prevWeekEnd   = (clone $weekEnd)->subWeek();
        $prevTotal = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereBetween('expense_date', [$prevWeekStart->toDateString(), $prevWeekEnd->toDateString()])
            ->sum('amount');

        if ($prevTotal > 0) {
            $diff = $total - $prevTotal;
            $diffPct = round(abs($diff) / $prevTotal * 100, 1);
            $diffFmt = number_format(abs($diff), 2, ',', ' ');
            $prevFmt = number_format($prevTotal, 2, ',', ' ');
            $reply .= "\n_Semaine precedente : {$prevFmt} €_\n";
            if ($diff > 0) {
                $reply .= "📈 +{$diffFmt} € (+{$diffPct}%) vs semaine derniere\n";
            } elseif ($diff < 0) {
                $reply .= "📉 -{$diffFmt} € (-{$diffPct}%) vs semaine derniere ✅\n";
            } else {
                $reply .= "➡️ Identique a la semaine derniere\n";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Weekly summary viewed', ['total' => $total, 'count' => $expenses->count()]);

        return AgentResult::reply($reply, ['action' => 'weekly_summary', 'total' => $total]);
    }

    private function handleSetGlobalBudget(AgentContext $context, string $rawAmount): AgentResult
    {
        $amount = (float) str_replace(',', '.', $rawAmount);

        if ($amount <= 0) {
            $reply = "❌ *Montant invalide.* Exemple : _budget global 2000_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'global_budget_invalid']);
        }

        if ($amount > 9999999) {
            $reply = "❌ *Montant trop eleve.* Maximum : 9 999 999 €";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'global_budget_invalid']);
        }

        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        try {
            $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, '__global__', $monthKey);
            $budgetCat->update(['monthly_limit' => $amount]);
            $budgetCat->calculateMonthlySpent();
        } catch (\Throwable $e) {
            Log::error("[budget_tracker] handleSetGlobalBudget failed: {$e->getMessage()}");
            $reply = "❌ *Erreur lors de la definition du budget global.* Veuillez reessayer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'global_budget_error']);
        }

        $amountFmt = number_format($amount, 2, ',', ' ');
        $spentFmt = number_format($budgetCat->spent_this_month, 2, ',', ' ');
        $bar = $this->progressBar($budgetCat->usagePercent());
        $remainFmt = number_format($budgetCat->remainingBudget(), 2, ',', ' ');

        $reply = "🎯 *Budget Global Mensuel defini*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📅 Mois : *{$monthLabel}*\n";
        $reply .= "💰 Plafond total : *{$amountFmt} €*\n";
        $reply .= "📊 Deja depense : {$spentFmt} € {$bar} {$budgetCat->usagePercent()}%\n";
        $reply .= "💡 Reste disponible : *{$remainFmt} €*\n";
        $reply .= "\n_Le budget global apparait dans le resume et les previsions._\n";
        $reply .= "💡 _budget global 0_ — Pour supprimer ce plafond";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Global budget set', ['limit' => $amount]);

        return AgentResult::reply($reply, ['action' => 'global_budget_set', 'limit' => $amount]);
    }

    private function handleSearchExpenses(AgentContext $context, string $term): AgentResult
    {
        $sanitizedTerm = mb_substr(strip_tags($term), 0, 50);

        $expenses = BudgetExpense::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where(function ($q) use ($sanitizedTerm) {
                $q->where('description', 'like', "%{$sanitizedTerm}%")
                  ->orWhere('category', 'like', "%{$sanitizedTerm}%");
            })
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $reply = "🔍 *Recherche : \"{$sanitizedTerm}\"*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($expenses->isEmpty()) {
            $reply .= "_Aucune depense trouvee pour \"{$sanitizedTerm}\"._\n";
            $reply .= "\n💡 Essayez : _chercher restaurant_, _chercher uber_";
        } else {
            $total = $expenses->sum('amount');
            $reply .= "_({$expenses->count()} resultat(s) — total : " . number_format($total, 2, ',', ' ') . " €)_\n\n";

            foreach ($expenses as $exp) {
                $amountFmt = number_format($exp->amount, 2, ',', ' ');
                $date = $exp->expense_date->format('d/m/Y');
                $reply .= "  💸 {$date} — *{$amountFmt} €* [{$exp->category}] {$exp->description}\n";
            }

            $reply .= "\n📊 _resume budget_ — Rapport complet";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Expenses searched', ['term' => $sanitizedTerm, 'results' => $expenses->count()]);

        return AgentResult::reply($reply, ['action' => 'search_expenses', 'term' => $sanitizedTerm, 'count' => $expenses->count()]);
    }

    // ─── UTILITIES ────────────────────────────────────────────────────────────

    private function progressBar(float $percent): string
    {
        $capped = min($percent, 100);
        $filled = (int) round($capped / 10);
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';

        if ($percent > 100) {
            $bar .= '!!';
        }

        return $bar;
    }
}
