<?php

namespace App\Services\Agents;

use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Services\AgentContext;
use App\Services\FlashcardService;
use Illuminate\Support\Carbon;

class FlashcardAgent extends BaseAgent
{
    private FlashcardService $flashcardService;

    public function __construct()
    {
        parent::__construct();
        $this->flashcardService = new FlashcardService();
    }

    public function name(): string
    {
        return 'flashcard';
    }

    public function description(): string
    {
        return 'Agent de flashcards avec repetition espacee (SRS/SM-2). Permet de creer des cartes question/reponse, organiser en decks thematiques, generer des cartes en batch depuis un sujet (nombre configurable), reviser avec notation 0-5, noter les cartes (Oubli/Mauvais/Difficile/Correct/Bien/Parfait), modifier ou supprimer des cartes (avec confirmation), deplacer une carte vers un autre deck, rechercher dans les cartes, afficher les details SRS d\'une carte, lister les cartes d\'un deck, reinitialiser la progression SRS d\'un deck, et suivre sa progression d\'apprentissage avec streak de revision.';
    }

    public function keywords(): array
    {
        return [
            'flashcard', 'flashcards', 'flash card', 'flash cards',
            'deck', 'decks', 'mes decks', 'my decks',
            'reviser', 'revision', 'revisions', 'review',
            'apprendre', 'apprentissage', 'learning', 'learn',
            'SRS', 'repetition espacee', 'spaced repetition',
            'carte', 'cartes', 'card', 'cards',
            'creer flashcard', 'create flashcard', 'nouvelle carte',
            'etudier', 'study', 'session revision',
            'memoriser', 'memorize', 'retenir',
            '/flashcard', 'flashcard stats', 'stats flashcard',
            'quiz', 'quizz', 'question reponse',
            'modifier carte', 'editer carte', 'edit card', 'edit flashcard',
            'supprimer deck', 'delete deck', 'effacer deck',
            'generer flashcards', 'batch flashcard', 'creer plusieurs cartes',
            'reset deck', 'reinitialiser deck', 'recommencer deck',
            'show card', 'voir carte', 'details carte', 'infos carte',
            'chercher carte', 'rechercher flashcard', 'search card',
            'deplacer carte', 'move card', 'changer deck',
            'lister cartes', 'cartes du deck',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if ($context->routedAgent === 'flashcard') {
            return true;
        }
        if (!$context->body) {
            return false;
        }
        return (bool) preg_match('/\b(flashcard|flashcards|deck|reviser|revision|apprendre|srs)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Flashcard command received', ['body' => mb_substr($body, 0, 100)]);

        // Handle pending context (confirmations, etc.)
        $pending = $context->session->pending_agent_context ?? null;
        if ($pending && ($pending['agent'] ?? '') === 'flashcard') {
            $result = $this->handlePendingContext($context, $pending);
            if ($result !== null) {
                return $result;
            }
        }

        // Edit card: /flashcard edit ID question | answer
        if (preg_match('/\/flashcard\s+edit\s+(\d+)/i', $lower, $m)) {
            return $this->editCard($context, (int) $m[1], $body);
        }

        // Show card details: /flashcard show ID
        if (preg_match('/\/flashcard\s+show\s+(\d+)/i', $lower, $m)) {
            return $this->showCard($context, (int) $m[1]);
        }

        // Delete deck: /flashcard deck delete NomDuDeck
        if (preg_match('/\/flashcard\s+deck\s+delete\b/i', $lower) || preg_match('/\b(supprimer|effacer|delete)\s+deck\b/iu', $lower)) {
            return $this->deleteDeck($context, $body);
        }

        // Batch generate: /flashcard batch [Deck] Sujet [count]
        if (preg_match('/\/flashcard\s+batch\b/i', $lower) || preg_match('/\b(generer|creer plusieurs|batch)\s+(flashcards?|cartes?)\b/iu', $lower)) {
            return $this->batchGenerate($context, $body);
        }

        // Reset deck SRS: /flashcard reset NomDuDeck
        if (preg_match('/\/flashcard\s+reset\b/i', $lower) || preg_match('/\b(reset|reinitialiser|recommencer)\s+(deck|progression)\b/iu', $lower)) {
            return $this->resetDeck($context, $body);
        }

        // Move card: /flashcard move ID NomDuDeck
        if (preg_match('/\/flashcard\s+move\s+(\d+)\s+(.+)/i', $lower, $m)) {
            return $this->moveCard($context, (int) $m[1], trim($m[2]));
        }

        // Create card
        if (preg_match('/\/flashcard\s+create\b/i', $lower) || preg_match('/\b(creer?|ajouter?|nouvelle?)\s+(flashcard|carte|card)\b/iu', $lower)) {
            return $this->createCard($context, $body);
        }

        // Create deck
        if (preg_match('/\/flashcard\s+deck\s+create\b/i', $lower) || preg_match('/\b(creer?|nouveau)\s+deck\b/iu', $lower)) {
            return $this->createDeck($context, $body);
        }

        // Study
        if (preg_match('/\/flashcard\s+study\b/i', $lower) || preg_match('/\b(etudier|study|reviser|revision)\b/iu', $lower)) {
            return $this->study($context, $body);
        }

        // Review card: /flashcard review ID quality
        if (preg_match('/\/flashcard\s+review\s+(\d+)\s+(\d)/i', $lower, $m)) {
            return $this->reviewCard($context, (int) $m[1], (int) $m[2]);
        }

        // Stats
        if (preg_match('/\/flashcard\s+stats\b/i', $lower) || preg_match('/\b(stats?|statistiques?)\s*(flashcard|carte|deck|revision)?/iu', $lower)) {
            return $this->showStats($context);
        }

        // Delete card: /flashcard delete ID
        if (preg_match('/\/flashcard\s+delete\s+(\d+)/i', $lower, $m)) {
            return $this->deleteCard($context, (int) $m[1]);
        }

        // Search cards: /flashcard search terme
        if (preg_match('/\/flashcard\s+search\s+(.+)/iu', $lower, $m) || preg_match('/\b(chercher|rechercher|search|trouver)\s+(une?\s+)?(carte|flashcard)\b/iu', $lower)) {
            return $this->searchCards($context, $body);
        }

        // List cards in deck: /flashcard list DeckName (more specific, before list decks)
        if (preg_match('/\/flashcard\s+list\s+(\S.+)/i', $lower, $m)) {
            return $this->listCards($context, trim($m[1]));
        }

        // List decks
        if (preg_match('/\/flashcard\s+list\b/i', $lower) || preg_match('/\b(list(er)?|voir)\s*(mes\s+)?(flashcards?|cartes?|decks?)\b/iu', $lower)) {
            return $this->listDecks($context);
        }

        // Help
        if (preg_match('/\/flashcard\s+help\b/i', $lower) || preg_match('/\b(aide|help)\s*(flashcard|carte|deck)?\b/iu', $lower)) {
            return $this->showHelp();
        }

        // Natural language handling via Claude
        return $this->handleNaturalLanguage($body, $context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];
        $body = mb_strtolower(trim($context->body ?? ''));

        if ($type === 'confirm_delete_deck') {
            $this->clearPendingContext($context);
            if (preg_match('/^(oui|yes|o|y|confirme?|ok|d\'accord)\b/iu', $body)) {
                return $this->executeDeckDeletion($context, $data['deck_name']);
            }
            return AgentResult::reply("Suppression annulee. Le deck *{$data['deck_name']}* est conserve.");
        }

        if ($type === 'confirm_reset_deck') {
            $this->clearPendingContext($context);
            if (preg_match('/^(oui|yes|o|y|confirme?|ok|d\'accord)\b/iu', $body)) {
                return $this->executeResetDeck($context, $data['deck_name']);
            }
            return AgentResult::reply("Reinitialisation annulee. La progression du deck *{$data['deck_name']}* est conservee.");
        }

        if ($type === 'confirm_delete_card') {
            $this->clearPendingContext($context);
            if (preg_match('/^(oui|yes|o|y|confirme?|ok|d\'accord)\b/iu', $body)) {
                return $this->executeCardDeletion($context, $data['card_id'], $data['question'], $data['deck_name']);
            }
            return AgentResult::reply("Suppression annulee. La carte *#{$data['card_id']}* est conservee.");
        }

        return null;
    }

    // ── Create Card ───────────────────────────────────────────────────────────

    private function createCard(AgentContext $context, string $body): AgentResult
    {
        $deckName = 'General';
        $question = '';
        $answer = '';

        if (preg_match('/(?:\/flashcard\s+create|(?:creer?|ajouter?|nouvelle?)\s+(?:flashcard|carte|card))\s+(?:\[([^\]]+)\]\s*)?(.+)/iu', $body, $m)) {
            if (!empty($m[1])) {
                $deckName = trim($m[1]);
            }
            $content = trim($m[2]);

            if (str_contains($content, '|')) {
                [$question, $answer] = array_map('trim', explode('|', $content, 2));
            } else {
                return $this->generateCardWithClaude($context, $deckName, $content);
            }
        }

        if (empty($question) || empty($answer)) {
            return AgentResult::reply(
                "Pour creer une flashcard, utilise ce format :\n\n"
                . "*/flashcard create [Deck] Question | Reponse*\n\n"
                . "Exemples :\n"
                . "• /flashcard create [Python] Qu'est-ce qu'un decorateur ? | Fonction qui modifie le comportement d'une autre fonction\n"
                . "• /flashcard create [English] What is \"ephemeral\"? | Lasting a very short time\n"
                . "• /flashcard create [Python] Explique les list comprehensions (sans |, je genereral la carte)\n\n"
                . "Le deck est optionnel (defaut: General)"
            );
        }

        if (mb_strlen($question) > 500 || mb_strlen($answer) > 1000) {
            return AgentResult::reply("La question (max 500 car.) ou la reponse (max 1000 car.) est trop longue.");
        }

        return $this->saveCard($context, $deckName, $question, $answer);
    }

    private function generateCardWithClaude(AgentContext $context, string $deckName, string $content): AgentResult
    {
        if (mb_strlen($content) < 5) {
            return AgentResult::reply("Le contenu est trop court pour generer une flashcard. Ajoute plus de details ou utilise le format : question | reponse");
        }

        $model = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Contenu a transformer en flashcard: \"{$content}\"\nDeck cible: {$deckName}",
            $model,
            "Tu es un expert en creation de flashcards pedagogiques pour la methode SRS (SuperMemo).\n"
            . "A partir du contenu fourni, genere UNE flashcard d'apprentissage optimale.\n\n"
            . "Regles:\n"
            . "- La question doit tester la comprehension ou la memoire d'un concept precis\n"
            . "- Formule la question de facon autonome (comprehensible sans contexte externe)\n"
            . "- La reponse doit etre concise (1-3 phrases max) mais complete et pedagogique\n"
            . "- Prefere les questions ouvertes aux questions oui/non\n"
            . "- Adapte le niveau et le vocabulaire au contexte du deck\n\n"
            . "Reponds UNIQUEMENT en JSON valide (sans markdown):\n"
            . "{\"question\": \"...\", \"answer\": \"...\"}"
        );

        $parsed = $this->parseJson($response);
        if (!$parsed || empty($parsed['question']) || empty($parsed['answer'])) {
            return AgentResult::reply("Je n'ai pas pu generer de flashcard automatiquement. Utilise le format :\n/flashcard create [{$deckName}] question | reponse");
        }

        return $this->saveCard($context, $deckName, $parsed['question'], $parsed['answer']);
    }

