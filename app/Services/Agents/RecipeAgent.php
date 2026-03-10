<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use Illuminate\Support\Facades\Log;

class RecipeAgent extends BaseAgent
{
    public function name(): string
    {
        return 'recipe';
    }

    public function description(): string
    {
        return 'Chef cuisinier IA : recettes par ingrédients ou type de plat, régimes alimentaires (vegan, keto, sans gluten, etc.), recettes express, valeurs nutritionnelles, plan de repas hebdomadaire, liste de courses sauvegardable dans les todos.';
    }

    public function keywords(): array
    {
        return [
            'recette', 'recettes', 'cuisiner', 'cuisine', 'cuisinier',
            'ingredients', 'ingredient', 'ingrédients', 'ingrédient',
            'qu\'est-ce que je peux faire avec', 'quoi cuisiner',
            'que manger', 'que faire a manger', 'quoi manger', 'qu\'est-ce que je mange',
            'repas', 'diner', 'déjeuner', 'dejeuner', 'petit-dejeuner', 'gouter', 'dessert',
            'vegan', 'vegetarien', 'végétarien', 'keto', 'sans gluten', 'sans lactose',
            'mediterraneen', 'méditerranéen', 'healthy', 'light', 'regime', 'paleo', 'halal', 'casher',
            'recette rapide', 'quick recipe', 'en 30 min', 'facile', 'express',
            'recipe', 'cook', 'cooking', 'meal', 'dish', 'plat', 'plats',
            'calories', 'nutrition', 'nutritionnel', 'macros', 'valeur nutritionnelle', 'valeurs nutritionnelles',
            'proteines', 'glucides', 'lipides', 'kcal',
            'entree', 'accompagnement',
            'liste de courses', 'shopping list', 'ingredients manquants',
            'plan repas', 'planning repas', 'menu semaine', 'meal plan', 'semaine repas', 'planifie mes repas',
            'aide recette', 'help recipe', 'aide cuisine',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'recipe';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Help command
        if (preg_match('/\b(aide|help|commandes|que sais-tu faire|que peux-tu faire)\b/iu', $lower)) {
            return $this->showHelp($context);
        }

        // Nutritional info intent — detect before diet to avoid conflict
        if (preg_match('/\b(calories?|nutrition|nutritionnel|macros?|valeurs?\s+nutritionnelles?|prot[eé]ines?|glucides?|lipides?|valeur\s+calorique|kcal|apport\s+calorique)\b/iu', $lower)) {
            return $this->nutritionInfo($context, $body);
        }

        // Meal plan intent
        if (preg_match('/\b(plan\s+repas|planning\s+repas|menu\s+(semaine|du\s+jour|\d+\s*jours?)|meal\s+plan|semaine\s+repas|planifie\s+(mes\s+)?repas|programme\s+repas|\d+\s*jours?\s+repas)\b/iu', $lower)) {
            return $this->mealPlan($context, $body);
        }

        // Diet intent
        if (preg_match('/\b(vegan|v[eé]g[eé]tarien|vegetalien|v[eé]g[eé]talien|keto|c[eé]tog[eè]ne|sans\s+gluten|gluten[\s-]?free|sans\s+lactose|lactose[\s-]?free|m[eé]diterran[eé]en|halal|casher|paleo|pal[eé]o)\b/iu', $lower, $dietMatch)) {
            return $this->suggestByDiet($context, $body, mb_strtolower($dietMatch[1]));
        }

        // Quick recipes intent
        if (preg_match('/\b(rapide|vite|express|press[eé]|en\s+\d+\s*min|moins\s+de\s+\d+\s*min|quick|fast|30\s*min|20\s*min|15\s*min|10\s*min)\b/iu', $lower)) {
            $maxMinutes = 30;
            if (preg_match('/(\d+)\s*min/iu', $lower, $timeMatch)) {
                $maxMinutes = (int) $timeMatch[1];
            }
            return $this->quickRecipes($context, $body, $maxMinutes);
        }

        // Shopping list intent
        if (preg_match('/\b(liste\s+de\s+courses|shopping\s+list|ingr[eé]dients?\s+manquants?)\b/iu', $lower)) {
            return $this->handleShoppingList($context, $body);
        }

        // Default: search recipes by ingredients or general query
        return $this->searchRecipes($context, $body);
    }

    /**
     * Handle multi-turn pending context (e.g. confirm save to todo after shopping list).
     */
    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];

