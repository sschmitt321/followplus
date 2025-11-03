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
        Schema::create('user_assets_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('total_balance', 36, 6)->default(0)->comment('总余额');
            $table->decimal('principal_balance', 36, 6)->default(0)->comment('本金余额');
            $table->decimal('profit_balance', 36, 6)->default(0)->comment('利润余额');
            $table->decimal('bonus_balance', 36, 6)->default(0)->comment('奖励余额');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_assets_summary');
    }
};
