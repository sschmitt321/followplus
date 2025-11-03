<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    use HasFactory;

    protected $fillable = [
        'base',
        'quote',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the symbol name (e.g., BTC/USDT).
     */
    public function getNameAttribute(): string
    {
        return "{$this->base}/{$this->quote}";
    }

    /**
     * Get follow windows for this symbol.
     */
    public function followWindows(): HasMany
    {
        return $this->hasMany(FollowWindow::class);
    }

    /**
     * Get invite tokens for this symbol.
     */
    public function inviteTokens(): HasMany
    {
        return $this->hasMany(InviteToken::class);
    }

    /**
     * Get follow orders for this symbol.
     */
    public function followOrders(): HasMany
    {
        return $this->hasMany(FollowOrder::class);
    }
}

