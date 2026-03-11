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
        return 'Chef cuisinier IA : recettes par ingrédients ou type de plat, régimes alimentaires (vegan, keto, sans gluten, etc.), recettes express, valeurs nutritionnelles, plan de repas hebdomadaire, liste de courses sauvegardable dans les todos, adaptation de portions, substitution d\'ingrédients, techniques culinaires, conservation des aliments, accord mets-vins, recettes de saison.';
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
            'adapter recette', 'adapter la recette', 'pour x personnes', 'personnes',
            'doubler recette', 'tripler recette', 'portions',
            'remplacer', 'substitut', 'alternative ingredient', 'je n\'ai pas de',
            'a la place de', 'sans oeuf', 'sans beurre', 'equivalent',
            // Nouvelles v1.3.0 — techniques culinaires
            'technique', 'techniques', 'technique culinaire', 'techniques culinaires',
            'comment faire', 'comment preparer', 'mode de cuisson', 'cuisson sous vide',
            'braiser', 'pocher', 'julienner', 'blanchir', 'carameliser', 'mariner',
            'temperer', 'flamber', 'glacer', 'faire un roux', 'beurre blanc', 'beurre clarifie',
            // Nouvelles v1.3.0 — conservation des aliments
            'conserver', 'conservation', 'congelation', 'congeler', 'duree de conservation',
            'combien de temps se conserve', 'dlc', 'date limite', 'peremption',
            'frigo', 'congelateur', 'refrigerateur', 'garde au frais',
            // Nouvelles v1.4.0 — accord mets-vins
            'vin', 'vins', 'wine', 'accord mets', 'accord vin', 'accord vins', 'mariage vins',
            'quel vin', 'quelle bouteille', 'quelle bouteille de vin', 'bouteille de vin',
            'accompagner avec du vin', 'sommelier', 'cave', 'cepage', 'bordeaux', 'bourgogne',
            'champagne', 'rose', 'rouge', 'blanc sec', 'vin blanc', 'vin rouge', 'vin rose',
            // Nouvelles v1.4.0 — recettes de saison
            'saison', 'saisonnier', 'seasonal', 'de saison', 'legumes de saison',
            'fruits de saison', 'produits de saison', 'recette de saison', 'recettes de saison',
            'printemps', 'ete', 'automne', 'hiver', 'recette printaniere', 'recette estivale',
            'recette automnale', 'recette hivernale', 'calendrier fruits', 'calendrier legumes',
            // Nouvelles v1.5.0 — anti-gaspi / restes
            'restes', 'reste de', 'anti-gaspi', 'antigaspi', 'vider le frigo', 'vider frigo',
            'vider le congelateur', 'j\'ai des restes', 'utiliser mes restes', 'recycler mes restes',
            'recette anti-gaspi', 'zero dechet cuisine', 'zero gaspi',
            // Nouvelles v1.5.0 — inspiration quotidienne
            'inspire-moi', 'inspire moi', 'surprends-moi', 'surprise-moi', 'surprise moi',
            'idee de recette', 'idee de plat', 'suggestion du chef', 'donne-moi une idee',
            'quoi de bon', 'une idee de plat', 'suggere-moi', 'inspire', 'inspirez-moi',
            // Nouvelles v1.6.0 — allergènes
            'allergene', 'allergenes', 'allergène', 'allergènes', 'allergie', 'allergies', 'allergique',
            'intolerance', 'intolérance', 'intolerances', 'intolérances',
            'contient du gluten', 'contient des noix', 'contient des arachides',
            'sans arachide', 'sans noix', 'sans soja', 'liste allergenes', 'liste allergènes',
            'verifier allergenes', 'vérifier allergènes', 'convient pour allergie',
            // Nouvelles v1.6.0 — batch cooking / meal prep
            'batch cooking', 'batch', 'meal prep', 'food prep',
            'preparer repas semaine', 'préparer repas semaine',
            'cuisine en avance', 'cuisiner en avance',
            'preparer a lavance', 'préparer à l\'avance', 'preparer d\'avance',
            'semaine de cuisine', 'prep semaine', 'prep repas',
            'cuisson en avance', 'cuire en avance', 'session cuisine',
        ];
    }

    public function version(): string
    {
        return '1.6.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'recipe';
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            return $this->dispatch($context);
        } catch (\Throwable $e) {
            Log::error('RecipeAgent handle exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $reply = "Désolé, une erreur inattendue s'est produite. Réessaie dans un instant !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }
    }

    private function dispatch(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // Help command
        if (preg_match('/\b(aide|help|commandes|que sais-tu faire|que peux-tu faire)\b/iu', $lower)) {
            return $this->showHelp($context);
        }

        // Food storage / conservation intent — before search to avoid conflict
        if (preg_match('/\b(conserver|conservation|congelation|congeler|combien\s+de\s+temps\s+(se\s+conserve|garde|dure|reste|tient)|dur[eé]e\s+de\s+conservation|dlc|date\s+(limite|de\s+p[eé]remption)|p[eé]rim[eé]|au\s+frigo|au\s+cong[eé]lateur|r[eé]frig[eé]rateur|garde\s+au\s+frais)\b/iu', $lower)) {
            return $this->foodStorage($context, $body);
        }

        // Cooking technique intent — before search to avoid conflict
        if (preg_match('/\b(technique(?:s)?\s+culinaire|comment\s+(?:faire|pr[eé]parer|r[eé]aliser|cuire|couper)\s+\w|mode\s+de\s+cuisson|cuisson\s+sous\s+vide|braiser|braised?|pocher|poach|julienner|blanchir|blanch|carameliser|caramel|mariner|marinader|temp[eé]rer\s+(?:le\s+)?chocolat|flamber|glacer|faire\s+un\s+roux|beurre\s+(?:blanc|clarifi[eé])|techniques?\s+de\s+cuisson)\b/iu', $lower)) {
            return $this->cookingTechnique($context, $body);
        }

        // Ingredient substitution intent — before search to avoid conflict
        if (preg_match('/\b(remplacer|substitut|remplacement|alternative|equivalen[ct]|a la place de|sans (?:oeuf|beurre|lait|farine|sucre|cr[eè]me)|je n\'ai pas de|je n\'ai plus de|je manque de)\b/iu', $lower)) {
            return $this->ingredientSubstitution($context, $body);
        }

        // Recipe scaling intent
        if (preg_match('/\b(pour\s+\d+\s*personnes?|adapter\s+(la\s+)?recette|doubler|tripler|divis[eé]|multiplier|portions?\s+pour|\d+\s*personnes?\s+(au lieu|plut[oô]t)|changer\s+(le\s+)?nombre\s+de\s+personnes?)\b/iu', $lower)) {
            return $this->scaleRecipe($context, $body);
        }

        // Nutritional info intent — detect before diet to avoid conflict
        if (preg_match('/\b(calories?|nutrition|nutritionnel|macros?|valeurs?\s+nutritionnelles?|prot[eé]ines?|glucides?|lipides?|valeur\s+calorique|kcal|apport\s+calorique|index\s+glyc[eé]mique|ig\s+de)\b/iu', $lower)) {
            return $this->nutritionInfo($context, $body);
        }

        // Meal plan intent
        if (preg_match('/\b(plan\s+repas|planning\s+repas|menu\s+(semaine|du\s+jour|\d+\s*jours?)|meal\s+plan|semaine\s+repas|planifie\s+(mes\s+)?repas|programme\s+repas|\d+\s*jours?\s+repas)\b/iu', $lower)) {
            return $this->mealPlan($context, $body);
        }

        // Diet intent
        if (preg_match('/\b(vegan|v[eé]g[eé]tarien|vegetalien|v[eé]g[eé]talien|keto|c[eé]tog[eè]ne|sans\s+gluten|gluten[\s-]?free|sans\s+lactose|lactose[\s-]?free|m[eé]diterran[eé]en|halal|casher|paleo|pal[eé]o|diab[eé]tique|sportif|musculation|prise\s+de\s+masse|s[eè]che\s+musculaire|low\s+fodmap|fodmap|anti[\s-]?oxydant)\b/iu', $lower, $dietMatch)) {
            return $this->suggestByDiet($context, $body, mb_strtolower($dietMatch[1]));
        }

        // Quick recipes intent
        if (preg_match('/\b(rapide|vite|express|press[eé]|en\s+\d+\s*min|\d+\s*min|moins\s+de\s+\d+\s*min|quick|fast)\b/iu', $lower)) {
            $maxMinutes = 30;
            if (preg_match('/(\d+)\s*min/iu', $lower, $timeMatch)) {
                $maxMinutes = (int) $timeMatch[1];
            }
            return $this->quickRecipes($context, $body, $maxMinutes);
        }

        // Shopping list intent
        if (preg_match('/\b(liste\s+de\s+courses|shopping\s+list|ingr[eé]dients?\s+manquants?|acheter\s+pour)\b/iu', $lower)) {
            return $this->handleShoppingList($context, $body);
        }

        // Wine pairing intent
        if (preg_match('/\b(accord\s+(?:mets?[\s-]?vins?|vins?)|quel\s+vin\b|quelle\s+bouteille|mariage\s+vins?|sommelier|c[eé]page|vin\s+(?:pour|avec|qui\s+va)|accompagner\s+(?:ce\s+plat|avec\s+du\s+vin)|vin\s+(?:blanc|rouge|ros[eé])\s+(?:pour|avec)|bouteille\s+de\s+vin)\b/iu', $lower)) {
            return $this->winePairing($context, $body);
        }

        // Seasonal recipes intent
        if (preg_match('/\b(recettes?\s+de\s+saison|l[eé]gumes?\s+de\s+saison|fruits?\s+de\s+saison|produits?\s+de\s+saison|saisonnier|recette\s+(?:printani[eè]re|estivale|automnale|hivernale)|de\s+saison|(?:printemps|[eé]t[eé]|automne|hiver)\s+(?:recette|plat|cuisine|menu)|recettes?\s+(?:de\s+)?(?:printemps|[eé]t[eé]|automne|hiver))\b/iu', $lower)) {
            return $this->seasonalRecipes($context, $body);
        }

        // Leftovers / anti-gaspi intent
        if (preg_match('/\b(restes?\s+de|j\'ai\s+des\s+restes?|utiliser\s+(mes\s+)?restes?|recycler\s+(mes\s+)?restes?|anti[\s-]?gaspi|z[eé]ro\s+gaspi|vider\s+(le\s+)?(frigo|cong[eé]lateur|r[eé]frig[eé]rateur|placard)|recette\s+(anti[\s-]?gaspi|avec\s+(mes\s+)?restes?))\b/iu', $lower)) {
            return $this->leftovers($context, $body);
        }

        // Daily inspiration / surprise intent
        if (preg_match('/\b(inspire[\s-]?moi|inspir[eé][\s-]?moi|surprends[\s-]?moi|surprise[\s-]?moi|id[eé]e\s+de\s+(recette|plat)|suggestion\s+du\s+chef|sugg[eè]re[\s-]?moi|donne[\s-]?moi\s+une\s+id[eé]e|quoi\s+de\s+bon|une\s+id[eé]e\s+de\s+(plat|recette))\b/iu', $lower)) {
            return $this->dailyInspiration($context, $body);
        }

        // Allergen check intent — v1.6.0
        if (preg_match('/\b(allerg[eè]ne(?:s)?|allergie(?:s)?|allergique|intol[eé]rance(?:s)?|contient[-\s]+(?:du|des|de\s+la|de\s+l\')|sans\s+(?:arachide|noix|soja|oeuf|lait|gluten)\s+(?:dans|pour|en)|liste\s+d(?:es?|\')\s*allerg[eè]nes?|v[eé]rifier\s+allerg[eè]nes?|convient\s+(?:pour|aux?)\s+allerg|allergies?\s+alimentaires?)\b/iu', $lower)) {
            return $this->allergenCheck($context, $body);
        }

        // Batch cooking / meal prep intent — v1.6.0
        if (preg_match('/\b(batch[\s-]?cooking|meal[\s-]?prep|food[\s-]?prep|pr[eé]parer?(?:\s+(?:mes\s+)?repas)?\s+(?:en\s+avance|[àa]\s+l\'?avance|d\'avance|(?:pour\s+)?la\s+semaine)|cuisiner?\s+en\s+avance|cuire\s+en\s+avance|session\s+(?:de\s+)?cuisine|prep\s+(?:semaine|repas))\b/iu', $lower)) {
            return $this->batchCooking($context, $body);
        }

        // Default: search recipes by ingredients or general query
        return $this->searchRecipes($context, $body);
    }

    /**
     * Handle multi-turn pending context (confirm save to todo, confirm scale source, etc.)
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

RÈGLES ABSOLUES :
1. Propose 1 à 3 recettes pertinentes selon la demande
2. Pour chaque recette, respecte EXACTEMENT ce format :

[EMOJI] Nom du Plat
⏱️ Temps : X min prépa + X min cuisson = X min total
👤 Difficulté : ⭐ Facile  (ou ⭐⭐ Moyen / ⭐⭐⭐ Avancé)
🍽️ Pour : 2-4 personnes

📋 Ingrédients :
• X g de ... (marque d'un ⚠️ les allergènes courants : gluten, lactose, fruits à coque, œufs)
• ...

📝 Préparation :
1. ...
2. ...
3. ...

🔥 ~XXX kcal/portion

3. Si l'utilisateur cite des ingrédients, privilégie des recettes qui les utilisent au maximum
4. Si la demande est vague, propose des classiques variés (1 simple, 1 intermédiaire)
5. Si le profil utilisateur contient des allergies ou régimes, respecte-les ABSOLUMENT
6. Termine TOUJOURS par : 👨‍🍳 Astuce : [conseil pratique court]
7. Après l'astuce, ajoute TOUJOURS sur une nouvelle ligne : "📩 Je peux aussi : 🛒 liste de courses | ⚖️ adapter les portions | 🌱 version [régime] | ⚠️ vérifier les allergènes"
8. Formatage WhatsApp UNIQUEMENT : utilise des émojis, des tirets •, des chiffres. JAMAIS de #, **, _
9. Langue de réponse : {$lang}

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
            'vegan'              => 'vegan (100% végétal, aucun produit animal ni dérivé)',
            'vegetarien'         => 'végétarien (sans viande ni poisson, mais œufs et produits laitiers autorisés)',
            'vegetalien'         => 'végétalien (100% végétal, aucun produit animal)',
            'keto'               => 'cétogène/keto (très faible en glucides ≤50g/j, riche en lipides, modéré en protéines)',
            'cetogene'           => 'cétogène/keto (très faible en glucides ≤50g/j, riche en lipides)',
            'sans gluten'        => 'sans gluten (aucun blé, seigle, orge, épeautre, kamut)',
            'gluten free'        => 'sans gluten (aucun blé, seigle, orge, épeautre)',
            'glutenfree'         => 'sans gluten',
            'gluten-free'        => 'sans gluten',
            'sans lactose'       => 'sans lactose (aucun produit laitier contenant du lactose)',
            'lactose free'       => 'sans lactose',
            'lactosefree'        => 'sans lactose',
            'lactose-free'       => 'sans lactose',
            'mediterraneen'      => 'méditerranéen (légumes abondants, huile d\'olive, poissons, légumineuses, peu de viande rouge)',
            'halal'              => 'halal (viande certifiée halal, sans alcool ni porc)',
            'casher'             => 'casher (selon les règles alimentaires juives — séparation viande/laitages)',
            'paleo'              => 'paléo (sans céréales, sans légumineuses, sans produits transformés, sans sucre raffiné)',
            'diabetique'         => 'adapté aux diabétiques (index glycémique bas/moyen, faible en sucres rapides, portions contrôlées)',
            'sportif'            => 'adapté aux sportifs (riche en protéines, glucides complexes pour l\'énergie, récupération musculaire)',
            'musculation'        => 'musculation/prise de masse (très riche en protéines, calories positives, glucides complexes)',
            'prise de masse'     => 'prise de masse (hypercalorique, riche en protéines et glucides complexes)',
            'seche musculaire'   => 'sèche musculaire (hypocalorique, très riche en protéines, faible en glucides et lipides)',
            'low fodmap'         => 'low FODMAP (faible en fermentescibles : sans oignon, ail, blé, légumineuses, pommes, miel — idéal pour syndrome de l\'intestin irritable)',
            'fodmap'             => 'low FODMAP (faible en fermentescibles : sans oignon, ail, blé, légumineuses — idéal pour côlon irritable)',
            'antioxydant'        => 'riche en antioxydants (baies, légumes colorés, épices, thé vert, chocolat noir — anti-inflammatoire)',
            'anti-oxydant'       => 'riche en antioxydants (baies, légumes colorés, épices — anti-inflammatoire)',
        ];

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

RÈGLES ABSOLUES :
1. Propose 2-3 recettes STRICTEMENT compatibles avec le régime : {$dietLabel}
2. Pour chaque recette :
   [EMOJI] + Badge du régime (🌱 Vegan / 🥑 Keto / 🚫🌾 Sans Gluten / 🏋️ Sport / 💉 IG Bas / 🌊 FODMAP / etc.) + Nom du plat
   ⏱️ Temps : X min
   📋 Ingrédients (avec quantités précises)
   📝 Étapes numérotées
   🔥 Calories + macros : P: Xg / G: Xg / L: Xg
   👤 Difficulté
3. CRUCIAL : vérifie que CHAQUE ingrédient est compatible avec le régime. En cas de doute, exclus-le.
4. Si l'utilisateur mentionne un ingrédient incompatible, signale-le et propose une alternative compatible avec explication courte.
5. Si le régime a des bénéfices spécifiques, mentionne-les brièvement en introduction (1 phrase).
6. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
7. Langue : {$lang}

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

        $timelabel = match(true) {
            $maxMinutes <= 10 => 'ultra-rapide (moins de 10 min, pas ou très peu de cuisson)',
            $maxMinutes <= 15 => 'très rapide (moins de 15 min)',
            $maxMinutes <= 20 => 'rapide (moins de 20 min)',
            $maxMinutes <= 30 => 'express (moins de 30 min)',
            default           => "rapide (moins de {$maxMinutes} min)",
        };

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé en recettes {$timelabel}.

RÈGLES ABSOLUES :
1. Propose 2-3 recettes réalisables en {$maxMinutes} minutes MAXIMUM (prépa + cuisson, chrono garanti)
2. Pour chaque recette :
   [EMOJI] Nom ⏱️ {$maxMinutes}min max
   ⏱️ Prépa : X min | Cuisson : X min (total ≤ {$maxMinutes} min — OBLIGATOIRE)
   📋 Ingrédients (max 8, courants et faciles à trouver)
   📝 Étapes (max 5, ultra-simples)
   🔥 ~XXX kcal/portion
   💡 Astuce gain de temps : ...
3. Règles selon le temps :
   - ≤10 min : plats froids exclusivement (salade, tartine, wrap, smoothie, yaourt)
   - ≤15 min : micro-ondes, œufs, pâtes à cuisson rapide, sandwichs chauds OK
   - ≤30 min : sauté, poêlée, soupe express, pasta OK
4. Si l'utilisateur mentionne des ingrédients, utilise-les en priorité
5. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
6. Langue : {$lang}

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

RÈGLES :
1. Identifie la recette ou le type de plat concerné (si non précisé, demande-le ou propose une recette classique)
2. Indique pour combien de personnes (par défaut 4)
3. Génère une liste de courses complète et organisée par rayon :
   🥩 Viandes / Poissons
   🥬 Fruits et Légumes
   🧀 Produits laitiers
   🍞 Boulangerie / Céréales
   🫙 Épicerie sèche
   🧊 Surgelés (si applicable)
   🧴 Autres
4. Indique les quantités précises pour chaque article
5. Marque avec ⭐ les articles ESSENTIELS à ne pas oublier
6. Estime le coût total : "💰 Total estimé : ~X€"
7. À la TOUTE FIN, ajoute EXACTEMENT cette ligne JSON compacte sur UNE SEULE LIGNE (sans retour à la ligne dans le JSON) :
   JSON:{"recipe":"<nom>","items":["qté + ingrédient",...]}
8. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
9. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer la liste de courses. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Robust JSON extraction — handles optional whitespace, multi-line JSON, trailing text
        $recipeName = 'la recette';
        $items = [];
        // Try compact JSON on last line first, then broader multi-line match
        if (preg_match('/JSON:\s*(\{[^{}]+\})\s*$/ms', $response, $jsonMatch)
            || preg_match('/JSON:\s*(\{.+?\})/s', $response, $jsonMatch)) {
            // Normalize whitespace before decoding (handles newlines inside JSON values)
            $jsonStr = preg_replace('/\s+/', ' ', trim($jsonMatch[1]));
            // Sanitize: ensure proper string quotes (defensive)
            $parsed = json_decode($jsonStr, true);
            if (!is_array($parsed) && json_last_error() !== JSON_ERROR_NONE) {
                // Second attempt: strip control characters and retry
                $jsonStr = preg_replace('/[\x00-\x1F\x7F]/', '', $jsonStr);
                $parsed = json_decode($jsonStr, true);
            }
            if (is_array($parsed)) {
                $recipeName = is_string($parsed['recipe'] ?? null) ? $parsed['recipe'] : 'la recette';
                $items = array_filter((array) ($parsed['items'] ?? []), 'is_string');
                $items = array_values($items);
            }
        }

        // Strip the raw JSON line from the displayed response (same pattern as extraction)
        $displayResponse = preg_replace('/\nJSON:\s*\{.*?\}\s*$/ms', '', $response);
        $displayResponse = trim($displayResponse ?: $response);

        $this->sendText($context->from, $displayResponse);

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

        return AgentResult::reply($displayResponse, ['action' => 'shopping_list']);
    }

    /**
     * Nutritional information for a dish or ingredient.
     */
    public function nutritionInfo(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un nutritionniste IA expert et pédagogue.

RÈGLES :
1. Identifie l'aliment, le plat ou la recette mentionné(e)
2. Affiche les valeurs nutritionnelles pour 1 portion standard (précise le poids en g) :
   🔥 Calories : X kcal
   💪 Protéines : X g
   🌾 Glucides : X g (dont sucres : X g)
   🥑 Lipides : X g (dont saturés : X g)
   🌿 Fibres : X g
   🧂 Sel : X g
3. Index glycémique (IG) : Bas (<55) / Moyen (55-70) / Élevé (>70) — indique uniquement si pertinent
4. % des Apports Journaliers Recommandés (AJR adulte moyen) pour les 3 macros principaux
5. 2-3 faits nutritionnels intéressants sur cet aliment/plat (bénéfices, points de vigilance)
6. Fréquence de consommation recommandée (ex: "à consommer 2-3 fois/semaine")
7. Si l'utilisateur compare 2 aliments (ex: "différence calories entre X et Y"), compare-les dans un tableau texte
8. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
9. Langue : {$lang}

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
     * Weekly or multi-day meal plan.
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

        // Detect embedded diet constraint
        $dietHint = '';
        if (preg_match('/\b(vegan|v[eé]g[eé]talien|v[eé]g[eé]tarien|vegetalien|keto|c[eé]tog[eè]ne|sans\s+gluten|gluten[\s-]?free|sans\s+lactose|lactose[\s-]?free|m[eé]diterran[eé]en|halal|casher|paleo|pal[eé]o|diab[eé]tique|sportif|musculation|prise\s+de\s+masse|s[eè]che\s+musculaire|low\s+fodmap|fodmap|anti[\s-]?oxydant)\b/iu', $query, $dm)) {
            $dietHint = "- Contrainte alimentaire OBLIGATOIRE à respecter sur TOUS les repas : {$dm[1]}";
        }

        // Detect calorie target from query
        $calorieHint = '';
        if (preg_match('/(\d{3,4})\s*kcal/iu', $query, $cm)) {
            $kcal = max(1200, min((int) $cm[1], 4000));
            $calorieHint = "- Objectif calorique journalier cible : ~{$kcal} kcal/jour";
        }

        // Detect budget constraint
        $budgetHint = '';
        if (preg_match('/\b(pas\s+cher|petit\s+budget|budget\s+serr[eé]|[eé]conomique|bon\s+march[eé]|moins\s+de\s+\d+\s*[€$€]|\d+\s*[€$€]\s*(?:par\s+(?:jour|semaine)|max)|abordable|low\s+cost)\b/iu', $query, $bm)) {
            $budgetHint = "- Contrainte BUDGET : recettes économiques, ingrédients courants et peu coûteux (évite les produits de luxe : foie gras, homard, truffes, etc.)";
        }

        $extraConstraints = implode("\n", array_filter([$dietHint, $calorieHint, $budgetHint]));

        $systemPrompt = <<<PROMPT
Tu es un nutritionniste et chef cuisinier IA. Tu crées des plans de repas équilibrés, variés et pratiques.

RÈGLES :
1. Crée un plan de repas pour {$days} jours, structuré ainsi pour chaque jour :
   ── Jour X ──
   🌅 Petit-déjeuner : [nom + ~kcal]
   ☀️ Déjeuner : [nom + accompagnement + ~kcal]
   🌙 Dîner : [nom + accompagnement + ~kcal]
   🍎 Collation : [optionnelle, légère + ~kcal]
   📊 Total jour : ~XXXX kcal
2. Règles nutritionnelles :
   - Variété maximale : ne répète PAS le même plat principal deux jours de suite
   - Équilibre journalier cible : 1800-2200 kcal (sauf contrainte contraire)
   - Mix protéines animales/végétales, fibres, légumes à chaque repas
{$extraConstraints}
3. Après le plan, ajoute une liste de courses globale groupée par catégorie pour les {$days} jours
4. Formatage WhatsApp compact — séparateurs visuels entre chaque jour, jamais de #, **, _
5. Langue : {$lang}

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
        $this->log($context, 'Meal plan generated', ['days' => $days, 'diet_hint' => $dietHint]);

        return AgentResult::reply($response, ['action' => 'meal_plan', 'days' => $days]);
    }

    /**
     * Scale a recipe for a different number of servings.
     */
    public function scaleRecipe(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        // Try to extract target servings count
        $targetServings = null;
        if (preg_match('/\b(\d+)\s*personnes?\b/iu', $query, $m)) {
            $targetServings = max(1, min((int) $m[1], 50));
        } elseif (preg_match('/\b(doubler|double)\b/iu', $query)) {
            $targetServings = 'doubler (×2)';
        } elseif (preg_match('/\b(tripler|triple)\b/iu', $query)) {
            $targetServings = 'tripler (×3)';
        } elseif (preg_match('/\b(divis[eé]r?\s+par\s*(\d+))/iu', $query, $m2)) {
            $targetServings = 'diviser par ' . ($m2[2] ?? '2');
        }

        if (is_int($targetServings)) {
            $servingsInstruction = "L'utilisateur veut adapter la recette pour {$targetServings} personnes (ajuste toutes les quantités en conséquence).";
        } elseif ($targetServings) {
            $servingsInstruction = "L'utilisateur veut {$targetServings} (ajuste toutes les quantités en conséquence).";
        } else {
            $servingsInstruction = "L'utilisateur veut adapter les portions d'une recette — déduis le nombre de personnes visé depuis sa demande.";
        }

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA expert en calcul de proportions. {$servingsInstruction}

RÈGLES :
1. Identifie la recette mentionnée (ou demande laquelle si ambiguë)
2. Affiche la recette adaptée avec :
   [EMOJI] Nom de la recette — version pour X personnes
   📋 Ingrédients recalculés (toutes les quantités proportionnellement ajustées, arrondies au pratique)
   📝 Étapes (mêmes étapes, avec ajustements si nécessaire : temps de cuisson, taille du moule, etc.)
   ⚠️ Points d'attention : signale les éléments qui NE s'adaptent PAS linéairement (ex: levure, temps de cuisson d'un grand rôti, taille du plat)
3. Si le temps de cuisson change, recalcule-le et explique pourquoi
4. Si la recette originale n'est pas précisée, propose une recette classique et adapte-la
5. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
6. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu adapter les proportions. Précise la recette et le nombre de personnes !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Recipe scaling', ['target_servings' => $targetServings, 'query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'scale_recipe', 'target_servings' => $targetServings]);
    }

    /**
     * Find substitutes for missing or unwanted ingredients.
     */
    public function ingredientSubstitution(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA expert en substitution d'ingrédients.

RÈGLES :
1. Identifie l'ingrédient à remplacer et le contexte (recette, régime, allergie, indisponibilité)
2. Propose 3 à 5 substituts classés par pertinence :
   [N°] [EMOJI] Substitut — quantité équivalente pour X g/ml de l'original
   ✅ Avantages : ...
   ⚠️ Différence de goût/texture : ...
   🍳 Comment l'utiliser : ...
3. Indique le MEILLEUR substitut en premier, avec la mention "⭐ Recommandé"
4. Si le remplacement change significativement le résultat final, préviens-en l'utilisateur
5. Si c'est pour une allergie ou un régime spécifique, priorise les substituts compatibles
6. Référentiel de substitutions courants :
   - Beurre → huile de coco, purée d'amande, compote (pâtisserie)
   - Œufs → graines de lin+eau (vegan), banane écrasée (pâtisserie), aquafaba (blanc)
   - Farine de blé → farine de riz, maïzena, fécule de pomme de terre (sans gluten)
   - Crème fraîche → yaourt grec, lait de coco, crème de cajou (vegan)
   - Sucre → miel, sirop d'agave, érythritol (keto), purée de dattes
   - Lait → lait d'avoine, lait d'amande, lait de coco (selon usage)
7. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
8. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu trouver de substituts. Précise l'ingrédient à remplacer et la recette concernée !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Ingredient substitution', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'ingredient_substitution']);
    }

    /**
     * NEW v1.3.0 — Explain cooking techniques step by step.
     */
    public function cookingTechnique(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA pédagogue, spécialisé dans l'enseignement des techniques culinaires.

RÈGLES :
1. Identifie la technique culinaire demandée (ex: braiser, julienner, faire un roux, tempérer le chocolat, cuire sous vide, blanchir, pocher, caraméliser, etc.)
2. Explique la technique avec ce format :

[EMOJI] Nom de la technique
📖 C'est quoi ? [définition simple en 1-2 phrases]
🎯 Pour quoi faire ? [usages principaux]

🛠️ Matériel nécessaire :
• ...

📝 Étapes détaillées :
1. ...
2. ...
3. ...

⚠️ Erreurs courantes à éviter :
• ...

💡 Astuce de chef : ...
🍽️ Exemples de plats utilisant cette technique : ...

3. Si la technique a des variantes (ex: braiser à l'étouffée vs braisage court), mentionne-les brièvement
4. Niveau de difficulté : ⭐ Débutant / ⭐⭐ Intermédiaire / ⭐⭐⭐ Expert
5. Si la demande est vague (ex: "comment cuire une viande"), demande des précisions ou propose les 2-3 techniques les plus adaptées
6. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
7. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu expliquer cette technique. Précise la technique culinaire que tu veux apprendre !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Cooking technique', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'cooking_technique']);
    }

    /**
     * NEW v1.3.0 — Food storage and freezing advice.
     */
    public function foodStorage(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un expert en conservation des aliments et sécurité alimentaire.

RÈGLES :
1. Identifie l'aliment ou le plat dont l'utilisateur veut connaître la conservation
2. Donne des informations complètes et précises :

[EMOJI] Nom de l'aliment / plat

🧊 Conservation au réfrigérateur (0-4°C) :
• Durée : X jours/semaines
• Condition : [comment le stocker : récipient hermétique, film plastique, etc.]

❄️ Conservation au congélateur (-18°C) :
• Durée : X mois
• Comment congeler : [conseils pratiques]
• Comment décongeler : [méthode recommandée]

🌡️ Conservation à température ambiante :
• Durée : X [si applicable]
• Conditions : ...

⚠️ Signes de péremption à surveiller :
• [couleur, odeur, texture, moisissures]

✅ Conseils pour prolonger la durée de conservation :
• ...

3. Si l'utilisateur demande pour un plat cuisiné, adapte les conseils (ex: soupe, lasagnes)
4. Mentionne si la congélation impacte la texture ou le goût
5. Inclus toujours un avertissement de sécurité si l'aliment est risqué (viande crue, poisson, œufs)
6. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
7. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu donner les informations de conservation. Précise l'aliment ou le plat !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Food storage advice', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'food_storage']);
    }

    /**
     * NEW v1.4.0 — Wine pairing suggestions for a dish, with non-alcoholic alternatives.
     */
    public function winePairing(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un sommelier IA expert, passionné par l'accord mets-vins. Tu proposes des accords précis, pédagogiques et accessibles.

RÈGLES :
1. Identifie le plat ou le type de cuisine mentionné
2. Propose 2-3 accords vins classés du plus recommandé au plus original :

🍷 Accord N°X — [Nom du vin / Appellation] ⭐ (Recommandé / Original / Audacieux)
🍇 Cépage(s) : ...
🌍 Région / Appellation : ...
🌡️ Service : X-Y°C (en verre [type de verre])
👃 Profil aromatique : [notes principales]
🤝 Pourquoi ça marche : [explication courte du mariage de saveurs]
💰 Budget indicatif : ~X-XX€ la bouteille

3. Pour chaque accord, ajoute une alternative sans alcool :
   🍹 Alternative sans alcool : [jus, kombucha, eau pétillante aromatisée, etc.] — pourquoi ça fonctionne

4. Si le plat est difficile à accorder (artichaut, vinaigrette, oeufs, etc.), explique pourquoi et propose le meilleur compromis
5. Ajoute une règle générale d'accord pour ce type de plat (1 ligne)
6. Si l'utilisateur mentionne une occasion (repas de fête, anniversaire, dîner romantique), adapte les recommandations en conséquence
7. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
8. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu proposer d'accord mets-vins. Précise le plat ou la cuisine !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Wine pairing', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'wine_pairing']);
    }

    /**
     * NEW v1.4.0 — Seasonal recipe suggestions based on current month/season.
     */
    public function seasonalRecipes(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        // Determine current season from current month (Northern hemisphere, France)
        $month = (int) date('n');
        $season = match(true) {
            in_array($month, [3, 4, 5])  => 'printemps',
            in_array($month, [6, 7, 8])  => 'été',
            in_array($month, [9, 10, 11]) => 'automne',
            default                       => 'hiver',
        };
        $monthName = (new \DateTime())->format('F');
        $frMonthNames = [
            'January' => 'janvier', 'February' => 'février', 'March' => 'mars',
            'April' => 'avril', 'May' => 'mai', 'June' => 'juin',
            'July' => 'juillet', 'August' => 'août', 'September' => 'septembre',
            'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre',
        ];
        $monthFr = $frMonthNames[$monthName] ?? $monthName;

        // Allow override if user explicitly mentions a season
        $overrideSeason = null;
        if (preg_match('/\b(printemps|spring)\b/iu', $query)) {
            $overrideSeason = 'printemps';
        } elseif (preg_match('/\b([eé]t[eé]|summer)\b/iu', $query)) {
            $overrideSeason = 'été';
        } elseif (preg_match('/\b(automne|autumn|fall)\b/iu', $query)) {
            $overrideSeason = 'automne';
        } elseif (preg_match('/\b(hiver|winter)\b/iu', $query)) {
            $overrideSeason = 'hiver';
        }
        $targetSeason = $overrideSeason ?? $season;

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA passionné par la cuisine de saison et les produits locaux. Nous sommes en {$monthFr} (saison : {$targetSeason}).

RÈGLES :
1. Commence par lister les produits DE SAISON du mois de {$monthFr} en {$targetSeason} :
   🥬 Légumes : [liste compact]
   🍎 Fruits : [liste compact]
   🐟 Poissons/Fruits de mer : [si pertinent]

2. Propose 2-3 recettes mettant en valeur ces produits de saison :

[EMOJI] Nom du plat — {$targetSeason}
⭐ Produit vedette : [ingrédient de saison principal]
⏱️ Temps : X min
📋 Ingrédients (mets en avant les produits de saison avec 🌱)
📝 Étapes numérotées
🔥 ~XXX kcal/portion
💡 Pourquoi c'est de saison : [bénéfice gustatif ou nutritionnel bref]

3. Pour chaque recette, signale si des ingrédients peuvent être remplacés par d'autres produits de saison
4. Ajoute en fin de réponse : "🗓️ Astuce marché : [conseil pratique pour acheter ces produits en {$monthFr}]"
5. Si l'utilisateur a une contrainte alimentaire dans son profil, respecte-la pour toutes les recettes
6. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
7. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer des recettes de saison. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Seasonal recipes', ['season' => $targetSeason, 'month' => $monthFr]);

        return AgentResult::reply($response, ['action' => 'seasonal_recipes', 'season' => $targetSeason]);
    }

    /**
     * NEW v1.5.0 — Anti-gaspi: creative recipes using leftover ingredients.
     */
    public function leftovers(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA spécialisé dans la cuisine anti-gaspi et la valorisation des restes. Ton objectif : transformer ce qui reste en cuisine en un repas savoureux, sans rien gaspiller.

RÈGLES :
1. Identifie les ingrédients restants mentionnés par l'utilisateur (restes de plats cuisinés, légumes oubliés, fonds de placard, etc.)
2. Si l'utilisateur ne précise pas d'ingrédients, demande-lui ce qu'il a dans son frigo/placard ou propose 3 idées génériques anti-gaspi
3. Propose 2-3 recettes créatives et réalistes, classées du plus simple au plus élaboré :

[EMOJI] ♻️ Nom du plat — Anti-gaspi
⏱️ Temps : X min
🎯 Valorise : [liste des restes utilisés dans cette recette]
📋 Ingrédients (marque les restes avec ♻️ et les compléments à avoir en placard avec 🫙)
📝 Étapes numérotées
🔥 ~XXX kcal/portion
💡 Variante : [comment adapter si on n'a pas certains compléments]

4. Après les recettes, ajoute :
   🌱 Bilan anti-gaspi : [ce que tu as réussi à utiliser et ce qui pourrait encore être conservé ou congelé]
   🧊 Conseil conservation : [si certains restes peuvent encore être congelés ou consommés plus tard]

5. Indique si certains restes semblent trop vieux ou risqués à consommer (⚠️ Prudence)
6. Sois encourageant — cuisiner anti-gaspi est un geste écologique et économique
7. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
8. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer des recettes anti-gaspi. Dis-moi ce que tu as dans ton frigo !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Leftovers / anti-gaspi', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'leftovers']);
    }

    /**
     * NEW v1.5.0 — Daily chef inspiration: personalized recipe suggestion based on time & season.
     */
    public function dailyInspiration(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        // Determine time of day and meal context
        $hour = (int) date('G');
        $mealContext = match(true) {
            $hour >= 6  && $hour < 11 => 'petit-déjeuner (matin)',
            $hour >= 11 && $hour < 14 => 'déjeuner (midi)',
            $hour >= 14 && $hour < 17 => 'goûter / collation (après-midi)',
            $hour >= 17 && $hour < 22 => 'dîner (soir)',
            default                   => 'repas nocturne (nuit)',
        };

        // Determine current season
        $month = (int) date('n');
        $season = match(true) {
            in_array($month, [3, 4, 5])  => 'printemps',
            in_array($month, [6, 7, 8])  => 'été',
            in_array($month, [9, 10, 11]) => 'automne',
            default                       => 'hiver',
        };

        $frMonthNames = [
            'January' => 'janvier', 'February' => 'février', 'March' => 'mars',
            'April' => 'avril', 'May' => 'mai', 'June' => 'juin',
            'July' => 'juillet', 'August' => 'août', 'September' => 'septembre',
            'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre',
        ];
        $monthFr = $frMonthNames[date('F')] ?? date('F');
        $dayOfWeek = ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi',
                      'Wednesday' => 'mercredi', 'Thursday' => 'jeudi', 'Friday' => 'vendredi',
                      'Saturday' => 'samedi'][date('l')] ?? date('l');

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA créatif et inspirant. L'utilisateur cherche une suggestion personnalisée — surprends-le avec quelque chose qu'il n'aurait pas pensé à cuisiner lui-même.

CONTEXTE :
- Moment de la journée : {$mealContext}
- Jour : {$dayOfWeek}
- Mois : {$monthFr} (saison : {$season})

RÈGLES :
1. Propose UNE recette coup de cœur, originale mais réalisable, adaptée au moment de la journée et à la saison
2. La recette doit surprendre agréablement — ni trop basique, ni trop exotique
3. Format de présentation :

✨ Inspiration du jour — {$dayOfWeek} {$monthFr}

[EMOJI] Nom du plat
📖 L'histoire en 1 phrase : [pourquoi cette recette maintenant, ce qui la rend spéciale ou originale]
⏱️ Temps : X min prépa + X min cuisson
👤 Difficulté : ⭐ / ⭐⭐ / ⭐⭐⭐
🍽️ Pour : X personnes
🌱 Produit vedette de {$monthFr} : [ingrédient de saison mis en valeur]

📋 Ingrédients :
• ...

📝 Préparation :
1. ...

🔥 ~XXX kcal/portion
🎯 Le petit plus : [astuce ou touche du chef qui fait la différence]

4. Respecte ABSOLUMENT les contraintes alimentaires du profil utilisateur (allergies, régimes)
5. Si le profil utilisateur contient des préférences culinaires, oriente la suggestion en conséquence
6. Termine par : "🍽️ Bonne dégustation ! Dis-moi si tu veux la liste de courses ou adapter les portions."
7. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
8. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu générer une inspiration du jour. Réessaie !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Daily inspiration', ['meal_context' => $mealContext, 'season' => $season]);

        return AgentResult::reply($response, ['action' => 'daily_inspiration', 'meal_context' => $mealContext]);
    }

    /**
     * NEW v1.6.0 — Check the 14 major EU allergens in a dish or recipe.
     */
    public function allergenCheck(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        $systemPrompt = <<<PROMPT
Tu es un expert en allergènes alimentaires et sécurité alimentaire, conforme à la réglementation européenne (14 allergènes majeurs — directive EU 1169/2011).

RÈGLES :
1. Identifie le plat, la recette ou l'ingrédient mentionné
2. Analyse et structure ta réponse ainsi :

[EMOJI] Analyse allergènes — [Nom du plat/recette]

⚠️ Allergènes PRÉSENTS (recette classique) :
• [Allergène] — présent dans : [ingrédient(s)]

✅ Allergènes ABSENTS (dans une recette classique) :
• [Liste compact sur une ligne]

🔶 Risque de contamination croisée possible :
• [Allergène] — raison : [ex: fabriqué dans un atelier utilisant des noix]

3. Les 14 allergènes réglementés (EU) à vérifier SYSTÉMATIQUEMENT :
   Gluten (blé/seigle/orge/épeautre) • Crustacés • Œufs • Poissons • Arachides
   Soja • Lait/lactose • Fruits à coque (amandes/noix/noisettes/etc.)
   Céleri • Moutarde • Graines de sésame • Anhydride sulfureux/sulfites • Lupin • Mollusques

4. Si l'utilisateur demande si un plat convient pour UNE allergie spécifique :
   - Réponds clairement en première ligne : ✅ OUI / ❌ NON / ⚠️ ATTENTION (à vérifier)
   - Explique en 1-2 phrases pourquoi

5. Propose 1-2 substitutions simples pour éliminer l'allergène principal si possible
   Ex: "Sans lactose → remplace le beurre par huile de coco, la crème par lait de coco"

6. Rappel légal (toujours inclure) :
   "⚠️ Ces informations sont indicatives. En cas d'allergie sévère, vérifiez toujours l'étiquetage des produits industriels et renseignez-vous directement auprès du restaurateur ou fabricant."

7. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
8. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu analyser les allergènes. Précise le plat ou la recette concernée, et l'allergie que tu veux vérifier !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Allergen check', ['query' => mb_substr($query, 0, 100)]);

        return AgentResult::reply($response, ['action' => 'allergen_check']);
    }

    /**
     * NEW v1.6.0 — Batch cooking guide: plan and organize a meal prep session for the week.
     */
    public function batchCooking(AgentContext $context, string $query): AgentResult
    {
        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);
        $prefs = $this->getUserPrefs($context);
        $lang = $prefs['language'] ?? 'fr';

        // Detect number of days
        $days = 5;
        if (preg_match('/(\d+)\s*jours?/iu', $query, $dm)) {
            $days = max(2, min((int) $dm[1], 7));
        }

        // Detect number of people
        $people = 1;
        if (preg_match('/(\d+)\s*personnes?/iu', $query, $pm)) {
            $people = max(1, min((int) $pm[1], 10));
        }

        // Detect diet constraint
        $dietHint = '';
        if (preg_match('/\b(vegan|v[eé]g[eé]tarien|vegetalien|v[eé]g[eé]talien|keto|c[eé]tog[eè]ne|sans\s+gluten|sans\s+lactose|halal|casher|paleo|pal[eé]o|diab[eé]tique|sportif|musculation)\b/iu', $query, $dm2)) {
            $dietHint = "\n- Contrainte alimentaire à respecter sur TOUTES les préparations : {$dm2[1]}";
        }

        // Detect budget constraint
        $budgetHint = '';
        if (preg_match('/\b(pas\s+cher|petit\s+budget|[eé]conomique|bon\s+march[eé]|low\s+cost|abordable)\b/iu', $query)) {
            $budgetHint = "\n- Contrainte BUDGET : ingrédients courants et peu coûteux";
        }

        $systemPrompt = <<<PROMPT
