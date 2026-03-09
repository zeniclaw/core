<?php

namespace App\Services\Agents;

use App\Models\Budget;
use App\Models\Expense;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceAgent extends BaseAgent
{
    public function name(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Agent de gestion financiere personnelle. Suivi des depenses par categorie, definition et suppression de budgets mensuels, alertes de depassement, historique, rapports, projections de fin de mois, detail par categorie et detection d\'anomalies de depenses.';
    }

    public function keywords(): array
    {
        return [
            'depense', 'depenses', 'expense', 'expenses', 'spent', 'depenser',
            'cout', 'coute', 'couter', 'achete', 'acheter', 'achat', 'achats',
            'paye', 'payer', 'payement', 'payment',
            'budget', 'budgets', 'budget mensuel', 'monthly budget',
            'solde', 'balance', 'reste', 'remaining', 'restant',
            'bilan', 'bilan financier', 'rapport financier', 'financial report',
            'stats finance', 'statistiques finance', 'finance stats',
            'alerte budget', 'alerte depense', 'budget alert',
            'combien j\'ai depense', 'how much did i spend',
            'argent', 'money', 'euros', 'euro',
            'finance', 'financier', 'financiere', 'finances',
            'economie', 'economies', 'saving', 'savings', 'epargne',
            'categorie depense', 'expense category',
            'alimentation', 'transport', 'loisirs', 'restaurant', 'shopping',
            'abonnement', 'abonnements', 'subscription',
            'historique', 'history', 'dernieres depenses', 'recent expenses',
            'supprimer depense', 'annuler depense', 'delete expense',
            'supprimer budget', 'delete budget', 'enlever budget',
            'aide finance', 'help finance', 'commandes finance',
            'projection', 'prevision', 'fin de mois',
            'detail', 'details', 'analyse categorie', 'category detail',
            'semaine', 'hebdo', 'hebdomadaire', 'resume semaine', 'cette semaine', 'semaine en cours',
            'top depenses', 'grosses depenses', 'plus grosses depenses', 'grandes depenses', 'depenses importantes',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        $body = mb_strtolower(trim($context->body));

        $patterns = [
            '/\b(depense|depenses|spent|expense|cout|coute|achete|paye|achat)\b/iu',
            '/\bbudget\b/i',
            '/\b(solde|balance|reste|remaining)\b/i',
            '/\b(statistiques?|stats?|rapport|report|bilan)\b/i',
            '/\b(alerte|alert)\b/i',
            '/\bajout\s+depense\b/iu',
            '/\b\d+[\.,]?\d*\s*€?\s*(euro|eur)?\b/i',
            '/\b(finance|financier|financiere)\b/i',
            '/\b(historique|history)\b/i',
            '/\b(supprimer|annuler)\s+(depense|derniere|budget)\b/iu',
            '/\b(aide|help)\s+finance\b/iu',
            '/\bprojection\b/i',
            '/\bdetail\s+\w+/iu',
            '/\b(semaine|hebdo|hebdomadaire)\b/iu',
            '/\btop\s+depenses?\b/iu',
            '/\b(grosses?|grandes?)\s+depenses?\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);

        // Try to parse command directly
        $command = $this->parseCommand($body);

        if ($command) {
            return $this->executeCommand($command, $context);
        }

        // Use Claude to understand the intent
        return $this->handleWithClaude($body, $context, $contextMemory);
    }

    private function parseCommand(string $body): ?array
    {
        $lower = mb_strtolower($body);

        // aide / help
        if (preg_match('/\b(aide|help)\b/iu', $lower) && preg_match('/\bfinance\b/iu', $lower)) {
            return ['action' => 'help'];
        }

        // supprimer budget [categorie]
        if (preg_match('/\b(supprimer|annuler|enlever|delete)\b.{0,10}\bbudget\b\s+(\S+)/iu', $lower, $m)) {
            return ['action' => 'delete_budget', 'category' => trim($m[2])];
        }

        // supprimer / annuler la derniere depense
        if (preg_match('/\b(supprimer|annuler|delete|undo)\b.*(depense|expense|derniere|last)/iu', $lower)) {
            return ['action' => 'delete_last'];
        }

        // historique [n] — extract limit only from explicit digit + context pattern
        if (preg_match('/\b(historique|history|dernieres?)\b/iu', $lower)) {
            $limit = 10;
            // Only match patterns like "10 dernieres", "historique 5", "last 15"
            if (preg_match('/(?:historique|history|dernieres?|last)\s+(\d{1,2})\b|\b(\d{1,2})\s+(?:dernieres?|last|depenses?)/i', $lower, $m)) {
                $raw   = (int) ($m[1] ?: $m[2]);
                $limit = min($raw, 20);
            }
            return ['action' => 'history', 'limit' => $limit];
        }

        // projection / prevision
        if (preg_match('/\b(projection|prevision|fin de mois)\b/iu', $lower)) {
            return ['action' => 'projection'];
        }

        // detail [categorie] — detailed stats for a category
        if (preg_match('/\bdetail\s+(\S+)/iu', $lower, $m)) {
            return ['action' => 'category_detail', 'category' => trim($m[1])];
        }

        // ajout depense [montant] [categorie] [description]
        if (preg_match('/(?:ajout(?:e|er)?|add|log)\s*(?:depense|expense)?\s*(\d+[\.,]?\d*)\s*€?\s+(\S+)\s*(.*)/iu', $lower, $m)) {
            return [
                'action'      => 'log_expense',
                'amount'      => (float) str_replace(',', '.', $m[1]),
                'category'    => $m[2],
                'description' => trim($m[3]) ?: null,
            ];
        }

        // depense [montant] [categorie] [description] (shorthand)
        if (preg_match('/(?:depense|spent|paye|achete)\s+(\d+[\.,]?\d*)\s*€?\s+(?:en\s+|pour\s+)?(\S+)\s*(.*)/iu', $lower, $m)) {
            return [
                'action'      => 'log_expense',
                'amount'      => (float) str_replace(',', '.', $m[1]),
                'category'    => $m[2],
                'description' => trim($m[3]) ?: null,
            ];
        }

        // budget [categorie] [montant]
        if (preg_match('/budget\s+(\S+)\s+(\d+[\.,]?\d*)\s*€?/iu', $lower, $m)) {
            return [
                'action'   => 'set_budget',
                'category' => $m[1],
                'amount'   => (float) str_replace(',', '.', $m[2]),
            ];
        }

        // solde / balance
        if (preg_match('/\b(solde|balance|reste|remaining|bilan)\b/iu', $lower)) {
            return ['action' => 'balance'];
        }

        // statistiques / stats / rapport
        if (preg_match('/\b(statistiques?|stats?|rapport|report)\b/iu', $lower)) {
            return ['action' => 'stats'];
        }

        // alertes
        if (preg_match('/\b(alertes?|alerts?)\b/iu', $lower)) {
            return ['action' => 'alerts'];
        }

        // resume semaine / cette semaine / hebdo
        if (preg_match('/\b(semaine|hebdo|hebdomadaire|cette\s+semaine|resume\s+semaine|semaine\s+en\s+cours)\b/iu', $lower)) {
            return ['action' => 'weekly_summary'];
        }

        // top depenses / grosses depenses
        if (preg_match('/\b(top\s+(?:\d{1,2}\s+)?depenses?|grosses?\s+depenses?|grandes?\s+depenses?|depenses?\s+importantes?|plus\s+grosses?\s+depenses?|plus\s+grandes?\s+depenses?)\b/iu', $lower)) {
            $limit = 5;
            if (preg_match('/top\s+(\d{1,2})\s+depenses?/iu', $lower, $m)) {
                $limit = min((int) $m[1], 10);
            }
            return ['action' => 'top_expenses', 'limit' => $limit];
        }

        return null;
    }

    private function executeCommand(array $command, AgentContext $context): AgentResult
    {
        $response = match ($command['action']) {
            'log_expense'     => $this->logExpense(
                $context->from,
                $command['amount'],
                $command['category'],
                $command['description'] ?? null
            ),
            'set_budget'      => $this->setBudget($context->from, $command['category'], $command['amount']),
            'delete_budget'   => $this->deleteBudget($context->from, $command['category']),
            'balance'         => $this->getBalance($context->from),
            'stats'           => $this->generateMonthlyReport($context->from),
            'alerts'          => $this->getAlerts($context->from),
            'history'         => $this->getHistory($context->from, $command['limit'] ?? 10),
            'delete_last'     => $this->deleteLastExpense($context->from),
            'projection'      => $this->getProjectionReport($context->from),
            'category_detail' => $this->getCategoryDetail($context->from, $command['category']),
            'weekly_summary'  => $this->getWeeklySummary($context->from),
            'top_expenses'    => $this->getTopExpenses($context->from, $command['limit'] ?? 5),
            'help'            => $this->getHelp(),
            default           => null,
        };

        if ($response) {
            $this->sendText($context->from, $response);
            $this->log($context, "Finance command: {$command['action']}", $command);
            return AgentResult::reply($response);
        }

        $help = $this->getHelp();
        $this->sendText($context->from, $help);
        return AgentResult::reply($help);
    }

    private function handleWithClaude(string $body, AgentContext $context, string $contextMemory): AgentResult
    {
        // Get current financial context
        $balance       = $this->getBalance($context->from);
        $topCategories = Expense::getTopCategories($context->from);

        $message  = "Message de l'utilisateur: \"{$body}\"\n\n";
        $message .= "ETAT FINANCIER ACTUEL:\n{$balance}\n\n";

        if (!empty($topCategories)) {
            $message .= "TOP CATEGORIES CE MOIS:\n";
            foreach ($topCategories as $cat) {
                $message .= "- {$cat['category']}: {$cat['total']}€ ({$cat['count']} depenses)\n";
            }
        }

        if ($contextMemory) {
            $message .= "\n{$contextMemory}\n";
        }

        $message .= "\nAnalyse la demande et reponds de facon utile.\n"
            . "Si l'utilisateur veut ENREGISTRER une depense, inclus sur une ligne separee: EXPENSE_LOG:montant|categorie|description\n"
            . "Si l'utilisateur veut DEFINIR un budget, inclus sur une ligne separee: BUDGET_SET:categorie|montant";

        $response = $this->claude->chat(
            $message,
            $this->resolveModel($context),
            $this->buildSystemPrompt()
        );

        if (!$response) {
            $response = $this->getHelp();
        }

        // Extract expense from Claude's response if identified
        if (preg_match('/EXPENSE_LOG:\s*(\d+[\.,]?\d*)\|([^|\n]+)\|([^\n]*)/i', $response, $m)) {
            $expenseAmount      = (float) str_replace(',', '.', $m[1]);
            $expenseCategory    = trim($m[2]);
            $expenseDescription = trim($m[3]) ?: null;

            if ($expenseAmount > 0 && $expenseCategory !== '') {
                $logResult = $this->logExpense(
                    $context->from,
                    $expenseAmount,
                    $expenseCategory,
                    $expenseDescription
                );
                $response = preg_replace('/EXPENSE_LOG:[^\n]*/m', '', $response);
                $response = trim($response) . "\n\n" . $logResult;
            }
        }

        // Extract budget definition from Claude's response if identified
        if (preg_match('/BUDGET_SET:\s*([^|\n]+)\|(\d+[\.,]?\d*)/i', $response, $m)) {
            $budgetCategory = trim($m[1]);
            $budgetAmount   = (float) str_replace(',', '.', $m[2]);

            if ($budgetAmount > 0 && $budgetCategory !== '') {
                $budgetResult = $this->setBudget($context->from, $budgetCategory, $budgetAmount);
                $response     = preg_replace('/BUDGET_SET:[^\n]*/m', '', $response);
                $response     = trim($response) . "\n\n" . $budgetResult;
            }
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Finance handled with Claude');

        return AgentResult::reply($response);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant financier intelligent et bienveillant integre a WhatsApp.

ROLE:
- Aider l'utilisateur a suivre ses depenses et budgets mensuels
- Donner des conseils financiers personnalises et actionables
- Detecter les anomalies de depenses (depenses inhabituellement elevees)
- Presenter les informations de facon claire et visuelle pour WhatsApp

FORMAT DE REPONSE:
- Utilise des emojis pertinents pour rendre les infos visuelles (💰 💳 📊 ⚠️ ✅ 🚨 📉 📈)
- Sois concis et actionnable (max 200 mots)
- Reponds TOUJOURS en francais

DETECTION DE DEPENSE:
Si l'utilisateur decrit une depense (ex: "j'ai achete des courses pour 45€", "taxi 12.50", "abonnement netflix 13.99"), extrais les infos et ajoute EXACTEMENT cette ligne (rien avant ni apres sur la meme ligne):
EXPENSE_LOG:montant|categorie|description

Exemples:
- "j'ai pris un taxi pour 15€" → EXPENSE_LOG:15|transport|taxi
- "courses au supermarche 87.50€" → EXPENSE_LOG:87.5|alimentation|courses supermarche
- "cinema avec des amis 22€" → EXPENSE_LOG:22|loisirs|cinema
- "loyer du mois 800€" → EXPENSE_LOG:800|logement|loyer mensuel
- "abonnement spotify 9.99" → EXPENSE_LOG:9.99|abonnements|spotify

DETECTION DE BUDGET:
Si l'utilisateur veut definir un budget mensuel pour une categorie, ajoute EXACTEMENT cette ligne:
BUDGET_SET:categorie|montant

Exemples:
- "je veux mettre 300€ pour l'alimentation" → BUDGET_SET:alimentation|300
- "limite transport a 200 euros par mois" → BUDGET_SET:transport|200

CATEGORIES SUGGEREES:
alimentation, transport, loisirs, sante, logement, shopping, restaurant, abonnements, education, autres

COMMANDES DISPONIBLES (rappel si l'utilisateur demande de l'aide):
- depense [montant] [categorie] [description] — enregistrer une depense
- budget [categorie] [montant] — definir un budget mensuel
- solde — voir solde et budgets
- stats — rapport mensuel
- historique [n] — dernieres depenses
- detail [categorie] — analyse d'une categorie
- projection — prevision fin de mois
- resume semaine — depenses de la semaine en cours
- top depenses — top 5 depenses individuelles du mois
- alertes — alertes de depassement
- supprimer derniere depense — annuler la derniere depense

CONSEILS:
- Si le budget est serre (>80%), propose des pistes d'economies concretes
- Si une depense semble anormalement elevee, signale-le avec ⚠️
- Si aucune depense ce mois, encourage l'utilisateur a commencer le suivi
- Ne genere PAS de EXPENSE_LOG si l'utilisateur pose juste une question
PROMPT;
    }

    private function logExpense(string $userPhone, float $amount, string $category, ?string $description): string
    {
        if ($amount <= 0) {
            return "❌ Le montant doit etre positif.";
        }

        if ($amount > 100000) {
            return "❌ Montant trop eleve ({$amount}€). Verifie la saisie.";
        }

        $category = mb_strtolower(trim($category));
        if ($category === '') {
            return "❌ La categorie ne peut pas etre vide.";
        }

        try {
            Expense::create([
                'user_phone'  => $userPhone,
                'amount'      => $amount,
                'category'    => $category,
                'description' => $description,
                'date'        => Carbon::now()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] logExpense failed: " . $e->getMessage());
            return "❌ Erreur lors de l'enregistrement de la depense. Reessaie.";
        }

        $monthlySpent = Expense::calculateMonthlySpent($userPhone, $category);
        $average      = Expense::getAverageForCategory($userPhone, $category);

        $response  = "✅ Depense enregistree !\n";
        $response .= "💳 *{$amount}€* en *{$category}*";
        if ($description) {
            $response .= " ({$description})";
        }
        $response .= "\n📅 Total {$category} ce mois: *{$monthlySpent}€*";

        // Anomaly check: only flag if single expense > 2x monthly average
        if ($average > 0 && $amount > ($average * 2)) {
            $anomaly = $this->detectAnomalies($userPhone, $category, $amount, $average);
            if ($anomaly) {
                $response .= "\n\n{$anomaly}";
            }
        }

        // Budget threshold check
        $budget = Budget::where('user_phone', $userPhone)->where('category', $category)->first();
        if ($budget) {
            $check = $budget->checkBudgetThreshold();
            if ($check['exceeded']) {
                $response .= "\n\n🚨 *Budget depasse !* {$check['spent']}€ / {$check['limit']}€ ({$check['percentage']}%)";
            } elseif ($check['threshold_reached']) {
                $response .= "\n\n⚠️ Attention: {$check['percentage']}% du budget *{$category}* utilise ({$check['remaining']}€ restants)";
            }
        }

        return $response;
    }

    private function setBudget(string $userPhone, string $category, float $amount): string
    {
        if ($amount <= 0) {
            return "❌ Le montant du budget doit etre positif.";
        }

        $category = mb_strtolower(trim($category));
        if ($category === '') {
            return "❌ La categorie ne peut pas etre vide.";
        }

        try {
            Budget::updateOrCreate(
                ['user_phone' => $userPhone, 'category' => $category],
                ['monthly_limit' => $amount]
            );

            // Auto-create alert at 80%
            DB::table('finances_alerts')->updateOrInsert(
                ['user_phone' => $userPhone, 'category' => $category],
                ['threshold_percentage' => 80, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] setBudget failed: " . $e->getMessage());
            return "❌ Erreur lors de la definition du budget. Reessaie.";
        }

        $spent      = Expense::calculateMonthlySpent($userPhone, $category);
        $remaining  = round($amount - $spent, 2);
        $percentage = $amount > 0 ? round(($spent / $amount) * 100, 1) : 0;

        $response  = "✅ Budget defini !\n";
        $response .= "📊 *{$category}*: {$amount}€/mois\n";
        $response .= "💰 Depense ce mois: {$spent}€ ({$percentage}%)\n";
        $response .= "💵 Restant: {$remaining}€\n";
        $response .= "🔔 Alerte auto a 80% activee";

        return $response;
    }

    private function deleteBudget(string $userPhone, string $category): string
    {
        $category = mb_strtolower(trim($category));

        $budget = Budget::where('user_phone', $userPhone)->where('category', $category)->first();
        if (!$budget) {
            return "❌ Aucun budget trouve pour la categorie *{$category}*.\n"
                . "_Liste tes budgets avec: solde_";
        }

        $limit = $budget->monthly_limit;

        try {
            $budget->delete();
            DB::table('finances_alerts')
                ->where('user_phone', $userPhone)
                ->where('category', $category)
                ->delete();
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] deleteBudget failed: " . $e->getMessage());
            return "❌ Erreur lors de la suppression du budget. Reessaie.";
        }

        return "🗑️ Budget *{$category}* supprime !\n"
            . "_(Limite etait: {$limit}€/mois)_\n"
            . "Alerte associee desactivee.";
    }

    private function getBalance(string $userPhone): string
    {
        $today      = Carbon::now();
        $totalSpent = Expense::calculateTotalMonthlySpent($userPhone);
        $budgets    = Budget::where('user_phone', $userPhone)->get()->keyBy('category');
        $month      = $today->translatedFormat('F Y');
        $dayOfMonth = $today->day;
        $daysInMonth = $today->daysInMonth;

        $response  = "💰 *Solde financier - {$month}*\n";
        $response .= "📅 Jour {$dayOfMonth}/{$daysInMonth}\n\n";
        $response .= "📉 Total depenses: *{$totalSpent}€*\n";

        if ($budgets->isNotEmpty()) {
            $totalBudget    = $budgets->sum('monthly_limit');
            $totalRemaining = round($totalBudget - $totalSpent, 2);
            $response .= "📊 Budget total: {$totalBudget}€\n";
            $response .= "💵 Restant global: *{$totalRemaining}€*\n\n";

            foreach ($budgets as $budget) {
                $check = $budget->checkBudgetThreshold();
                $bar   = $this->buildProgressBar($check['percentage']);
                $icon  = $check['exceeded'] ? '🚨' : ($check['threshold_reached'] ? '⚠️' : '✅');
                $response .= "{$icon} *{$check['category']}*: {$check['spent']}€/{$check['limit']}€ {$bar}\n";
            }

            // Show categories with expenses but no budget
            $topCategories = Expense::getTopCategories($userPhone);
            $unbudgeted = array_filter($topCategories, fn($c) => !$budgets->has($c['category']));
            if (!empty($unbudgeted)) {
                $response .= "\n_Sans budget:_\n";
                foreach ($unbudgeted as $cat) {
                    $response .= "  💳 *{$cat['category']}*: {$cat['total']}€\n";
                }
            }
        } else {
            $response .= "\n_Aucun budget defini. Utilise 'budget [categorie] [montant]' pour en creer._";
        }

        return $response;
    }

    private function generateMonthlyReport(string $userPhone): string
    {
        $month         = Carbon::now();
        $totalSpent    = Expense::calculateTotalMonthlySpent($userPhone, $month);
        $topCategories = Expense::getTopCategories($userPhone, 5, $month);

        $response  = "📊 *Rapport mensuel - " . $month->translatedFormat('F Y') . "*\n\n";
        $response .= "💳 Total depenses: *{$totalSpent}€*\n";

        if (empty($topCategories)) {
            $response .= "\n_Aucune depense ce mois._\n";
            $response .= "Commence avec: *depense [montant] [categorie]*";
            return $response;
        }

        $response .= "\n📈 *Top categories:*\n";
        foreach ($topCategories as $i => $cat) {
            $percentage = $totalSpent > 0 ? round(($cat['total'] / $totalSpent) * 100, 1) : 0;
            $bar        = $this->buildProgressBar($percentage);
            $response  .= ($i + 1) . ". *{$cat['category']}*: {$cat['total']}€ ({$percentage}%) {$bar}\n";
        }

        // Budget comparison
        $budgets = Budget::where('user_phone', $userPhone)->get();
        if ($budgets->isNotEmpty()) {
            $response .= "\n📋 *Budgets:*\n";
            foreach ($budgets as $budget) {
                $check    = $budget->checkBudgetThreshold();
                $icon     = $check['exceeded'] ? '🚨' : ($check['threshold_reached'] ? '⚠️' : '✅');
                $response .= "{$icon} {$check['category']}: {$check['spent']}€/{$check['limit']}€ ({$check['percentage']}%)\n";
            }
        }

        // Month-over-month comparison
        $lastMonth      = Carbon::now()->subMonth();
        $lastMonthSpent = Expense::calculateTotalMonthlySpent($userPhone, $lastMonth);
        if ($lastMonthSpent > 0) {
            $diff        = round($totalSpent - $lastMonthSpent, 2);
            $diffPercent = round(($diff / $lastMonthSpent) * 100, 1);
            $trend       = $diff > 0 ? "📈 +{$diff}€ (+{$diffPercent}%)" : "📉 {$diff}€ ({$diffPercent}%)";
            $response   .= "\n🔄 vs mois dernier: {$trend}";
        }

        // End-of-month projection
        $projection = $this->getMonthlyProjection($totalSpent);
        if ($projection !== null) {
            $response .= "\n🔮 Projection fin de mois: *~{$projection}€*";
        }

        return $response;
    }

    private function getProjectionReport(string $userPhone): string
    {
        $today       = Carbon::now();
        $totalSpent  = Expense::calculateTotalMonthlySpent($userPhone);
        $dayOfMonth  = $today->day;
        $daysInMonth = $today->daysInMonth;
        $daysLeft    = $daysInMonth - $dayOfMonth;

        $response  = "🔮 *Projection fin de mois - " . $today->translatedFormat('F Y') . "*\n\n";
        $response .= "📅 Jour {$dayOfMonth}/{$daysInMonth} ({$daysLeft} jours restants)\n";
        $response .= "💳 Depenses actuelles: *{$totalSpent}€*\n";

        if ($totalSpent <= 0) {
            $response .= "\n_Aucune depense enregistree ce mois._";
            return $response;
        }

        if ($dayOfMonth < 3) {
            $response .= "\n_Pas assez de donnees pour projeter (min 3 jours)._";
            return $response;
        }

        $dailyAverage = round($totalSpent / $dayOfMonth, 2);
        $projection   = round($dailyAverage * $daysInMonth, 2);

        $response .= "📊 Moyenne journaliere: {$dailyAverage}€/jour\n";
        $response .= "🎯 Projection fin de mois: *~{$projection}€*\n";

        // Compare with total budget
        $totalBudget = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');
        if ($totalBudget > 0) {
            if ($projection > $totalBudget) {
                $overrun  = round($projection - $totalBudget, 2);
                $response .= "\n🚨 *Depassement prevu de {$overrun}€* sur le budget total ({$totalBudget}€)";
            } else {
                $margin   = round($totalBudget - $projection, 2);
                $response .= "\n✅ Dans le budget ({$totalBudget}€) — marge prevue: *{$margin}€*";
            }
        }

        return $response;
    }

    private function getCategoryDetail(string $userPhone, string $category): string
    {
        $category = mb_strtolower(trim($category));
        $month    = Carbon::now();

        // Current month stats
        $monthlySpent = Expense::calculateMonthlySpent($userPhone, $category, $month);
        $average3m    = Expense::getAverageForCategory($userPhone, $category, 3);

        // Total count for the month
        $monthlyCount = Expense::where('user_phone', $userPhone)
            ->where('category', $category)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->count();

        // Recent expenses for this category (last 5)
        $recentExpenses = Expense::where('user_phone', $userPhone)
            ->where('category', $category)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($monthlySpent <= 0 && $recentExpenses->isEmpty()) {
            return "📂 *Detail: {$category}*\n\n_Aucune depense en {$category} ce mois._\n"
                . "Enregistre une depense: *depense [montant] {$category}*";
        }

        $shownCount = $recentExpenses->count();
        $response = "📂 *Detail: {$category}* — " . $month->translatedFormat('F Y') . "\n\n";
        $response .= "💳 Total ce mois: *{$monthlySpent}€* ({$monthlyCount} depense(s))\n";
        $response .= "📊 Moy. mensuelle (3 mois): {$average3m}€\n";

        // Trend
        $lastMonthSpent = Expense::calculateMonthlySpent($userPhone, $category, Carbon::now()->subMonth());
        if ($lastMonthSpent > 0) {
            $diff  = round($monthlySpent - $lastMonthSpent, 2);
            $trend = $diff > 0 ? "📈 +{$diff}€ vs mois dernier" : "📉 {$diff}€ vs mois dernier";
            $response .= "{$trend}\n";
        }

        // Budget for this category
        $budget = Budget::where('user_phone', $userPhone)->where('category', $category)->first();
        if ($budget) {
            $check = $budget->checkBudgetThreshold();
            $bar   = $this->buildProgressBar($check['percentage']);
            $icon  = $check['exceeded'] ? '🚨' : ($check['threshold_reached'] ? '⚠️' : '✅');
            $response .= "{$icon} Budget: {$check['spent']}€/{$check['limit']}€ {$bar}\n";
        }

        // Recent entries (up to 5 most recent)
        if ($recentExpenses->isNotEmpty()) {
            $suffix = $monthlyCount > 5 ? " _(sur {$monthlyCount})_" : '';
            $response .= "\n📋 *{$shownCount} derniere(s) depense(s) ce mois{$suffix}:*\n";
            foreach ($recentExpenses as $expense) {
                $date = Carbon::parse($expense->date);
                $dateStr = $date->isToday()
                    ? "Aujourd'hui"
                    : ($date->isYesterday() ? 'Hier' : $date->translatedFormat('d M'));
                $desc = $expense->description ? " — {$expense->description}" : '';
                $response .= "  {$dateStr}: {$expense->amount}€{$desc}\n";
            }
        }

        return $response;
    }

    private function getMonthlyProjection(float $spentSoFar): ?float
    {
        $today       = Carbon::now();
        $daysInMonth = $today->daysInMonth;
        $dayOfMonth  = $today->day;

        // Need at least 3 days of data to project
        if ($dayOfMonth < 3 || $spentSoFar <= 0) {
            return null;
        }

        $dailyAverage = $spentSoFar / $dayOfMonth;
        return round($dailyAverage * $daysInMonth, 2);
    }

    private function getHistory(string $userPhone, int $limit = 10): string
    {
        $limit    = max(1, min($limit, 20));
        $expenses = Expense::where('user_phone', $userPhone)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($expenses->isEmpty()) {
            return "📋 *Historique des depenses*\n\n_Aucune depense enregistree._\n"
                . "Commence avec: *depense [montant] [categorie]*";
        }

        $actualCount = $expenses->count();
        $title = $actualCount < $limit ? "{$actualCount} depense(s)" : "{$limit} dernieres depenses";
        $response = "📋 *{$title}*\n\n";

        $currentDate = null;
        foreach ($expenses as $expense) {
            $date    = Carbon::parse($expense->date);
            $dateStr = $date->isToday()
                ? "Aujourd'hui"
                : ($date->isYesterday() ? 'Hier' : $date->translatedFormat('d M'));

            if ($currentDate !== $dateStr) {
                $response   .= "\n📅 *{$dateStr}*\n";
                $currentDate = $dateStr;
            }

            $desc = $expense->description ? " — {$expense->description}" : '';
            $response .= "  💳 {$expense->amount}€ {$expense->category}{$desc}\n";
        }

        $monthTotal = Expense::calculateTotalMonthlySpent($userPhone);
        $response  .= "\n💰 Total ce mois: *{$monthTotal}€*";

        return $response;
    }

    private function deleteLastExpense(string $userPhone): string
    {
        $last = Expense::where('user_phone', $userPhone)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return "❌ Aucune depense a supprimer.";
        }

        $amount      = $last->amount;
        $category    = $last->category;
        $description = $last->description ? " ({$last->description})" : '';
        $date        = Carbon::parse($last->date)->translatedFormat('d M Y');

        try {
            $last->delete();
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] deleteLastExpense failed: " . $e->getMessage());
            return "❌ Erreur lors de la suppression. Reessaie.";
        }

        $response  = "🗑️ Depense supprimee !\n";
        $response .= "💳 {$amount}€ en *{$category}*{$description}\n";
        $response .= "📅 Date: {$date}\n\n";
        $response .= "💰 Total ce mois maintenant: *" . Expense::calculateTotalMonthlySpent($userPhone) . "€*";

        return $response;
    }

    private function detectAnomalies(string $userPhone, string $category, float $amount, float $average): ?string
    {
        if ($average <= 0) return null;

        // Single expense is more than 2x the monthly average
        if ($amount > ($average * 2)) {
            return "⚠️ *Depense inhabituelle* : {$amount}€ en {$category} (moyenne mensuelle: {$average}€)";
        }

        return null;
    }

    private function getAlerts(string $userPhone): string
    {
        $budgets = Budget::where('user_phone', $userPhone)->get();

        if ($budgets->isEmpty()) {
            return "🔔 *Alertes financieres*\n\n_Aucun budget defini. Cree un budget pour activer les alertes._\n"
                . "Exemple: *budget alimentation 300*";
        }

        $response  = "🔔 *Alertes financieres*\n\n";
        $hasAlerts = false;

        foreach ($budgets as $budget) {
            $check = $budget->checkBudgetThreshold();

            if ($check['exceeded']) {
                $response  .= "🚨 *{$check['category']}*: DEPASSE ! {$check['spent']}€/{$check['limit']}€ ({$check['percentage']}%)\n";
                $hasAlerts  = true;
            } elseif ($check['threshold_reached']) {
                $response  .= "⚠️ *{$check['category']}*: {$check['percentage']}% utilise — {$check['remaining']}€ restants\n";
                $hasAlerts  = true;
            } else {
                $response .= "✅ *{$check['category']}*: {$check['percentage']}% — {$check['remaining']}€ restants\n";
            }
        }

        if (!$hasAlerts) {
            $response .= "\n✨ Tout va bien ! Aucun budget en alerte.";
        }

        return $response;
    }

    private function getHelp(): string
    {
        return "💰 *Aide Finance*\n\n"
            . "📥 *Enregistrer une depense:*\n"
            . "  `depense 45 alimentation courses`\n"
            . "  `ajouter depense 12.50 transport taxi`\n\n"
            . "📊 *Budgets:*\n"
            . "  `budget alimentation 300` — definir\n"
            . "  `supprimer budget transport` — supprimer\n\n"
            . "📋 *Consulter:*\n"
            . "  `solde` — solde et budgets\n"
            . "  `stats` — rapport mensuel + projection\n"
            . "  `alertes` — alertes de depassement\n"
            . "  `historique` — 10 dernieres depenses\n"
            . "  `historique 5` — 5 dernieres depenses\n"
            . "  `detail alimentation` — analyse d'une categorie\n"
            . "  `projection` — prevision fin de mois\n"
            . "  `resume semaine` — depenses de la semaine\n"
            . "  `top depenses` — top 5 depenses du mois\n\n"
            . "🗑️ *Annuler:*\n"
            . "  `supprimer derniere depense`\n\n"
            . "💬 _Tu peux aussi parler naturellement et je comprendrai !_";
    }

    private function getWeeklySummary(string $userPhone): string
    {
        $today       = Carbon::now();
        $startOfWeek = $today->copy()->startOfWeek(Carbon::MONDAY);
        $daysElapsed = $startOfWeek->diffInDays($today) + 1;

        $categories = Expense::where('user_phone', $userPhone)
            ->whereBetween('date', [$startOfWeek->toDateString(), $today->toDateString()])
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $totalWeek = round((float) $categories->sum('total'), 2);
        $dailyAvg  = $daysElapsed > 0 ? round($totalWeek / $daysElapsed, 2) : 0;

        $weekLabel = $startOfWeek->translatedFormat('d M') . ' - ' . $today->translatedFormat('d M');
        $response  = "📅 *Resume semaine* ({$weekLabel})\n";
        $response .= "Jour " . $today->translatedFormat('l') . " — {$daysElapsed}/7 jour(s)\n\n";
        $response .= "💳 Total: *{$totalWeek}€*\n";
        $response .= "📊 Moyenne: {$dailyAvg}€/jour\n";

        if ($categories->isEmpty()) {
            $response .= "\n_Aucune depense cette semaine._";
            return $response;
        }

        $response .= "\n📈 *Par categorie:*\n";
        foreach ($categories as $cat) {
            $pct      = $totalWeek > 0 ? round(($cat['total'] / $totalWeek) * 100, 1) : 0;
            $response .= "  💳 *{$cat['category']}*: {$cat['total']}€ ({$cat['count']}x) — {$pct}%\n";
        }

        // Compare with same period last week
        $lastWeekStart = $startOfWeek->copy()->subWeek();
        $lastWeekEnd   = $today->copy()->subWeek();
        $lastWeekTotal = round((float) Expense::where('user_phone', $userPhone)
            ->whereBetween('date', [$lastWeekStart->toDateString(), $lastWeekEnd->toDateString()])
            ->sum('amount'), 2);

        if ($lastWeekTotal > 0) {
            $diff  = round($totalWeek - $lastWeekTotal, 2);
            $trend = $diff > 0 ? "📈 +{$diff}€ vs sem. precedente" : "📉 {$diff}€ vs sem. precedente";
            $response .= "\n{$trend}";
        }

        return $response;
    }

    private function getTopExpenses(string $userPhone, int $limit = 5): string
    {
        $limit    = max(1, min($limit, 10));
        $month    = Carbon::now();
        $expenses = Expense::where('user_phone', $userPhone)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->orderByDesc('amount')
            ->limit($limit)
            ->get();

        $response = "🏆 *Top {$limit} depenses - " . $month->translatedFormat('F Y') . "*\n\n";

        if ($expenses->isEmpty()) {
            $response .= "_Aucune depense ce mois._\n";
            $response .= "Commence avec: *depense [montant] [categorie]*";
            return $response;
        }

        foreach ($expenses as $i => $expense) {
            $date    = Carbon::parse($expense->date);
            $dateStr = $date->isToday()
                ? "Aujourd'hui"
                : ($date->isYesterday() ? 'Hier' : $date->translatedFormat('d M'));
            $desc     = $expense->description ? " — {$expense->description}" : '';
            $response .= ($i + 1) . ". *{$expense->amount}€* {$expense->category} ({$dateStr}){$desc}\n";
        }

        $totalSpent = Expense::calculateTotalMonthlySpent($userPhone);
        $topTotal   = round((float) $expenses->sum('amount'), 2);
        $pct        = $totalSpent > 0 ? round(($topTotal / $totalSpent) * 100, 1) : 0;
        $response  .= "\n_Ces {$expenses->count()} depenses = {$topTotal}€ ({$pct}% du total mois)_";

        return $response;
    }

    private function buildProgressBar(float $percentage): string
    {
        $filled = (int) round(min($percentage, 100) / 10);
        $empty  = 10 - $filled;
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
