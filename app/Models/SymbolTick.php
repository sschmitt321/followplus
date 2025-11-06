<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolTick extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id',
        'last_price',
        'change_percent',
        'tick_at',
    ];

    protected function casts(): array
    {
        return [
            'last_price' => MoneyCast::class,
            'change_percent' => 'decimal:4',
            'tick_at' => 'datetime',
        ];
    }

    /**
     * Get the symbol this tick belongs to.
     */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}




