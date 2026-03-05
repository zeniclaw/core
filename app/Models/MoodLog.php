<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MoodLog extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'mood_level',
        'mood_label',
        'notes',
        'recommendations_applied',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    protected function casts(): array
    {
        return [
            'mood_level' => 'integer',
            'recommendations_applied' => 'boolean',
        ];
    }

    /**
     * Get daily mood trend for the user (last 7 days).
     * Returns array of ['date' => '...', 'avg_mood' => X.X]
     */
    public static function getDailyTrend(string $userPhone, int $days = 7): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        $logs = self::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        $grouped = $logs->groupBy(fn ($log) => $log->created_at->format('Y-m-d'));

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dayLogs = $grouped->get($date);
            $trend[] = [
                'date' => $date,
                'avg_mood' => $dayLogs ? round($dayLogs->avg('mood_level'), 1) : null,
                'count' => $dayLogs ? $dayLogs->count() : 0,
            ];
        }

        return $trend;
    }

    /**
     * Get weekly pattern: average mood per day of week.
     */
    public static function getWeeklyPattern(string $userPhone): array
    {
        $since = Carbon::now()->subWeeks(4);

        $logs = self::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->get();

        $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $grouped = $logs->groupBy(fn ($log) => $log->created_at->dayOfWeekIso);

        $pattern = [];
        for ($d = 1; $d <= 7; $d++) {
            $dayLogs = $grouped->get($d);
            $pattern[] = [
                'day' => $days[$d - 1],
                'avg_mood' => $dayLogs ? round($dayLogs->avg('mood_level'), 1) : null,
                'count' => $dayLogs ? $dayLogs->count() : 0,
            ];
        }

        return $pattern;
    }

    /**
     * Detect hours with consistently low energy (mood <= 2).
     */
    public static function detectLowEnergyHours(string $userPhone): array
    {
        $since = Carbon::now()->subWeeks(2);

        $logs = self::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->where('mood_level', '<=', 2)
            ->get();

        $hourCounts = $logs->groupBy(fn ($log) => $log->created_at->format('H'))
            ->map(fn ($group) => $group->count())
            ->filter(fn ($count) => $count >= 2)
            ->sortDesc();

        return $hourCounts->toArray();
    }
}
