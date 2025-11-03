<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'account_id',
        'currency',
        'amount',
        'balance_after',
        'biz_type',
        'ref_id',
        'meta_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
            'meta_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
