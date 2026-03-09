# Rapport d'amélioration — VoiceCommandAgent v1.4.0

**Date :** 2026-03-09
**Version précédente :** 1.3.0
**Nouvelle version :** 1.4.0
**Fichier :** `app/Services/Agents/VoiceCommandAgent.php`

---

## Résumé des améliorations apportées

### Capacités existantes améliorées

1. **Hallucinations Whisper enrichies**
   - Ajout de 9 nouveaux patterns : `[music]`, `[musique]`, `[applaudissements]`, `[applause]`, `[rires]`, `[laughter]`, `[bruit de fond]`, `[background noise]`, `[inaudible]`, `[silence]`
   - Ajout d'une détection par caractères musicaux : `♪`, `♫`, `🎵`, `🎶` (transcript rejeté si composé quasi-exclusivement de ces symboles)

2. **Note de langue avec nom complet**
   - Avant : `_Langue detectee : en_`
   - Après : `_🇬🇧 Langue detectee : Anglais_`
   - Ajout de la constante `LANGUAGE_NAMES` avec 28 langues nommées en français

3. **Prompt de résumé adapté à la langue détectée**
   - Avant : prompt fixé en français
   - Après : le nom de la langue est injecté dans le prompt (`"en {$langName}"`) pour que le résumé soit généré dans la langue du transcript

4. **Support de `video/quicktime` (.mov)**
   - Ajout dans `TRANSCRIBABLE_VIDEO_TYPES` pour les messages vidéo iOS/macOS

5. **Message de re-demande de confirmation enrichi**
   - Avant : `"Reponds *oui* pour valider ou *non* pour annuler"`
   - Après : inclut aussi l'option `*corriger: [ton texte]*`

6. **Message de confirmation faible confiance enrichi**
   - La phrase finale inclut désormais l'option de correction manuelle

---

## Nouvelles fonctionnalités ajoutées

### 1. Correction interactive du transcript (`user_corrected`)

Dans le flux de confirmation faible confiance (`low_confidence_confirm`), l'utilisateur peut désormais corriger directement le transcript sans renvoyer un nouveau message vocal.

**Usage :** répondre `corriger: [texte corrigé]`

**Comportement :**
- Regex : `^corrig(?:er|é|e)\s*:\s*(.+)$` (insensible à la casse et aux variantes accentuées)
- Le texte corrigé remplace le transcript stocké
- Retourne `AgentResult::reply` avec les métadonnées `user_corrected: true`, `user_confirmed: true`, `confidence: 1.0`
- Loggue la correction avec l'original et le texte corrigé

### 2. Estimation de durée parlée (`duration_sec`)

Chaque transcription réussie inclut désormais une estimation de durée (en secondes) dans les métadonnées.

**Calcul :** `wordCount / 130 * 60` (130 mots/minute, moyenne français/anglais)

**Constante :** `WORDS_PER_MINUTE = 130`

**Métadonnée retournée :** `duration_sec` (int, ≥ 0)

---

## Résultats des tests

```
Tests\Feature\Agents\VoiceCommandAgentTest   27 passed (71 assertions)
Duration: 21.14s
```

### Nouveaux tests ajoutés (7)

| Test | Statut |
|------|--------|
| `test_handle_pending_context_re_asks_on_ambiguous_reply` (assert `corriger`) | PASS |
| `test_handle_pending_context_accepts_manual_correction` | PASS |
| `test_handle_pending_context_accepts_correction_with_accent` | PASS |
| `test_version_is_1_4` | PASS |
| `test_can_handle_returns_true_for_quicktime_video` | PASS |
| `test_handle_includes_duration_in_metadata` | PASS |
| `test_handle_detects_music_hallucination` | PASS |
| `test_handle_detects_applause_hallucination` | PASS |
| `test_language_note_shows_full_name_not_code` | PASS |

### Tests existants (20)
Tous passent sans modification.

### Routes
`php artisan route:list` — 104 routes, aucune erreur.

### Suite complète
Les 37 failures du projet (Auth, Profile, ZeniClawSelf, SmartContext) sont pré-existantes et non liées à cet agent (confirmé par comparaison avant/après avec `git stash`).

---

## Version

| | Version |
|---|---|
| Précédente | `1.3.0` |
| Nouvelle | `1.4.0` |
