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
        Schema::create('swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('from_currency', 10)->comment('源币种');
            $table->string('to_currency', 10)->comment('目标币种');
            $table->decimal('rate_snapshot', 36, 6)->nullable()->comment('汇率快照');
            $table->decimal('amount_from', 36, 6)->comment('源金额');
            $table->decimal('amount_to', 36, 6)->comment('目标金额');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->foreign('from_currency')->references('name')->on('currencies')->onDelete('restrict');
            $table->foreign('to_currency')->references('name')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('swaps');
    }
};
