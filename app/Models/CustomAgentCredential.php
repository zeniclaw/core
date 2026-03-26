<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class CustomAgentCredential extends Model
{
    protected $fillable = [
        'custom_agent_id', 'key', 'value', 'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = ['value'];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    /**
     * Encrypt value before storing.
     */
    public function setValueAttribute(string $value): void
    {
        $this->attributes['value'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt value when reading.
     */
    public function getDecryptedValueAttribute(): string
    {
        try {
            return Crypt::decryptString($this->attributes['value']);
        } catch (\Throwable $e) {
            return '[decryption failed]';
        }
    }
}
