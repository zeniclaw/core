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
        return 'Agent de flashcards avec repetition espacee (SRS/SM-2). Permet de creer des cartes question/reponse, organiser en decks thematiques, reviser avec notation, et suivre sa progression d\'apprentissage.';
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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\b(flashcard|flashcards|deck|reviser|revision|apprendre|srs)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Flashcard command received', ['body' => mb_substr($body, 0, 100)]);

        // Parse commands
        if (preg_match('/\/flashcard\s+create\b/i', $lower) || preg_match('/\b(creer?|ajouter?|nouvelle?)\s+(flashcard|carte|card)\b/iu', $lower)) {
            return $this->createCard($context, $body);
        }

        if (preg_match('/\/flashcard\s+deck\s+create\b/i', $lower) || preg_match('/\b(creer?|nouveau)\s+deck\b/iu', $lower)) {
            return $this->createDeck($context, $body);
        }

        if (preg_match('/\/flashcard\s+study\b/i', $lower) || preg_match('/\b(etudier|study|reviser|revision)\b/iu', $lower)) {
            return $this->study($context, $body);
        }

        if (preg_match('/\/flashcard\s+review\s+(\d+)\s+(\d)/i', $lower, $m)) {
            return $this->reviewCard($context, (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/\/flashcard\s+stats\b/i', $lower) || preg_match('/\b(stats?|statistiques?)\s*(flashcard|carte|deck|revision)/iu', $lower)) {
            return $this->showStats($context);
        }

        if (preg_match('/\/flashcard\s+delete\s+(\d+)/i', $lower, $m)) {
            return $this->deleteCard($context, (int) $m[1]);
        }

        if (preg_match('/\/flashcard\s+list\b/i', $lower) || preg_match('/\b(list(er)?|voir)\s*(mes\s+)?(flashcards?|cartes?|decks?)\b/iu', $lower)) {
            return $this->listDecks($context);
        }

        // Natural language handling via Claude
        return $this->handleNaturalLanguage($body, $context);
    }

    private function createCard(AgentContext $context, string $body): AgentResult
    {
        // Try to parse: /flashcard create [deck] question | answer
        // Or: creer flashcard [deck] question | answer
        $deckName = 'General';
        $question = '';
        $answer = '';

        // Pattern: /flashcard create DeckName: question | answer
        if (preg_match('/(?:\/flashcard\s+create|(?:creer?|ajouter?|nouvelle?)\s+(?:flashcard|carte|card))\s+(?:\[([^\]]+)\]\s*)?(.+)/iu', $body, $m)) {
            if (!empty($m[1])) {
                $deckName = trim($m[1]);
            }
            $content = trim($m[2]);

            // Split question | answer
            if (str_contains($content, '|')) {
                [$question, $answer] = array_map('trim', explode('|', $content, 2));
            } else {
                // Use Claude to generate Q&A from the content
                return $this->generateCardWithClaude($context, $deckName, $content);
            }
        }

        if (empty($question) || empty($answer)) {
            return AgentResult::reply(
                "Pour creer une flashcard, utilise ce format :\n\n"
                . "*/flashcard create [Deck] Question | Reponse*\n\n"
                . "Exemples :\n"
                . "- /flashcard create [Python] Qu'est-ce qu'un decorateur ? | Un decorateur est une fonction qui modifie le comportement d'une autre fonction\n"
                . "- /flashcard create [English] What is \"ephemeral\"? | Lasting for a very short time\n\n"
                . "Le deck est optionnel (defaut: General)"
            );
        }

        return $this->saveCard($context, $deckName, $question, $answer);
    }

    private function generateCardWithClaude(AgentContext $context, string $deckName, string $content): AgentResult
    {
        $model = $this->resolveModel($context);
        $response = $this->claude->chat(
            "Contenu: \"{$content}\"\nDeck: {$deckName}",
            $model,
            "A partir du contenu fourni, genere une flashcard d'apprentissage.\n"
            . "Reponds UNIQUEMENT en JSON: {\"question\": \"...\", \"answer\": \"...\"}\n"
            . "La question doit tester la comprehension. La reponse doit etre concise mais complete."
        );

        $parsed = json_decode(trim($response ?? ''), true);
        if (!$parsed || empty($parsed['question']) || empty($parsed['answer'])) {
            return AgentResult::reply("Je n'ai pas pu generer de flashcard. Utilise le format : question | reponse");
        }

        return $this->saveCard($context, $deckName, $parsed['question'], $parsed['answer']);
    }

    private function saveCard(AgentContext $context, string $deckName, string $question, string $answer): AgentResult
    {
        // Ensure deck exists
        FlashcardDeck::firstOrCreate(
            ['user_phone' => $context->from, 'agent_id' => $context->agent->id, 'name' => $deckName],
            ['description' => '', 'language' => 'fr', 'difficulty' => 'medium']
        );

        $card = Flashcard::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'deck_name' => $deckName,
            'question' => $question,
            'answer' => $answer,
            'ease_factor' => 2.5,
            'interval' => 0,
            'repetitions' => 0,
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
            . "Total dans ce deck : *{$total}* cartes"
        );
    }

    private function createDeck(AgentContext $context, string $body): AgentResult
    {
        if (preg_match('/(?:\/flashcard\s+deck\s+create|(?:creer?|nouveau)\s+deck)\s+(.+)/iu', $body, $m)) {
            $parts = array_map('trim', explode('|', $m[1], 2));
            $name = $parts[0];
            $description = $parts[1] ?? '';

            $existing = FlashcardDeck::where('user_phone', $context->from)
                ->where('agent_id', $context->agent->id)
                ->where('name', $name)
                ->first();

            if ($existing) {
                return AgentResult::reply("Le deck *{$name}* existe deja !");
            }

            FlashcardDeck::create([
                'user_phone' => $context->from,
                'agent_id' => $context->agent->id,
                'name' => $name,
                'description' => $description,
                'language' => 'fr',
                'difficulty' => 'medium',
            ]);

            $this->log($context, 'Deck created', ['name' => $name]);

            return AgentResult::reply(
                "Deck *{$name}* cree !\n\n"
                . "Ajoute des cartes avec :\n"
                . "/flashcard create [{$name}] Question | Reponse"
            );
        }

        return AgentResult::reply(
            "Pour creer un deck :\n"
            . "*/flashcard deck create NomDuDeck*\n"
            . "ou */flashcard deck create NomDuDeck | Description*"
        );
    }

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
                return AgentResult::reply(
                    "Aucune flashcard trouvee !\n\n"
                    . "Cree-en avec : /flashcard create [Deck] Question | Reponse"
                );
            }

            $nextReview = $nextCard->next_review_at->diffForHumans();
            return AgentResult::reply(
                "Pas de carte a reviser pour le moment !\n\n"
                . "Prochaine revision : {$nextReview}\n"
                . "Carte : _{$nextCard->question}_"
            );
        }

        $card = $cards->first();
        $remaining = $cards->count() - 1;
        $deckLabel = $card->deck_name;

        $response = "--- Session de revision ---\n"
            . "Deck : *{$deckLabel}*\n"
            . "Cartes a reviser : *" . ($remaining + 1) . "*\n\n"
            . "Q: _{$card->question}_\n\n"
            . "Reflechis, puis reponds avec ta note :\n"
            . "/flashcard review {$card->id} [0-5]\n\n"
            . "Echelle :\n"
            . "0 = Aucun souvenir\n"
            . "1 = Mauvais, reponse incorrecte\n"
            . "2 = Difficile, reponse partielle\n"
            . "3 = Correct avec effort\n"
            . "4 = Bien, petit hesitation\n"
            . "5 = Parfait !\n\n"
            . "Reponse : ||{$card->answer}||";

        return AgentResult::reply($response);
    }

    private function reviewCard(AgentContext $context, int $cardId, int $quality): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply("Carte #{$cardId} introuvable.");
        }

        $oldInterval = $card->interval;
        $card = $this->flashcardService->reviewCard($card, $quality);

        $this->log($context, 'Card reviewed', [
            'card_id' => $cardId,
            'quality' => $quality,
            'new_interval' => $card->interval,
        ]);

        $emoji = match (true) {
            $quality >= 4 => '',
            $quality === 3 => '',
            default => '',
        };

        $nextReview = $card->next_review_at->diffForHumans();

        $response = "{$emoji} Note : *{$quality}/5*\n\n"
            . "Intervalle : {$oldInterval}j -> *{$card->interval}j*\n"
            . "Prochaine revision : {$nextReview}\n"
            . "Facteur de facilite : {$card->ease_factor}";

        // Check for more cards to review
        $remaining = $this->flashcardService->getCardsToReview(
            $context->from,
            $context->agent->id,
            $card->deck_name
        );

        if ($remaining->isNotEmpty()) {
            $next = $remaining->first();
            $response .= "\n\n--- Carte suivante ---\n"
                . "Q: _{$next->question}_\n\n"
                . "/flashcard review {$next->id} [0-5]\n\n"
                . "Reponse : ||{$next->answer}||";
        } else {
            $response .= "\n\nSession terminee ! Toutes les cartes ont ete revisees.";
        }

        return AgentResult::reply($response);
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $stats = $this->flashcardService->generateStats($context->from, $context->agent->id);

        if ($stats['total'] === 0) {
            return AgentResult::reply(
                "Pas encore de flashcards !\n\n"
                . "Commence avec : /flashcard create [Deck] Question | Reponse"
            );
        }

        $masteredBar = $this->generateProgressBar($stats['total'] > 0 ? ($stats['mastered'] / $stats['total']) * 100 : 0);

        $response = "*Tes stats Flashcards :*\n\n"
            . "Total cartes : *{$stats['total']}*\n"
            . "A reviser : *{$stats['due']}*\n"
            . "En apprentissage : *{$stats['learning']}*\n"
            . "Maitrisees : *{$stats['mastered']}* {$masteredBar}\n"
            . "Nouvelles : *{$stats['new']}*\n";

        if (!empty($stats['decks'])) {
            $response .= "\n*Par deck :*\n";
            foreach ($stats['decks'] as $deckName => $deckStats) {
                $dueLabel = $deckStats['due'] > 0 ? " ({$deckStats['due']} a reviser)" : '';
                $response .= "- *{$deckName}* : {$deckStats['total']} cartes, {$deckStats['mastered']} maitrisees{$dueLabel}\n";
            }
        }

        return AgentResult::reply($response);
    }

    private function deleteCard(AgentContext $context, int $cardId): AgentResult
    {
        $card = Flashcard::where('id', $cardId)
            ->where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->first();

        if (!$card) {
            return AgentResult::reply("Carte #{$cardId} introuvable.");
        }

        $question = $card->question;
        $card->delete();

        $this->log($context, 'Card deleted', ['card_id' => $cardId]);

        return AgentResult::reply("Carte #{$cardId} supprimee.\n_{$question}_");
    }

    private function listDecks(AgentContext $context): AgentResult
    {
        $decks = FlashcardDeck::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        if ($decks->isEmpty()) {
            return AgentResult::reply(
                "Aucun deck trouve !\n\n"
                . "Cree-en un avec : /flashcard deck create NomDuDeck"
            );
        }

        $response = "*Tes decks :*\n\n";
        foreach ($decks as $deck) {
            $total = $deck->cardCount();
            $due = $deck->dueCount();
            $dueLabel = $due > 0 ? " (*{$due} a reviser*)" : '';
            $desc = $deck->description ? " — {$deck->description}" : '';
            $response .= "- *{$deck->name}* : {$total} cartes{$dueLabel}{$desc}\n";
        }

        $response .= "\nCommandes :\n"
            . "- /flashcard study NomDuDeck\n"
            . "- /flashcard create [NomDuDeck] Q | R";

        return AgentResult::reply($response);
    }

    private function handleNaturalLanguage(string $body, AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);

        $stats = $this->flashcardService->generateStats($context->from, $context->agent->id);
        $statsContext = "Cartes: {$stats['total']}, A reviser: {$stats['due']}, Maitrisees: {$stats['mastered']}";

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\nStats: {$statsContext}",
            $model,
            "Tu es l'agent Flashcard pour l'apprentissage par repetition espacee (SRS).\n"
            . "Comprends l'intention et reponds en JSON:\n"
            . "{\"action\": \"create|study|stats|list|help\", \"deck\": \"NomDuDeck\", \"question\": \"...\", \"answer\": \"...\"}\n"
            . "- create = creer une carte (inclure deck, question, answer)\n"
            . "- study = lancer une session de revision\n"
            . "- stats = voir statistiques\n"
            . "- list = lister les decks\n"
            . "- help = aide\n"
            . "Reponds UNIQUEMENT avec le JSON."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp();
        }

        return match ($parsed['action']) {
            'create' => isset($parsed['question'], $parsed['answer'])
                ? $this->saveCard($context, $parsed['deck'] ?? 'General', $parsed['question'], $parsed['answer'])
                : AgentResult::reply("Precise la question et la reponse pour creer une carte."),
            'study' => $this->study($context, '/flashcard study ' . ($parsed['deck'] ?? '')),
            'stats' => $this->showStats($context),
            'list' => $this->listDecks($context),
            default => $this->showHelp(),
        };
    }

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*Flashcards - Apprentissage par repetition espacee :*\n\n"
            . "*Creer :*\n"
            . "/flashcard create [Deck] Question | Reponse\n"
            . "/flashcard deck create NomDuDeck\n\n"
            . "*Etudier :*\n"
            . "/flashcard study — Reviser les cartes dues\n"
            . "/flashcard study NomDuDeck — Reviser un deck\n"
            . "/flashcard review ID 0-5 — Noter une carte\n\n"
            . "*Gerer :*\n"
            . "/flashcard list — Lister les decks\n"
            . "/flashcard stats — Statistiques\n"
            . "/flashcard delete ID — Supprimer une carte\n\n"
            . "*Echelle de notation (SM-2) :*\n"
            . "0=Aucun souvenir, 1=Mauvais, 2=Difficile\n"
            . "3=Correct, 4=Bien, 5=Parfait"
        );
    }

    private function generateProgressBar(float $percentage): string
    {
        $filled = (int) round($percentage / 10);
        $empty = 10 - $filled;
        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }
}
