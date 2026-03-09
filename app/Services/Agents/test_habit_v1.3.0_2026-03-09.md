# Rapport d'amélioration HabitAgent — v1.3.0

**Date :** 2026-03-09
**Version précédente :** 1.2.0
**Nouvelle version :** 1.3.0

---

## Résumé des améliorations

### Corrections de bugs

1. **Streak hebdomadaire incorrect** (bug critique)
   - Avant : les habitudes `weekly` utilisaient un calcul de streak en jours consécutifs, ce qui était incorrect
   - Après : nouveau moteur `calculateConsecutiveWeeks()` — compte les semaines ISO consécutives (lundi–dimanche)
   - Le streak `weekly` s'affiche désormais en "semaines" (`sem`) et non en "jours"

2. **Double-log des habitudes hebdomadaires** (bug)
   - Avant : un utilisateur pouvait cocher une habitude `weekly` plusieurs fois par semaine (une entrée par jour)
   - Après : vérification `whereBetween` sur la semaine courante — message "déjà fait cette semaine (le DD/MM)" si doublon

3. **Détection du nouveau record personnel** (bug logique)
   - Avant : `$newStreak === $bestStreak && $newStreak > 1` pouvait afficher "Nouveau record" même si c'était une égalité
   - Après : `$newStreak > $oldBestStreak` (comparaison avant mise à jour du best_streak)

4. **Unlog des habitudes hebdomadaires**
   - Avant : `handleUnlog` cherchait uniquement le log d'aujourd'hui, même pour les habitudes `weekly`
   - Après : recherche `whereBetween` sur la semaine pour les habitudes `weekly`

---

## Améliorations des capacités existantes

### handleList
- Vérification weekly (par semaine) en plus du check daily (par jour)
- Unité de streak adaptée : `j` (daily) vs `sem` (weekly)

### handleToday
- Streak affiché en `j` pour daily, `sem` pour weekly

### handleStats
- Unité de streak adaptée par fréquence (`j` / `sem`)
- Vérification "fait cette semaine" pour les habitudes weekly

### handleLog
- Message "déjà coché" amélioré avec unité correcte (jour/jours, semaine/semaines)

### getMilestoneMessage
- Paramètre `$frequency` ajouté
- Milestones adaptés pour les habitudes `weekly` : 2, 4, 8, 12, 26, 52 semaines
- Correction de la détection du nouveau record

### formatHabitList
- Passage de `$habit->frequency` à `calculateStreak` pour calcul correct

### calculateStreak
- Nouveau paramètre `string $frequency = 'daily'`
- Délègue vers `calculateConsecutiveDays()` ou `calculateConsecutiveWeeks()`

---

## Nouvelles capacités

### 1. `change_frequency` — Changer la fréquence d'une habitude
- Permet de basculer une habitude entre `daily` et `weekly`
- Invalide le cache du streak (recalcul avec la nouvelle unité)
- Empêche de changer vers la même fréquence (message informatif)
- Commandes : *"Passer habitude 2 en hebdo"*, *"Changer frequence meditation en quotidien"*

### 2. `motivate` — Bilan motivation
- Affiche les habitudes faites (on track), celles dont le streak est en jeu (at risk), et celles non commencées
- Message d'encouragement contextuel (toutes faites / >50% / streaks en jeu / rien fait)
- Ne nécessite pas d'appel LLM supplémentaire — calcul purement en PHP/DB
- Commandes : *"Motivation"*, *"Mes streaks en jeu"*, *"Encourage-moi"*

---

## Mise à jour du prompt LLM

- Ajout des actions `change_frequency` et `motivate` avec exemples
- Mise à jour du message de fallback (mention de "Motivation")
- Mise à jour du guide `handleHelp` avec les nouvelles commandes

## Mise à jour des keywords

- Ajout : `changer frequence`, `changer la frequence`, `change frequency`
- Ajout : `passer en hebdo`, `passer en quotidien`, `modifier frequence`
- Ajout : `motivation habitude`, `motiver`, `encouragement habitude`, `motivate`

---

## Résultats des tests

```
php artisan test
Tests:    41 failed, 81 passed (226 assertions)
```

- **Aucun nouveau test échoué** introduit par les modifications HabitAgent
- Tous les 41 échecs sont **pré-existants** (Auth, SmartMeeting, Profile, ZeniClawSelf, SmartContext)
- Aucun test spécifique à HabitAgent n'existait avant cette version
- `php artisan route:list` : 104 routes OK, aucune erreur

---

## Fichiers modifiés

- `app/Services/Agents/HabitAgent.php` — version 1.2.0 → 1.3.0
