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
        Schema::table('ref_stats', function (Blueprint $table) {
            $table->unsignedInteger('direct_active_count')->default(0)->after('direct_count')->comment('直接邀请人数（已激活）');
            $table->unsignedInteger('team_active_count')->default(0)->after('team_count')->comment('团队总人数（已激活）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ref_stats', function (Blueprint $table) {
            $table->dropColumn(['direct_active_count', 'team_active_count']);
        });
    }
};
