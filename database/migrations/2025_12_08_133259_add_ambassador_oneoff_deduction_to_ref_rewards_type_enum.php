<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify enum to add new type 'ambassador_oneoff_deduction'
        DB::statement("ALTER TABLE ref_rewards MODIFY COLUMN type ENUM(
            'referral_10pct',
            'notifier_5pct',
            'upline_5pct',
            'newbie_next_day',
            'ambassador_oneoff',
            'ambassador_oneoff_deduction',
            'dividend'
        ) COMMENT '奖励类型'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'ambassador_oneoff_deduction' from enum
        // Note: This will fail if there are existing records with this type
        DB::statement("ALTER TABLE ref_rewards MODIFY COLUMN type ENUM(
            'referral_10pct',
            'notifier_5pct',
            'upline_5pct',
            'newbie_next_day',
            'ambassador_oneoff',
            'dividend'
        ) COMMENT '奖励类型'");
    }
};
