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
        return 'Agent de flashcards avec repetition espacee (SRS/SM-2). Permet de creer des cartes question/reponse, organiser en decks thematiques, generer des cartes en batch depuis un sujet, reviser avec notation 0-5, modifier ou supprimer des cartes, reinitialiser la progression SRS d\'un deck, et suivre sa progression d\'apprentissage.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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

        // Delete deck: /flashcard deck delete NomDuDeck
        if (preg_match('/\/flashcard\s+deck\s+delete\b/i', $lower) || preg_match('/\b(supprimer|effacer|delete)\s+deck\b/iu', $lower)) {
            return $this->deleteDeck($context, $body);
        }

        // Batch generate: /flashcard batch [Deck] Sujet
        if (preg_match('/\/flashcard\s+batch\b/i', $lower) || preg_match('/\b(generer|creer plusieurs|batch)\s+(flashcards?|cartes?)\b/iu', $lower)) {
            return $this->batchGenerate($context, $body);
        }

        // Reset deck SRS: /flashcard reset NomDuDeck
        if (preg_match('/\/flashcard\s+reset\b/i', $lower) || preg_match('/\b(reset|reinitialiser|recommencer)\s+(deck|progression)\b/iu', $lower)) {
            return $this->resetDeck($context, $body);
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

        // Delete card
        if (preg_match('/\/flashcard\s+delete\s+(\d+)/i', $lower, $m)) {
            return $this->deleteCard($context, (int) $m[1]);
        }

        // List
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
            . "- La reponse doit etre concise (1-3 phrases max) mais complete\n"
            . "- Prefere les questions ouvertes aux questions oui/non\n"
            . "- Adapte le niveau au contexte du deck\n\n"
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
                . "R: {$card->answer}"
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

        $this->log($context, 'Card edited', ['card_id' => $cardId]);

        return AgentResult::reply(
            "Carte #{$cardId} modifiee !\n\n"
            . "Q: _{$question}_\n"
            . "R: {$answer}"
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
                . "*/flashcard batch [Deck] Sujet*\n\n"
                . "Exemples :\n"
                . "• /flashcard batch [Python] Les bases de la POO\n"
                . "• /flashcard batch [Histoire] La Revolution francaise\n"
                . "• /flashcard batch [English] Vocabulary: body parts"
            );
        }

        if (!empty($m[1])) {
            $deckName = trim($m[1]);
        }
        $subject = trim($m[2]);

        if (mb_strlen($subject) < 3) {
            return AgentResult::reply("Le sujet est trop court. Sois plus precis pour generer de bonnes cartes.");
        }

        $model = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Sujet: \"{$subject}\"\nDeck: {$deckName}",
            $model,
            "Tu es un expert en pedagogie et creation de flashcards SRS (SuperMemo/Anki).\n"
            . "Genere 5 flashcards pertinentes et variees sur le sujet donne.\n\n"
            . "Regles:\n"
            . "- Couvre les concepts cles du sujet\n"
            . "- Varie les types de questions (definition, application, exemple, comparaison)\n"
            . "- Questions claires et precises, reponses concises (1-2 phrases)\n"
            . "- Adapte le niveau a un apprenant intermediaire\n\n"
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

        $response = "*Session de revision*\n"
            . "Deck : *{$deckLabel}* — {$total} carte(s) a reviser\n\n"
            . "Q: _{$card->question}_\n\n"
            . "Note ta reponse (0-5) :\n"
            . "/flashcard review {$card->id} [note]\n\n"
            . "0 Oubli total  1 Mauvais  2 Difficile\n"
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
        $card = $this->flashcardService->reviewCard($card, $quality);

        $this->log($context, 'Card reviewed', [
            'card_id'      => $cardId,
            'quality'      => $quality,
            'new_interval' => $card->interval,
        ]);

        $emoji = match (true) {
            $quality >= 4 => 'Excellent',
            $quality === 3 => 'Correct',
            $quality === 2 => 'Difficile',
            default        => 'A revoir',
        };

        $intervalLabel = $card->interval === 1 ? '1 jour' : "{$card->interval} jours";
        $nextReview = $card->next_review_at->diffForHumans();

        $response = "*{$emoji}* — Note : {$quality}/5\n\n"
            . "Intervalle : {$oldInterval}j => *{$card->interval}j*\n"
            . "Prochaine revision : {$nextReview}\n"
            . "Facilite : {$card->ease_factor}";

        $remaining = $this->flashcardService->getCardsToReview(
            $context->from,
            $context->agent->id,
            $card->deck_name
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

        $response = "*Tes stats Flashcards :*\n\n"
            . "Total cartes : *{$stats['total']}*\n"
            . "A reviser maintenant : *{$stats['due']}*\n"
            . "En apprentissage : *{$stats['learning']}*\n"
            . "Maitrisees : *{$stats['mastered']}* ({$masteredPct}%) {$masteredBar}\n"
            . "Nouvelles : *{$stats['new']}*\n";

        if (!empty($stats['decks'])) {
            $response .= "\n*Par deck :*\n";
            foreach ($stats['decks'] as $deckName => $deckStats) {
                $dueLabel     = $deckStats['due'] > 0 ? " — *{$deckStats['due']} a reviser*" : '';
                $masteredDeck = $deckStats['total'] > 0
                    ? round(($deckStats['mastered'] / $deckStats['total']) * 100)
                    : 0;
                $response .= "• *{$deckName}* : {$deckStats['total']} cartes, {$masteredDeck}% maitrisees{$dueLabel}\n";
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
                . "_Verifie l'ID avec /flashcard list._"
            );
        }

        $question = $card->question;
        $deckName = $card->deck_name;
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
            $totalCards += $total;
            $totalDue += $due;

            $dueLabel = $due > 0 ? " — *{$due} a reviser*" : '';
            $desc = $deck->description ? " _{$deck->description}_" : '';
            $response .= "• *{$deck->name}* : {$total} carte(s){$dueLabel}{$desc}\n";
        }

        $response .= "\nTotal : *{$totalCards}* cartes";
        if ($totalDue > 0) {
            $response .= " (*{$totalDue}* a reviser)";
        }

        $response .= "\n\nCommandes :\n"
            . "• /flashcard study NomDuDeck\n"
            . "• /flashcard create [NomDuDeck] Q | R\n"
            . "• /flashcard batch [NomDuDeck] Sujet\n"
            . "• /flashcard stats";

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
            . "- help: aide generale\n\n"
            . "Reponds UNIQUEMENT en JSON valide (sans markdown):\n"
            . "{\"action\": \"create|study|stats|list|batch|help\", \"deck\": \"NomDuDeck\", \"question\": \"...\", \"answer\": \"...\", \"subject\": \"...\"}"
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
            . "/flashcard batch [Deck] Sujet — Genere 5 cartes\n"
            . "/flashcard deck create NomDuDeck\n\n"
            . "*Etudier :*\n"
            . "/flashcard study — Toutes les cartes dues\n"
            . "/flashcard study NomDuDeck — Deck specifique\n"
            . "/flashcard review ID 0-5 — Noter une carte\n\n"
            . "*Gerer :*\n"
            . "/flashcard list — Lister les decks\n"
            . "/flashcard stats — Statistiques globales\n"
            . "/flashcard edit ID Q | R — Modifier une carte\n"
            . "/flashcard delete ID — Supprimer une carte\n"
            . "/flashcard deck delete Deck — Supprimer un deck\n"
            . "/flashcard reset Deck — Reinitialiser la progression\n\n"
            . "*Notation SM-2 :*\n"
            . "0 Oubli  1 Mauvais  2 Difficile  3 Correct  4 Bien  5 Parfait"
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
