<?php

namespace App\Services\Agents;

use App\Models\BudgetCategory;
use App\Models\BudgetExpense;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

class BudgetTrackerAgent extends BaseAgent
{
    public function name(): string
    {
        return 'budget_tracker';
    }

    public function description(): string
    {
        return 'Suivi intelligent des depenses et budgets mensuels avec categories automatiques, alertes de depassement et rapports';
    }

    public function keywords(): array
    {
        return [
            'depense', 'budget', 'cout', 'prix', 'je paie', 'je paye',
            'j\'ai depense', 'j\'ai paye', 'achat', 'achete',
            'resume budget', 'categories', 'reset budget',
            'budget mois', 'limite budget', 'set budget',
            'mes depenses', 'combien', 'reste', 'solde',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
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

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Parse commands
        if (preg_match('/\b(r[eé]sum[eé]\s+budget|bilan\s+budget|rapport\s+budget|budget\s+summary)\b/iu', $lower)) {
            return $this->handleSummary($context);
        }

        if (preg_match('/\b(cat[eé]gories|mes\s+cat[eé]gories|list\s+categories)\b/iu', $lower)) {
            return $this->handleCategories($context);
        }

        if (preg_match('/\b(reset\s+budget|reinitialiser|r[eé]initialiser\s+budget)\b/iu', $lower)) {
            return $this->handleReset($context);
        }

        if (preg_match('/\b(mes\s+depenses|dernieres\s+depenses|historique\s+depenses|recent\s+expenses)\b/iu', $lower)) {
            return $this->handleRecentExpenses($context);
        }

        // Set budget limit: "budget 500 courses" or "budget mois 500 courses" or "set budget courses 500"
        if (preg_match('/\b(?:budget|set\s+budget|limite)\s+(?:mois\s+)?(\d+(?:[.,]\d{1,2})?)\s*([^\d\s].+)/iu', $body, $m)) {
            return $this->handleSetBudget($context, $m[1], trim($m[2]));
        }
        if (preg_match('/\b(?:budget|set\s+budget|limite)\s+(.+?)\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)?$/iu', $body, $m)) {
            return $this->handleSetBudget($context, $m[2], trim($m[1]));
        }

        // Add expense: "depense 25 restaurant" or "25€ resto" or "j'ai paye 30 courses"
        $expenseMatch = $this->parseExpense($body);
        if ($expenseMatch) {
            return $this->handleAddExpense($context, $expenseMatch);
        }

        // Fallback: show help
        return $this->handleHelp($context);
    }

    private function parseExpense(string $body): ?array
    {
        // Pattern: "depense 25€ restaurant diner" or "25€ restaurant" or "j'ai paye 30 courses"
        $patterns = [
            // "depense 25 restaurant diner"
            '/(?:d[eé]pense|d[eé]pens[eé]|spent|expense|pay[eé]|paye|achat|achet[eé])\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)?\s+(.+)/iu',
            // "j'ai depense/paye 25 en courses"
            "/j['\x{2019}]ai\\s+(?:d[eé]pens[eé]|pay[eé]|achet[eé])\\s+(\\d+(?:[.,]\\d{1,2})?)\\s*(?:€|eur(?:os?)?)?\\s+(?:en\\s+|pour\\s+|au?\\s+)?(.+)/iu",
            // "25€ restaurant" or "25 resto diner"
            '/^(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)\s+(.+)/iu',
            // "restaurant 25€"
            '/^(.+?)\s+(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur(?:os?)?)\s*$/iu',
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $body, $m)) {
                if ($i === 3) {
                    // Last pattern has description first, amount second
                    $amount = str_replace(',', '.', $m[2]);
                    $description = trim($m[1]);
                } else {
                    $amount = str_replace(',', '.', $m[1]);
                    $description = trim($m[2]);
                }

                if ((float) $amount <= 0) {
                    continue;
                }

                return [
                    'amount' => (float) $amount,
                    'description' => $description,
                    'currency' => 'EUR',
                ];
            }
        }

