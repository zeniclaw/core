<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Budget extends Model
{
    protected $table = 'finances_budgets';

    protected $fillable = [
        'user_phone',
        'category',
        'monthly_limit',
    ];

    protected function casts(): array
    {
        return [
            'monthly_limit' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public function getRemainingBudget(?Carbon $month = null): float
    {
        $spent = Expense::calculateMonthlySpent($this->user_phone, $this->category, $month);
        return round((float) $this->monthly_limit - $spent, 2);
    }

    public function checkBudgetThreshold(?Carbon $month = null, int $threshold = 80): array
    {
        $spent = Expense::calculateMonthlySpent($this->user_phone, $this->category, $month);
        $limit = (float) $this->monthly_limit;
        $percentage = $limit > 0 ? round(($spent / $limit) * 100, 1) : 0;
        $remaining = round($limit - $spent, 2);

        return [
            'category' => $this->category,
            'limit' => $limit,
            'spent' => $spent,
            'remaining' => $remaining,
            'percentage' => $percentage,
            'exceeded' => $percentage >= 100,
            'threshold_reached' => $percentage >= $threshold,
        ];
    }
}
