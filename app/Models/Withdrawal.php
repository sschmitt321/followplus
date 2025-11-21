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
        'review_note',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_request' => MoneyCast::class,
            'fee' => MoneyCast::class,
            'amount_actual' => MoneyCast::class,
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
