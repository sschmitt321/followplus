<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FollowWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id',
        'window_type',
        'start_at',
        'expire_at',
        'reward_rate_min',
        'reward_rate_max',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'expire_at' => 'datetime',
            'reward_rate_min' => 'decimal:4',
            'reward_rate_max' => 'decimal:4',
        ];
    }

    /**
     * Get the symbol this window belongs to.
     */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /**
     * Get invite tokens for this window.
     */
    public function inviteTokens(): HasMany
    {
        return $this->hasMany(InviteToken::class);
    }

    /**
     * Get follow orders for this window.
     */
    public function followOrders(): HasMany
    {
        return $this->hasMany(FollowOrder::class);
    }

    /**
     * Check if window is currently active.
     */
    public function isActive(): bool
    {
        $now = now();
        return $this->status === 'active' 
            && $now->gte($this->start_at) 
            && $now->lte($this->expire_at);
    }
}

