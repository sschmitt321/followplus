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
        // Modify enum to add new event type 'ambassador_level_down'
        DB::statement("ALTER TABLE ref_events MODIFY COLUMN event_type ENUM(
            'first_deposit',
            'newbie_next_day',
            'ambassador_level_up',
            'ambassador_level_down',
            'dividend',
            'withdraw_paid'
        ) COMMENT '事件类型'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'ambassador_level_down' from enum
        // Note: This will fail if there are existing records with this type
        DB::statement("ALTER TABLE ref_events MODIFY COLUMN event_type ENUM(
            'first_deposit',
            'newbie_next_day',
            'ambassador_level_up',
            'dividend',
            'withdraw_paid'
        ) COMMENT '事件类型'");
    }
};
