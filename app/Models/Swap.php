<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Swap extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'from_currency',
        'to_currency',
        'rate_snapshot',
        'amount_from',
        'amount_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rate_snapshot' => MoneyCast::class,
            'amount_from' => MoneyCast::class,
            'amount_to' => MoneyCast::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromCurrencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency', 'name');
    }

    public function toCurrencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency', 'name');
    }
}