    private function saveCard(AgentContext $context, string $deckName, string $question, string $answer): AgentResult
    {
        FlashcardDeck::firstOrCreate(
            ['user_phone' => $context->from, 'agent_id' => $context->agent->id, 'name' => $deckName],
            ['description' => '', 'language' => 'fr', 'difficulty' => 'medium']
        );

        $card = Flashcard::create([
            'user_phone'     => $context->from,
            'agent_id'       => $context->agent->id,
            'deck_name'      => $deckName,
            'question'       => $question,
            'answer'         => $answer,
            'ease_factor'    => 2.5,
            'interval'       => 0,
            'repetitions'    => 0,
            'next_review_at' => Carbon::now(),
        ]);

        $this->log($context, 'Flashcard created', ['card_id' => $card->id, 'deck' => $deckName]);

        $total = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->count();

        return AgentResult::reply(
            "Flashcard ajoutee au deck *{$deckName}* ! (#{$card->id})\n\n"
            . "Q: _{$question}_\n"
            . "R: {$answer}\n\n"
            . "Total dans ce deck : *{$total}* carte(s)\n"
            . "_/flashcard study {$deckName} pour reviser_"
        );
    }

    // ── Edit Card ─────────────────────────────────────────────────────────────

    private function editCard(AgentContext $context, int $cardId, string $body): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply("Carte #{$cardId} introuvable. Verifie l'ID avec /flashcard list.");
        }

        // Extract new content after the ID: /flashcard edit ID question | answer
        if (!preg_match('/\/flashcard\s+edit\s+\d+\s+(.+)/i', $body, $m)) {
            return AgentResult::reply(
                "Format : */flashcard edit {$cardId} Nouvelle question | Nouvelle reponse*\n\n"
                . "Carte actuelle :\n"
                . "Q: _{$card->question}_\n"
                . "R: {$card->answer}\n"
                . "Deck : *{$card->deck_name}*"
            );
        }

        $content = trim($m[1]);
        if (!str_contains($content, '|')) {
            return AgentResult::reply(
                "Separe la question et la reponse avec | :\n"
                . "/flashcard edit {$cardId} Nouvelle question | Nouvelle reponse"
            );
        }

        [$question, $answer] = array_map('trim', explode('|', $content, 2));

        if (empty($question) || empty($answer)) {
            return AgentResult::reply("La question et la reponse ne peuvent pas etre vides.");
        }

        if (mb_strlen($question) > 500 || mb_strlen($answer) > 1000) {
            return AgentResult::reply("La question (max 500 car.) ou la reponse (max 1000 car.) est trop longue.");
        }

        $card->update(['question' => $question, 'answer' => $answer]);

        $this->log($context, 'Card edited', ['card_id' => $cardId, 'deck' => $card->deck_name]);

        return AgentResult::reply(
            "Carte #{$cardId} modifiee ! (Deck : *{$card->deck_name}*)\n\n"
            . "Q: _{$question}_\n"
            . "R: {$answer}"
        );
    }

    // ── Show Card ─────────────────────────────────────────────────────────────

    private function showCard(AgentContext $context, int $cardId): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply(
                "Carte #{$cardId} introuvable.\n"
                . "_Verifie l'ID avec /flashcard list NomDuDeck._"
            );
        }

        $statusLabel = match (true) {
            $card->repetitions === 0 => 'Nouvelle',
            $card->repetitions >= 5  => 'Maitrisee',
            default                  => 'En apprentissage',
        };

        $isDue = $card->next_review_at <= now();
        $nextReview = $isDue ? 'A reviser maintenant' : $card->next_review_at->diffForHumans();
        $lastReview = $card->last_reviewed_at ? $card->last_reviewed_at->diffForHumans() : 'Jamais revisee';

        return AgentResult::reply(
            "*Carte #{$cardId}* — Deck : *{$card->deck_name}*\n\n"
            . "Q: _{$card->question}_\n"
            . "R: {$card->answer}\n\n"
            . "*SRS :* {$statusLabel}\n"
            . "Repetitions : {$card->repetitions}\n"
            . "Intervalle : {$card->interval} jour(s)\n"
            . "Facilite : {$card->ease_factor}\n"
            . "Prochaine revision : {$nextReview}\n"
            . "Derniere revision : {$lastReview}\n\n"
            . "/flashcard edit {$cardId} Q | R — Modifier\n"
            . "/flashcard delete {$cardId} — Supprimer\n"
            . "/flashcard move {$cardId} NomDuDeck — Deplacer"
        );
    }

    // ── Create Deck ───────────────────────────────────────────────────────────

    private function createDeck(AgentContext $context, string $body): AgentResult
    {
        if (!preg_match('/(?:\/flashcard\s+deck\s+create|(?:creer?|nouveau)\s+deck)\s+(.+)/iu', $body, $m)) {
            return AgentResult::reply(
                "Pour creer un deck :\n"
                . "*/flashcard deck create NomDuDeck*\n"
                . "ou */flashcard deck create NomDuDeck | Description*"
            );
        }

        $parts = array_map('trim', explode('|', $m[1], 2));
        $name = $parts[0];
        $description = $parts[1] ?? '';

        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            return AgentResult::reply("Le nom du deck doit avoir entre 2 et 50 caracteres.");
        }

        $existing = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $count = $existing->cardCount();
            return AgentResult::reply(
                "Le deck *{$name}* existe deja ! ({$count} carte(s))\n\n"
                . "Pour ajouter des cartes :\n"
                . "/flashcard create [{$name}] Question | Reponse"
            );
        }

        FlashcardDeck::create([
            'user_phone'  => $context->from,
            'agent_id'    => $context->agent->id,
            'name'        => $name,
            'description' => $description,
            'language'    => 'fr',
            'difficulty'  => 'medium',
        ]);

        $this->log($context, 'Deck created', ['name' => $name]);

        return AgentResult::reply(
            "Deck *{$name}* cree !\n"
            . ($description ? "Description : {$description}\n\n" : "\n")
            . "Ajoute des cartes avec :\n"
            . "/flashcard create [{$name}] Question | Reponse\n\n"
            . "Ou genere-en plusieurs d'un coup :\n"
            . "/flashcard batch [{$name}] Sujet a apprendre"
        );
    }

    // ── Delete Deck ───────────────────────────────────────────────────────────

    private function deleteDeck(AgentContext $context, string $body): AgentResult
    {
        if (!preg_match('/(?:\/flashcard\s+deck\s+delete|(?:supprimer|effacer|delete)\s+deck)\s+(.+)/iu', $body, $m)) {
            return AgentResult::reply(
                "Pour supprimer un deck :\n"
                . "*/flashcard deck delete NomDuDeck*\n\n"
                . "_Attention : toutes les cartes du deck seront supprimees._"
            );
        }

        $name = trim($m[1]);

        $deck = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $name)
            ->first();

        if (!$deck) {
            return AgentResult::reply("Deck *{$name}* introuvable. Verifie le nom avec /flashcard list.");
        }

        $count = $deck->cardCount();

        $this->setPendingContext($context, 'confirm_delete_deck', ['deck_name' => $name], 2, true);

        return AgentResult::reply(
            "Supprimer le deck *{$name}* et ses *{$count}* carte(s) ?\n\n"
            . "Cette action est irreversible.\n\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler."
        );
    }

    private function executeDeckDeletion(AgentContext $context, string $deckName): AgentResult
    {
        $deck = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $deckName)
            ->first();

        if (!$deck) {
            return AgentResult::reply("Deck *{$deckName}* introuvable.");
        }

        $count = $deck->cardCount();

        Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->delete();

        $deck->delete();

        $this->log($context, 'Deck deleted', ['deck' => $deckName, 'cards_deleted' => $count]);

        return AgentResult::reply(
            "Deck *{$deckName}* supprime avec ses *{$count}* carte(s)."
        );
    }

    // ── Batch Generate ────────────────────────────────────────────────────────

    private function batchGenerate(AgentContext $context, string $body): AgentResult
    {
        $deckName = 'General';

        if (!preg_match('/(?:\/flashcard\s+batch|(?:generer|creer plusieurs|batch)\s+(?:flashcards?|cartes?))\s+(?:\[([^\]]+)\]\s*)?(.+)/iu', $body, $m)) {
            return AgentResult::reply(
                "Pour generer plusieurs flashcards depuis un sujet :\n\n"
                . "*/flashcard batch [Deck] Sujet [nombre]*\n\n"
                . "Exemples :\n"
                . "• /flashcard batch [Python] Les bases de la POO\n"
                . "• /flashcard batch [Histoire] La Revolution francaise 8\n"
                . "• /flashcard batch [English] Vocabulary: body parts 3\n\n"
                . "_Nombre de cartes : 3-10 (defaut: 5)_"
            );
        }

        if (!empty($m[1])) {
            $deckName = trim($m[1]);
        }
        $subject = trim($m[2]);

        // Extract optional count at end of subject (3-10 cards)
        $count = 5;
        if (preg_match('/\s+(\d+)$/', $subject, $cm)) {
            $count = max(3, min(10, (int) $cm[1]));
            $subject = trim(substr($subject, 0, -strlen($cm[0])));
        }

        if (mb_strlen($subject) < 3) {
            return AgentResult::reply("Le sujet est trop court. Sois plus precis pour generer de bonnes cartes.");
        }

        $model = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Sujet: \"{$subject}\"\nDeck: {$deckName}\nNombre de cartes a generer: {$count}",
            $model,
            "Tu es un expert en pedagogie et creation de flashcards SRS (SuperMemo/Anki).\n"
            . "Genere exactement {$count} flashcards pertinentes et variees sur le sujet donne.\n\n"
            . "Regles:\n"
            . "- Couvre les concepts cles, definitions, applications et exemples du sujet\n"
            . "- Varie les types de questions: definition, application, exemple, comparaison, mecanisme\n"
            . "- Questions claires et autonomes (comprehensibles sans contexte externe)\n"
            . "- Reponses concises (1-3 phrases max) mais completes et pedagogiques\n"
            . "- Adapte le vocabulaire au niveau intermediaire/avance\n"
            . "- Evite les questions trop similaires entre elles\n\n"
            . "Reponds UNIQUEMENT en JSON valide (sans markdown):\n"
            . "{\"cards\": [{\"question\": \"...\", \"answer\": \"...\"}, ...]}"
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['cards']) || !is_array($parsed['cards'])) {
            return AgentResult::reply("Je n'ai pas pu generer les flashcards. Reessaie avec un sujet plus precis.");
        }

        $created = 0;
        $errors = 0;

        FlashcardDeck::firstOrCreate(
            ['user_phone' => $context->from, 'agent_id' => $context->agent->id, 'name' => $deckName],
            ['description' => "Genere depuis: {$subject}", 'language' => 'fr', 'difficulty' => 'medium']
        );

        foreach ($parsed['cards'] as $cardData) {
            if (empty($cardData['question']) || empty($cardData['answer'])) {
                $errors++;
                continue;
            }

            Flashcard::create([
                'user_phone'     => $context->from,
                'agent_id'       => $context->agent->id,
                'deck_name'      => $deckName,
                'question'       => mb_substr(trim($cardData['question']), 0, 500),
                'answer'         => mb_substr(trim($cardData['answer']), 0, 1000),
                'ease_factor'    => 2.5,
                'interval'       => 0,
                'repetitions'    => 0,
                'next_review_at' => Carbon::now(),
            ]);
            $created++;
        }

        $this->log($context, 'Batch cards generated', [
            'deck'    => $deckName,
            'subject' => $subject,
            'created' => $created,
            'count_requested' => $count,
        ]);

        if ($created === 0) {
            return AgentResult::reply("La generation a echoue. Reessaie avec un sujet different.");
        }

        $total = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->count();

        return AgentResult::reply(
            "*{$created} flashcard(s) generee(s)* pour le deck *{$deckName}* !\n"
            . "Sujet : {$subject}\n"
            . "Total dans le deck : *{$total}* carte(s)\n\n"
            . "Lance la revision avec :\n"
            . "/flashcard study {$deckName}"
        );
    }

    // ── Reset Deck ────────────────────────────────────────────────────────────

    private function resetDeck(AgentContext $context, string $body): AgentResult
    {
        if (!preg_match('/(?:\/flashcard\s+reset|(?:reset|reinitialiser|recommencer)\s+(?:deck|progression))\s+(.+)/iu', $body, $m)) {
            return AgentResult::reply(
                "Pour reinitialiser la progression SRS d'un deck :\n"
                . "*/flashcard reset NomDuDeck*\n\n"
                . "_Toutes les cartes reviendront a l'etat 'nouveau'._"
            );
        }

        $name = trim($m[1]);

        $deck = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $name)
            ->first();

        if (!$deck) {
            return AgentResult::reply("Deck *{$name}* introuvable. Verifie le nom avec /flashcard list.");
        }

        $count = $deck->cardCount();

        $this->setPendingContext($context, 'confirm_reset_deck', ['deck_name' => $name], 2, true);

        return AgentResult::reply(
            "Reinitialiser la progression SRS des *{$count}* carte(s) du deck *{$name}* ?\n\n"
            . "Les intervalles et repetitions seront remis a zero.\n\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler."
        );
    }

    private function executeResetDeck(AgentContext $context, string $deckName): AgentResult
    {
        $updated = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->update([
                'ease_factor'      => 2.5,
                'interval'         => 0,
                'repetitions'      => 0,
                'next_review_at'   => Carbon::now(),
                'last_reviewed_at' => null,
            ]);

        $this->log($context, 'Deck reset', ['deck' => $deckName, 'cards_reset' => $updated]);

        return AgentResult::reply(
            "Progression reinitalisee pour le deck *{$deckName}* !\n"
            . "*{$updated}* carte(s) remises a zero.\n\n"
            . "Lance une nouvelle session :\n"
            . "/flashcard study {$deckName}"
        );
    }

    // ── Move Card ─────────────────────────────────────────────────────────────

    private function moveCard(AgentContext $context, int $cardId, string $targetDeck): AgentResult
    {
        if (mb_strlen($targetDeck) < 2 || mb_strlen($targetDeck) > 50) {
            return AgentResult::reply("Le nom du deck cible doit avoir entre 2 et 50 caracteres.");
        }

        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply(
                "Carte #{$cardId} introuvable.\n"
                . "_Verifie l'ID avec /flashcard list NomDuDeck._"
            );
        }

        if ($card->deck_name === $targetDeck) {
            return AgentResult::reply("La carte #{$cardId} est deja dans le deck *{$targetDeck}*.");
        }

        $oldDeck = $card->deck_name;

        // Create target deck if it doesn't exist
        FlashcardDeck::firstOrCreate(
            ['user_phone' => $context->from, 'agent_id' => $context->agent->id, 'name' => $targetDeck],
            ['description' => '', 'language' => 'fr', 'difficulty' => 'medium']
        );

        $card->update(['deck_name' => $targetDeck]);

        $this->log($context, 'Card moved', ['card_id' => $cardId, 'from' => $oldDeck, 'to' => $targetDeck]);

        return AgentResult::reply(
            "Carte #{$cardId} deplacee !\n\n"
            . "De : *{$oldDeck}*\n"
            . "Vers : *{$targetDeck}*\n\n"
            . "Q: _{$card->question}_\n\n"
            . "/flashcard study {$targetDeck} — Reviser ce deck"
        );
    }

    // ── Study ─────────────────────────────────────────────────────────────────

    private function study(AgentContext $context, string $body): AgentResult
    {
        $deckName = null;
        if (preg_match('/(?:\/flashcard\s+study|etudier|reviser)\s+(.+)/iu', $body, $m)) {
            $deckName = trim($m[1]);
        }

        $cards = $this->flashcardService->getCardsToReview($context->from, $context->agent->id, $deckName);

        if ($cards->isEmpty()) {
            $nextCard = Flashcard::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->when($deckName, fn ($q) => $q->where('deck_name', $deckName))
                ->orderBy('next_review_at')
                ->first();

            if (!$nextCard) {
                $hint = $deckName
                    ? "Deck *{$deckName}* vide ou introuvable."
                    : "Aucune flashcard trouvee !";
                return AgentResult::reply(
                    "{$hint}\n\n"
                    . "Cree-en avec : /flashcard create [Deck] Question | Reponse\n"
                    . "Ou genere-en plusieurs : /flashcard batch [Deck] Sujet"
                );
            }

            $nextReview = $nextCard->next_review_at->diffForHumans();
            return AgentResult::reply(
                "Aucune carte a reviser pour le moment !\n\n"
                . "Prochaine revision : *{$nextReview}*\n"
                . "Carte : _{$nextCard->question}_\n"
                . "Deck : {$nextCard->deck_name}"
            );
        }

        $card = $cards->first();
        $total = $cards->count();
        $deckLabel = $deckName ?? $card->deck_name;

        $response = "*Session de revision* — Carte 1/{$total}\n"
            . "Deck : *{$deckLabel}*\n\n"
            . "Q: _{$card->question}_\n\n"
            . "Note ta reponse (0-5) :\n"
            . "/flashcard review {$card->id} [note]\n\n"
            . "0 Oubli  1 Mauvais  2 Difficile\n"
            . "3 Correct  4 Bien  5 Parfait\n\n"
            . "Reponse : ||{$card->answer}||";

        return AgentResult::reply($response);
    }

    // ── Review Card ───────────────────────────────────────────────────────────

    private function reviewCard(AgentContext $context, int $cardId, int $quality): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply(
                "Carte #{$cardId} introuvable.\n"
                . "_Lance une session avec /flashcard study pour obtenir les IDs actuels._"
            );
        }

        if ($quality < 0 || $quality > 5) {
            return AgentResult::reply("La note doit etre entre 0 et 5. Exemple : /flashcard review {$cardId} 4");
        }

        $oldInterval = $card->interval;
        $deckName = $card->deck_name;
        $card = $this->flashcardService->reviewCard($card, $quality);

        $this->log($context, 'Card reviewed', [
            'card_id'      => $cardId,
            'quality'      => $quality,
            'new_interval' => $card->interval,
        ]);

        $qualityLabel = match ($quality) {
            5       => 'Parfait',
            4       => 'Bien',
            3       => 'Correct',
            2       => 'Difficile',
            1       => 'Mauvais',
            default => 'Oubli total',
        };

        $intervalLabel = $card->interval === 1 ? '1 jour' : "{$card->interval} jours";
        $nextReview = $card->next_review_at->diffForHumans();

        $response = "*{$qualityLabel}* ({$quality}/5)\n\n"
            . "Intervalle : {$oldInterval}j -> *{$card->interval}j*\n"
            . "Prochaine revision : {$nextReview}\n"
            . "Facilite : {$card->ease_factor}";

        $remaining = $this->flashcardService->getCardsToReview(
            $context->from,
            $context->agent->id,
            $deckName
        );

        if ($remaining->isNotEmpty()) {
            $next = $remaining->first();
            $count = $remaining->count();
            $response .= "\n\n*Carte suivante* ({$count} restante(s))\n"
                . "Q: _{$next->question}_\n\n"
                . "/flashcard review {$next->id} [0-5]\n\n"
                . "Reponse : ||{$next->answer}||";
        } else {
            $response .= "\n\nSession terminee ! Toutes les cartes ont ete revisees.";

            $stats = $this->flashcardService->generateStats($context->from, $context->agent->id);
            if ($stats['due'] === 0 && $stats['total'] > 0) {
                $response .= "\n_Reviens demain pour la prochaine session._";
            }
        }

        return AgentResult::reply($response);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    private function showStats(AgentContext $context): AgentResult
    {
        $stats = $this->flashcardService->generateStats($context->from, $context->agent->id);

        if ($stats['total'] === 0) {
            return AgentResult::reply(
                "Pas encore de flashcards !\n\n"
                . "Commence avec :\n"
                . "• /flashcard create [Deck] Question | Reponse\n"
                . "• /flashcard batch [Deck] Sujet a apprendre"
            );
        }

        $masteredPct = $stats['total'] > 0 ? round(($stats['mastered'] / $stats['total']) * 100) : 0;
        $masteredBar = $this->generateProgressBar($masteredPct);

        $streak = $this->computeStudyStreak($context->from, $context->agent->id);
        $streakLabel = $streak > 0 ? "*{$streak}* jour(s) consecutifs" : 'Aucune serie en cours';

        $response = "*Tes stats Flashcards :*\n\n"
            . "Total cartes : *{$stats['total']}*\n"
            . "A reviser maintenant : *{$stats['due']}*\n"
            . "En apprentissage : *{$stats['learning']}*\n"
            . "Maitrisees : *{$stats['mastered']}* ({$masteredPct}%) {$masteredBar}\n"
            . "Nouvelles : *{$stats['new']}*\n"
            . "Serie de revision : {$streakLabel}\n";

        if (!empty($stats['decks'])) {
            $response .= "\n*Par deck :*\n";
            foreach ($stats['decks'] as $deckName => $deckStats) {
                $dueLabel = $deckStats['due'] > 0 ? " — *{$deckStats['due']} a reviser*" : '';
                $masteredDeck = $deckStats['total'] > 0
                    ? round(($deckStats['mastered'] / $deckStats['total']) * 100)
                    : 0;
                $bar = $this->generateProgressBar($masteredDeck);
                $response .= "• *{$deckName}* : {$deckStats['total']} cartes, {$masteredDeck}% {$bar}{$dueLabel}\n";
            }
        }

        if ($stats['due'] > 0) {
            $response .= "\nLance la revision : /flashcard study";
        }

        return AgentResult::reply($response);
    }

    // ── Delete Card ───────────────────────────────────────────────────────────

    private function deleteCard(AgentContext $context, int $cardId): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply(
                "Carte #{$cardId} introuvable.\n"
                . "_Verifie l'ID avec /flashcard list NomDuDeck._"
            );
        }

        $this->setPendingContext($context, 'confirm_delete_card', [
            'card_id'   => $cardId,
            'question'  => $card->question,
            'deck_name' => $card->deck_name,
        ], 2, true);

        return AgentResult::reply(
            "Supprimer la carte #{$cardId} ?\n\n"
            . "Q: _{$card->question}_\n"
            . "Deck : *{$card->deck_name}*\n\n"
            . "Cette action est irreversible.\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler."
        );
    }

    private function executeCardDeletion(AgentContext $context, int $cardId, string $question, string $deckName): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply("Carte #{$cardId} introuvable (peut-etre deja supprimee).");
        }

        $card->delete();

        $this->log($context, 'Card deleted', ['card_id' => $cardId, 'deck' => $deckName]);

        $remaining = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->count();

        return AgentResult::reply(
            "Carte #{$cardId} supprimee.\n"
            . "_{$question}_\n\n"
            . "Deck *{$deckName}* : {$remaining} carte(s) restante(s)."
        );
    }

    // ── List Decks ────────────────────────────────────────────────────────────

    private function listDecks(AgentContext $context): AgentResult
    {
        $decks = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        if ($decks->isEmpty()) {
            return AgentResult::reply(
                "Aucun deck trouve !\n\n"
                . "Cree-en un avec : /flashcard deck create NomDuDeck\n"
                . "Ou genere des cartes : /flashcard batch [Deck] Sujet"
            );
        }

        $response = "*Tes decks :*\n\n";
        $totalCards = 0;
        $totalDue = 0;

        foreach ($decks as $deck) {
            $total = $deck->cardCount();
            $due = $deck->dueCount();
            $mastered = Flashcard::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('deck_name', $deck->name)
                ->where('repetitions', '>=', 5)
                ->count();

            $totalCards += $total;
            $totalDue += $due;

            $masteredPct = $total > 0 ? round(($mastered / $total) * 100) : 0;
            $bar = $this->generateProgressBar($masteredPct);
            $dueLabel = $due > 0 ? " — *{$due} a reviser*" : '';
            $desc = $deck->description ? " _{$deck->description}_" : '';

            $response .= "• *{$deck->name}* : {$total} carte(s) — {$masteredPct}% {$bar}{$dueLabel}{$desc}\n";
        }

        $response .= "\nTotal : *{$totalCards}* cartes";
        if ($totalDue > 0) {
            $response .= " (*{$totalDue}* a reviser)";
        }

        $response .= "\n\nCommandes :\n"
            . "• /flashcard study NomDuDeck\n"
            . "• /flashcard list NomDuDeck — Voir les cartes\n"
            . "• /flashcard create [NomDuDeck] Q | R\n"
            . "• /flashcard batch [NomDuDeck] Sujet\n"
            . "• /flashcard stats";

        return AgentResult::reply($response);
    }

    // ── List Cards ────────────────────────────────────────────────────────────

    private function listCards(AgentContext $context, string $deckName): AgentResult
    {
        $deck = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('name', $deckName)
            ->first();

        if (!$deck) {
            return AgentResult::reply(
                "Deck *{$deckName}* introuvable.\n"
                . "_Verifie le nom avec /flashcard list._"
            );
        }

        $cards = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('deck_name', $deckName)
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            return AgentResult::reply(
                "Le deck *{$deckName}* est vide.\n\n"
                . "Ajoute des cartes avec :\n"
                . "/flashcard create [{$deckName}] Question | Reponse"
            );
        }

        $due = 0;
        $response = "*Cartes du deck \"{$deckName}\"* ({$cards->count()}) :\n\n";

        foreach ($cards->take(20) as $card) {
            $isDue = $card->next_review_at <= now();
            if ($isDue) {
                $due++;
            }

            $statusIcon = match (true) {
                $card->repetitions === 0 => '[N]',
                $card->repetitions >= 5  => '[M]',
                $isDue                   => '[!]',
                default                  => '[~]',
            };

            $q = mb_substr($card->question, 0, 60);
            if (mb_strlen($card->question) > 60) {
                $q .= '...';
            }
            $response .= "{$statusIcon} #{$card->id}: _{$q}_\n";
        }

        if ($cards->count() > 20) {
            $response .= "_... et " . ($cards->count() - 20) . " carte(s) supplementaire(s)_\n";
        }

        $response .= "\n*Legende :* [N]=Nouveau [~]=En cours [!]=A reviser [M]=Maitrise";

        if ($due > 0) {
            $response .= "\n\n{$due} carte(s) a reviser : /flashcard study {$deckName}";
        }

        $response .= "\n/flashcard show ID — Voir une carte en detail";

        return AgentResult::reply($response);
    }

    // ── Search Cards ──────────────────────────────────────────────────────────

    private function searchCards(AgentContext $context, string $body): AgentResult
    {
        if (!preg_match('/(?:\/flashcard\s+search|(?:chercher|rechercher|search|trouver)\s+(?:une?\s+)?(?:carte|flashcard))\s+(.+)/iu', $body, $m)) {
            return AgentResult::reply(
                "Pour rechercher dans tes flashcards :\n\n"
                . "*/flashcard search terme*\n\n"
                . "Exemple : /flashcard search photosynthese"
            );
        }

        $term = trim($m[1]);

        if (mb_strlen($term) < 2) {
            return AgentResult::reply("Le terme de recherche est trop court (minimum 2 caracteres).");
        }

        $cards = Flashcard::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where(function ($q) use ($term) {
                $q->where('question', 'like', "%{$term}%")
                  ->orWhere('answer', 'like', "%{$term}%");
            })
            ->orderBy('deck_name')
            ->orderBy('id')
            ->limit(15)
            ->get();

        if ($cards->isEmpty()) {
            return AgentResult::reply(
                "Aucune carte trouvee pour *\"{$term}\"*.\n\n"
                . "_Essaie avec un autre mot-cle._"
            );
        }

        $count = $cards->count();
        $response = "*{$count} carte(s) trouvee(s) pour \"{$term}\" :*\n\n";

        foreach ($cards as $card) {
            $q = mb_substr($card->question, 0, 80);
            if (mb_strlen($card->question) > 80) {
                $q .= '...';
            }
            $response .= "#{$card->id} [{$card->deck_name}]\n_{$q}_\n\n";
        }

        $response .= "_/flashcard show ID — Voir les details_\n"
            . "_/flashcard edit ID Q | R — Modifier_";

        return AgentResult::reply($response);
    }

    // ── Natural Language ──────────────────────────────────────────────────────

    private function handleNaturalLanguage(string $body, AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $stats = $this->flashcardService->generateStats($context->from, $context->agent->id);
        $statsContext = "Total:{$stats['total']}, A_reviser:{$stats['due']}, Maitrisees:{$stats['mastered']}, En_apprentissage:{$stats['learning']}";

        $decks = array_keys($stats['decks'] ?? []);
        $decksContext = empty($decks) ? 'aucun deck' : implode(', ', $decks);

        $response = $this->claude->chat(
            "Message: \"{$body}\"\nStats: {$statsContext}\nDecks: {$decksContext}",
            $model,
            "Tu es l'agent Flashcard (SRS/SuperMemo) de ZeniClaw. Analyse l'intention de l'utilisateur.\n\n"
            . "Actions disponibles:\n"
            . "- create: creer une carte (besoin: deck, question, answer)\n"
            . "- study: lancer une session de revision (optionnel: deck)\n"
            . "- stats: voir statistiques\n"
            . "- list: lister les decks\n"
            . "- batch: generer plusieurs cartes (besoin: deck, subject)\n"
            . "- search: rechercher des cartes (besoin: term)\n"
            . "- show: voir les details d'une carte (besoin: card_id)\n"
            . "- help: aide generale\n\n"
            . "Reponds UNIQUEMENT en JSON valide (sans markdown):\n"
            . "{\"action\": \"create|study|stats|list|batch|search|show|help\", \"deck\": \"NomDuDeck\", \"question\": \"...\", \"answer\": \"...\", \"subject\": \"...\", \"term\": \"...\", \"card_id\": 123}"
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp();
        }

        return match ($parsed['action']) {
            'create' => isset($parsed['question'], $parsed['answer'])
                ? $this->saveCard($context, $parsed['deck'] ?? 'General', $parsed['question'], $parsed['answer'])
                : AgentResult::reply("Precise la question et la reponse.\nFormat : /flashcard create [Deck] Question | Reponse"),
            'study'  => $this->study($context, '/flashcard study ' . ($parsed['deck'] ?? '')),
            'stats'  => $this->showStats($context),
            'list'   => $this->listDecks($context),
            'batch'  => isset($parsed['subject'])
                ? $this->batchGenerate($context, '/flashcard batch [' . ($parsed['deck'] ?? 'General') . '] ' . $parsed['subject'])
                : AgentResult::reply("Precise le sujet.\nFormat : /flashcard batch [Deck] Sujet"),
            'search' => isset($parsed['term'])
                ? $this->searchCards($context, '/flashcard search ' . $parsed['term'])
                : AgentResult::reply("Precise le terme de recherche.\nFormat : /flashcard search terme"),
            'show'   => isset($parsed['card_id'])
                ? $this->showCard($context, (int) $parsed['card_id'])
                : AgentResult::reply("Precise l'ID de la carte.\nFormat : /flashcard show ID"),
            default  => $this->showHelp(),
        };
    }

    // ── Help ──────────────────────────────────────────────────────────────────

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*Flashcards — Repetition espacee (SRS/SM-2)*\n\n"
            . "*Creer :*\n"
            . "/flashcard create [Deck] Question | Reponse\n"
            . "/flashcard batch [Deck] Sujet [3-10] — Genere des cartes\n"
            . "/flashcard deck create NomDuDeck\n\n"
            . "*Etudier :*\n"
            . "/flashcard study — Toutes les cartes dues\n"
            . "/flashcard study NomDuDeck — Deck specifique\n"
            . "/flashcard review ID 0-5 — Noter une carte\n\n"
            . "*Gerer :*\n"
            . "/flashcard list — Lister les decks\n"
            . "/flashcard list NomDuDeck — Cartes d'un deck\n"
            . "/flashcard stats — Statistiques + streak\n"
            . "/flashcard show ID — Details SRS d'une carte\n"
            . "/flashcard search terme — Rechercher des cartes\n"
            . "/flashcard edit ID Q | R — Modifier une carte\n"
            . "/flashcard move ID Deck — Deplacer une carte\n"
            . "/flashcard delete ID — Supprimer une carte\n"
            . "/flashcard deck delete Deck — Supprimer un deck\n"
            . "/flashcard reset Deck — Reinitialiser la progression\n\n"
            . "*Notation SM-2 :*\n"
            . "0 Oubli  1 Mauvais  2 Difficile  3 Correct  4 Bien  5 Parfait"
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute consecutive study streak in days.
     */
    private function computeStudyStreak(string $userPhone, int $agentId): int
    {
        $dates = Flashcard::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->whereNotNull('last_reviewed_at')
            ->selectRaw('DATE(last_reviewed_at) as review_date')
            ->distinct()
            ->orderByDesc('review_date')
            ->pluck('review_date')
            ->toArray();

        if (empty($dates)) {
            return 0;
        }

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $mostRecent = $dates[0];

        // Streak only valid if studied today or yesterday
        if ($mostRecent !== $today && $mostRecent !== $yesterday) {
            return 0;
        }

        $streak = 0;
        $expected = $mostRecent;

        foreach ($dates as $date) {
            if ($date === $expected) {
                $streak++;
                $expected = Carbon::parse($expected)->subDay()->toDateString();
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Parse JSON from Claude response, handling markdown code blocks.
     */
    private function parseJson(?string $response): ?array
    {
        if (empty($response)) {
            return null;
        }

        $clean = trim($response);

        // Strip markdown code blocks (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $clean, $m)) {
            $clean = trim($m[1]);
        }

        $decoded = json_decode($clean, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }

    private function generateProgressBar(float $percentage): string
    {
        $filled = (int) round($percentage / 10);
        $empty = 10 - $filled;
        return str_repeat('|', $filled) . str_repeat('.', $empty);
    }
}
