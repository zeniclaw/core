# Propositions d'amélioration — ProjectAgent

## Etat actuel

Le ProjectAgent gère le switch de projet actif. Il utilise un matching intelligent (exact, slug, IA) pour trouver le projet, demande confirmation, et met à jour la session. Pas de création de projet via WhatsApp.

**Points forts** : matching multi-stratégie, confirmation avant switch, contexte session.

---

## Proposition 1 : Création de projet via WhatsApp

**Problème** : Impossible de créer un projet depuis WhatsApp. Il faut passer par l'interface web.

**Solution** :
- "Crée un projet mon-app avec le repo gitlab.com/..."
- L'agent extrait nom + URL GitLab via Haiku
- Crée le projet en status `pending` (si whitelist) ou `approved` (si admin)
- Envoie confirmation : "Projet mon-app créé ! Tu veux bosser dessus ?"

**Impact** : Autonomie complète depuis WhatsApp.

---

## Proposition 2 : Résumé de projet intelligent

**Problème** : Quand on switch de projet, aucun résumé de l'état actuel.

**Solution** :
- Au switch, afficher automatiquement :
  - Dernière tâche + son statut
  - Nombre de tâches réalisées
  - Dernier commit / dernière MR
  - Problèmes en cours
- Format : "Projet X activé ! Dernière tâche : 'fix login' (terminée il y a 2h). 12 tâches réalisées."

**Impact** : Contexte immédiat, l'utilisateur sait où il en est.

---

## Proposition 3 : Favoris et raccourcis

**Problème** : Si l'utilisateur a beaucoup de projets, le matching peut être lent ou ambigu.

**Solution** :
- Permettre de marquer des projets en favoris
- "Mes projets" → affiche les favoris en premier
- Raccourcis : "1", "2", "3" pour switcher rapidement entre les favoris
- Stocker les favoris par utilisateur (dans la session ou un nouveau champ)

**Impact** : UX rapide pour les utilisateurs multi-projets.

---

## Proposition 4 : Archivage et historique

**Problème** : Les projets terminés restent dans la liste sans distinction claire.

**Solution** :
- Permettre d'archiver un projet : "Archive le projet X"
- Les projets archivés n'apparaissent plus dans les listes par défaut
- "Tous mes projets" → inclut les archivés
- Historique : "Historique du projet X" → liste toutes les tâches

**Impact** : Organisation, liste propre.

---

## Proposition 5 : Permissions et rôles par projet

**Problème** : `allowed_phones` est un simple array. Pas de distinction de rôles.

**Solution** :
- Ajouter des rôles : `owner`, `developer`, `viewer`
- `owner` peut modifier le projet, approuver des tâches
- `developer` peut créer des tâches
- `viewer` peut voir le statut mais pas modifier
- "Ajoute Pierre en dev sur mon-app"

**Impact** : Collaboration structurée.

---

## Proposition 6 : Notifications de projet

**Problème** : Les notifications de projet sont basiques (juste à la création).

**Solution** :
- Notifier les membres quand :
  - Une tâche est terminée
  - Un déploiement est fait
  - Une MR est mergée
  - Un membre rejoint/quitte
- Configurable : chacun choisit ses notifications
- "Mute le projet X" / "Active les notifs pour X"

**Impact** : Suivi d'équipe en temps réel.

---

## Proposition 7 : Statistiques de projet

**Problème** : Pas de vue d'ensemble quantitative.

**Solution** :
- "Stats du projet X" →
  ```
  Projet mon-app :
  - 15 tâches réalisées
  - 3 en cours
  - Dernier déploiement : il y a 2h
  - Temps moyen par tâche : 12 min
  - Modèles utilisés : Opus 60%, Sonnet 30%, Haiku 10%
  ```
- Basé sur les SubAgent logs existants

**Impact** : Visibilité, ROI mesurable.

---

## Proposition 8 : Intégration CI/CD status

**Problème** : Aucune visibilité sur les pipelines CI/CD depuis WhatsApp.

**Solution** :
- "Pipeline de mon-app ?" → statut du dernier pipeline GitLab
- Notification automatique si un pipeline échoue
- "Relance le pipeline" → trigger via API GitLab

**Impact** : Monitoring depuis WhatsApp.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 2 | Résumé au switch | Faible | Haut |
| 1 | Création via WhatsApp | Moyen | Haut |
| 7 | Statistiques projet | Moyen | Haut |
| 4 | Archivage | Faible | Moyen |
| 6 | Notifications | Moyen | Haut |
| 3 | Favoris/raccourcis | Faible | Moyen |
| 8 | CI/CD status | Moyen | Moyen |
| 5 | Rôles/permissions | Haut | Moyen |
