# Propositions d'amélioration — ReminderAgent

## Etat actuel

Le ReminderAgent extrait les infos de rappel depuis un message WhatsApp via Haiku, crée un Reminder en base avec scheduled_at et recurrence_rule optionnel. ProcessReminders envoie les rappels dus et re-planifie les récurrents.

**Points forts** : création naturelle par message, récurrence, timezone Europe/Paris.

---

## Proposition 1 : Gestion des reminders existants (CRUD complet)

**Problème** : L'agent ne peut que créer des reminders. Impossible de lister, modifier, supprimer ou reporter un reminder existant.

**Solution** :
- Injecter la liste des reminders actifs dans le prompt (comme le TodoAgent)
- Ajouter les actions : `list`, `delete`, `postpone`, `modify`
- "Mes rappels" → liste des reminders pending
- "Supprime le rappel pour Jean" → delete
- "Reporte le rappel à demain" → update scheduled_at
- "Change le rappel sport à 7h30" → modify

**Impact** : Gestion complète, indispensable.

---

## Proposition 2 : Confirmation de réception

**Problème** : Quand un reminder est envoyé, on ne sait pas si l'utilisateur l'a vu/traité.

**Solution** :
- Après envoi du rappel, ajouter : "Réponds 'fait' pour confirmer"
- Si l'utilisateur répond "fait", "ok", "done" → marquer comme `acknowledged`
- Si pas de réponse après X minutes → re-envoyer (snooze automatique)
- Stocker le statut : `pending` → `sent` → `acknowledged` ou `snoozed`

**Impact** : Reminders vraiment utiles — pas ignorés.

---

## Proposition 3 : Snooze / Reporter

**Problème** : Quand un rappel arrive au mauvais moment, pas moyen de le reporter.

**Solution** :
- Quand un reminder est envoyé, proposer : "Rappel : {message}\nReporte ? (5min / 1h / demain)"
- L'utilisateur répond "1h" → re-schedule +1h
- Intégrer dans le routeur : un message court après un reminder = snooze intent

**Impact** : UX naturelle, comme un vrai réveil.

---

## Proposition 4 : Reminders par localisation

**Problème** : Uniquement des rappels temporels. Pas de rappels "quand j'arrive à la maison".

**Solution** :
- Stocker une localisation optionnelle (`location_trigger`)
- Quand l'utilisateur partage sa position WhatsApp, vérifier les reminders géolocalisés
- Ex : "Rappelle-moi d'acheter du lait quand je suis au supermarché"

**Impact** : Haut effort, mais feature différenciante.

---

## Proposition 5 : Reminders pour d'autres personnes

**Problème** : Les reminders sont uniquement pour l'utilisateur lui-même.

**Solution** :
- "Rappelle à Pierre d'envoyer le rapport demain à 10h"
- Créer le reminder avec `requester_phone = Pierre`
- Envoyer le message à Pierre directement
- Option : notifier aussi le créateur quand le reminder est envoyé

**Impact** : Collaboration, délégation.

---

## Proposition 6 : Recurring avancé (expressions naturelles)

**Problème** : La récurrence est limitée à daily/weekly/monthly. Pas de "tous les 2 jours", "le premier lundi du mois", "les jours de semaine".

**Solution** :
- Etendre le format : `weekdays:08:00`, `biweekly:monday:09:00`, `every:3:days:08:00`
- Ou passer à une expression cron standard pour plus de flexibilité
- Le prompt Haiku extrait la récurrence en format étendu

**Impact** : Moyen effort, flexibilité accrue.

---

## Proposition 7 : Canaux multiples

**Problème** : Uniquement WhatsApp. Pas d'email, SMS, ou notification push.

**Solution** :
- Ajouter un champ `channels` (array) au lieu de `channel` (string)
- "Rappelle-moi par email et WhatsApp"
- Intégrer un provider email (SMTP/Mailgun) et optionnellement SMS (Twilio)

**Impact** : Haut effort, mais utile pour les rappels importants.

---

## Proposition 8 : Vue agenda / timeline

**Problème** : Aucune vue d'ensemble des rappels à venir.

**Solution** :
- "Mon agenda" → liste les reminders des 7 prochains jours, groupés par jour
- Format :
  ```
  Tes prochains rappels :
  Aujourd'hui :
    - 14:00 Appeler Jean
  Demain :
    - 09:00 Réunion équipe
  Jeudi :
    - 09:00 Sortir les poubelles (récurrent)
  ```

**Impact** : Faible effort, haute visibilité.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 1 | CRUD complet | Moyen | Critique |
| 8 | Vue agenda | Faible | Haut |
| 3 | Snooze / Reporter | Faible | Haut |
| 2 | Confirmation réception | Moyen | Haut |
| 6 | Récurrence avancée | Moyen | Moyen |
| 5 | Reminders pour autrui | Moyen | Moyen |
| 7 | Canaux multiples | Haut | Moyen |
| 4 | Localisation | Haut | Faible |
