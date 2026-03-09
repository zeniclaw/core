# Rapport de test — HangmanGameAgent v1.4.0
**Date :** 2026-03-09
**Version precedente :** 1.3.0 → **Nouvelle version :** 1.4.0

---

## Resume des ameliorations

### Ameliorations des capacites existantes

| Capacite | Amelioration |
|---|---|
| `status()` | Affiche maintenant la duree ecoulee depuis le debut de la partie (⏱️) |
| `hint()` | Affiche le plateau meme quand l'indice est trop risque (1 vie restante) |
| `hint()` win | Affiche le score quand un indice provoque la victoire |
| `guessLetter()` | Precision améliorée : `({N}x dans le mot)` au lieu de `({N}x)` |
| `getDisplayBoard()` | Separation visuelle entre lettres correctes (✅) et incorrectes (❌) |
| `showStats()` | Lien vers `/hangman top` dans le pied de message |
| `showHelp()` | Inclut les 2 nouvelles commandes (`/hangman daily`, `/hangman top`) |
| `startGame()` | Affiche toutes les 6 categories dans la suggestion de categorie |
| `handleNaturalLanguage()` | Prompt LLM enrichi : 2 nouvelles actions, 4 exemples supplementaires, contexte de partie plus detaille (vies restantes incluses) |
| `computeScore()` | Bonus de vitesse selon duree de partie (voir ci-dessous) |

### Nouveau calcul de score — bonus de vitesse

| Duree | Bonus |
|---|---|
| < 60 secondes | +20 pts ⚡ |
| 60–119 secondes | +10 pts 🚀 |
| 120–299 secondes | +5 pts ⏱️ |
| >= 300 secondes | +0 pts |

Le message de victoire indique le niveau de vitesse atteint.

---

## Nouvelles fonctionnalites

### 1. Defi du Jour — `/hangman daily`
- Mot deterministe base sur la date du jour (`crc32(date('Y-m-d'))`)
- Meme mot pour tous les joueurs le meme jour, quelle que soit la categorie
- Affiche la categorie et la date formatee
- Abandonne automatiquement la partie en cours si existante
- Loggue le demarrage du defi
- **Keywords ajoutes :** `defi du jour`, `pendu daily`, `hangman daily`, `mot du jour`
- **Action NLP :** `daily`

### 2. Classement des joueurs — `/hangman top`
- Top 5 joueurs par nombre de victoires (tri secondaire: meilleure serie)
- Numeros de telephone masques (`****XXXX`)
- Medailles 🥇🥈🥉 pour les 3 premiers
- Indique `← Toi` si le joueur actuel figure dans le top
- Message d'encouragement si le joueur n'est pas dans le top 5
- **Keywords ajoutes :** `classement pendu`, `top pendu`, `hangman top`, `meilleurs joueurs pendu`
- **Action NLP :** `leaderboard`

### 3. Nouvelles categories de mots

| Categorie | Label | Mots |
|---|---|---|
| `sport` | Sport 🏆 | 25 mots (FOOTBALL, TENNIS, BASKETBALL, MARATHON, TRIATHLON...) |
| `gastronomie` | Gastronomie 🍽️ | 25 mots (BAGUETTE, RATATOUILLE, MILLEFEUILLE, TARTIFLETTE...) |

**Aliases ajoutes :**
- `sports`, `foot` → `sport`
- `cuisine`, `food`, `gastro` → `gastronomie`

Total categories : 6 (tech, animaux, nature, vocab, sport, gastronomie)

---

## Resultats des tests

```
Tests\Unit\Agents\HangmanGameAgentTest   56 passed (112 assertions)   2.95s
```

### Tests existants (40) — tous passes ✅
- Basics (name, version, canHandle)
- Start game (create, abandon existing, custom word, validation)
- Guess letter (correct, wrong, duplicate, no game)
- Winning/losing stats
- Hint (reveal, no game, blocked at 1 life)
- Abandon (end game, no game, counts as loss)
- Status (board, no game, guess count, hidden letters)
- Stats (zero, best score)
- History (empty, games, scores)
- Reset stats (no stats, clears values)
- Categories listing
- Score display
- Guess word (correct, wrong cost 2, loss, stats, no game, multi-letter body)

### Nouveaux tests (16) ✅

| Test | Description |
|---|---|
| `test_agent_version_is_1_4_0` | Version correctement incrementee |
| `test_status_shows_elapsed_time` | Indicateur ⏱️ dans le status |
| `test_start_with_sport_category_creates_game` | Categorie sport fonctionnelle |
| `test_start_with_gastronomie_category_creates_game` | Categorie gastronomie fonctionnelle |
| `test_categories_list_includes_sport_and_gastronomie` | Nouvelles categories dans la liste |
| `test_daily_challenge_starts_a_game` | Defi du jour cree une partie |
| `test_daily_challenge_is_deterministic_same_day` | Deux joueurs ont le meme mot |
| `test_daily_challenge_abandons_existing_game` | Abandonne ancienne partie |
| `test_leaderboard_shows_no_players_message_when_empty` | Message si pas de joueurs |
| `test_leaderboard_shows_top_players` | Affiche classement |
| `test_leaderboard_masks_phone_numbers` | Telephone masque dans le classement |
| `test_winning_game_fast_shows_speed_bonus_message` | Message bonus vitesse |
| `test_hint_win_shows_score` | Score affiché lors d'une victoire par indice |

---

## Verification routes

```
php artisan route:list → OK (aucune regression)
```

---

## Fichiers modifies

| Fichier | Type de modification |
|---|---|
| `app/Services/Agents/HangmanGameAgent.php` | Agent mis a jour (v1.3.0 → v1.4.0) |
| `tests/Unit/Agents/HangmanGameAgentTest.php` | Tests mis a jour + 13 nouveaux tests |
