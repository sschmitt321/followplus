<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TronHdWallet extends Model
{
    protected $table = 'tron_hd_wallet';

    protected $fillable = [
        'encrypted_master_seed',
        'next_derivation_index',
    ];

    protected $hidden = [
        'encrypted_master_seed',
    ];

    /**
     * Get the singleton HD wallet instance.
     */
    public static function getInstance(): self
    {
        return static::firstOrCreate([], [
            'encrypted_master_seed' => '',
            'next_derivation_index' => 0,
        ]);
    }
}

