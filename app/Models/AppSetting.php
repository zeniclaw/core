<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AppSetting extends Model
{
    protected $fillable = ['key', 'encrypted_value'];

    public static function get(string $key): ?string
    {
        $setting = static::where('key', $key)->first();
        if (!$setting) return null;
        try {
            return Crypt::decryptString($setting->encrypted_value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['encrypted_value' => Crypt::encryptString($value)]
        );
    }

    public static function has(string $key): bool
    {
        return static::where('key', $key)->exists();
    }
}
