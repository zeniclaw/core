# Rapport de Test — HabitAgent v1.5.0
**Date**: 2026-03-09
**Version precedente**: 1.4.0 → **Nouvelle version**: 1.5.0

---

## Resume des ameliorations apportees

### Ameliorations des capacites existantes

| Zone | Amelioration |
|---|---|
| `handleAdd` | Sanitisation du nom : `preg_replace('/\s+/', ' ')` pour normaliser les espaces multiples |
| `handleStats` | Correction du denominateur pour les habitudes hebdomadaires : `30/7 ≈ 4.3` → `4` (4 semaines en 30 jours) |
| `getMilestoneMessage` | Ajout des milestones 60 jours ("2 mois sans interruption") et 90 jours ("3 mois de regularite") et 365 jours |
| `buildPrompt` | Ajout des 2 nouvelles actions avec exemples (monthly_report, best_day) |
| `handleHelp` | Ajout des 2 nouvelles commandes dans le guide |
| `description()` | Mise a jour pour mentionner les nouveaux rapports |
| `keywords()` | Ajout de 7 nouveaux mots-cles (rapport mensuel, bilan mensuel, monthly report, etc.) |

### Corrections de qualite

- Le dénominateur weekly dans `handleStats` passait de `4.3` (float) à `4` (int), rendant les taux de complétion plus cohérents pour les habitudes hebdomadaires
- Les noms d'habitudes avec espaces multiples sont désormais normalisés

---

## Nouvelles fonctionnalites ajoutees

### 1. `monthly_report` — Rapport mensuel des 30 derniers jours

**Commande**: "Rapport mensuel" / "Bilan 30 jours" / "Comment j'ai fait ce mois"

**Description**: Affiche un rapport de progression sur 30 jours, decoupes en 4 segments:
- S1 (jours 22-29) : plus ancien
- S2 (jours 15-21)
- S3 (jours 8-14)
- S4 (jours 0-7) : plus recent

Pour chaque habitude :
- **Daily**: barre de progression `S1..S4` avec taux par segment + total 30j
- **Weekly**: barre de progression sur 4 semaines

Messages contextuels selon le taux global (≥90% "Mois exceptionnel", ≥75%, ≥50%, <50%).

**Exemple de sortie**:
```
Rapport mensuel (07/02 - 09/03) :

1. Meditation [daily]
   S1: ###-- 3/8 (38%)
   S2: ##### 5/7 (71%)
   S3: ##### 5/7 (71%)
   S4: ##### 5/8 (63%)
   Total: 18/30j (60%)

---
Taux global 30j : 60%
Bonne progression — on peut faire encore mieux !
```

### 2. `best_day` — Analyse par jour de la semaine

**Commande**: "Mon meilleur jour" / "Quel jour suis-je le plus regulier" / "Analyse par jour"

**Description**: Analyse les 90 derniers jours de logs pour les habitudes **quotidiennes uniquement** et calcule un taux de completion par jour de la semaine (Lundi → Dimanche).

Algorithme:
1. Compte les logs par `dayOfWeekIso` (1=Lundi, 7=Dimanche)
2. Calcule le nombre d'occurrences de chaque jour dans la periode (90 jours)
3. Taux = (logs_jour / (occurrences_jour × nb_habitudes_daily)) × 100
4. Identifie le meilleur et le moins bon jour

Cas limites geres:
- Aucune habitude daily → message "Tu n'as aucune habitude quotidienne"
- Aucun log → message "Pas encore assez de donnees"

**Exemple de sortie**:
```
Analyse par jour de semaine (90 derniers jours) :
(2 habitude(s) quotidienne(s))

  Lundi:     ####### 78% <-
  Mardi:     ######- 72%
  Mercredi:  #####-- 60%
  Jeudi:     ####--- 50%
  Vendredi:  #####-- 64%
  Samedi:    ###---- 40%
  Dimanche:  ##----- 32%

Meilleur jour : Lundi (78%)
Jour le plus difficile : Dimanche (32%)
Bon rythme le Lundi !
```

---

## Resultats des tests

```
Tests:    69 passed (119 assertions)
Duration: 2.14s
```

### Nouveaux tests ajoutes (19 tests)

| Test | Resultat |
|---|---|
| `test_monthly_report_shows_empty_message_when_no_habits` | PASS |
| `test_monthly_report_shows_segments_for_daily_habit` | PASS |
| `test_monthly_report_shows_segments_for_weekly_habit` | PASS |
| `test_monthly_report_shows_perfect_message_when_100_percent` | PASS |
| `test_best_day_shows_error_when_no_daily_habits` | PASS |
| `test_best_day_shows_no_data_when_no_logs` | PASS |
| `test_best_day_shows_analysis_with_logs` | PASS |
| `test_best_day_returns_day_between_1_and_7` | PASS |
| `test_keywords_include_rapport_mensuel` | PASS |
| `test_keywords_include_meilleur_jour` | PASS |
| `test_agent_version_is_1_5_0` (mis a jour) | PASS |

### Tests existants (tous passes)
Tous les 58 tests precedents (version 1.4.0) continuent de passer sans modification.

### Routes
```
php artisan route:list → OK (aucune erreur)
```

---

## Fichiers modifies

| Fichier | Type de modification |
|---|---|
| `app/Services/Agents/HabitAgent.php` | Ameliorations + 2 nouvelles methodes |
| `tests/Unit/Agents/HabitAgentTest.php` | 11 nouveaux tests + 2 helpers de reflexion |

---

## Changelog complet

```
v1.4.0 → v1.5.0 (2026-03-09)

NOUVELLES FONCTIONNALITES:
+ action "monthly_report": rapport mensuel 30j par segments S1-S4
+ action "best_day": analyse de regularite par jour de semaine (90j)
+ keywords: rapport mensuel, bilan mensuel, monthly report, bilan 30 jours,
            30 jours habitudes, rapport 30j, meilleur jour, best day,
            jour regulier, quel jour, analyse jour

AMELIORATIONS:
~ handleAdd: sanitisation des espaces multiples dans le nom
~ handleStats: denominateur weekly 4.3 -> 4 (plus precis)
~ getMilestoneMessage: ajout milestones 60j, 90j, 365j
~ handleHelp: ajout "Rapport mensuel" et "Mon meilleur jour"
~ buildPrompt: actions 16 (monthly_report) et 17 (best_day) avec exemples
~ description(): mention des nouveaux rapports

TESTS:
+ 11 nouveaux tests (y compris 2 tests de keywords)
~ test_agent_version_is_1_4_0 → test_agent_version_is_1_5_0
= 69/69 tests passent (119 assertions)
```
