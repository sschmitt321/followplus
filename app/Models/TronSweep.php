<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TronSweep extends Model
{
    use HasFactory;

    protected $table = 'tron_sweeps';

    protected $fillable = [
        'user_id',
        'from_address',
        'to_address',
        'txid',
        'amount',
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

