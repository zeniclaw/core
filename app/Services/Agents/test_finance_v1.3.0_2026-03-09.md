# Rapport de test — FinanceAgent v1.3.0

**Date:** 2026-03-09
**Version precedente → nouvelle version:** `1.2.0` → `1.3.0`

---

## Resume des ameliorations apportees

### Corrections / ameliorations des capacites existantes

| Methode | Amelioration |
|---------|-------------|
| `getBalance()` | Ajout de la ligne "Jour X/31" pour contextualiser le solde dans le mois |
| `getCategoryDetail()` | Affiche le nombre total de depenses du mois (pas seulement les 5 affichees). Titre adaptatif "X dernieres (sur N)" si plus de 5 |
| `getHistory()` | Titre dynamique : affiche le vrai nombre de depenses si inferieur au limit demande |
| `parseCommand()` | Correction regex `top N depenses` — le pattern autorise maintenant un nombre entre "top" et "depenses" |
| `buildSystemPrompt()` | Ajout d'une section "COMMANDES DISPONIBLES" complete avec les nouvelles commandes |
| `getHelp()` | Ajout des deux nouvelles commandes : `resume semaine` et `top depenses` |
| `keywords()` | +7 nouveaux mots-cles : semaine, hebdo, hebdomadaire, resume semaine, cette semaine, top depenses, grosses depenses, plus grosses depenses, grandes depenses, depenses importantes |
| `canHandle()` | +3 nouveaux patterns regex pour les nouvelles commandes |

---

## Nouvelles capacites ajoutees

### 1. `resume semaine` — Resume hebdomadaire (`weekly_summary`)

**Declencheurs:** `resume semaine`, `cette semaine`, `semaine`, `hebdo`, `hebdomadaire`, `semaine en cours`

**Fonctionnalites:**
- Total depense depuis le lundi de la semaine courante jusqu'a aujourd'hui
- Nombre de jours ecoules (X/7)
- Moyenne journaliere de la semaine
- Breakdown par categorie avec pourcentage du total semaine et nombre de transactions
- Comparaison avec la meme periode la semaine precedente (tendance +/-)

**Exemple de sortie:**
```
📅 Resume semaine (03 Mar - 09 Mar)
Jour lundi — 7/7 jour(s)

💳 Total: 342.50€
📊 Moyenne: 48.93€/jour

📈 Par categorie:
  💳 alimentation: 145.00€ (4x) — 42.3%
  💳 transport: 87.50€ (6x) — 25.5%
  💳 restaurant: 110.00€ (3x) — 32.1%

📉 -23.00€ vs sem. precedente
```

---

### 2. `top depenses` — Top depenses individuelles (`top_expenses`)

**Declencheurs:** `top depenses`, `top N depenses`, `grosses depenses`, `grandes depenses`, `depenses importantes`, `plus grosses depenses`

**Fonctionnalites:**
- Top N depenses individuelles du mois en cours (tri decroissant par montant)
- Limit configurable (ex: `top 3 depenses`), plafonnee a 10
- Affiche categorie, date (Aujourd'hui / Hier / d M) et description
- Calcule la part que ces N depenses representent dans le total mensuel

**Exemple de sortie:**
```
🏆 Top 5 depenses - mars 2026

1. 800.00€ logement (01 Mar) — loyer mensuel
2. 245.00€ shopping (05 Mar) — vetements
3. 110.00€ restaurant (07 Mar) — diner anniversaire
4. 87.50€ alimentation (06 Mar)
5. 65.00€ sante (03 Mar) — pharmacie

Ces 5 depenses = 1307.50€ (78.2% du total mois)
```

---

## Resultats des tests

```
Tests:    82 passed (142 assertions)
Duration: 1.89s
```

**Tests nouveaux (22 tests):**
- `test_keywords_include_semaine` ✅
- `test_keywords_include_top_depenses` ✅
- `test_can_handle_resume_semaine` ✅
- `test_can_handle_cette_semaine` ✅
- `test_can_handle_top_depenses` ✅
- `test_can_handle_grosses_depenses` ✅
- `test_parse_command_weekly_summary` ✅
- `test_parse_command_weekly_summary_cette_semaine` ✅
- `test_parse_command_weekly_summary_hebdo` ✅
- `test_parse_command_top_expenses_default` ✅
- `test_parse_command_top_expenses_with_limit` ✅
- `test_parse_command_grosses_depenses` ✅
- `test_weekly_summary_no_expenses` ✅
- `test_weekly_summary_with_expenses` ✅
- `test_weekly_summary_shows_daily_average` ✅
- `test_top_expenses_no_expenses` ✅
- `test_top_expenses_shows_sorted_by_amount` ✅
- `test_top_expenses_shows_percentage_of_total` ✅
- `test_top_expenses_limit_capped_at_10` ✅
- `test_balance_shows_day_of_month` ✅
- `test_help_contains_resume_semaine_command` ✅

**Tests existants (61 tests):** tous passes sans modification.

**Routes:** `php artisan route:list` — 104 routes, aucune erreur.

---

## Fichiers modifies

- `app/Services/Agents/FinanceAgent.php` — version 1.2.0 → 1.3.0
- `tests/Unit/Agents/FinanceAgentTest.php` — 61 → 82 tests
