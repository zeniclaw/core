<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Expense extends Model
{
    protected $table = 'finances_expenses';

    protected $fillable = [
        'user_phone',
        'amount',
        'category',
        'description',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public static function calculateMonthlySpent(string $userPhone, string $category, ?Carbon $month = null): float
    {
        $month = $month ?? Carbon::now();

        return (float) self::where('user_phone', $userPhone)
            ->where('category', $category)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->sum('amount');
    }

    public static function calculateTotalMonthlySpent(string $userPhone, ?Carbon $month = null): float
    {
        $month = $month ?? Carbon::now();

        return (float) self::where('user_phone', $userPhone)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->sum('amount');
    }

    public static function getTopCategories(string $userPhone, int $limit = 5, ?Carbon $month = null): array
    {
        $month = $month ?? Carbon::now();

        return self::where('user_phone', $userPhone)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public static function getAverageForCategory(string $userPhone, string $category, int $months = 3): float
    {
        $since = Carbon::now()->subMonths($months)->startOfMonth();

        $total = (float) self::where('user_phone', $userPhone)
            ->where('category', $category)
            ->where('date', '>=', $since)
            ->sum('amount');

        return $months > 0 ? round($total / $months, 2) : 0;
    }
}
