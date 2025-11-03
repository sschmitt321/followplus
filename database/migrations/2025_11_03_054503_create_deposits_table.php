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
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 10)->comment('币种');
            $table->string('chain', 20)->nullable()->comment('链名称，如 TRC20, ERC20');
            $table->string('address', 255)->nullable()->comment('充币地址');
            $table->decimal('amount', 36, 6)->comment('金额');
            $table->enum('status', ['pending', 'confirmed', 'failed'])->default('pending');
            $table->string('txid', 255)->nullable()->comment('交易哈希');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->foreign('currency')->references('name')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
