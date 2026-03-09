<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollaborativeVote extends Model
{
    protected $fillable = [
        'message_group_id',
        'task_description',
        'vote_quorum',
        'created_by',
        'status',
        'votes',
        'created_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'votes' => 'array',
            'vote_quorum' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function scopeByGroup($query, string $groupId)
    {
        return $query->where('message_group_id', $groupId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeExpired($query, int $hoursLimit = 24)
    {
        return $query->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($hoursLimit));
    }

    public function getVoteCount(string $type = 'approve'): int
    {
        $emoji = match ($type) {
            'approve' => '👍',
            'reject' => '👎',
            'abstain' => '❓',
            default => $type,
        };

        $votes = $this->votes ?? [];

        return collect($votes)->filter(fn ($v) => $v === $emoji)->count();
    }

    public function getTotalVotes(): int
    {
        return count($this->votes ?? []);
    }

    public function isQuorumReached(): bool
    {
        if ($this->getTotalVotes() === 0) {
            return false;
        }

        $approveCount = $this->getVoteCount('approve');
        $totalVotes = $this->getTotalVotes();

        if ($totalVotes === 0) {
            return false;
        }

        $approvePercent = ($approveCount / $totalVotes) * 100;

        return $approvePercent >= $this->vote_quorum;
    }

    public function isRejected(): bool
    {
        $rejectCount = $this->getVoteCount('reject');
        $totalVotes = $this->getTotalVotes();

        if ($totalVotes === 0) {
            return false;
        }

        $rejectPercent = ($rejectCount / $totalVotes) * 100;

        return $rejectPercent > (100 - $this->vote_quorum);
    }

    public function addVote(string $userId, string $emoji): void
    {
        $votes = $this->votes ?? [];
        $votes[$userId] = $emoji;
        $this->update(['votes' => $votes]);
    }

    public function hasVoted(string $userId): bool
    {
        $votes = $this->votes ?? [];

        return array_key_exists($userId, $votes);
    }

    public function approve(): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function reject(): void
    {
        $this->update([
            'status' => 'rejected',
        ]);
    }

    public function formatStatus(): string
    {
        $votes = $this->votes ?? [];
        $approves = collect($votes)->filter(fn ($v) => $v === '👍')->count();
        $rejects = collect($votes)->filter(fn ($v) => $v === '👎')->count();
        $abstains = collect($votes)->filter(fn ($v) => $v === '❓')->count();

        return "👍 {$approves} | 👎 {$rejects} | ❓ {$abstains} — Total: " . count($votes) . " vote(s)";
    }

    public static function getPendingForGroup(string $groupId)
    {
        return self::byGroup($groupId)->pending()->orderByDesc('created_at')->get();
    }

    public static function getRecentForGroup(string $groupId, int $limit = 10)
    {
        return self::byGroup($groupId)->orderByDesc('created_at')->limit($limit)->get();
    }
}
