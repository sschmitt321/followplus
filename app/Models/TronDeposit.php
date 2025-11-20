<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TronDeposit extends Model
{
    use HasFactory;

    protected $table = 'tron_deposits';

    protected $fillable = [
        'user_id',
        'tron_address',
        'txid',
        'from_address',
        'amount',
        'token_symbol',
        'confirmations',
        'required_confirmations',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

