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
            ->where('created_at', '>=', Carbon::now()->subDays(90)->startOfDay())
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
     * Generate insights: best/worst day, peak hours, most common mood, volatility.
     */
    public static function getInsights(string $userPhone): array
    {
        $since = Carbon::now()->subDays(30)->startOfDay();
        $logs = self::where('user_phone', $userPhone)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) {
            return ['total_entries' => 0, 'days_tracked' => 0, 'overall_avg' => null,
                'best_day' => null, 'worst_day' => null, 'peak_hours' => [],
                'low_hours' => [], 'most_common_label' => null, 'most_common_count' => 0,
                'volatility' => null];
        }

        $days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $byDay = $logs->groupBy(fn ($l) => $l->created_at->dayOfWeekIso);
        $dayAvgs = [];
        foreach ($byDay as $dow => $group) {
            $dayAvgs[] = ['day' => $days[$dow - 1], 'avg' => round($group->avg('mood_level'), 1)];
        }
        usort($dayAvgs, fn ($a, $b) => $b['avg'] <=> $a['avg']);

        // Peak/low hours (avg mood by hour, top/bottom 2)
        $byHour = $logs->groupBy(fn ($l) => (int) $l->created_at->format('H'));
        $hourAvgs = [];
        foreach ($byHour as $hour => $group) {
            if ($group->count() >= 2) {
                $hourAvgs[$hour] = round($group->avg('mood_level'), 1);
            }
        }
        arsort($hourAvgs);
        $peakHours = array_slice(array_keys($hourAvgs), 0, 2);
        asort($hourAvgs);
        $lowHours = array_slice(array_keys(array_filter($hourAvgs, fn ($v) => $v <= 2.5)), 0, 2);

        // Most common label
        $labelCounts = $logs->groupBy('mood_label')->map->count()->sortDesc();
        $topLabel = $labelCounts->keys()->first();
        $topLabelCount = $labelCounts->first();

        // Volatility (std dev)
        $levels = $logs->pluck('mood_level')->toArray();
        $mean = array_sum($levels) / count($levels);
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $levels)) / count($levels);
        $stdDev = round(sqrt($variance), 1);

        $uniqueDays = $logs->map(fn ($l) => $l->created_at->format('Y-m-d'))->unique()->count();

        return [
            'total_entries'     => $logs->count(),
            'days_tracked'      => $uniqueDays,
            'overall_avg'       => round($logs->avg('mood_level'), 1),
            'best_day'          => $dayAvgs[0] ?? null,
            'worst_day'         => count($dayAvgs) > 1 ? end($dayAvgs) : null,
            'peak_hours'        => $peakHours,
            'low_hours'         => $lowHours,
            'most_common_label' => $topLabel,
            'most_common_count' => $topLabelCount,
            'volatility'        => $stdDev,
        ];
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
