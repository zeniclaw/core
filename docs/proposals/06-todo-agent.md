# Propositions d'amélioration — TodoAgent

## Etat actuel

Le TodoAgent gère une checklist via WhatsApp : add, check, uncheck, delete, list. Les todos avec horaire récurrent créent automatiquement un reminder. Réponses avec emojis.

**Points forts** : CRUD complet, lien avec reminders récurrents, emojis.

---

## Proposition 1 : Catégories / listes multiples

**Problème** : Tous les todos sont dans une seule liste plate. Pas de distinction "courses", "travail", "perso".

**Solution** :
- Ajouter un champ `category` au modèle Todo
- "Ajoute acheter du pain dans ma liste courses"
- "Ma liste courses" → filtre par catégorie
- "Ma todo" (sans catégorie) → toutes les listes groupées
- Format :
  ```
  🛒 Courses :
  1. ⬜ Acheter du pain
  2. ✅ Acheter du lait

  💼 Travail :
  3. ⬜ Envoyer le rapport
  ```

**Impact** : Organisation, scalabilité.

---

## Proposition 2 : Priorités

**Problème** : Pas de notion de priorité. Tout est au même niveau.

**Solution** :
- Ajouter un champ `priority` : `high`, `normal`, `low`
- "Ajoute URGENT appeler le dentiste"
- Affichage avec indicateur : 🔴 urgent, 🟡 normal, 🟢 low
- Tri par priorité dans la liste

**Impact** : Priorisation, focus sur l'important.

---

## Proposition 3 : Dates d'échéance (deadlines)

**Problème** : Les todos n'ont pas de deadline. Seuls les récurrents ont un horaire.

**Solution** :
- Ajouter un champ `due_at` au modèle
- "Ajoute finir le rapport pour vendredi"
- Affichage : "⬜ Finir le rapport (vendredi 05/03)"
- Notifications quand une deadline approche (J-1, jour J)
- Todos en retard affichés en rouge : "⚠️ EN RETARD"

**Impact** : Gestion du temps, rappels proactifs.

---

## Proposition 4 : Sous-tâches

**Problème** : Pas de hiérarchie. Impossible de décomposer une tâche.

**Solution** :
- Ajouter un champ `parent_id` (self-reference)
- "Ajoute 'préparer la réunion' avec sous-tâches : réserver la salle, envoyer l'ordre du jour, imprimer les docs"
- Affichage indenté :
  ```
  1. ⬜ Préparer la réunion
     1.1 ✅ Réserver la salle
     1.2 ⬜ Envoyer l'ordre du jour
     1.3 ⬜ Imprimer les docs
  ```
- Parent auto-coché quand toutes les sous-tâches sont faites

**Impact** : Gestion de tâches complexes.

---

## Proposition 5 : Assignation à d'autres personnes

**Problème** : Les todos sont personnels. Pas de partage ou délégation.

**Solution** :
- "Assigne 'envoyer le rapport' à Pierre"
- Notifier Pierre via WhatsApp : "Guillaume t'a assigné : Envoyer le rapport"
- Pierre peut cocher depuis son WhatsApp
- Vue partagée : "Todos de l'équipe" → tous les todos assignés

**Impact** : Collaboration d'équipe.

---

## Proposition 6 : Historique et stats

**Problème** : Quand un todo est supprimé, il disparaît. Pas d'historique.

**Solution** :
- Soft-delete au lieu de hard-delete
- "Mon historique" → todos complétés cette semaine
- Stats :
  ```
  Cette semaine :
  ✅ 12 tâches complétées
  ⬜ 3 en cours
  Taux de complétion : 80%
  ```
- Gamification optionnelle : streak de jours consécutifs

**Impact** : Motivation, suivi de productivité.

---

## Proposition 7 : Templates de listes

**Problème** : L'utilisateur recrée les mêmes listes régulièrement (ex: courses hebdo).

**Solution** :
- "Sauvegarde ma liste courses comme template"
- "Charge le template courses" → recrée les todos non cochés
- Templates personnels stockés en base

**Impact** : Gain de temps pour les listes récurrentes.

---

## Proposition 8 : Intégration intelligente avec le contexte

**Problème** : Le TodoAgent fonctionne de manière isolée. Il ne connaît pas le contexte des autres agents.

**Solution** :
- Quand un DevAgent termine une tâche liée à un todo → auto-cocher le todo
- Quand un reminder est acknowledgé → cocher le todo associé
- "Ajoute une tâche pour chaque issue GitLab ouverte" → import depuis GitLab
- Synchronisation bidirectionnelle avec les issues GitLab

**Impact** : Écosystème connecté, source unique de vérité.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 3 | Deadlines | Moyen | Critique |
| 1 | Catégories | Faible | Haut |
| 2 | Priorités | Faible | Haut |
| 6 | Historique/stats | Faible | Moyen |
| 7 | Templates | Moyen | Moyen |
| 4 | Sous-tâches | Moyen | Moyen |
| 5 | Assignation | Haut | Haut |
| 8 | Intégration contexte | Haut | Haut |
