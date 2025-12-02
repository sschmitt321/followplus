<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses_liquidity', function (Blueprint $table) {
            $table->id();
            $table->string('address', 128)->unique()->comment('TRON 地址');
            $table->decimal('trx_balance', 36, 8)->default(0)->comment('TRX 余额');
            $table->decimal('usdt_balance', 36, 8)->default(0)->comment('USDT 余额');
            $table->decimal('dt_balance', 36, 8)->default(0)->comment('DT 余额');
            
            $table->string('status', 32)->comment('状态: NEW, READY_TO_TRANSFER, NEED_TRX_TOPUP, TRX_TOPPED_UP, SKIP_SMALL_BALANCE, TRANSFER_SENT, DONE, FAILED');
            $table->string('gas_strategy', 32)->nullable()->comment('Gas 策略: USE_TRX, USE_DT, NEED_TOPUP');
            
            $table->dateTime('last_checked_at')->nullable()->comment('最近一次余额检查时间');
            $table->string('last_tx_hash', 128)->nullable()->comment('最近一次 USDT 转账 tx hash');
            $table->string('last_topup_hash', 128)->nullable()->comment('最近一次 TRX 补给 tx hash');
            
            $table->string('error_code', 64)->nullable()->comment('最近一次失败的错误码');
            $table->text('error_message')->nullable()->comment('最近一次失败的错误内容');
            
            $table->timestamps();
            
            $table->index('status');
            $table->index('last_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses_liquidity');
    }
};
