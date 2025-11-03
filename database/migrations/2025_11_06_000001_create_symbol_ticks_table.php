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
        Schema::create('symbol_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->onDelete('cascade');
            $table->decimal('last_price', 36, 6)->comment('最新价格');
            $table->decimal('change_percent', 10, 4)->default(0)->comment('涨跌幅（百分比）');
            $table->timestamp('tick_at')->comment('行情时间');
            $table->timestamps();
            
            $table->index('symbol_id', 'idx_symbol_ticks_symbol');
            $table->index('tick_at', 'idx_symbol_ticks_tick_at');
            $table->index(['symbol_id', 'tick_at'], 'idx_symbol_ticks_symbol_tick');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('symbol_ticks');
    }
};

