# Propositions d'amélioration — AnalysisAgent

## Etat actuel

L'AnalysisAgent fournit des analyses approfondies en utilisant Sonnet/Opus selon la complexité. Il injecte le contexte projet et la mémoire conversationnelle. Réponses longues et structurées autorisées.

**Points forts** : modèles puissants, analyse structurée, contexte projet.

---

## Proposition 1 : Analyse de documents (PDF, images)

**Problème** : L'AnalysisAgent ne traite que du texte. Pas de support documents envoyés.

**Solution** :
- Intégrer le même support multimodal que ChatAgent (images, PDF)
- Quand un PDF est envoyé avec "analyse ce document" → extraction + analyse
- Résumé structuré : points clés, recommandations, risques identifiés
- Support de documents multi-pages (itérer par blocs si nécessaire)

**Impact** : Use case majeur — analyse de contrats, rapports, factures.

---

## Proposition 2 : Analyse comparative

**Problème** : L'agent analyse un seul sujet à la fois. Pas de comparaison structurée.

**Solution** :
- Détecter les demandes de comparaison : "compare X et Y", "X vs Y"
- Réponse en tableau structuré :
  ```
  | Critère | Option A | Option B |
  |---------|---------|---------|
  | Prix | ... | ... |
  | Performance | ... | ... |
  ```
- Conclusion avec recommandation claire

**Impact** : Aide à la décision, très utile pour les choix techniques.

---

## Proposition 3 : Analyse de code / code review

**Problème** : L'analyse est générique. Pas de spécialisation code.

**Solution** :
- Quand le contexte projet est actif, permettre "Analyse le code de la page login"
- Cloner le repo (comme DevAgent), lire les fichiers pertinents
- Produire un code review : qualité, sécurité, performance, maintenabilité
- Format structuré avec score par catégorie

**Impact** : Code review automatisé, complémentaire au DevAgent.

---

## Proposition 4 : Export des analyses

**Problème** : Les analyses sont envoyées sur WhatsApp uniquement. Pas d'export.

**Solution** :
- Après une analyse, proposer : "Tu veux que j'exporte en PDF ?"
- Générer un PDF formaté avec l'analyse complète
- L'envoyer comme document WhatsApp
- Optionnel : stocker dans une table `analyses` pour historique

**Impact** : Professionnalisation, partage d'analyses.

---

## Proposition 5 : Analyse récurrente / monitoring

**Problème** : Chaque analyse est one-shot. Pas de suivi dans le temps.

**Solution** :
- "Analyse les ventes chaque lundi matin"
- Créer un reminder lié à une analyse template
- Chaque semaine, l'agent exécute l'analyse avec les données à jour
- Envoie le résumé automatiquement

**Impact** : Reporting automatisé.

---

## Proposition 6 : Sources et citations

**Problème** : Les analyses n'ont pas de sources vérifiables.

**Solution** :
- Intégrer la recherche web (comme proposé pour ChatAgent)
- Citer les sources dans l'analyse : "[1] https://..."
- Distinguer : fait vérifié vs opinion de l'IA
- Ajouter un disclaimer sur les limites de l'analyse

**Impact** : Crédibilité des analyses.

---

## Proposition 7 : Analyse SWOT / frameworks

**Problème** : Les analyses sont libres, pas de framework structuré.

**Solution** :
- Détecter ou permettre de choisir un framework d'analyse :
  - SWOT (Forces, Faiblesses, Opportunités, Menaces)
  - PESTEL (Politique, Economique, Social, Technologique, Environnemental, Légal)
  - 5 Forces de Porter
  - Matrice BCG
- "Fais une analyse SWOT de mon projet"
- Réponse structurée selon le framework choisi

**Impact** : Analyses professionnelles, utilisables en présentation.

---

## Proposition 8 : Chaîne d'analyse (multi-step)

**Problème** : Les analyses complexes nécessitent souvent plusieurs étapes (recherche → analyse → recommandation).

**Solution** :
- Pour les analyses `complex`, découper en étapes :
  1. Clarification des objectifs
  2. Collecte d'informations (questions à l'utilisateur ou recherche)
  3. Analyse proprement dite
  4. Recommandations
- L'agent pose des questions intermédiaires avant de produire l'analyse finale

**Impact** : Analyses beaucoup plus pertinentes et personnalisées.

---

## Priorités recommandées

| # | Proposition | Effort | Impact |
|---|-----------|--------|--------|
| 1 | Analyse de documents | Moyen | Critique |
| 2 | Analyse comparative | Faible | Haut |
| 7 | Frameworks SWOT etc. | Faible | Haut |
| 8 | Chaîne multi-step | Moyen | Haut |
| 3 | Code review | Moyen | Haut |
| 4 | Export PDF | Moyen | Moyen |
| 6 | Sources/citations | Moyen | Moyen |
| 5 | Analyse récurrente | Haut | Moyen |
