<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Withdrawal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'currency',
        'amount_request',
        'fee',
        'amount_actual',
        'status',
        'to_address',
        'chain',
        'txid',
    ];

    protected function casts(): array
    {
        return [
            'amount_request' => MoneyCast::class,
            'fee' => MoneyCast::class,
            'amount_actual' => MoneyCast::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currencyModel(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency', 'name');
    }
}
