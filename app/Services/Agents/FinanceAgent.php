<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
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
        return 'Agent de gestion financiere personnelle. Suivi des depenses par categorie, definition et suppression de budgets mensuels, alertes de depassement, historique, rapports, projections de fin de mois, detail par categorie, detection d\'anomalies, comparaison mensuelle et recherche dans les depenses.';
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
            'comparer mois', 'comparaison mois', 'compare mois', 'bilan comparatif', 'evolution depenses',
            'chercher depense', 'rechercher depense', 'trouver depense', 'search expense',
            'tendance', 'trend', '6 mois', 'evolution mensuelle', 'historique mensuel', 'courbe depenses',
            'recurrent', 'recurrents', 'recurrentes', 'abonnements actifs', 'depenses recurrentes', 'recurring',
            'budget journalier', 'budget du jour', 'combien par jour', 'par jour', 'disponible jour', 'quota journalier',
            'export', 'exporter', 'liste complete', 'tout le mois', 'toutes depenses', 'toutes les depenses',
            'epargne', 'economies', 'objectif epargne', 'objectif savings', 'bilan epargne', 'objectif mensuel',
            'modifier depense', 'corriger depense', 'modifier derniere', 'corriger derniere', 'edit expense',
        ];
    }

    public function version(): string
    {
        return '1.7.0';
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
            '/\b(comparer?\s+mois|comparaison|bilan\s+comparatif|evolution\s+depenses?)\b/iu',
            '/\b(chercher?|rechercher?|trouver|search)\s+\S+/iu',
            '/\b(tendance|trend|6\s+mois|evolution\s+mensuelle|historique\s+mensuel)\b/iu',
            '/\b(recurrents?|recurrentes?|abonnements?\s+actifs?|depenses?\s+recurrentes?)\b/iu',
            '/\b(budget\s+journalier|budget\s+du\s+jour|combien\s+par\s+jour|disponible\s+jour|quota\s+journalier)\b/iu',
            '/\b(export(?:er)?|liste\s+compl[eè]te|tout\s+le\s+mois|toutes?\s+(?:les\s+)?depenses?)\b/iu',
            '/\b(epargne|economies|objectif\s+epargne|bilan\s+epargne|objectif\s+mensuel)\b/iu',
            '/\b(modifier|corriger)\s+(depense|derniere|last)\b/iu',
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

        // supprimer / annuler la derniere depense (must be before edit_last and history)
        if (preg_match('/\b(supprimer|annuler|delete|undo)\b.*(depense|expense|derniere|last)/iu', $lower)) {
            return ['action' => 'delete_last'];
        }

        // modifier / corriger derniere depense [montant] [categorie?] (must be before history)
        if (preg_match('/\b(modifier|corriger|edit)\b.{0,15}\b(depense|derniere|last)\b\s+(\d+[\.,]?\d*)\s*€?\s*(\S+)?\s*(.*)/iu', $lower, $m)) {
            return [
                'action'      => 'edit_last',
                'amount'      => (float) str_replace(',', '.', $m[3]),
                'category'    => $m[4] ? trim($m[4]) : null,
                'description' => $m[5] ? trim($m[5]) : null,
            ];
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

        // objectif epargne [montant] — set savings goal (must be before savings_status)
        if (preg_match('/\b(objectif\s+(?:epargne|savings?|mensuel)|epargne\s+(?:objectif|goal))\s+(\d+[\.,]?\d*)/iu', $lower, $m)) {
            return ['action' => 'set_savings_goal', 'amount' => (float) str_replace(',', '.', $m[2])];
        }

        // bilan epargne / epargne stats / epargne — savings status (must be before balance)
        if (preg_match('/\b(bilan\s+epargne|epargne\s+(?:stats?|bilan|status)|objectif\s+epargne|epargne)\b/iu', $lower)) {
            return ['action' => 'savings_status'];
        }

        // solde / balance (bilan seul matches here, not bilan epargne which is caught above)
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

        // comparer mois / comparaison / evolution depenses
        if (preg_match('/\b(comparer?\s+mois|comparaison|bilan\s+comparatif|evolution\s+depenses?|compare\s+months?)\b/iu', $lower)) {
            return ['action' => 'compare_months'];
        }

        // chercher / rechercher [terme]
        if (preg_match('/\b(?:chercher?|rechercher?|trouver|search)\s+(.+)/iu', $lower, $m)) {
            return ['action' => 'search_expenses', 'query' => trim($m[1])];
        }

        // tendance / trend / 6 mois / evolution mensuelle
        if (preg_match('/\b(tendance|trend|6\s+mois|evolution\s+mensuelle|historique\s+mensuel|courbe\s+depenses?)\b/iu', $lower)) {
            return ['action' => 'monthly_trend'];
        }

        // depenses recurrentes / recurrents / abonnements actifs
        if (preg_match('/\b(recurrents?|recurrentes?|abonnements?\s+actifs?|depenses?\s+recurrentes?|recurring)\b/iu', $lower)) {
            return ['action' => 'recurring_expenses'];
        }

        // budget journalier / combien par jour
        if (preg_match('/\b(budget\s+journalier|budget\s+du\s+jour|combien\s+par\s+jour|disponible\s+jour|quota\s+journalier|par\s+jour)\b/iu', $lower)) {
            return ['action' => 'daily_budget'];
        }

        // export / liste complete / toutes les depenses
        if (preg_match('/\b(export(?:er)?|liste\s+compl[eè]te|tout\s+le\s+mois|toutes?\s+(?:les\s+)?depenses?)\b/iu', $lower)) {
            return ['action' => 'export_month'];
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
            'compare_months'  => $this->compareMonths($context->from),
            'search_expenses'    => $this->searchExpenses($context->from, $command['query'] ?? ''),
            'monthly_trend'      => $this->getMonthlyTrend($context->from),
            'recurring_expenses' => $this->getRecurringExpenses($context->from),
            'daily_budget'       => $this->getDailyBudget($context->from),
            'export_month'       => $this->exportMonth($context->from),
            'set_savings_goal'   => $this->setSavingsGoal($context->from, $command['amount']),
            'savings_status'     => $this->getSavingsStatus($context->from),
            'edit_last'          => $this->editLastExpense(
                $context->from,
                $command['amount'],
                $command['category'] ?? null,
                $command['description'] ?? null
            ),
            'help'               => $this->getHelp(),
            default              => null,
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

        if (!$response || trim($response) === '') {
            $fallback = "❓ Je n'ai pas compris ta demande. Tape *aide finance* pour voir les commandes disponibles.";
            $this->sendText($context->from, $fallback);
            return AgentResult::reply($fallback);
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
- modifier derniere [montant] [categorie] — corriger la derniere depense
- budget [categorie] [montant] — definir un budget mensuel
- solde — voir solde et budgets
- stats — rapport mensuel avec moyenne journaliere
- historique [n] — dernieres depenses
- detail [categorie] — analyse d'une categorie
- projection — prevision fin de mois
- resume semaine — depenses de la semaine en cours
- top depenses — top 5 depenses individuelles du mois
- comparer mois — comparaison mois actuel vs mois precedent
- chercher [terme] — rechercher dans les depenses
- tendance — evolution des depenses sur 6 mois
- recurrents — depenses et abonnements recurrents detectes
- budget journalier — budget disponible par jour pour le reste du mois
- export — liste complete de toutes les depenses du mois
- alertes — alertes de depassement
- objectif epargne [montant] — definir un objectif d'epargne mensuel
- epargne — voir le bilan d'epargne (budget - depenses)
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

        if (mb_strlen($category) > 30) {
            return "❌ Le nom de categorie est trop long (max 30 caracteres).";
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

        // Today's spending total
        $todayTotal = round((float) Expense::where('user_phone', $userPhone)
            ->whereDate('date', Carbon::now()->toDateString())
            ->sum('amount'), 2);

        $response  = "✅ Depense enregistree !\n";
        $response .= "💳 *{$amount}€* en *{$category}*";
        if ($description) {
            $response .= " ({$description})";
        }
        $response .= "\n📅 Total {$category} ce mois: *{$monthlySpent}€*";
        if ($todayTotal > $amount) {
            $response .= "\n💡 Aujourd'hui total: *{$todayTotal}€*";
        }

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
            $response .= "💵 Restant global: *{$totalRemaining}€*\n";
            if ($totalRemaining > 0 && $daysInMonth > $dayOfMonth) {
                $daysLeft      = $daysInMonth - $dayOfMonth;
                $dailyAllowance = round($totalRemaining / $daysLeft, 2);
                $response .= "💡 Budget/jour: *{$dailyAllowance}€* ({$daysLeft}j restants)\n";
            }
            $response .= "\n";

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

        $dayOfMonth   = $month->day;
        $dailyAverage = $dayOfMonth > 0 && $totalSpent > 0 ? round($totalSpent / $dayOfMonth, 2) : 0;

        $response  = "📊 *Rapport mensuel - " . $month->translatedFormat('F Y') . "*\n";
        $response .= "📅 Jour {$dayOfMonth}/{$month->daysInMonth}\n\n";
        $response .= "💳 Total depenses: *{$totalSpent}€*\n";
        if ($dailyAverage > 0) {
            $response .= "📊 Moyenne journaliere: {$dailyAverage}€/jour\n";
        }

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

        // Projection-based warning
        $totalSpent  = Expense::calculateTotalMonthlySpent($userPhone);
        $dayOfMonth  = Carbon::now()->day;
        $daysInMonth = Carbon::now()->daysInMonth;
        if ($totalSpent > 0 && $dayOfMonth >= 3) {
            $projectedTotal = round(($totalSpent / $dayOfMonth) * $daysInMonth, 2);
            $totalBudget    = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');
            if ($totalBudget > 0 && $projectedTotal > $totalBudget) {
                $overrun    = round($projectedTotal - $totalBudget, 2);
                $response  .= "\n\n🔮 *Projection fin de mois: ~{$projectedTotal}€*";
                $response  .= "\n⚠️ Depassement budget projete: +{$overrun}€";
            }
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
            . "🎯 *Epargne:*\n"
            . "  `objectif epargne 500` — fixer un objectif\n"
            . "  `epargne` — voir ton bilan d'epargne\n\n"
            . "📋 *Consulter:*\n"
            . "  `solde` — solde et budgets\n"
            . "  `stats` — rapport mensuel + projection\n"
            . "  `alertes` — alertes de depassement\n"
            . "  `historique` — 10 dernieres depenses\n"
            . "  `historique 5` — 5 dernieres depenses\n"
            . "  `detail alimentation` — analyse d'une categorie\n"
            . "  `projection` — prevision fin de mois\n"
            . "  `resume semaine` — depenses de la semaine\n"
            . "  `top depenses` — top 5 depenses du mois\n"
            . "  `comparer mois` — M vs M-1 par categorie\n"
            . "  `chercher [terme]` — rechercher une depense\n"
            . "  `tendance` — evolution depenses sur 6 mois\n"
            . "  `recurrents` — depenses/abonnements recurrents\n"
            . "  `budget journalier` — budget dispo par jour\n"
            . "  `export` — liste complete du mois\n\n"
            . "✏️ *Corriger / Annuler:*\n"
            . "  `modifier derniere 45 alimentation` — corriger la derniere depense\n"
            . "  `supprimer derniere depense` — supprimer la derniere depense\n\n"
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

    private function compareMonths(string $userPhone): string
    {
        $currentMonth = Carbon::now();
        $lastMonth    = Carbon::now()->subMonth();

        $currentTotal = Expense::calculateTotalMonthlySpent($userPhone, $currentMonth);
        $lastTotal    = Expense::calculateTotalMonthlySpent($userPhone, $lastMonth);

        $currentLabel = $currentMonth->translatedFormat('F Y');
        $lastLabel    = $lastMonth->translatedFormat('F Y');

        $response  = "📊 *Comparaison mensuelle*\n";
        $response .= "_{$lastLabel}_ → _{$currentLabel}_\n\n";

        if ($lastTotal <= 0 && $currentTotal <= 0) {
            $response .= "_Aucune donnee disponible pour la comparaison._\n";
            $response .= "Commence par enregistrer des depenses avec: *depense [montant] [categorie]*";
            return $response;
        }

        // Global comparison
        $diff    = round($currentTotal - $lastTotal, 2);
        $diffPct = $lastTotal > 0 ? round(($diff / $lastTotal) * 100, 1) : 0;
        $icon    = $diff > 0 ? '📈' : ($diff < 0 ? '📉' : '➡️');
        $sign    = $diff > 0 ? '+' : '';

        $response .= "💳 *Total:* {$lastTotal}€ → *{$currentTotal}€*\n";
        $response .= "{$icon} Evolution: {$sign}{$diff}€";
        if ($lastTotal > 0) {
            $response .= " ({$sign}{$diffPct}%)";
        }
        $response .= "\n";

        // Per-category comparison
        $currentCategories = collect(Expense::getTopCategories($userPhone, 10, $currentMonth))->keyBy('category');
        $lastCategories    = collect(Expense::getTopCategories($userPhone, 10, $lastMonth))->keyBy('category');
        $allCategories     = $currentCategories->keys()->merge($lastCategories->keys())->unique()->sort();

        if ($allCategories->isNotEmpty()) {
            $response .= "\n📋 *Par categorie:*\n";
            foreach ($allCategories as $category) {
                $current  = (float) ($currentCategories[$category]['total'] ?? 0);
                $last     = (float) ($lastCategories[$category]['total'] ?? 0);
                $catDiff  = round($current - $last, 2);
                $catIcon  = $catDiff > 0 ? '📈' : ($catDiff < 0 ? '📉' : '➡️');
                $catSign  = $catDiff > 0 ? '+' : '';
                $response .= "  {$catIcon} *{$category}*: {$last}€ → {$current}€ ({$catSign}{$catDiff}€)\n";
            }
        }

        // Tip
        if ($currentTotal > 0 && $lastTotal > 0 && $diff > 0) {
            $response .= "\n💡 _Tu depenses plus ce mois. Consulte 'detail [categorie]' pour analyser._";
        } elseif ($diff < 0) {
            $response .= "\n✨ _Bien joue ! Tu as reduit tes depenses ce mois._";
        }

        return $response;
    }

    private function searchExpenses(string $userPhone, string $query): string
    {
        $query = mb_strtolower(trim($query));

        if (mb_strlen($query) < 2) {
            return "❌ La recherche doit contenir au moins 2 caracteres.";
        }

        if (mb_strlen($query) > 50) {
            return "❌ Le terme de recherche est trop long (max 50 caracteres).";
        }

        try {
            $expenses = Expense::where('user_phone', $userPhone)
                ->where(function ($q) use ($query) {
                    $q->whereRaw('LOWER(category) LIKE ?', ["%{$query}%"])
                      ->orWhereRaw('LOWER(description) LIKE ?', ["%{$query}%"]);
                })
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] searchExpenses failed: " . $e->getMessage());
            return "❌ Erreur lors de la recherche. Reessaie.";
        }

        if ($expenses->isEmpty()) {
            return "🔍 *Recherche: \"{$query}\"*\n\n_Aucune depense trouvee._\n"
                . "_Essaie un autre terme ou consulte: *historique*_";
        }

        $total    = round((float) $expenses->sum('amount'), 2);
        $count    = $expenses->count();
        $response = "🔍 *Recherche: \"{$query}\"*\n";
        $response .= "{$count} resultat(s) — Total: *{$total}€*\n\n";

        $currentDate = null;
        foreach ($expenses as $expense) {
            $date    = Carbon::parse($expense->date);
            $dateStr = $date->isToday()
                ? "Aujourd'hui"
                : ($date->isYesterday() ? 'Hier' : $date->translatedFormat('d M Y'));

            if ($currentDate !== $dateStr) {
                $response   .= "📅 *{$dateStr}*\n";
                $currentDate = $dateStr;
            }

            $desc     = $expense->description ? " — {$expense->description}" : '';
            $response .= "  💳 {$expense->amount}€ *{$expense->category}*{$desc}\n";
        }

        if ($count >= 10) {
            $response .= "\n_Affichage limite a 10 resultats._";
        }

        return $response;
    }

    private function getMonthlyTrend(string $userPhone): string
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month        = Carbon::now()->subMonths($i);
            $total        = Expense::calculateTotalMonthlySpent($userPhone, $month);
            $months[]     = [
                'label'      => $month->translatedFormat('M Y'),
                'total'      => $total,
                'isCurrent'  => $i === 0,
            ];
        }

        $nonEmpty = array_filter($months, fn($m) => $m['total'] > 0);

        if (empty($nonEmpty)) {
            return "📈 *Tendance sur 6 mois*\n\n_Aucune donnee disponible._\n"
                . "Commence par enregistrer des depenses avec: *depense [montant] [categorie]*";
        }

        $maxTotal = max(array_column($months, 'total'));
        $response = "📈 *Tendance des depenses — 6 mois*\n\n";

        foreach ($months as $m) {
            $label    = $m['label'];
            $total    = $m['total'];
            $bar      = $this->buildTrendBar($total, $maxTotal);
            $marker   = $m['isCurrent'] ? ' ← maintenant' : '';
            $totalStr = $total > 0 ? "*{$total}€*" : '—';
            $response .= "{$label}: {$totalStr} {$bar}{$marker}\n";
        }

        // Trend direction: compare last two non-empty months
        $nonEmptyValues = array_values($nonEmpty);
        $count          = count($nonEmptyValues);
        if ($count >= 2) {
            $last = $nonEmptyValues[$count - 1];
            $prev = $nonEmptyValues[$count - 2];
            $diff = round($last['total'] - $prev['total'], 2);
            $sign = $diff > 0 ? '+' : '';
            if ($diff > 0) {
                $trend = "📈 En hausse: {$sign}{$diff}€ vs mois precedent";
            } elseif ($diff < 0) {
                $trend = "📉 En baisse: {$diff}€ vs mois precedent";
            } else {
                $trend = "➡️ Stable vs mois precedent";
            }
            $response .= "\n{$trend}";
        }

        // Average over available months
        $avg      = round(array_sum(array_column($nonEmptyValues, 'total')) / $count, 2);
        $response .= "\n📊 Moyenne: *{$avg}€/mois* sur {$count} mois";

        return $response;
    }

    private function buildTrendBar(float $value, float $max, int $width = 8): string
    {
        if ($max <= 0 || $value <= 0) {
            return str_repeat('░', $width);
        }
        $filled = (int) round(min($value / $max, 1.0) * $width);
        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    private function getRecurringExpenses(string $userPhone): string
    {
        $since = Carbon::now()->subMonths(2)->startOfMonth();

        $expenses = Expense::where('user_phone', $userPhone)
            ->where('date', '>=', $since->toDateString())
            ->get(['category', 'amount', 'date']);

        if ($expenses->isEmpty()) {
            return "🔄 *Depenses recurrentes*\n\n_Aucune depense enregistree._\n"
                . "Enregistre des depenses pour detecter les patterns recurrents.";
        }

        // Group totals by category across months (DB-agnostic)
        $byCategoryMonth = [];
        foreach ($expenses as $expense) {
            $monthKey = Carbon::parse($expense->date)->format('Y-m');
            $byCategoryMonth[$expense->category][$monthKey] =
                ($byCategoryMonth[$expense->category][$monthKey] ?? 0) + (float) $expense->amount;
        }

        // Recurring = appears in 2+ months out of last 3
        $recurring = [];
        foreach ($byCategoryMonth as $category => $monthData) {
            if (count($monthData) >= 2) {
                $avgMonthly = round(array_sum($monthData) / count($monthData), 2);
                $recurring[$category] = [
                    'months'  => count($monthData),
                    'avg'     => $avgMonthly,
                    'total'   => round(array_sum($monthData), 2),
                ];
            }
        }

        if (empty($recurring)) {
            return "🔄 *Depenses recurrentes*\n\n_Aucun pattern recurrent detecte sur 3 mois._\n"
                . "_Reviens apres quelques mois de suivi._";
        }

        // Sort by avg desc
        uasort($recurring, fn($a, $b) => $b['avg'] <=> $a['avg']);

        $response     = "🔄 *Depenses recurrentes* (3 derniers mois)\n\n";
        $totalMonthly = 0;

        foreach ($recurring as $category => $data) {
            $monthsLabel  = $data['months'] === 3 ? "3/3 mois" : "{$data['months']}/3 mois";
            $response    .= "📌 *{$category}*: ~{$data['avg']}€/mois ({$monthsLabel})\n";
            $totalMonthly += $data['avg'];
        }

        $totalMonthly = round($totalMonthly, 2);
        $response    .= "\n💰 Cout mensuel estime: *{$totalMonthly}€*\n";
        $response    .= "💡 _Verifie tes abonnements et charges fixes._";

        return $response;
    }

    private function getDailyBudget(string $userPhone): string
    {
        $today       = Carbon::now();
        $dayOfMonth  = $today->day;
        $daysInMonth = $today->daysInMonth;
        $daysLeft    = $daysInMonth - $dayOfMonth + 1; // include today
        $month       = $today->translatedFormat('F Y');

        $totalSpent  = Expense::calculateTotalMonthlySpent($userPhone);
        $totalBudget = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');

        if ($totalBudget <= 0) {
            return "📅 *Budget journalier — {$month}*\n\n"
                . "_Aucun budget defini._\n"
                . "Cree un budget: *budget [categorie] [montant]*\n\n"
                . "💡 Avec un budget global, je calcule combien tu peux depenser par jour.";
        }

        $totalRemaining = round($totalBudget - $totalSpent, 2);

        $response  = "📅 *Budget journalier — {$month}*\n";
        $response .= "Jour {$dayOfMonth}/{$daysInMonth} • {$daysLeft} jour(s) restant(s)\n\n";

        if ($totalRemaining <= 0) {
            $response .= "🚨 *Budget global depasse !* ({$totalSpent}€ / {$totalBudget}€)\n";
            $response .= "Depassement: *" . abs($totalRemaining) . "€*";
            return $response;
        }

        $dailyAllowance = $daysLeft > 0 ? round($totalRemaining / $daysLeft, 2) : 0;

        $response .= "💰 Restant global: *{$totalRemaining}€*\n";
        $response .= "📊 Disponible par jour: *{$dailyAllowance}€/jour*\n";

        // Today's expenses
        $todayTotal = round((float) Expense::where('user_phone', $userPhone)
            ->whereDate('date', $today->toDateString())
            ->sum('amount'), 2);

        if ($todayTotal > 0) {
            $todayRemaining = round($dailyAllowance - $todayTotal, 2);
            $response .= "\n📌 *Aujourd'hui:* {$todayTotal}€ depenses";
            if ($todayRemaining > 0) {
                $response .= "\n✅ Encore *{$todayRemaining}€* disponibles aujourd'hui";
            } else {
                $response .= "\n⚠️ Quota journalier depasse de *" . abs($todayRemaining) . "€*";
            }
        }

        // Per-category breakdown (only if multiple budgets)
        $budgets = Budget::where('user_phone', $userPhone)->get();
        if ($budgets->count() > 1) {
            $response .= "\n\n📋 *Par categorie (restant/jour):*\n";
            foreach ($budgets as $budget) {
                $check = $budget->checkBudgetThreshold();
                if ($check['remaining'] > 0) {
                    $catDaily  = round($check['remaining'] / $daysLeft, 2);
                    $icon      = $check['threshold_reached'] ? '⚠️' : '✅';
                    $response .= "{$icon} *{$check['category']}*: {$catDaily}€/jour\n";
                } else {
                    $response .= "🚨 *{$check['category']}*: budget depasse\n";
                }
            }
        }

        return $response;
    }

    private function exportMonth(string $userPhone): string
    {
        $month    = Carbon::now();
        $expenses = Expense::where('user_phone', $userPhone)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $monthLabel = $month->translatedFormat('F Y');

        if ($expenses->isEmpty()) {
            return "📤 *Export — {$monthLabel}*\n\n_Aucune depense ce mois._\n"
                . "Commence avec: *depense [montant] [categorie]*";
        }

        $count = $expenses->count();
        $total = round((float) $expenses->sum('amount'), 2);

        $response  = "📤 *Export complet — {$monthLabel}*\n";
        $response .= "{$count} depense(s) • Total: *{$total}€*\n";
        $response .= "─────────────────────────\n";

        // Group by date
        $byDate = $expenses->groupBy(fn ($e) => Carbon::parse($e->date)->toDateString());

        foreach ($byDate as $dateStr => $dateExpenses) {
            $date      = Carbon::parse($dateStr);
            $dateLabel = $date->isToday()
                ? "Aujourd'hui " . $date->translatedFormat('d M')
                : $date->translatedFormat('d M (l)');
            $dayTotal  = round((float) $dateExpenses->sum('amount'), 2);

            $response .= "\n📅 *{$dateLabel}* — {$dayTotal}€\n";
            foreach ($dateExpenses as $expense) {
                $desc      = $expense->description ? " {$expense->description}" : '';
                $response .= "  • {$expense->amount}€ {$expense->category}{$desc}\n";
            }
        }

        $response .= "\n─────────────────────────\n";
        $response .= "💳 *TOTAL: {$total}€*";

        // Budget summary if available
        $totalBudget = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');
        if ($totalBudget > 0) {
            $remaining = round($totalBudget - $total, 2);
            $pct       = round(($total / $totalBudget) * 100, 1);
            $response .= "\n📊 Budget: {$total}€/{$totalBudget}€ ({$pct}%)";
            if ($remaining >= 0) {
                $response .= " • Restant: {$remaining}€";
            } else {
                $response .= " • ⚠️ Depassement: " . abs($remaining) . "€";
            }
        }

        return $response;
    }

    private function setSavingsGoal(string $userPhone, float $amount): string
    {
        if ($amount <= 0) {
            return "❌ L'objectif d'epargne doit etre positif.";
        }

        if ($amount > 100000) {
            return "❌ Objectif trop eleve ({$amount}€). Verifie la saisie.";
        }

        $key = 'finance_savings_goal_' . md5($userPhone);
        AppSetting::set($key, (string) $amount);

        // Show current savings projection
        $totalBudget = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');
        $totalSpent  = Expense::calculateTotalMonthlySpent($userPhone);
        $saved       = $totalBudget > 0 ? round($totalBudget - $totalSpent, 2) : null;
        $month       = Carbon::now()->translatedFormat('F Y');

        $response  = "🎯 *Objectif d'epargne defini !*\n";
        $response .= "💰 Objectif: *{$amount}€/mois*\n";
        $response .= "📅 *{$month}*\n";

        if ($saved !== null && $totalBudget > 0) {
            $pct     = $amount > 0 ? round(($saved / $amount) * 100, 1) : 0;
            $bar     = $this->buildProgressBar(min($pct, 100));
            $icon    = $pct >= 100 ? '🎉' : ($pct >= 50 ? '📈' : '⏳');
            $response .= "\n{$icon} Avancement: {$saved}€ / {$amount}€ ({$pct}%) {$bar}";
            if ($saved >= $amount) {
                $response .= "\n✨ *Objectif atteint !*";
            } else {
                $remaining = round($amount - $saved, 2);
                $response .= "\n💡 Il te faut encore epargner {$remaining}€ ce mois.";
            }
        } else {
            $response .= "\n💡 Definis des budgets pour suivre ton epargne automatiquement.";
        }

        return $response;
    }

    private function getSavingsStatus(string $userPhone): string
    {
        $key    = 'finance_savings_goal_' . md5($userPhone);
        $goal   = (float) (AppSetting::get($key) ?? 0);
        $month  = Carbon::now()->translatedFormat('F Y');

        $totalBudget = (float) Budget::where('user_phone', $userPhone)->sum('monthly_limit');
        $totalSpent  = Expense::calculateTotalMonthlySpent($userPhone);
        $saved       = round($totalBudget - $totalSpent, 2);

        $response  = "🎯 *Bilan Epargne — {$month}*\n\n";
        $response .= "💳 Depenses: *{$totalSpent}€*\n";

        if ($totalBudget > 0) {
            $response .= "📊 Budget total: {$totalBudget}€\n";
            $savedLabel = $saved >= 0 ? "*{$saved}€ economises*" : "*" . abs($saved) . "€ de depassement*";
            $response  .= "💰 " . ($saved >= 0 ? "Economises" : "Depassement") . ": {$savedLabel}\n";
        } else {
            $response .= "⚠️ _Aucun budget defini — impossible de calculer l'epargne._\n";
            $response .= "Cree des budgets: *budget [categorie] [montant]*";
        }

        if ($goal > 0) {
            $response .= "\n🎯 Objectif: {$goal}€\n";
            if ($totalBudget > 0) {
                $pct  = round(($saved / $goal) * 100, 1);
                $bar  = $this->buildProgressBar(max(0, min($pct, 100)));
                $icon = $pct >= 100 ? '🎉' : ($pct >= 50 ? '📈' : '⏳');
                $response .= "{$icon} Progression: {$bar} {$pct}%\n";
                if ($saved >= $goal) {
                    $response .= "✨ *Bravo, objectif atteint !*";
                } else {
                    $needed = round($goal - $saved, 2);
                    $response .= "💡 Encore {$needed}€ a economiser ce mois.";
                }
            }
        } else {
            $response .= "\n_Aucun objectif defini. Utilise:_ *objectif epargne [montant]*";
        }

        return $response;
    }

    private function editLastExpense(string $userPhone, float $amount, ?string $category, ?string $description): string
    {
        if ($amount <= 0) {
            return "❌ Le nouveau montant doit etre positif.";
        }

        if ($amount > 100000) {
            return "❌ Montant trop eleve ({$amount}€). Verifie la saisie.";
        }

        $last = Expense::where('user_phone', $userPhone)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return "❌ Aucune depense a modifier.";
        }

        $oldAmount   = $last->amount;
        $oldCategory = $last->category;
        $newCategory = $category ? mb_strtolower(trim($category)) : $oldCategory;

        if (mb_strlen($newCategory) > 30) {
            return "❌ Le nom de categorie est trop long (max 30 caracteres).";
        }

        try {
            $last->amount      = $amount;
            $last->category    = $newCategory;
            if ($description !== null) {
                $last->description = $description ?: null;
            }
            $last->save();
        } catch (\Throwable $e) {
            Log::error("[FinanceAgent] editLastExpense failed: " . $e->getMessage());
            return "❌ Erreur lors de la modification. Reessaie.";
        }

        $date     = Carbon::parse($last->date)->translatedFormat('d M Y');
        $response = "✏️ *Depense modifiee !*\n";
        $response .= "Avant: {$oldAmount}€ *{$oldCategory}*\n";
        $response .= "Apres: *{$amount}€* *{$newCategory}*\n";
        $response .= "📅 Date: {$date}\n\n";
        $response .= "💰 Total ce mois: *" . Expense::calculateTotalMonthlySpent($userPhone) . "€*";

        // Budget check for new category
        $budget = Budget::where('user_phone', $userPhone)->where('category', $newCategory)->first();
        if ($budget) {
            $check = $budget->checkBudgetThreshold();
            if ($check['exceeded']) {
                $response .= "\n🚨 Budget *{$newCategory}* depasse: {$check['spent']}€/{$check['limit']}€";
            } elseif ($check['threshold_reached']) {
                $response .= "\n⚠️ Budget *{$newCategory}*: {$check['percentage']}% utilise";
            }
        }

        return $response;
    }

    private function buildProgressBar(float $percentage): string
    {
        $filled = (int) round(min($percentage, 100) / 10);
        $empty  = 10 - $filled;
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
