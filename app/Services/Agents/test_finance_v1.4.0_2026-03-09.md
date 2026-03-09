# Rapport de test — FinanceAgent v1.4.0
**Date:** 2026-03-09
**Version:** 1.3.0 → 1.4.0

---

## Resume des ameliorations apportees

### Ameliorations des capacites existantes

| Fonctionnalite | Amelioration |
|---|---|
| `logExpense` | Validation max 30 caracteres sur le nom de categorie |
| `generateMonthlyReport` | Ajout de la moyenne journaliere des depenses et du numero de jour du mois |
| `description()` | Mise a jour pour mentionner les nouvelles fonctionnalites |
| Systeme prompt | Ajout des nouvelles commandes `comparer mois` et `chercher` |
| `getHelp()` | Ajout des nouvelles commandes dans l'aide utilisateur |

### Nouvelles capacites ajoutees

#### 1. Comparaison mensuelle — `compare_months`
**Commande:** `comparer mois` / `comparaison` / `bilan comparatif`

Affiche une comparaison detaillee entre le mois actuel et le mois precedent:
- Total global M-1 → M avec evolution en € et %
- Comparaison par categorie (montant M-1 → M, delta)
- Conseils contextuels (bravo si reduction, alerte si hausse)

#### 2. Recherche dans les depenses — `search_expenses`
**Commande:** `chercher [terme]` / `rechercher [terme]` / `trouver [terme]`

Recherche dans l'historique complet (toutes periodes confondues):
- Recherche dans la description et la categorie (insensible a la casse)
- Limite a 10 resultats, groupe par date
- Affiche le total des resultats trouves
- Validations: min 2 chars, max 50 chars

---

## Nouveaux keywords ajoutes

```
'comparer mois', 'comparaison mois', 'compare mois', 'bilan comparatif', 'evolution depenses',
'chercher depense', 'rechercher depense', 'trouver depense', 'search expense'
```

## Nouveaux patterns canHandle ajoutes

```regex
'/\b(comparer?\s+mois|comparaison|bilan\s+comparatif|evolution\s+depenses?)\b/iu'
'/\b(chercher?|rechercher?|trouver|search)\s+\S+/iu'
```

---

## Resultats des tests

```
php artisan test tests/Unit/Agents/FinanceAgentTest.php --no-coverage

  PASS  Tests\Unit\Agents\FinanceAgentTest
  Tests:    104 passed (180 assertions)
  Duration: 2.30s
```

### Nouveaux tests ajoutes (21 tests)

| Test | Resultat |
|---|---|
| test_keywords_include_comparer_mois | PASS |
| test_keywords_include_chercher_depense | PASS |
| test_can_handle_comparer_mois | PASS |
| test_can_handle_comparaison | PASS |
| test_can_handle_chercher | PASS |
| test_can_handle_rechercher | PASS |
| test_parse_command_compare_months | PASS |
| test_parse_command_compare_months_comparaison | PASS |
| test_parse_command_search_expenses | PASS |
| test_parse_command_search_expenses_variant | PASS |
| test_compare_months_no_data | PASS |
| test_compare_months_with_current_month_data | PASS |
| test_compare_months_with_both_months_data | PASS |
| test_search_expenses_no_results | PASS |
| test_search_expenses_finds_by_description | PASS |
| test_search_expenses_finds_by_category | PASS |
| test_search_expenses_rejects_short_query | PASS |
| test_search_expenses_rejects_too_long_query | PASS |
| test_search_expenses_shows_total | PASS |
| test_log_expense_rejects_too_long_category | PASS |
| test_monthly_report_shows_daily_average | PASS |
| test_help_contains_comparer_mois_command | PASS |

### Routes
`php artisan route:list` — OK, aucune route cassee.

---

## Changements de version

**v1.3.0 → v1.4.0**

Methode `version()` mise a jour dans `FinanceAgent.php`.
Test `test_agent_version_is_1_3_0` renomme en `test_agent_version_is_1_4_0`.
