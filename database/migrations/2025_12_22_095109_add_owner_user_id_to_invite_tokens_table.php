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
        Schema::table('invite_tokens', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('symbol_id')->constrained('users')->onDelete('cascade')->comment('邀请码所有者（创建者）的用户ID');
            $table->index('owner_user_id', 'idx_invite_tokens_owner');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invite_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_invite_tokens_owner');
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
};