Tu es un chef cuisinier IA expert en batch cooking (préparation des repas en avance). Tu aides l'utilisateur à organiser une session de cuisine efficace pour couvrir {$days} jours, pour {$people} personne(s).{$dietHint}{$budgetHint}

RÈGLES :
1. Crée un plan de batch cooking complet avec ce format :

📦 Plan Batch Cooking — {$days} jours | {$people} personne(s)
⏱️ Durée session : ~X heures (estimation totale)

🛒 Liste de courses (tous ingrédients groupés par rayon) :
🥩 Viandes/Protéines : ...
🥬 Fruits & Légumes : ...
🧀 Laitages : ...
🫙 Épicerie sèche : ...
💰 Budget estimé : ~X€

🔥 Ordre de préparation optimal (minimise le temps, optimise fours/feux) :
1. Préchauffer le four à X°C → lancer [prépa 1]
2. Pendant la cuisson de X → préparer [prépa 2]
...

🍱 Ce que tu prépares et comment conserver :
• [Base/Plat] → 🧊 Frigo : X jours | ❄️ Congélateur : X mois
• ...

📅 Organisation des repas sur {$days} jours :
Jour 1 : Midi → [plat] | Soir → [plat]
Jour 2 : ...
...

💡 Astuces batch cooking :
• [Conseil sur les contenants hermétiques, étiquetage, rotation FIFO]
• [Conseil sur les bases modulables : riz, légumes rôtis, protéines polyvalentes]
• [Conseil pour éviter la monotonie]

