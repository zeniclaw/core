# Propositions d'amélioration — ChatAgent

## Etat actuel

Le ChatAgent est l'agent par défaut/fallback. Il gère les conversations générales, les questions, et supporte les médias (images, PDF, audio via Whisper). Il injecte le contexte projet et la mémoire conversationnelle dans le prompt.

**Points forts** : support multimodal, personnalité sympa, contexte projet, mémoire.

---

## Proposition 1 : Détection de langue automatique

**Problème** : Tout est hardcodé en français. Si un utilisateur écrit en anglais, l'agent répond quand même en français.

**Solution** : Détecter la langue du message et adapter le system prompt.
- Ajouter dans le prompt : "Réponds dans la même langue que l'utilisateur"
- Ou détecter via Haiku en pre-pass et adapter le prompt

**Impact** : Faible complexité, gros gain d'UX pour les utilisateurs non-francophones.

---

## Proposition 2 : Réponses structurées (listes, tableaux, code)

**Problème** : Le prompt demande des réponses courtes (2-3 phrases), ce qui peut être limitant pour des questions techniques.

**Solution** : Adapter dynamiquement la consigne de longueur selon la complexité routée :
- `simple` → 2-3 phrases max
- `medium` → réponse structurée autorisée (listes, exemples)
- `complex` → réponse longue autorisée avec sections

**Impact** : Meilleure qualité de réponse sans changer de modèle.

---

## Proposition 3 : Mode "recherche web"

**Problème** : L'agent ne peut répondre qu'avec ses connaissances internes (cutoff). Pas d'accès à l'info en temps réel.

**Solution** : Intégrer une recherche web optionnelle quand l'utilisateur pose une question d'actualité ou demande une info précise.
- Détecter via le routeur : `complexity: "web_search"`
- Appeler une API de recherche (Tavily, Brave Search, ou SerpAPI)
- Injecter les résultats dans le prompt avant de répondre

**Impact** : Moyen — nécessite une API externe mais transforme l'agent en assistant vraiment utile au quotidien.

---

## Proposition 4 : Résumé de conversations longues

**Problème** : La mémoire est limitée à 20 entrées. Les conversations longues perdent du contexte.

**Solution** :
- Quand la mémoire dépasse 20 entrées, générer un résumé compressé des anciennes entrées
- Stocker en `longterm` memory au lieu de supprimer
- Le prompt reçoit : résumé long-terme + 20 dernières entrées

**Impact** : Continuité conversationnelle sur plusieurs jours/semaines.

---

## Proposition 5 : Réactions et feedback utilisateur

**Problème** : Aucun mécanisme pour savoir si la réponse était utile.

**Solution** :
- Après une réponse complexe, ajouter un petit message : "Ca t'aide ? (oui/non)"
- Stocker le feedback pour améliorer le routing futur
- Si "non" → proposer de reformuler ou escalader vers un modèle plus puissant

**Impact** : Boucle d'amélioration continue.

---

## Proposition 6 : Cache des contextes projet

**Problème** : Chaque message déclenche une requête DB pour charger les projets. Pas de cache.

**Solution** :
- Mettre en cache Redis le contexte projet par phone+agent_id (TTL 5 min)
- Invalider le cache quand un projet est modifié

**Impact** : Performance — réduit les requêtes DB répétitives.

---

## Proposition 7 : Support vidéo (résumé)

**Problème** : Les vidéos ne sont pas traitées (fallback texte).

**Solution** :
- Extraire la première frame de la vidéo avec ffmpeg
- Envoyer en vision à Claude pour décrire
- Extraire l'audio et transcrire via Whisper
- Combiner : description visuelle + transcription

**Impact** : Support médias complet.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 1 | Détection de langue | Faible | Haut |
| 2 | Réponses adaptatives | Faible | Moyen |
| 6 | Cache contexte projet | Faible | Moyen |
| 4 | Résumé conversations | Moyen | Haut |
| 3 | Recherche web | Moyen | Haut |
| 5 | Feedback utilisateur | Moyen | Moyen |
| 7 | Support vidéo | Haut | Moyen |
