<?php

namespace App\Services\Agents;

use App\Services\AgentContext;

class RecipeAgent extends BaseAgent
{
    public function name(): string
    {
        return 'recipe';
    }

    public function description(): string
    {
        return 'Suggestions de recettes intelligentes par ingredients disponibles, regime alimentaire et temps de preparation. Peut creer une liste de courses via TodoAgent.';
    }

    public function keywords(): array
    {
        return [
            'recette', 'cuisiner', 'cuisine', 'ingredients', 'ingredient',
            'qu\'est-ce que je peux faire avec', 'quoi cuisiner', 'repas',
            'diner', 'dejeuner', 'petit-dejeuner', 'gouter', 'dessert',
            'vegan', 'vegetarien', 'keto', 'sans gluten', 'sans lactose',
            'mediterraneen', 'healthy', 'light', 'regime',
            'recette rapide', 'quick recipe', 'en 30 min', 'facile',
            'recipe', 'cook', 'cooking', 'meal', 'dish',
            'calories', 'nutrition', 'plat', 'entree', 'accompagnement',
            'liste de courses', 'shopping list', 'ingredients manquants',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'recipe';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Detect intent
        if (preg_match('/\b(vegan|v[eé]g[eé]tarien|vegetalien|keto|c[eé]tog[eè]ne|sans\s+gluten|gluten[\s-]?free|sans\s+lactose|lactose[\s-]?free|m[eé]diterran[eé]en|halal|casher|paleo)\b/iu', $lower, $dietMatch)) {
            return $this->suggestByDiet($context, $body, mb_strtolower($dietMatch[1]));
        }

        if (preg_match('/\b(rapide|vite|express|pressé|en\s+\d+\s*min|moins\s+de\s+\d+\s*min|quick|fast|30\s*min|20\s*min|15\s*min|10\s*min)\b/iu', $lower)) {
            $maxMinutes = 30;
            if (preg_match('/(\d+)\s*min/iu', $lower, $timeMatch)) {
                $maxMinutes = (int) $timeMatch[1];
            }
            return $this->quickRecipes($context, $body, $maxMinutes);
        }

        if (preg_match('/\b(liste\s+de\s+courses|shopping\s+list|ingr[eé]dients?\s+manquants?)\b/iu', $lower)) {
            return $this->handleShoppingList($context, $body);
        }

        // Default: search recipes by ingredients or general query
        return $this->searchRecipes($context, $body);
    }

    public function searchRecipes(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA expert et passionné. Tu proposes des recettes adaptées aux ingrédients et préférences de l'utilisateur.

REGLES:
1. Propose 1 à 3 recettes pertinentes selon la demande
2. Pour chaque recette, inclus:
   - Nom du plat avec emoji
   - Liste des ingrédients avec quantités
   - Étapes numérotées claires et concises
   - Temps de préparation et cuisson estimés
   - Calories estimées par portion
   - Niveau de difficulté (facile/moyen/avancé)
3. Si l'utilisateur donne des ingrédients, propose des recettes qui les utilisent au maximum
4. Signale les ingrédients qui pourraient manquer avec un astérisque (*)
5. Sois créatif mais réaliste
6. Formate avec des emojis et une mise en page claire pour WhatsApp
7. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu trouver de recettes pour le moment. Réessaie en me donnant tes ingrédients disponibles !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Recipe search', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'search_recipes']);
    }

    public function suggestByDiet(AgentContext $context, string $query, string $diet): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $dietLabels = [
            'vegan' => 'vegan (100% végétal)',
            'végétarien' => 'végétarien (sans viande ni poisson)',
            'vegetarien' => 'végétarien (sans viande ni poisson)',
            'vegetalien' => 'végétalien (100% végétal)',
            'keto' => 'cétogène/keto (très faible en glucides, riche en lipides)',
            'cétogène' => 'cétogène/keto (très faible en glucides, riche en lipides)',
            'cetogene' => 'cétogène/keto (très faible en glucides, riche en lipides)',
            'sans gluten' => 'sans gluten',
            'gluten-free' => 'sans gluten',
            'glutenfree' => 'sans gluten',
            'sans lactose' => 'sans lactose',
            'lactose-free' => 'sans lactose',
            'lactosefree' => 'sans lactose',
            'méditerranéen' => 'méditerranéen',
            'mediterraneen' => 'méditerranéen',
            'halal' => 'halal',
            'casher' => 'casher',
            'paleo' => 'paléo',
        ];

        $dietLabel = $dietLabels[$diet] ?? $diet;

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé en régime {$dietLabel}.

REGLES:
1. Propose 2-3 recettes strictement compatibles avec le régime {$dietLabel}
2. Pour chaque recette:
   - Nom du plat avec emoji
   - Badge du régime (ex: 🌱 Vegan, 🥑 Keto, 🚫🌾 Sans Gluten)
   - Ingrédients avec quantités
   - Étapes numérotées
   - Temps total estimé
   - Calories et macros estimées (protéines, glucides, lipides)
   - Niveau de difficulté
3. IMPORTANT: Vérifie scrupuleusement que CHAQUE ingrédient est compatible avec le régime
4. Propose des alternatives si l'utilisateur mentionne des ingrédients incompatibles
5. Formate pour WhatsApp avec emojis
6. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer de recettes {$dietLabel}. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Diet recipe suggestion', ['diet' => $diet, 'query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'suggest_by_diet', 'diet' => $diet]);
    }

    public function quickRecipes(AgentContext $context, string $query, int $maxMinutes = 30): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé en recettes RAPIDES (max {$maxMinutes} minutes).

