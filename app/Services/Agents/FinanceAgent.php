<?php

namespace App\Services\Agents;

use App\Models\Budget;
use App\Models\Expense;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceAgent extends BaseAgent
{
    public function name(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Agent de gestion financiere personnelle. Suivi des depenses par categorie, definition de budgets mensuels, alertes de depassement, rapports et statistiques, detection d\'anomalies de depenses.';
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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
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
            '/\bfinance|financier|financiere\b/i',
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

        // ajout depense [montant] [categorie] [description]
        if (preg_match('/(?:ajout(?:e|er)?|add|log)\s*(?:depense|expense)?\s*(\d+[\.,]?\d*)\s*€?\s+(\S+)\s*(.*)/iu', $lower, $m)) {
            return [
                'action' => 'log_expense',
                'amount' => (float) str_replace(',', '.', $m[1]),
                'category' => $m[2],
                'description' => trim($m[3]) ?: null,
            ];
        }

        // depense [montant] [categorie] [description] (shorthand)
        if (preg_match('/(?:depense|spent|paye|achete)\s+(\d+[\.,]?\d*)\s*€?\s+(?:en\s+|pour\s+)?(\S+)\s*(.*)/iu', $lower, $m)) {
            return [
                'action' => 'log_expense',
                'amount' => (float) str_replace(',', '.', $m[1]),
                'category' => $m[2],
                'description' => trim($m[3]) ?: null,
            ];
        }

        // budget [categorie] [montant]
        if (preg_match('/budget\s+(\S+)\s+(\d+[\.,]?\d*)\s*€?/iu', $lower, $m)) {
            return [
                'action' => 'set_budget',
                'category' => $m[1],
                'amount' => (float) str_replace(',', '.', $m[2]),
            ];
        }

        // solde / balance
        if (preg_match('/\b(solde|balance|reste|remaining|bilan)\b/iu', $lower)) {
            return ['action' => 'balance'];
        }

        // statistiques / stats
        if (preg_match('/\b(statistiques?|stats?|rapport|report)\b/iu', $lower)) {
            return ['action' => 'stats'];
        }

        // alertes
        if (preg_match('/\b(alertes?|alerts?)\b/iu', $lower)) {
            return ['action' => 'alerts'];
        }

        return null;
    }

    private function executeCommand(array $command, AgentContext $context): AgentResult
    {
        $response = match ($command['action']) {
            'log_expense' => $this->logExpense(
                $context->from,
                $command['amount'],
                $command['category'],
                $command['description'] ?? null
            ),
            'set_budget' => $this->setBudget($context->from, $command['category'], $command['amount']),
            'balance' => $this->getBalance($context->from),
            'stats' => $this->generateMonthlyReport($context->from),
            'alerts' => $this->getAlerts($context->from),
            default => null,
        };

        if ($response) {
            $this->sendText($context->from, $response);
            $this->log($context, "Finance command: {$command['action']}", $command);
            return AgentResult::reply($response);
        }

        return AgentResult::reply('Commande non reconnue. Essaie: depense [montant] [categorie], budget [categorie] [montant], solde, stats, alertes');
    }

    private function handleWithClaude(string $body, AgentContext $context, string $contextMemory): AgentResult
    {
        // Get current financial context
        $balance = $this->getBalance($context->from);
        $topCategories = Expense::getTopCategories($context->from);

        $message = "Message de l'utilisateur: \"{$body}\"\n\n";
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

        $message .= "\nAnalyse la demande et reponds de facon utile. Si l'utilisateur veut ajouter une depense, extrais le montant, la categorie et la description.";

        $response = $this->claude->chat(
            $message,
            $this->resolveModel($context),
            $this->buildSystemPrompt()
        );

        if (!$response) {
            $response = "Je n'ai pas pu traiter ta demande financiere. Essaie avec:\n"
                . "- *depense [montant] [categorie] [description]*\n"
                . "- *budget [categorie] [montant]*\n"
                . "- *solde* / *stats* / *alertes*";
        }

        // Try to extract expense from Claude's response if it identified one
        if (preg_match('/EXPENSE_LOG:\s*(\d+[\.,]?\d*)\|([^|]+)\|(.+)/i', $response, $m)) {
            $logResult = $this->logExpense(
                $context->from,
                (float) str_replace(',', '.', $m[1]),
                trim($m[2]),
                trim($m[3])
            );
            $response = preg_replace('/EXPENSE_LOG:.*$/m', '', $response);
            $response = trim($response) . "\n\n" . $logResult;
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Finance handled with Claude');

        return AgentResult::reply($response);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant financier intelligent et bienveillant.

ROLE:
- Aider l'utilisateur a suivre ses depenses et budgets
- Donner des conseils financiers personnalises
- Detecter les anomalies de depenses
- Presenter les informations de facon claire et visuelle

FORMAT DE REPONSE:
- Utilise des emojis pour rendre les infos visuelles (💰 💳 📊 ⚠️ ✅)
- Sois concis et actionnable
- Si l'utilisateur decrit une depense sans utiliser la syntaxe formelle, extrais les infos et ajoute sur une ligne separee: EXPENSE_LOG:montant|categorie|description

CATEGORIES SUGGEREES:
alimentation, transport, loisirs, sante, logement, shopping, restaurant, abonnements, education, autres

REGLES:
- Reponds en francais
- Max 200 mots
- Si tu detectes une depense anormale (bien au-dessus de la moyenne), signale-le avec ⚠️
- Propose des economies si le budget est serre
PROMPT;
    }

    private function logExpense(string $userPhone, float $amount, string $category, ?string $description): string
    {
        $category = mb_strtolower(trim($category));

        $expense = Expense::create([
            'user_phone' => $userPhone,
            'amount' => $amount,
            'category' => $category,
            'description' => $description,
            'date' => Carbon::now()->toDateString(),
        ]);

        $monthlySpent = Expense::calculateMonthlySpent($userPhone, $category);
        $average = Expense::getAverageForCategory($userPhone, $category);

        $response = "✅ Depense enregistree !\n";
        $response .= "💳 {$amount}€ en *{$category}*";
        if ($description) {
            $response .= " ({$description})";
        }
        $response .= "\n📅 Total {$category} ce mois: {$monthlySpent}€";

        // Check anomaly
        if ($average > 0 && $amount > ($average * 0.5)) {
            $anomaly = $this->detectAnomalies($userPhone, $category, $amount, $average);
            if ($anomaly) {
                $response .= "\n\n{$anomaly}";
            }
        }

        // Check budget threshold
        $budget = Budget::where('user_phone', $userPhone)->where('category', $category)->first();
        if ($budget) {
            $check = $budget->checkBudgetThreshold();
            if ($check['exceeded']) {
                $response .= "\n\n🚨 *Budget depasse !* {$check['spent']}€ / {$check['limit']}€ ({$check['percentage']}%)";
            } elseif ($check['threshold_reached']) {
                $response .= "\n\n⚠️ Attention: {$check['percentage']}% du budget {$category} utilise ({$check['remaining']}€ restants)";
            }
        }

        return $response;
    }

    private function setBudget(string $userPhone, string $category, float $amount): string
    {
        $category = mb_strtolower(trim($category));

        Budget::updateOrCreate(
            ['user_phone' => $userPhone, 'category' => $category],
            ['monthly_limit' => $amount]
        );

        // Auto-create alert at 80%
        DB::table('finances_alerts')->updateOrInsert(
            ['user_phone' => $userPhone, 'category' => $category],
            ['threshold_percentage' => 80, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $spent = Expense::calculateMonthlySpent($userPhone, $category);
        $remaining = round($amount - $spent, 2);
        $percentage = $amount > 0 ? round(($spent / $amount) * 100, 1) : 0;

        $response = "✅ Budget defini !\n";
        $response .= "📊 *{$category}*: {$amount}€/mois\n";
        $response .= "💰 Depense ce mois: {$spent}€ ({$percentage}%)\n";
        $response .= "💵 Restant: {$remaining}€\n";
        $response .= "🔔 Alerte auto a 80% activee";

        return $response;
    }

    private function getBalance(string $userPhone): string
    {
        $totalSpent = Expense::calculateTotalMonthlySpent($userPhone);
        $budgets = Budget::where('user_phone', $userPhone)->get();
        $month = Carbon::now()->translatedFormat('F Y');

        $response = "💰 *Solde financier - {$month}*\n\n";
        $response .= "📉 Total depenses: {$totalSpent}€\n";

        if ($budgets->isNotEmpty()) {
            $totalBudget = $budgets->sum('monthly_limit');
            $totalRemaining = round($totalBudget - $totalSpent, 2);
            $response .= "📊 Budget total: {$totalBudget}€\n";
            $response .= "💵 Restant global: {$totalRemaining}€\n\n";

            foreach ($budgets as $budget) {
                $check = $budget->checkBudgetThreshold();
                $bar = $this->buildProgressBar($check['percentage']);
                $icon = $check['exceeded'] ? '🚨' : ($check['threshold_reached'] ? '⚠️' : '✅');
                $response .= "{$icon} *{$check['category']}*: {$check['spent']}€/{$check['limit']}€ {$bar}\n";
            }
        } else {
            $response .= "\n_Aucun budget defini. Utilise 'budget [categorie] [montant]' pour en creer._";
        }

        return $response;
    }

    private function generateMonthlyReport(string $userPhone): string
    {
        $month = Carbon::now();
        $totalSpent = Expense::calculateTotalMonthlySpent($userPhone, $month);
        $topCategories = Expense::getTopCategories($userPhone, 5, $month);

        $response = "📊 *Rapport mensuel - " . $month->translatedFormat('F Y') . "*\n\n";
        $response .= "💳 Total depenses: {$totalSpent}€\n\n";

        if (empty($topCategories)) {
            $response .= "_Aucune depense ce mois._\n";
            $response .= "Commence avec: *depense [montant] [categorie]*";
            return $response;
        }

        $response .= "📈 *Top categories:*\n";
        foreach ($topCategories as $i => $cat) {
            $percentage = $totalSpent > 0 ? round(($cat['total'] / $totalSpent) * 100, 1) : 0;
            $bar = $this->buildProgressBar($percentage);
            $response .= ($i + 1) . ". *{$cat['category']}*: {$cat['total']}€ ({$percentage}%) {$bar}\n";
        }

        // Budget comparison
        $budgets = Budget::where('user_phone', $userPhone)->get();
        if ($budgets->isNotEmpty()) {
            $response .= "\n📋 *Budgets:*\n";
            foreach ($budgets as $budget) {
                $check = $budget->checkBudgetThreshold();
                $icon = $check['exceeded'] ? '🚨' : ($check['threshold_reached'] ? '⚠️' : '✅');
                $response .= "{$icon} {$check['category']}: {$check['spent']}€/{$check['limit']}€ ({$check['percentage']}%)\n";
            }
        }

        // Month-over-month comparison
        $lastMonth = Carbon::now()->subMonth();
        $lastMonthSpent = Expense::calculateTotalMonthlySpent($userPhone, $lastMonth);
        if ($lastMonthSpent > 0) {
            $diff = round($totalSpent - $lastMonthSpent, 2);
            $diffPercent = round(($diff / $lastMonthSpent) * 100, 1);
            $trend = $diff > 0 ? "📈 +{$diff}€ (+{$diffPercent}%)" : "📉 {$diff}€ ({$diffPercent}%)";
            $response .= "\n🔄 vs mois dernier: {$trend}";
        }

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

        $response = "🔔 *Alertes financieres*\n\n";
        $hasAlerts = false;

        foreach ($budgets as $budget) {
            $check = $budget->checkBudgetThreshold();

            if ($check['exceeded']) {
                $response .= "🚨 *{$check['category']}*: DEPASSE ! {$check['spent']}€/{$check['limit']}€ ({$check['percentage']}%)\n";
                $hasAlerts = true;
            } elseif ($check['threshold_reached']) {
                $response .= "⚠️ *{$check['category']}*: {$check['percentage']}% utilise — {$check['remaining']}€ restants\n";
                $hasAlerts = true;
            } else {
                $response .= "✅ *{$check['category']}*: {$check['percentage']}% — {$check['remaining']}€ restants\n";
            }
        }

        if (!$hasAlerts) {
            $response .= "\n✨ Tout va bien ! Aucun budget en alerte.";
        }

        return $response;
    }

    private function buildProgressBar(float $percentage): string
    {
        $filled = (int) round(min($percentage, 100) / 10);
        $empty = 10 - $filled;
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
