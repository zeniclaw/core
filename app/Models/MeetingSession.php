<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MeetingSession extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'group_name',
        'status',
        'started_at',
        'ended_at',
        'messages_captured',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'messages_captured' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public static function getActiveCacheKey(string $userPhone): string
    {
        return "meeting:active:{$userPhone}";
    }

    public static function getActive(string $userPhone): ?self
    {
        $id = Cache::get(self::getActiveCacheKey($userPhone));
        if (!$id) return null;

        return self::find($id);
    }

    public function activate(): void
    {
        Cache::put(self::getActiveCacheKey($this->user_phone), $this->id, now()->addHours(6));
    }

    public function deactivate(): void
    {
        Cache::forget(self::getActiveCacheKey($this->user_phone));
    }

    public function addMessage(string $sender, string $content): void
    {
        $messages = $this->messages_captured ?? [];
        $messages[] = [
            'sender' => $sender,
            'content' => $content,
            'timestamp' => now()->toISOString(),
        ];
        $this->update(['messages_captured' => $messages]);
    }

    public function scopeForUser($query, string $userPhone)
    {
        return $query->where('user_phone', $userPhone);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
