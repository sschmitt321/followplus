<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'follow_window_id',
        'symbol_id',
        'amount_base',
        'amount_input',
        'status',
        'profit',
        'invite_token',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_base' => MoneyCast::class,
            'amount_input' => MoneyCast::class,
            'profit' => MoneyCast::class,
            'settled_at' => 'datetime',
        ];
    }

    /**
     * Get the user who placed this order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the follow window this order belongs to.
     */
    public function followWindow(): BelongsTo
    {
        return $this->belongsTo(FollowWindow::class);
    }

    /**
     * Get the symbol this order belongs to.
     */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}

