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
        Schema::create('tron_sweeps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_address', 64)->comment('用户充值地址');
            $table->string('to_address', 64)->comment('热钱包地址');
            $table->string('txid', 128)->nullable()->comment('交易哈希');
            $table->decimal('amount', 36, 6)->comment('归集金额');
            $table->enum('status', ['created', 'broadcasted', 'confirmed', 'failed'])->default('created')->comment('状态');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('from_address');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tron_sweeps');
    }
};

