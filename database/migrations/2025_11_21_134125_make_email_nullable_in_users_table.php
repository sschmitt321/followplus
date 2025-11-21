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
        Schema::table('users', function (Blueprint $table) {
            // Make email nullable to support phone-only registration
            // First drop the unique constraint
            $table->dropUnique(['email']);
        });

        // Change column to nullable
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');

        // Re-add unique constraint for non-null emails
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Before making email non-nullable, ensure all users have email
        // This is a safety check - in production, you might want to handle this differently
        DB::statement("UPDATE users SET email = CONCAT('user_', id, '@placeholder.com') WHERE email IS NULL");

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