        return null;
    }

    private function detectCategory(string $description): string
    {
        $lower = mb_strtolower($description);

        $categoryMap = [
            'restaurant' => ['resto', 'restaurant', 'diner', 'dejeuner', 'brunch', 'pizz', 'sushi', 'burger', 'kebab', 'mcdo', 'mcdonald', 'kfc', 'fast food', 'cantine'],
            'courses' => ['courses', 'supermarche', 'carrefour', 'leclerc', 'auchan', 'lidl', 'aldi', 'monoprix', 'franprix', 'epicerie', 'marche', 'primeur'],
            'transport' => ['essence', 'carburant', 'metro', 'bus', 'uber', 'taxi', 'train', 'sncf', 'parking', 'peage', 'transport', 'vtc', 'bolt', 'trottinette'],
            'loisirs' => ['cinema', 'film', 'concert', 'spectacle', 'musee', 'theatre', 'bowling', 'escape', 'parc', 'sortie', 'bar', 'boite', 'club', 'loisir', 'jeu', 'jeux'],
            'sante' => ['pharmacie', 'medecin', 'docteur', 'dentiste', 'hopital', 'sante', 'ordonnance', 'medicament', 'lunettes', 'optique'],
            'shopping' => ['vetement', 'chaussure', 'zara', 'hm', 'nike', 'adidas', 'amazon', 'achat', 'shopping', 'cadeau', 'bijou'],
            'logement' => ['loyer', 'electricite', 'edf', 'gaz', 'eau', 'assurance', 'charges', 'copropriete', 'bricolage', 'meuble', 'ikea'],
            'abonnement' => ['netflix', 'spotify', 'abonnement', 'subscription', 'forfait', 'telephone', 'internet', 'box', 'free', 'sfr', 'orange', 'bouygues'],
            'education' => ['livre', 'cours', 'formation', 'ecole', 'universite', 'fourniture', 'cahier', 'stylo'],
            'cafe' => ['cafe', 'coffee', 'starbucks', 'the', 'boisson'],
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

    private function handleAddExpense(AgentContext $context, array $data): AgentResult
    {
        $category = $this->detectCategory($data['description']);
        $monthKey = now()->format('Y-m');

        $expense = BudgetExpense::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'category' => $category,
            'description' => $data['description'],
            'expense_date' => now()->toDateString(),
        ]);

        // Update category spent
        $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, $category, $monthKey);
        $budgetCat->calculateMonthlySpent();

        $amountFmt = number_format($data['amount'], 2, ',', ' ');
        $reply = "✅ *Depense enregistree*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "💸 Montant : *{$amountFmt} {$data['currency']}*\n";
        $reply .= "📁 Categorie : *{$category}*\n";
        $reply .= "📝 Description : {$data['description']}\n";
        $reply .= "📅 Date : " . now()->format('d/m/Y') . "\n";

        // Check budget alert
        if ($budgetCat->monthly_limit > 0) {
            $percent = $budgetCat->usagePercent();
            $remainFmt = number_format($budgetCat->remainingBudget(), 2, ',', ' ');
            $limitFmt = number_format($budgetCat->monthly_limit, 2, ',', ' ');

            if ($budgetCat->isOverBudget()) {
                $overAmount = number_format($budgetCat->spent_this_month - $budgetCat->monthly_limit, 2, ',', ' ');
                $reply .= "\n🚨 *ALERTE : Budget {$category} depasse !*\n";
                $reply .= "Depense ce mois : {$budgetCat->spent_this_month} / {$limitFmt} EUR\n";
                $reply .= "Depassement : +{$overAmount} EUR\n";
            } elseif ($percent >= 80) {
                $reply .= "\n⚠️ *Attention : Budget {$category} a {$percent}%*\n";
                $reply .= "Reste : {$remainFmt} EUR sur {$limitFmt} EUR\n";
            } else {
                $reply .= "\n📊 Budget {$category} : {$percent}% utilise ({$remainFmt} EUR restants)\n";
            }
        }

        // Monthly total
        $monthTotal = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
        $monthTotalFmt = number_format($monthTotal, 2, ',', ' ');
        $reply .= "\n💰 Total ce mois : *{$monthTotalFmt} EUR*";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Expense added', ['amount' => $data['amount'], 'category' => $category]);

        return AgentResult::reply($reply, ['action' => 'expense_added', 'amount' => $data['amount'], 'category' => $category]);
    }

    private function handleSetBudget(AgentContext $context, string $amount, string $categoryName): AgentResult
    {
        $amount = (float) str_replace(',', '.', $amount);
        $category = $this->detectCategory($categoryName);
        if ($category === 'autre') {
            $category = mb_strtolower(trim($categoryName));
        }

        $monthKey = now()->format('Y-m');
        $budgetCat = BudgetCategory::getOrCreate($context->from, $context->agent->id, $category, $monthKey);
        $budgetCat->update(['monthly_limit' => $amount]);
        $budgetCat->calculateMonthlySpent();

        $amountFmt = number_format($amount, 2, ',', ' ');
        $spentFmt = number_format($budgetCat->spent_this_month, 2, ',', ' ');

        $reply = "✅ *Budget defini*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📁 Categorie : *{$category}*\n";
        $reply .= "💰 Limite mensuelle : *{$amountFmt} EUR*\n";
        $reply .= "📊 Deja depense : {$spentFmt} EUR ({$budgetCat->usagePercent()}%)\n";
        $reply .= "💡 Reste : " . number_format($budgetCat->remainingBudget(), 2, ',', ' ') . " EUR\n";
        $reply .= "\n_Ajoutez des depenses avec : depense [montant] [description]_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget set', ['category' => $category, 'limit' => $amount]);

        return AgentResult::reply($reply, ['action' => 'budget_set', 'category' => $category, 'limit' => $amount]);
    }

    private function handleSummary(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $monthLabel = now()->translatedFormat('F Y');

        $totalSpent = BudgetExpense::getMonthlyTotal($context->from, $context->agent->id, $monthKey);
        $byCategory = BudgetExpense::getMonthlyByCategory($context->from, $context->agent->id, $monthKey);
        $categories = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey);

        $reply = "📊 *Resume Budget — {$monthLabel}*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        $totalFmt = number_format($totalSpent, 2, ',', ' ');
        $reply .= "💰 Total depense : *{$totalFmt} EUR*\n\n";

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
                    $limitInfo = " {$bar} {$percent}% de {$limitFmt}";
                }
                $reply .= "  📁 {$cat->category} : *{$catTotal} EUR* ({$cat->count} ops){$limitInfo}\n";
            }

            // Alerts
            $alerts = $categories->filter(fn ($c) => $c->monthly_limit > 0 && $c->isOverBudget());
            if ($alerts->isNotEmpty()) {
                $reply .= "\n🚨 *Alertes de depassement :*\n";
                foreach ($alerts as $alert) {
                    $over = number_format($alert->spent_this_month - $alert->monthly_limit, 2, ',', ' ');
                    $reply .= "  ⚠️ {$alert->name} : +{$over} EUR au-dessus du budget\n";
                }
            }
        }

        $reply .= "\n📋 _categories_ — Voir les budgets\n";
        $reply .= "📝 _mes depenses_ — Historique recent";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget summary viewed');

        return AgentResult::reply($reply, ['action' => 'summary']);
    }

    private function handleCategories(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');
        $categories = BudgetCategory::getAllForUser($context->from, $context->agent->id, $monthKey);

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
                    $reply .= "{$status} *{$cat->name}* : {$spentFmt} / {$limitFmt} EUR {$bar} {$percent}%\n";
                } else {
                    $reply .= "📁 *{$cat->name}* : {$spentFmt} EUR (pas de limite)\n";
                }
            }
        }

        $reply .= "\n💡 _budget [montant] [categorie]_ — Definir une limite\n";
        $reply .= "📊 _resume budget_ — Rapport complet";

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
            foreach ($expenses as $exp) {
                $amountFmt = number_format($exp->amount, 2, ',', ' ');
                $date = $exp->expense_date->format('d/m');
                $reply .= "  💸 {$date} — *{$amountFmt} EUR* [{$exp->category}] {$exp->description}\n";
            }
        }

        $reply .= "\n📊 _resume budget_ — Rapport complet";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'recent_expenses']);
    }

    private function handleReset(AgentContext $context): AgentResult
    {
        $monthKey = now()->format('Y-m');

        BudgetCategory::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('month_key', $monthKey)
            ->update(['spent_this_month' => 0]);

        $reply = "🔄 *Totaux mensuels reinitialises*\n\n";
        $reply .= "Les limites de budget sont conservees.\n";
        $reply .= "Les depenses ne sont pas supprimees.\n";
        $reply .= "\n📊 _resume budget_ — Voir le bilan";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Budget reset');

        return AgentResult::reply($reply, ['action' => 'reset']);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "💰 *Budget Tracker — Commandes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📝 *Ajouter une depense :*\n";
        $reply .= "  _depense 25 restaurant diner_\n";
        $reply .= "  _45,50€ courses carrefour_\n";
        $reply .= "  _j'ai paye 12 cafe_\n\n";
        $reply .= "💰 *Definir un budget :*\n";
        $reply .= "  _budget 500 courses_\n";
        $reply .= "  _budget restaurant 200_\n\n";
        $reply .= "📊 *Consulter :*\n";
        $reply .= "  _resume budget_ — Rapport mensuel\n";
        $reply .= "  _categories_ — Budgets par categorie\n";
        $reply .= "  _mes depenses_ — Historique recent\n\n";
        $reply .= "🔄 *Gestion :*\n";
        $reply .= "  _reset budget_ — Reinitialiser les totaux\n";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'help']);
    }

    private function progressBar(float $percent): string
    {
        $filled = (int) round(min($percent, 100) / 10);
        $empty = 10 - $filled;

        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
