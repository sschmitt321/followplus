<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InviteToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'follow_window_id',
        'token',
        'valid_after',
        'valid_before',
        'symbol_id',
    ];

    protected function casts(): array
    {
        return [
            'valid_after' => 'datetime',
            'valid_before' => 'datetime',
        ];
    }

    /**
     * Get the follow window this token belongs to.
     */
    public function followWindow(): BelongsTo
    {
        return $this->belongsTo(FollowWindow::class);
    }

    /**
     * Get the symbol this token belongs to.
     */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /**
     * Check if token is currently valid.
     */
    public function isValid(): bool
    {
        $now = now();
        return $now->gte($this->valid_after) && $now->lte($this->valid_before);
    }
}

