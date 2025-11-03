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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['spot', 'contract'])->comment('账户类型：现货/合约');
            $table->string('currency', 10)->comment('币种');
            $table->decimal('available', 36, 6)->default(0)->comment('可用余额');
            $table->decimal('frozen', 36, 6)->default(0)->comment('冻结余额');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['user_id', 'type', 'currency'], 'idx_user_type_currency');
            $table->index('user_id');
        });
        
        // Add foreign key constraint after currencies table exists
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreign('currency')->references('name')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
