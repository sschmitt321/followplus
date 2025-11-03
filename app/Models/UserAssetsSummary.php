<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssetsSummary extends Model
{
    use HasFactory;

    protected $table = 'user_assets_summary';

    protected $fillable = [
        'user_id',
        'total_balance',
        'principal_balance',
        'profit_balance',
        'bonus_balance',
    ];

    protected function casts(): array
    {
        return [
            'total_balance' => MoneyCast::class,
            'principal_balance' => MoneyCast::class,
            'profit_balance' => MoneyCast::class,
            'bonus_balance' => MoneyCast::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
