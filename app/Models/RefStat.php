<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'direct_count',
        'direct_active_count',
        'team_count',
        'team_active_count',
        'ambassador_level',
        'ambassador_reward_total',
        'dividend_rate',
        'last_dividend_follow_total',
        'last_dividend_at',
        'total_rewards',
    ];

    protected function casts(): array
    {
        return [
            'ambassador_reward_total' => MoneyCast::class,
            'dividend_rate' => 'decimal:4',
            'last_dividend_follow_total' => MoneyCast::class,
            'last_dividend_at' => 'datetime',
            'total_rewards' => MoneyCast::class,
        ];
    }

    /**
     * Get the user this stat belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

