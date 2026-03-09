<?php

namespace App\Listeners;

use App\Models\CollaborativeVote;
use Illuminate\Support\Facades\Log;

class ReactionVoteListener
{
    /**
     * Handle incoming WhatsApp reaction events.
     *
     * Expected payload from WAHA webhook:
     * {
     *   "event": "message.reaction",
     *   "payload": {
     *     "from": "group_id@g.us",
     *     "participant": "user@c.us",
     *     "reaction": { "text": "👍" },
     *     "msgId": { "id": "..." }
     *   }
     * }
     */
    public function handle(array $payload): void
    {
        try {
            $emoji = $payload['reaction']['text'] ?? null;
            $participant = $payload['participant'] ?? null;
            $groupId = $payload['from'] ?? null;

            if (!$emoji || !$participant || !$groupId) {
                return;
            }

            // Only handle vote emojis
            if (!in_array($emoji, ['👍', '👎', '❓'])) {
                return;
            }

            // Find pending votes in this group
            $pendingVotes = CollaborativeVote::getPendingForGroup($groupId);

            if ($pendingVotes->isEmpty()) {
                return;
            }

            // Apply vote to the most recent pending proposal
            $latestVote = $pendingVotes->first();

            if ($latestVote->hasVoted($participant)) {
                Log::info('ReactionVoteListener: user already voted', [
                    'participant' => $participant,
                    'vote_id' => $latestVote->id,
                ]);
                return;
            }

            $latestVote->addVote($participant, $emoji);

            Log::info('ReactionVoteListener: vote registered', [
                'vote_id' => $latestVote->id,
                'participant' => $participant,
                'emoji' => $emoji,
            ]);

            // Check quorum
            if ($latestVote->isQuorumReached()) {
                $latestVote->approve();
                Log::info('ReactionVoteListener: quorum reached, proposal approved', [
                    'vote_id' => $latestVote->id,
                ]);
            } elseif ($latestVote->isRejected()) {
                $latestVote->reject();
                Log::info('ReactionVoteListener: proposal rejected by majority', [
                    'vote_id' => $latestVote->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ReactionVoteListener error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