REGLES:
1. Propose 2-3 recettes réalisables en {$maxMinutes} minutes maximum (préparation + cuisson)
2. Pour chaque recette:
   - Nom du plat avec emoji et badge ⏱️ {$maxMinutes}min
   - Ingrédients (peu nombreux, faciles à trouver)
   - Étapes numérotées, simples et rapides
   - Temps de préparation + cuisson séparés
   - Calories estimées
   - Astuce gain de temps
3. Privilégie les recettes avec peu d'ingrédients et techniques simples
4. Formate pour WhatsApp avec emojis
5. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu trouver de recettes rapides. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Quick recipe suggestion', ['max_minutes' => $maxMinutes]);

        return AgentResult::reply($response, ['action' => 'quick_recipes', 'max_minutes' => $maxMinutes]);
    }

    private function handleShoppingList(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un assistant cuisine. L'utilisateur veut une liste de courses.

REGLES:
1. Analyse la demande pour comprendre quelle recette ou quels ingrédients sont concernés
2. Génère une liste de courses organisée par rayon:
   - 🥩 Viandes/Poissons
   - 🥬 Fruits & Légumes
   - 🧀 Produits laitiers
   - 🍞 Boulangerie/Céréales
   - 🫙 Épicerie
   - 🧊 Surgelés
   - 🧴 Autre
3. Indique les quantités nécessaires
4. Formate pour WhatsApp
5. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer la liste de courses. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Shopping list generated');

        return AgentResult::reply($response, ['action' => 'shopping_list']);
    }

    /**
     * Create a 'Courses' todo with missing ingredients via TodoAgent integration.
     */
    public function saveToTodo(AgentContext $context, array $missingIngredients, string $recipeName): AgentResult
    {
        $items = implode(', ', $missingIngredients);
        $title = "Courses pour: {$recipeName}";
        $description = "Ingredients manquants: {$items}";

        try {
            $todo = \App\Models\Todo::create([
                'requester_phone' => $context->from,
                'agent_id' => $context->agent->id,
                'title' => $title,
                'description' => $description,
                'is_done' => false,
            ]);

            $reply = "✅ Todo \"Courses\" creee !\n\n"
                . "📋 *{$title}*\n"
                . "🛒 {$items}\n\n"
                . "Retrouve-la dans tes todos.";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Shopping todo created', [
                'recipe' => $recipeName,
                'missing_count' => count($missingIngredients),
                'todo_id' => $todo->id,
            ]);

            return AgentResult::reply($reply, ['action' => 'save_to_todo', 'todo_id' => $todo->id]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('RecipeAgent saveToTodo failed: ' . $e->getMessage());
            $reply = "Desole, je n'ai pas pu creer la todo de courses. Reessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }
}
