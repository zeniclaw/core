<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'name',
        'monthly_limit',
        'spent_this_month',
        'month_key',
    ];

    protected function casts(): array
    {
        return [
            'monthly_limit' => 'decimal:2',
            'spent_this_month' => 'decimal:2',
        ];
    }

    public static function getOrCreate(string $userPhone, int $agentId, string $name, ?string $monthKey = null): self
    {
        $monthKey = $monthKey ?? now()->format('Y-m');

        return self::firstOrCreate(
            [
                'user_phone' => $userPhone,
                'agent_id' => $agentId,
                'name' => mb_strtolower($name),
                'month_key' => $monthKey,
            ],
            [
                'monthly_limit' => 0,
                'spent_this_month' => 0,
            ]
        );
    }

    public function calculateMonthlySpent(): float
    {
        $total = BudgetExpense::where('user_phone', $this->user_phone)
            ->where('agent_id', $this->agent_id)
            ->where('category', $this->name)
            ->whereYear('expense_date', substr($this->month_key, 0, 4))
            ->whereMonth('expense_date', substr($this->month_key, 5, 2))
            ->sum('amount');

        $this->update(['spent_this_month' => $total]);

        return (float) $total;
    }

    public function isOverBudget(): bool
    {
        if ($this->monthly_limit <= 0) {
            return false;
        }

        return $this->spent_this_month >= $this->monthly_limit;
    }

    public function remainingBudget(): float
    {
        if ($this->monthly_limit <= 0) {
            return 0;
        }

        return max(0, $this->monthly_limit - $this->spent_this_month);
    }

    public function usagePercent(): float
    {
        if ($this->monthly_limit <= 0) {
            return 0;
        }

        return round(($this->spent_this_month / $this->monthly_limit) * 100, 1);
    }

    public static function getAllForUser(string $userPhone, int $agentId, ?string $monthKey = null)
    {
        $monthKey = $monthKey ?? now()->format('Y-m');

        return self::where('user_phone', $userPhone)
            ->where('agent_id', $agentId)
            ->where('month_key', $monthKey)
            ->orderBy('name')
            ->get();
    }
}
