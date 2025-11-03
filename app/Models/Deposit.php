<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deposit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'currency',
        'chain',
        'address',
        'amount',
        'status',
        'txid',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'confirmed_at' => 'datetime',
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
