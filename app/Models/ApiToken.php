<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new token, save hashed version, return the plain token (show once).
     */
    public static function generate(int $userId, string $name): array
    {
        $plain = 'zc_' . Str::random(48);
        $token = static::create([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
        ]);
        return ['token' => $token, 'plain' => $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::where('token_hash', hash('sha256', $plain))->first();
    }
}
