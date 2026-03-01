<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AgentSecret extends Model
{
    protected $fillable = ['agent_id', 'key_name', 'encrypted_value'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function setValueAttribute(string $value): void
    {
        $this->attributes['encrypted_value'] = Crypt::encryptString($value);
    }

    public function getValueAttribute(): string
    {
        return Crypt::decryptString($this->attributes['encrypted_value']);
    }
}
