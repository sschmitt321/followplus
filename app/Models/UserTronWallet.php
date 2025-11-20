<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTronWallet extends Model
{
    use HasFactory;

    protected $table = 'user_tron_wallets';

    protected $fillable = [
        'user_id',
        'tron_address',
        'derivation_index',
        'encrypted_private_key',
    ];

    protected $hidden = [
        'encrypted_private_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

