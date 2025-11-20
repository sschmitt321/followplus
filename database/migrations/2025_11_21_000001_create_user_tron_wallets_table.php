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
        Schema::create('user_tron_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('tron_address', 64)->unique()->comment('Tron 充值地址');
            $table->unsignedInteger('derivation_index')->default(0)->comment('HD 地址 index（未来扩展用）');
            $table->text('encrypted_private_key')->comment('加密后的私钥');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('tron_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tron_wallets');
    }
};

