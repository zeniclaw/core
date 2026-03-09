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

    /**
     * Get the last N mood entries for a user.
     */
    public static function getLastEntries(string $userPhone, int $limit = 10): \Illuminate\Support\Collection
    {
        return self::where('user_phone', $userPhone)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Calculate the current consecutive-day streak (days with at least one entry).
     */
    public static function getStreak(string $userPhone): int
    {
        $tz = \App\Models\AppSetting::timezone();

        $logs = self::where('user_phone', $userPhone)
            ->orderBy('created_at', 'desc')
            ->get(['created_at']);

        if ($logs->isEmpty()) {
            return 0;
        }

        $dates = $logs
            ->map(fn ($log) => Carbon::parse($log->created_at)->timezone($tz)->format('Y-m-d'))
            ->unique()
            ->values()
            ->toArray();

        $streak    = 0;
        $checkDate = Carbon::now($tz)->format('Y-m-d');

        foreach ($dates as $date) {
            if ($date === $checkDate) {
                $streak++;
                $checkDate = Carbon::parse($checkDate)->subDay()->format('Y-m-d');
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Get weekly summaries for a 30-day trend (grouped by week).
     * Returns array of ['week' => 'Sem. 1', 'avg_mood' => X.X, 'count' => N]
     */
    public static function getWeeklySummary(string $userPhone, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        $logs = self::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        $numWeeks = (int) ceil($days / 7);
        $result   = [];

        for ($w = $numWeeks; $w >= 1; $w--) {
            $weekStart = Carbon::now()->subDays($w * 7)->startOfDay();
            $weekEnd   = Carbon::now()->subDays(($w - 1) * 7)->endOfDay();
            $weekLogs  = $logs->filter(
                fn ($log) => $log->created_at >= $weekStart && $log->created_at <= $weekEnd
            );

            $result[] = [
                'week'     => 'S-' . $w,
                'from'     => $weekStart->format('d/m'),
                'to'       => $weekEnd->format('d/m'),
                'avg_mood' => $weekLogs->isNotEmpty() ? round($weekLogs->avg('mood_level'), 1) : null,
                'count'    => $weekLogs->count(),
            ];
        }

        return $result;
    }
}
