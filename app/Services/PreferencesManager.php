<?php

namespace App\Services;

use App\Models\UserPreference;
use Illuminate\Support\Facades\Cache;

class PreferencesManager
{
    private const CACHE_TTL = 3600; // 1 hour

    public static function getPreferences(string $userId): array
    {
        $cacheKey = "user:{$userId}:prefs";

        return Cache::store('redis')->remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            $pref = UserPreference::where('user_id', $userId)->first();

            if (!$pref) {
                return UserPreference::$defaults;
            }

            return [
                'language' => $pref->language,
                'timezone' => $pref->timezone,
                'date_format' => $pref->date_format,
                'unit_system' => $pref->unit_system,
                'communication_style' => $pref->communication_style,
                'notification_enabled' => $pref->notification_enabled,
                'phone' => $pref->phone,
                'email' => $pref->email,
            ];
        });
    }

    public static function setPreference(string $userId, string $key, mixed $value): bool
    {
        if (!in_array($key, UserPreference::$validKeys)) {
            return false;
        }

        $pref = UserPreference::firstOrCreate(
            ['user_id' => $userId],
            UserPreference::$defaults
        );

        $pref->update([$key => $value]);

        self::clearCache($userId);

        return true;
    }

    public static function clearCache(string $userId): void
    {
        Cache::store('redis')->forget("user:{$userId}:prefs");
    }
}
