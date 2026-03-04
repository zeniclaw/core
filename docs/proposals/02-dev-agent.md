# Propositions d'amélioration — DevAgent + RunSubAgentJob

## Etat actuel

Le DevAgent gère les demandes de modification de code : il analyse la demande, trouve le projet, crée un SubAgent qui clone le repo, exécute Claude Code, commit, push, crée une MR et vérifie le déploiement. Pipeline complet automatisé.

**Points forts** : pipeline end-to-end, vérification auto, auto-merge, itération sur branches existantes.

---

## Proposition 1 : Support multi-plateforme Git

**Problème** : Uniquement GitLab supporté. Pas de GitHub, Gitea, Bitbucket.

**Solution** :
- Abstraire l'interface Git derrière un `GitProvider` (interface)
- Implémentations : `GitLabProvider`, `GitHubProvider`
- Détection automatique par URL du repo
- Adapter clone, MR/PR creation, merge

**Impact** : Ouvre ZeniClaw à tout l'écosystème Git.

---

## Proposition 2 : Preview de diff avant merge

**Problème** : L'utilisateur reçoit une notification "MR créée" mais ne voit pas ce qui a changé.

**Solution** :
- Après le commit, envoyer un résumé du diff à l'utilisateur via WhatsApp
- Format : "J'ai modifié 3 fichiers : ..." + les changements clés (résumé par Haiku)
- Demander confirmation avant merge : "Je merge ?" (oui/non)

**Impact** : Contrôle utilisateur, moins de surprises.

---

## Proposition 3 : Tests automatiques

**Problème** : La vérification actuelle ne fait que du syntax check PHP + analyse Haiku du diff. Pas de tests réels.

**Solution** :
- Après les modifications, détecter et lancer les tests existants : `php artisan test`, `npm test`, `pytest`
- Si tests échouent → auto-retry avec le rapport d'erreur comme contexte
- Rapporter le résultat à l'utilisateur

**Impact** : Qualité de code garantie avant merge.

---

## Proposition 4 : Mode itératif conversationnel

**Problème** : Chaque demande lance un nouveau SubAgent complet (clone, branch, etc.). Pas de conversation continue sur un même changement.

**Solution** :
- Après une tâche terminée, garder le workspace pendant 30 min
- Si l'utilisateur dit "modifie plutôt X" ou "ajoute aussi Y" → réutiliser le même workspace
- Eviter le re-clone, re-branch

**Impact** : Workflow plus naturel et rapide pour les itérations.

---

## Proposition 5 : Rollback automatique

**Problème** : Si un déploiement échoue, aucun mécanisme de rollback.

**Solution** :
- Sauvegarder le commit hash avant modification
- Si le déploiement échoue (erreurs dans les logs) :
  - Revert automatique du commit
  - Push force sur la branche
  - Notifier l'utilisateur : "Le déploiement a échoué, j'ai annulé les changements"

**Impact** : Sécurité en production.

---

## Proposition 6 : Support de contexte élargi

**Problème** : Le diff est limité à 8000 caractères pour la vérification. Les gros changements sont tronqués.

**Solution** :
- Augmenter la limite à 15000 chars
- Pour les très gros diffs, envoyer un résumé par fichier au lieu du diff brut
- Utiliser Sonnet au lieu de Haiku pour la vérification des changements complexes

**Impact** : Meilleure vérification sur les grosses tâches.

---

## Proposition 7 : Notifications de progression en temps réel

**Problème** : L'utilisateur reçoit des messages à chaque étape mais pas de détail sur ce que Claude Code fait.

**Solution** :
- Pendant l'exécution de Claude Code, envoyer des updates toutes les 30s :
  "En train de modifier `UserController.php`..."
  "Tests en cours..."
- Basé sur le stream JSON déjà parsé

**Impact** : Transparence, l'utilisateur sait ce qui se passe.

---

## Proposition 8 : Analyse de sécurité post-commit

**Problème** : Pas de vérification de sécurité sur le code généré.

**Solution** :
- Après le commit, scanner le diff pour :
  - Secrets/credentials hardcodés
  - Injections SQL, XSS, CSRF
  - Dépendances vulnérables
- Utiliser Haiku avec un prompt dédié sécurité
- Bloquer le merge si problème critique détecté

**Impact** : Sécurité by default.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 2 | Preview diff avant merge | Faible | Haut |
| 7 | Notifications progression | Faible | Moyen |
| 3 | Tests automatiques | Moyen | Haut |
| 5 | Rollback automatique | Moyen | Haut |
| 4 | Mode itératif | Moyen | Haut |
| 6 | Contexte élargi | Faible | Moyen |
| 8 | Analyse sécurité | Moyen | Haut |
| 1 | Multi-plateforme Git | Haut | Haut |
