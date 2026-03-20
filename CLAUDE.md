# Instructions pour Claude Code

## Strategie de lecture
- Pour les fichiers > 500 lignes, utilise Grep pour trouver les sections AVANT de lire
- Ne relis JAMAIS un fichier deja lu — garde les infos en memoire
- Utilise offset+limit pour lire par sections si necessaire
- Utilise l'outil Agent pour paralleliser: analyse dans un agent, recherche dans un autre

## Strategie d'edition
- Utilise Edit (pas Write) pour modifier des fichiers existants
- Fais des editions ciblees, jamais de rewrite complet
- Un Edit a la fois, avec assez de contexte pour etre unique

## Tests
- Lance les tests UNE SEULE FOIS
- Les echecs pre-existants ne sont PAS tes erreurs, ignore-les
- Verifie syntaxe avec `php -l` avant de commit

## Architecture ZeniClaw
- Laravel 12 + PHP 8.4
- Les agents heritent de BaseAgent et implementent AgentInterface
- Ne modifie pas les migrations existantes
- Ne touche pas au RouterAgent ni a l'AgentOrchestrator sauf pour les keywords
- Patterns: AnthropicClient pour les appels LLM, sendText pour les reponses WhatsApp
