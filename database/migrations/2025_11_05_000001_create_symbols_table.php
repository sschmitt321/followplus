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
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('base', 10)->comment('基础币种，如 BTC');
            $table->string('quote', 10)->comment('计价币种，如 USDT');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->unique(['base', 'quote'], 'idx_symbols_pair');
            $table->index('enabled', 'idx_symbols_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};

