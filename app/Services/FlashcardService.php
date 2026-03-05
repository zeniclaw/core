<?php

namespace App\Services;

use App\Models\Flashcard;
use Illuminate\Support\Carbon;

class FlashcardService
{
    /**
     * SM-2 Algorithm implementation.
     * quality: 0-5 (0=complete blackout, 5=perfect response)
     */
    public function reviewCard(Flashcard $card, int $quality): Flashcard
    {
        $quality = max(0, min(5, $quality));

        [$interval, $repetitions, $easeFactor] = $this->calculateNextInterval(
            $card->interval,
            $card->repetitions,
            $card->ease_factor,
            $quality
        );

        $card->update([
            'ease_factor' => round($easeFactor, 2),
            'interval' => $interval,
            'repetitions' => $repetitions,
            'next_review_at' => Carbon::now()->addDays($interval),
            'last_reviewed_at' => Carbon::now(),
        ]);

        return $card;
    }

    /**
     * SM-2 (SuperMemo-2) interval calculation.
     *
     * @return array{0: int, 1: int, 2: float} [interval, repetitions, easeFactor]
     */
    public function calculateNextInterval(int $interval, int $repetitions, float $easeFactor, int $quality): array
    {
        if ($quality >= 3) {
            // Correct response
            if ($repetitions === 0) {
                $interval = 1;
            } elseif ($repetitions === 1) {
                $interval = 6;
            } else {
                $interval = (int) round($interval * $easeFactor);
            }
            $repetitions++;
        } else {
            // Incorrect response — reset
            $repetitions = 0;
            $interval = 1;
        }

        // Update ease factor: EF' = EF + (0.1 - (5-q) * (0.08 + (5-q) * 0.02))
        $easeFactor = $easeFactor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
        $easeFactor = max(1.3, $easeFactor);

        return [$interval, $repetitions, $easeFactor];
    }

    public function getCardsToReview(string $userPhone, int $agentId, ?string $deckName = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Flashcard::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('next_review_at', '<=', Carbon::now())
            ->orderBy('next_review_at');

        if ($deckName) {
            $query->where('deck_name', $deckName);
        }

        return $query->get();
    }

    public function generateStats(string $userPhone, int $agentId): array
    {
        $cards = Flashcard::where('user_phone', $userPhone)
            ->where('agent_id', $agentId);

        $total = $cards->count();
        $due = (clone $cards)->where('next_review_at', '<=', Carbon::now())->count();
        $mastered = (clone $cards)->where('repetitions', '>=', 5)->count();
        $learning = (clone $cards)->where('repetitions', '>', 0)->where('repetitions', '<', 5)->count();
        $new = (clone $cards)->where('repetitions', 0)->count();

        $decks = Flashcard::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->select('deck_name')
            ->distinct()
            ->pluck('deck_name');

        $deckStats = [];
        foreach ($decks as $deck) {
            $deckCards = Flashcard::where('user_phone', $userPhone)
                ->where('agent_id', $agentId)
                ->where('deck_name', $deck);

            $deckStats[$deck] = [
                'total' => $deckCards->count(),
                'due' => (clone $deckCards)->where('next_review_at', '<=', Carbon::now())->count(),
                'mastered' => (clone $deckCards)->where('repetitions', '>=', 5)->count(),
            ];
        }

        return [
            'total' => $total,
            'due' => $due,
            'mastered' => $mastered,
            'learning' => $learning,
            'new' => $new,
            'decks' => $deckStats,
        ];
    }
}
