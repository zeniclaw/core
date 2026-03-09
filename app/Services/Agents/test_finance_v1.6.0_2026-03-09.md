# Rapport de test — FinanceAgent v1.6.0
**Date :** 2026-03-09
**Version precedente :** 1.5.0 → **Nouvelle version : 1.6.0**

---

## Resume des ameliorations apportees

### Ameliorations des capacites existantes

| Cible | Amelioration |
|-------|-------------|
| `logExpense` | Affiche le total depense aujourd'hui apres enregistrement si d'autres depenses existent ce jour (💡 Aujourd'hui total: X€) |
| `getBalance` | Affiche le budget journalier disponible (€/jour) base sur le budget restant et les jours restants du mois |
| `getAlerts` | Ajoute un warning de projection en bas : si la moyenne journaliere projette un depassement de budget en fin de mois, affiche 🔮 Projection et ⚠️ Depassement projete |
| `buildSystemPrompt` | Ajout des deux nouvelles commandes (`budget journalier`, `export`) dans la liste des commandes disponibles |
| `getHelp` | Mise a jour avec les deux nouvelles commandes |
| `keywords()` | Ajout de 9 nouveaux mots-cles : `budget journalier`, `budget du jour`, `combien par jour`, `par jour`, `disponible jour`, `quota journalier`, `export`, `exporter`, `liste complete`, `tout le mois`, `toutes depenses`, `toutes les depenses` |
| `canHandle()` | Ajout de 2 nouveaux patterns regex pour les nouvelles commandes |
| `parseCommand()` | Ajout de 2 nouveaux blocs de parsing (`daily_budget`, `export_month`) |
| `executeCommand()` | Ajout des 2 nouvelles actions dans le match |

---

## Nouvelles fonctionnalites

### 1. `getDailyBudget` — Budget journalier disponible
**Commande :** `budget journalier`, `budget du jour`, `combien par jour`, `par jour`, `quota journalier`, `disponible jour`

- Affiche le budget restant global divise par le nombre de jours restants dans le mois (incluant aujourd'hui)
- Affiche les depenses d'aujourd'hui et le quota restant pour la journee
- Si budget global depasse : alerte 🚨
- Si plusieurs budgets actifs : affichage par categorie du budget journalier disponible
- Si aucun budget defini : message d'invite a en creer un

**Exemple de sortie :**
```
📅 Budget journalier — mars 2026
Jour 9/31 • 23 jour(s) restant(s)

💰 Restant global: *245€*
📊 Disponible par jour: *10.65€/jour*

📌 Aujourd'hui: 25€ depenses
⚠️ Quota journalier depasse de *14.35€*

📋 Par categorie (restant/jour):
✅ *alimentation*: 7.83€/jour
✅ *transport*: 2.83€/jour
```

### 2. `exportMonth` — Export complet du mois
**Commande :** `export`, `exporter`, `liste complete`, `tout le mois`, `toutes les depenses`

- Liste toutes les depenses du mois courant groupees par date (ordre chronologique)
- Affiche le sous-total par journee
- Affiche le total general en bas
- Affiche le resume budget si defini (% utilise, restant ou depassement)
- Utile pour avoir une vue exhaustive du mois ou pour copier-coller

**Exemple de sortie :**
```
📤 Export complet — mars 2026
12 depense(s) • Total: *687.50€*
─────────────────────────

📅 *3 mars (mardi)* — 150€
  • 150€ logement loyer

📅 *5 mars (jeudi)* — 87.50€
  • 45€ alimentation courses
  • 42.50€ restaurant diner

📅 *Aujourd'hui 9 mars* — 450€
  • 25€ transport taxi
  • 425€ shopping vetements

─────────────────────────
💳 *TOTAL: 687.50€*
📊 Budget: 687.50€/900€ (76.4%) • Restant: 212.50€
```

---

## Resultats des tests

### Tests FinanceAgent specifiques

```
php artisan test tests/Unit/Agents/FinanceAgentTest.php
Tests: 151 passed (255 assertions)
Duration: 3.40s
```

**Tous les tests passent. Aucune regression.**

### Nouveaux tests ajoutes (v1.6.0) — 24 nouveaux tests

| Groupe | Tests |
|--------|-------|
| keywords v1.6.0 | `test_keywords_include_budget_journalier`, `test_keywords_include_export` |
| canHandle v1.6.0 | `test_can_handle_budget_journalier`, `test_can_handle_combien_par_jour`, `test_can_handle_export`, `test_can_handle_exporter` |
| parseCommand v1.6.0 | `test_parse_command_daily_budget`, `test_parse_command_daily_budget_combien`, `test_parse_command_export_month`, `test_parse_command_export_month_exporter` |
| getDailyBudget | `test_daily_budget_no_budget_defined`, `test_daily_budget_shows_daily_allowance`, `test_daily_budget_shows_today_expenses`, `test_daily_budget_exceeded`, `test_daily_budget_multi_category_breakdown` |
| exportMonth | `test_export_month_no_expenses`, `test_export_month_shows_all_expenses`, `test_export_month_shows_total`, `test_export_month_shows_budget_if_defined`, `test_export_month_groups_by_date` |
| logExpense today total | `test_log_expense_shows_today_total_when_multiple` |
| getAlerts projection | `test_alerts_shows_projection_warning_when_over_budget` |
| getBalance daily | `test_balance_shows_daily_allowance_when_budget_defined` |
| getHelp v1.6.0 | `test_help_contains_daily_budget_command` |

### Test de version mis a jour
- `test_agent_version_is_1_5_0` → `test_agent_version_is_1_6_0`

### Routes
```
php artisan route:list → 104 routes, aucune erreur
```

---

## Fichiers modifies

| Fichier | Modification |
|---------|-------------|
| `app/Services/Agents/FinanceAgent.php` | Version 1.5.0 → 1.6.0, 2 nouvelles methodes, ameliorations logExpense/getBalance/getAlerts, keywords, canHandle, parseCommand, executeCommand, system prompt, getHelp |
| `tests/Unit/Agents/FinanceAgentTest.php` | Mise a jour version test + 24 nouveaux tests |

---

## Compatibilite

- Interface `AgentInterface` : respectee (pas de changement de signature)
- `BaseAgent` : respectee (pas de modification)
- `RouterAgent` / `AgentOrchestrator` : non modifies
- Migrations : non modifiees
- DB : compatible PostgreSQL et MySQL