2. Privilégie des bases modulables (une même base de légumes rôtis peut servir en salade, en soupe ou en accompagnement)
3. Indique clairement ce qui se congèle vs se garde seulement au frigo
4. Si l'utilisateur ne précise pas d'ingrédients ou de régime, propose un batch cooking équilibré et varié
5. Formatage WhatsApp UNIQUEMENT — jamais de #, **, _
6. Langue : {$lang}

{$contextMemory}
PROMPT;

        $model = $this->resolveModel($context);
        $response = $this->claude->chat($query, $model, $systemPrompt);

        if (!$response) {
            $reply = "Désolé, je n'ai pas pu créer ton plan de batch cooking. Dis-moi pour combien de jours et de personnes, et j'organise ta session de cuisine !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->sendText($context->from, $response);
        $this->log($context, 'Batch cooking plan', ['days' => $days, 'people' => $people, 'diet' => $dietHint]);

        return AgentResult::reply($response, ['action' => 'batch_cooking', 'days' => $days, 'people' => $people]);
    }

    private function showHelp(AgentContext $context): AgentResult
    {
        $reply = "🍽️ Chef IA — Aide (v1.6.0)\n\n"
            . "Voici tout ce que je sais faire :\n\n"
            . "🔍 Recherche de recette\n"
            . "  → \"recette avec poulet et citron\"\n"
            . "  → \"que faire avec des pâtes et du beurre ?\"\n\n"
            . "🥗 Régimes alimentaires\n"
            . "  → \"recette vegan facile\"\n"
            . "  → \"idées keto pour le dîner\"\n"
            . "  → \"plat sans gluten pour sportif\"\n"
            . "  → \"recette low FODMAP\"\n\n"
            . "⏱️ Recettes express\n"
            . "  → \"recette rapide en 15 min\"\n"
            . "  → \"quelque chose de vite à cuisiner\"\n\n"
            . "📊 Valeurs nutritionnelles\n"
            . "  → \"calories dans une pizza margherita\"\n"
            . "  → \"macros du poulet rôti vs saumon\"\n\n"
            . "📅 Plan de repas\n"
            . "  → \"plan repas pour 5 jours\"\n"
            . "  → \"menu de la semaine végétarien 1800 kcal\"\n\n"
            . "🛒 Liste de courses\n"
            . "  → \"liste de courses pour une quiche lorraine\"\n"
            . "  → \"ingrédients manquants pour faire une tarte\"\n\n"
            . "⚖️ Adapter les portions\n"
            . "  → \"recette pour 8 personnes\"\n"
            . "  → \"doubler la recette de gâteau au chocolat\"\n\n"
            . "🔄 Substitution d'ingrédients\n"
            . "  → \"remplacer les œufs dans un gâteau\"\n"
            . "  → \"je n'ai pas de crème fraîche, que faire ?\"\n\n"
            . "👨‍🍳 Techniques culinaires\n"
            . "  → \"comment faire un roux ?\"\n"
            . "  → \"explique-moi la technique du braisage\"\n\n"
            . "🧊 Conservation des aliments\n"
            . "  → \"combien de temps se conserve le poulet cuit ?\"\n"
            . "  → \"comment congeler des lasagnes ?\"\n\n"
            . "🍷 Accord mets-vins\n"
            . "  → \"quel vin avec un saumon grillé ?\"\n"
            . "  → \"accord vin pour un boeuf bourguignon\"\n\n"
            . "🌱 Recettes de saison\n"
            . "  → \"recettes de saison pour ce mois-ci\"\n"
            . "  → \"que cuisiner avec les légumes d'automne ?\"\n\n"
            . "♻️ Anti-gaspi / Restes\n"
            . "  → \"que faire avec mes restes de poulet et riz ?\"\n"
            . "  → \"recette anti-gaspi avec ce que j'ai au frigo\"\n\n"
            . "✨ Inspiration du chef\n"
            . "  → \"inspire-moi pour ce soir\"\n"
            . "  → \"surprise-moi avec une recette originale\"\n\n"
            . "⚠️ Vérificateur d'allergènes (NOUVEAU)\n"
            . "  → \"quels allergènes dans une pizza margherita ?\"\n"
            . "  → \"le tiramisu convient-il pour une allergie aux œufs ?\"\n\n"
            . "📦 Batch cooking / Meal prep (NOUVEAU)\n"
            . "  → \"fais-moi un plan batch cooking pour 5 jours\"\n"
            . "  → \"comment préparer mes repas de la semaine en avance ?\"\n\n"
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