        if ($type === 'shopping_todo_confirm') {
            $body = mb_strtolower(trim($context->body ?? ''));
            $recipeName = $data['recipe_name'] ?? 'la recette';
            $items = $data['ingredients'] ?? [];

            if (preg_match('/\b(oui|yes|ok|ouais|yep|absolument|bien\s+s[uû]r|vas-y|go|save|sauvegarde|enregistre)\b/iu', $body)) {
                $this->clearPendingContext($context);
                return $this->saveToTodo($context, $items, $recipeName);
            }

            if (preg_match('/\b(non|no|nope|pas\s+maintenant|annule|cancel|laisse\s+(tomb[eé]|[cç][ae]))\b/iu', $body)) {
                $this->clearPendingContext($context);
                $reply = "D'accord ! La liste de courses n'a pas été sauvegardée. Bonne cuisine ! 🍳";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }

            // Ambiguous — re-ask
            $reply = "Réponds *oui* pour sauvegarder la liste de courses dans tes todos, ou *non* pour ignorer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        return null;
    }

    public function searchRecipes(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA expert et passionné. Tu proposes des recettes claires, savoureuses et adaptées à la demande.

RÈGLES:
1. Propose 1 à 3 recettes pertinentes selon la demande
2. Pour chaque recette, inclus:
   - Nom du plat avec emoji thématique
   - ⏱️ Temps total (prépa + cuisson)
   - 👤 Difficulté : ⭐ Facile / ⭐⭐ Moyen / ⭐⭐⭐ Avancé
   - 📋 Ingrédients avec quantités (marque d'un * les ingrédients qui pourraient manquer)
   - 📝 Étapes numérotées, concises et claires
   - 🔥 Calories estimées par portion
3. Si l'utilisateur cite des ingrédients, privilégie des recettes qui les utilisent au maximum
4. Si la demande est vague, propose des classiques appréciés et variés
5. Termine par une astuce du chef courte et pratique
6. Format WhatsApp uniquement — pas de markdown Markdown (#, **, etc.)
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
            'vegan'          => 'vegan (100% végétal, aucun produit animal)',
            'vegetarien'     => 'végétarien (sans viande ni poisson)',
            'vegetalien'     => 'végétalien (100% végétal)',
            'keto'           => 'cétogène/keto (très faible en glucides, riche en lipides)',
            'cetogene'       => 'cétogène/keto (très faible en glucides, riche en lipides)',
            'sans gluten'    => 'sans gluten (aucun blé, seigle, orge, épeautre)',
            'gluten free'    => 'sans gluten',
            'glutenfree'     => 'sans gluten',
            'gluten-free'    => 'sans gluten',
            'sans lactose'   => 'sans lactose (aucun produit laitier contenant du lactose)',
            'lactose free'   => 'sans lactose',
            'lactosefree'    => 'sans lactose',
            'lactose-free'   => 'sans lactose',
            'mediterraneen'  => 'méditerranéen (légumes, huile d\'olive, poissons, légumineuses)',
            'halal'          => 'halal (viande certifiée halal, sans alcool)',
            'casher'         => 'casher (selon les règles alimentaires juives)',
            'paleo'          => 'paléo (sans céréales, sans légumineuses, sans produits transformés)',
        ];

        // Normalize diet key for lookup (remove accents)
        $dietNorm = $this->normalizeStr($diet);
        $dietLabel = $diet;
        foreach ($dietLabels as $key => $label) {
            if ($this->normalizeStr($key) === $dietNorm) {
                $dietLabel = $label;
                break;
            }
        }

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé en régime {$dietLabel}.

RÈGLES:
1. Propose 2-3 recettes strictement compatibles avec le régime {$dietLabel}
2. Pour chaque recette:
   - Nom du plat avec emoji + badge du régime (ex: 🌱 Vegan, 🥑 Keto, 🚫🌾 Sans Gluten, ✡️ Casher)
   - ⏱️ Temps total (prépa + cuisson)
   - 📋 Ingrédients avec quantités
   - 📝 Étapes numérotées
   - 🔥 Calories et macros estimés (P: protéines / G: glucides / L: lipides)
   - 👤 Niveau de difficulté
3. CRUCIAL : Vérifie que CHAQUE ingrédient est compatible avec le régime {$dietLabel}
4. Si l'utilisateur mentionne des ingrédients incompatibles, propose une alternative compatible en précisant pourquoi
5. Format WhatsApp uniquement — pas de markdown Markdown (#, **, etc.)
6. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer de recettes pour ce régime. Réessaie !";
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

        // Clamp to reasonable range
        $maxMinutes = max(5, min($maxMinutes, 90));

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé en recettes RAPIDES (max {$maxMinutes} minutes, préparation + cuisson incluses).

RÈGLES:
1. Propose 2-3 recettes réalisables en {$maxMinutes} minutes maximum (prépa + cuisson)
2. Pour chaque recette:
   - Nom du plat avec emoji + badge ⏱️ {$maxMinutes}min max
   - ⏱️ Temps prépa + temps cuisson séparés (total ≤ {$maxMinutes} min garanti)
   - 📋 Ingrédients (max 8, courants et faciles à trouver)
   - 📝 Étapes numérotées ultra-simples (max 5 étapes)
   - 🔥 Calories estimées par portion
   - 💡 Astuce gain de temps
3. Si {$maxMinutes} <= 15 : privilégie les plats froids, wraps, salades, toasts ou micro-ondes
4. Format WhatsApp uniquement — pas de markdown Markdown (#, **, etc.)
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
Tu es un assistant cuisine expert. L'utilisateur veut une liste de courses.

RÈGLES:
1. Identifie la recette ou le type de plat concerné
2. Génère une liste de courses complète et organisée par rayon:
   🥩 Viandes / Poissons
   🥬 Fruits et Légumes
   🧀 Produits laitiers
   🍞 Boulangerie / Céréales
   🫙 Épicerie sèche
   🧊 Surgelés
   🧴 Autres
3. Indique les quantités précises pour chaque article
4. Signale avec ⭐ les articles essentiels à ne pas oublier
5. Ajoute une ligne "Total estimé : ~X€" avec prix approximatif
6. À la fin, ajoute EXACTEMENT cette ligne JSON sur une seule ligne (pour extraction automatique) :
   JSON:{"recipe":"<nom de la recette>","items":["quantité + ingrédient", ...]}
7. Format WhatsApp uniquement — pas de markdown Markdown (#, **, etc.)
8. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer la liste de courses. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Extract recipe name and items from JSON block
        $recipeName = 'la recette';
        $items = [];
        if (preg_match('/JSON:\s*(\{[^\n]+\})/s', $response, $jsonMatch)) {
            $parsed = json_decode(trim($jsonMatch[1]), true);
            if (is_array($parsed)) {
                $recipeName = $parsed['recipe'] ?? 'la recette';
                $items = $parsed['items'] ?? [];
            }
        }

        $this->sendText($context->from, $response);

        // Offer to save as Todo if items were extracted
        if (!empty($items)) {
            $offerMsg = "💾 Veux-tu que je sauvegarde cette liste dans tes todos ?\nRéponds *oui* ou *non*.";
            $this->sendText($context->from, $offerMsg);
            $this->setPendingContext($context, 'shopping_todo_confirm', [
                'recipe_name' => $recipeName,
                'ingredients' => $items,
            ], 5, true);
        }

        $this->log($context, 'Shopping list generated', ['recipe' => $recipeName, 'items_count' => count($items)]);

        return AgentResult::reply($response, ['action' => 'shopping_list']);
    }

    /**
     * NEW — Nutritional information for a dish or ingredient.
     */
    public function nutritionInfo(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un nutritionniste IA expert. Tu fournis des informations nutritionnelles précises et pédagogiques.

RÈGLES:
1. Identifie le plat ou l'aliment mentionné par l'utilisateur
2. Donne les valeurs nutritionnelles pour 1 portion standard (précise le poids en grammes) :
   🔥 Calories : X kcal
   💪 Protéines : X g
   🌾 Glucides : X g  (dont sucres : X g)
   🥑 Lipides : X g  (dont saturés : X g)
   🌿 Fibres : X g
3. Indique l'indice glycémique si pertinent (IG bas / moyen / élevé)
4. Donne 2-3 faits nutritionnels intéressants sur ce plat ou aliment
5. Compare les apports journaliers recommandés (AJR) en % pour un adulte moyen
6. Conseille sur la fréquence de consommation recommandée
7. Format WhatsApp uniquement — pas de markdown Markdown (#, **, etc.)
8. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu analyser les valeurs nutritionnelles. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Nutrition info', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'nutrition_info']);
    }

    /**
     * NEW — Weekly or multi-day meal plan.
     */
    public function mealPlan(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        // Detect number of days
        $days = 7;
        if (preg_match('/(\d+)\s*jours?/iu', $query, $daysMatch)) {
            $days = max(1, min((int) $daysMatch[1], 14));
        }

        $systemPrompt = <<<PROMPT
Tu es un nutritionniste et chef cuisinier IA. Tu crées des plans de repas équilibrés, variés et pratiques.

RÈGLES:
1. Crée un plan de repas pour {$days} jours
2. Pour chaque jour, propose :
   🌅 Petit-déjeuner
   ☀️ Déjeuner (plat + accompagnement)
   🌙 Dîner (plat + accompagnement)
   🍎 Collation (optionnelle, légère)
3. Assure une variété maximale : ne répète pas le même plat principal deux jours de suite
4. Équilibre nutritionnel global : 1 journée ≈ 1800-2200 kcal
5. Indique le total calorique estimé par journée
6. Si l'utilisateur mentionne un régime ou des contraintes, respecte-les strictement
7. Termine par une liste de courses globale groupée par catégorie pour les {$days} jours
8. Format WhatsApp compact (jours bien séparés visuellement)
9. Langue: {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu créer le plan de repas. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Meal plan generated', ['days' => $days]);

        return AgentResult::reply($response, ['action' => 'meal_plan', 'days' => $days]);
    }

    private function showHelp(AgentContext $context): AgentResult
    {
        $reply = "🍽️ Chef IA — Aide\n\n"
            . "Voici ce que je peux faire pour toi :\n\n"
            . "🔍 Recherche de recette\n"
            . "  → \"recette avec poulet et citron\"\n"
            . "  → \"que faire avec des pâtes et du beurre\"\n\n"
            . "🥗 Régimes alimentaires\n"
            . "  → \"recette vegan facile\"\n"
            . "  → \"idées keto pour le dîner\"\n"
            . "  → \"plat sans gluten rapide\"\n\n"
            . "⏱️ Recettes express\n"
            . "  → \"recette rapide en 15 min\"\n"
            . "  → \"quelque chose de vite à cuisiner\"\n\n"
            . "📊 Valeurs nutritionnelles\n"
            . "  → \"calories dans une pizza margherita\"\n"
            . "  → \"valeurs nutritionnelles du poulet rôti\"\n\n"
            . "📅 Plan de repas\n"
            . "  → \"plan repas pour 5 jours\"\n"
            . "  → \"menu de la semaine équilibré\"\n\n"
            . "🛒 Liste de courses\n"
            . "  → \"liste de courses pour une quiche lorraine\"\n"
            . "  → \"ingrédients manquants pour faire une tarte\"\n\n"
            . "Bonne cuisine ! 👨‍🍳";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'help']);
    }

    /**
     * Create a 'Courses' todo with the shopping list ingredients.
     */
    public function saveToTodo(AgentContext $context, array $missingIngredients, string $recipeName): AgentResult
    {
        if (empty($missingIngredients)) {
            $reply = "Aucun ingrédient à sauvegarder.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $title = "Courses : {$recipeName}";

        try {
            $todo = \App\Models\Todo::create([
                'requester_phone' => $context->from,
                'requester_name'  => $context->senderName,
                'agent_id'        => $context->agent->id,
                'title'           => $title,
                'list_name'       => 'Courses',
                'category'        => 'courses',
                'is_done'         => false,
            ]);

            $itemLines = implode("\n  🛒 ", $missingIngredients);
            $reply = "✅ Liste de courses sauvegardée dans tes todos !\n\n"
                . "📋 {$title}\n"
                . "  🛒 {$itemLines}\n\n"
                . "Retrouve-la avec la commande \"mes todos\". Bonne cuisine ! 🍳";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Shopping todo created', [
                'recipe'      => $recipeName,
                'items_count' => count($missingIngredients),
                'todo_id'     => $todo->id,
            ]);

            return AgentResult::reply($reply, ['action' => 'save_to_todo', 'todo_id' => $todo->id]);
        } catch (\Throwable $e) {
            Log::error('RecipeAgent saveToTodo failed: ' . $e->getMessage());
            $reply = "Désolé, je n'ai pas pu créer la todo de courses. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    /**
     * Normalize a string: lowercase + strip accents for map lookup.
     */
    private function normalizeStr(string $str): string
    {
        $str = mb_strtolower($str);
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str;
        return trim($str);
    }
}
