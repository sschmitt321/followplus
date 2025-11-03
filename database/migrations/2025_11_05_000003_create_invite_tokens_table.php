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
        Schema::create('invite_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follow_window_id')->constrained('follow_windows')->onDelete('cascade');
            $table->string('token', 64)->comment('邀请码');
            $table->timestamp('valid_after')->comment('生效时间');
            $table->timestamp('valid_before')->comment('失效时间');
            $table->foreignId('symbol_id')->constrained('symbols')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['token', 'follow_window_id'], 'idx_invite_tokens_token_window');
            $table->index('follow_window_id', 'idx_invite_tokens_window');
            $table->index('token', 'idx_invite_tokens_token');
            $table->index('valid_after', 'idx_invite_tokens_valid_after');
            $table->index('valid_before', 'idx_invite_tokens_valid_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invite_tokens');
    }
};

