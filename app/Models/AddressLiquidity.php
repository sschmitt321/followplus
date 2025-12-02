<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddressLiquidity extends Model
{
    use HasFactory;

    protected $table = 'addresses_liquidity';

    protected $fillable = [
        'address',
        'trx_balance',
        'usdt_balance',
        'dt_balance',
        'status',
        'gas_strategy',
        'last_checked_at',
        'last_tx_hash',
        'last_topup_hash',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'trx_balance' => MoneyCast::class,
            'usdt_balance' => MoneyCast::class,
            'dt_balance' => MoneyCast::class,
            'last_checked_at' => 'datetime',
        ];
    }

    // Status constants
    public const STATUS_NEW = 'NEW';
    public const STATUS_READY_TO_TRANSFER = 'READY_TO_TRANSFER';
    public const STATUS_NEED_TRX_TOPUP = 'NEED_TRX_TOPUP';
    public const STATUS_TRX_TOPPED_UP = 'TRX_TOPPED_UP';
    public const STATUS_SKIP_SMALL_BALANCE = 'SKIP_SMALL_BALANCE';
    public const STATUS_TRANSFER_SENT = 'TRANSFER_SENT';
    public const STATUS_DONE = 'DONE';
    public const STATUS_FAILED = 'FAILED';

    // Gas strategy constants
    public const GAS_STRATEGY_USE_TRX = 'USE_TRX';
    public const GAS_STRATEGY_USE_DT = 'USE_DT';
    public const GAS_STRATEGY_NEED_TOPUP = 'NEED_TOPUP';

    /**
     * Check if address is ready for transfer.
     */
    public function isReadyForTransfer(float $minTrx, float $minUsdt): bool
    {
        return $this->trx_balance >= $minTrx && $this->usdt_balance >= $minUsdt;
    }

    /**
     * Check if address needs TRX topup.
     */
    public function needsTrxTopup(float $minTrx, float $minUsdt): bool
    {
        return $this->usdt_balance >= $minUsdt && $this->trx_balance < $minTrx;
    }

    /**
     * Check if address has small balance.
     */
    public function hasSmallBalance(float $minUsdt): bool
    {
        return $this->usdt_balance < $minUsdt;
    }
}
