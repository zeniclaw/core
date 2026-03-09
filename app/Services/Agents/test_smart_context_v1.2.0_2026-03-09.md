# Rapport d'amelioration — SmartContextAgent
**Date** : 2026-03-09
**Version** : 1.1.0 → 1.2.0

---

## Ameliorations des capacites existantes

### `VALID_CATEGORIES` / `CATEGORY_LABELS`
- Ajout de la categorie **`"skill"`** (langages, frameworks, outils, technologies maitrisees)
- Separation semantique entre `"profession"` (poste/metier) et `"skill"` (technologies)
- Constante `CATEGORY_LABELS` extraite de `summarizeProfile()` vers une constante de classe partagee (reutilisable par `getFactsByCategory`, etc.)

### `buildExtractionPrompt()`
- Passage des **15 faits** les plus pertinents (vs 10) pour un contexte plus riche
- Description de `"skill"` ajoutee dans les categories du prompt (langages, frameworks, outils)
- Description de `"profession"` affinee (metier, poste, titre) pour mieux se distinguer de `"skill"`
- Exemple supplementaire : distinction `profession` vs `skill` ("je suis dev backend" / "j'utilise Laravel, Vue.js")
- Exemple d'apprentissage en cours : "je commence a apprendre Rust" → `skill` avec score 0.7
- Libelle `"preference"` etendu (alimentation, contact)

### `parseFactsResponse()`
- **Sanitisation améliorée des clés** : `trim($key, '_')` supprime les underscores parasites en debut/fin de clé
- Verification supplementaire : cle vide apres sanitisation → fact ignore
- `contradicts` est egalement trimme des underscores parasites

### `summarizeProfile()`
- **Indicateur de confiance** : les faits avec `score < 0.6` sont suffixes de `_(?)_` (signale l'incertitude)
- **Compteur par categorie** : si une categorie a plusieurs faits, affiche `(N)` apres le label
- Meilleur formatage pour WhatsApp : plus d'informations utiles en un coup d'oeil

### `handle()`
- Nouveau filtre : messages **numeriques uniquement** (ex: "123456789012") sont ignores silencieusement
- Reason `numeric_only_skipped` retournee dans le metadata

---

## Nouvelles capacites ajoutees

### `getFactsByCategory(string $userId, string $category): array`
Recupere les faits filtres par une categorie specifique.
- Retourne un tableau vide si la categorie n'est pas dans `VALID_CATEGORIES`
- Retourne les faits tries par l'ordre du store (score descendant)
- Utile pour l'injection contextuelle ciblee (ex: injecter uniquement les skills dans CodeReviewAgent)

**Exemple d'usage :**
```php
$skills = $smartContext->getFactsByCategory($userId, 'skill');
// → [['key' => 'tech_stack', 'value' => 'Laravel, Vue.js', ...]]
```

### `forgetCategory(string $userId, string $category): int`
Supprime tous les faits d'une categorie donnee.
- Retourne 0 si la categorie est invalide ou si aucun fait n'y correspond
- Retourne le nombre de faits effectivement supprimes
- Utile pour les commandes utilisateur "oublie mes competences" ou reset partiel de profil

**Exemple d'usage :**
```php
$removed = $smartContext->forgetCategory($userId, 'skill');
// → 2 (2 faits de type skill supprimes)
```

---

## Tests

### Tests existants herites de v1.1.0 (tous passes)
| Test | Resultat |
|------|----------|
| context_store_can_store_and_retrieve_facts | ✅ PASS |
| context_store_merges_facts_without_duplicates | ✅ PASS |
| context_store_persists_to_database_as_fallback | ✅ PASS |
| context_store_loads_from_database_when_redis_empty | ✅ PASS |
| context_store_cleanup_removes_old_entries | ✅ PASS |
| context_store_flush_removes_everything | ✅ PASS |
| context_store_ttl_is_set_in_redis | ✅ PASS |
| smart_context_agent_returns_correct_name | ✅ PASS |
| smart_context_agent_can_always_handle | ✅ PASS |
| smart_context_agent_silent_on_short_messages | ✅ PASS |
| context_memory_accessible_from_base_agent | ✅ PASS |
| context_store_limits_to_50_facts | ✅ PASS |
| smart_context_agent_skips_commands | ✅ PASS |
| smart_context_agent_summarize_profile_empty | ✅ PASS |
| smart_context_agent_summarize_profile_with_facts | ✅ PASS |
| smart_context_agent_forget_fact_removes_it | ✅ PASS |
| smart_context_agent_forget_nonexistent_fact_returns_false | ✅ PASS |
| smart_context_agent_get_profile_stats_empty | ✅ PASS |
| smart_context_agent_get_profile_stats_with_facts | ✅ PASS |

### Nouveaux tests v1.2.0
| Test | Resultat |
|------|----------|
| smart_context_agent_version_is_1_2_0 | ✅ PASS |
| get_facts_by_category_returns_matching_facts | ✅ PASS |
| get_facts_by_category_returns_empty_for_unknown_category | ✅ PASS |
| get_facts_by_category_returns_empty_when_no_match | ✅ PASS |
| forget_category_removes_all_facts_of_category | ✅ PASS |
| forget_category_returns_zero_for_unknown_category | ✅ PASS |
| forget_category_returns_zero_when_no_facts_in_category | ✅ PASS |
| summarize_profile_shows_skill_category | ✅ PASS |
| summarize_profile_marks_low_confidence_facts | ✅ PASS |
| smart_context_agent_skips_numeric_only_messages | ✅ PASS |

### Echec pre-existant (non lie a cette version)
| Test | Resultat | Raison |
|------|----------|--------|
| agent_controller_includes_smart_context_in_sub_agents | ❌ FAIL | Vite manifest not found (infrastructure, pre-existant depuis v1.1.0) |

**Total : 29/30 passes (le 1 echec est une regression d'infrastructure pre-existante non liee a l'agent)**

---

## Compatibilite
- Interface `AgentInterface` : respectee
- `BaseAgent` : aucune modification
- `RouterAgent` / `AgentOrchestrator` : non touches
- Migrations existantes : non modifiees
- `ContextStore` : non modifie
