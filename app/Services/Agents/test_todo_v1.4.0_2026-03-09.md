# Rapport d'amélioration — TodoAgent v1.3.0 → v1.4.0
**Date :** 2026-03-09
**Fichier :** `app/Services/Agents/TodoAgent.php`

---

## Résumé des améliorations

### Capacités existantes améliorées

| Élément | Avant | Après |
|---|---|---|
| `buildAllListsOverview()` | Listes non triées | Listes triées alphabétiquement |
| `buildAllListsOverview()` | Pas d'indicateur priorité | Affiche `🔴N` si tâches urgentes |
| `buildAllListsOverview()` | Hint basique | Total tâches + "tâches de la semaine" hint |
| `buildStats()` | Pas de prochaine échéance | Affiche "Prochaine échéance : X (dans Nj)" |
| `buildStats()` | Listes non triées | Listes triées alphabétiquement (`sortKeys()`) |
| `buildReply()` | Pas d'avertissement troncature | Avertissement si liste >= MAX_TODOS (50) |
| `handleHelp()` | Commandes v1.3 | Nouvelles commandes ajoutées |
| `keywords()` | 54 keywords | 58 keywords (nouveaux: "a venir", "prochains", "tout cocher", "changer categorie", etc.) |
| `description()` | v1.3 | Mention due_soon, check_all, set_category |
| Prompt LLM | 15 actions | 19 actions (due_soon, check_all, uncheck_all, set_category) |
| Prompt LLM | Schéma JSON sans `days`/`new_category` | Schéma complet avec `days` et `new_category` |

### Améliorations prompt
- Nouvelles sections dédiées : `TACHES A VENIR`, `COCHAGE EN MASSE`, `CHANGEMENT DE CATEGORIE`
- 9 nouveaux exemples JSON dans la section EXEMPLES
- Description détaillée de chaque nouvelle action

---

## Nouvelles fonctionnalités

### 1. `due_soon` — Tâches à venir / en retard
**Trigger :** "tâches de la semaine", "quoi de prévu", "en retard", "tâches dues dans 3 jours"
**Paramètre :** `days` (entier, défaut: 7) — fenêtre temporelle
**Comportement :**
- Requête DB filtrée : `is_done=false`, `due_at <= now+Nj`, trié par `due_at`
- Scindé en deux sections : ⚠️ En retard / ⏰ À venir
- Scope optionnel par liste
- `days=0` → uniquement les tâches en retard

**Exemples :**
```
"tâches de la semaine"         → due_soon, days: 7
"tâches dues demain"           → due_soon, days: 1
"quelles tâches sont en retard" → due_soon, days: 0
"tâches urgentes dans courses" → due_soon, days: 7, list_name: "courses"
```

---

### 2. `check_all` / `uncheck_all` — Cochage en masse
**Trigger :** "j'ai tout fait", "tout cocher", "tout terminé dans courses" / "recommencer la liste"
**Comportement :**
- `check_all` : coche toutes les tâches `is_done=false` d'une liste (ou globalement)
- `uncheck_all` : décoche toutes les tâches `is_done=true`
- Message de confirmation avec compteur et emoji 🎉
- Gestion du cas vide (tout déjà coché/décoché)

**Exemples :**
```
"j'ai tout fait dans courses"  → check_all, list_name: "courses"
"tout cocher"                  → check_all, list_name: null
"recommencer la liste courses" → uncheck_all, list_name: "courses"
```

---

### 3. `set_category` — Changement de catégorie
**Trigger :** "change catégorie du 2 en travail", "met en catégorie sante", "supprime catégorie du 3"
**Paramètre :** `new_category` (string ou null pour supprimer)
**Comportement :**
- Trouve la tâche par numéro (scoped à la liste si spécifiée)
- Met à jour `category` en DB
- Retourne l'emoji de catégorie correspondant
- `new_category: null` → supprime la catégorie

**Exemples :**
```
"change catégorie du 2 en travail" → set_category, items: [2], new_category: "travail"
"supprime catégorie du 3"         → set_category, items: [3], new_category: null
```

---

## Résultats des tests

```
php artisan test
Tests:    37 failed, 120 passed (328 assertions)
Duration: 31.50s
```

### Analyse des échecs
Tous les échecs sont **pré-existants** (confirmé par `git stash` + re-test) :

| Catégorie | Nb | Cause |
|---|---|---|
| `Auth\*` (login, register, etc.) | 16 | Pré-existant, non lié |
| `ProfileTest` | 5 | Pré-existant, non lié |
| `ZeniClawSelfTest` (update/health) | 2 | Pré-existant, non lié |
| `SmartContextAgentTest` | 1 | Pré-existant (agents.index → 500) |
| Autres | ~13 | Pré-existants |

**Aucun test ne casse suite aux modifications du TodoAgent.**

### Vérification syntaxique
```
php -l app/Services/Agents/TodoAgent.php
No syntax errors detected
```

### Routes
```
php artisan route:list → 104 routes, aucune erreur
```

---

## Versions

| | Valeur |
|---|---|
| Version précédente | `1.3.0` |
| Nouvelle version | `1.4.0` |
| Méthode `version()` | `return '1.4.0';` |
| Nouvelles actions LLM | `due_soon`, `check_all`, `uncheck_all`, `set_category` |
| Nouveaux handlers | `handleDueSoon()`, `handleCheckAll()`, `handleSetCategory()` |
| Lignes de code | 1265 → ~1430 |
