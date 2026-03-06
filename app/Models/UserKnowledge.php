<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserKnowledge extends Model
{
    protected $table = 'user_knowledge';

    protected $fillable = [
        'user_phone',
        'topic_key',
        'label',
        'data',
        'source',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Get non-expired knowledge for a user by topic key.
     */
    public static function recall(string $userPhone, string $topicKey): ?self
    {
        return static::where('user_phone', $userPhone)
            ->where('topic_key', $topicKey)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    /**
     * Store or update knowledge for a user.
     */
    public static function store(string $userPhone, string $topicKey, array $data, ?string $label = null, ?string $source = null, ?int $ttlMinutes = null): self
    {
        return static::updateOrCreate(
            ['user_phone' => $userPhone, 'topic_key' => $topicKey],
            [
                'data' => $data,
                'label' => $label,
                'source' => $source,
                'expires_at' => $ttlMinutes ? now()->addMinutes($ttlMinutes) : null,
            ]
        );
    }

    /**
     * Get all non-expired knowledge for a user.
     */
    public static function allFor(string $userPhone): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('user_phone', $userPhone)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Search knowledge by partial topic key.
     */
    public static function search(string $userPhone, string $keyword): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('user_phone', $userPhone)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('topic_key', 'like', "%{$keyword}%")
                ->orWhere('label', 'like', "%{$keyword}%"))
            ->orderBy('updated_at', 'desc')
            ->get();
    }
}
