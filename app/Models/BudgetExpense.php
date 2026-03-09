<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetExpense extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'amount',
        'currency',
        'category',
        'description',
        'expense_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public static function getMonthlyTotal(string $userPhone, int $agentId, ?string $monthKey = null): float
    {
        $monthKey = $monthKey ?? now()->format('Y-m');
        $year = substr($monthKey, 0, 4);
        $month = substr($monthKey, 5, 2);

        return (float) self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->sum('amount');
    }

    public static function getMonthlyByCategory(string $userPhone, int $agentId, ?string $monthKey = null)
    {
        $monthKey = $monthKey ?? now()->format('Y-m');
        $year = substr($monthKey, 0, 4);
        $month = substr($monthKey, 5, 2);

        return self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();
    }

    public static function getRecent(string $userPhone, int $agentId, int $limit = 10)
    {
        return self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
