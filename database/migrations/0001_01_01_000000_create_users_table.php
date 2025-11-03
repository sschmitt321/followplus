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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('invite_code', 32)->unique();
            $table->unsignedBigInteger('invited_by_user_id')->nullable();
            $table->string('ref_path', 255)->comment('邀请路径，如: /1/2/3');
            $table->unsignedInteger('ref_depth')->default(0)->comment('邀请深度');
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active');
            $table->timestamp('first_joined_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('invited_by_user_id', 'idx_invited_by');
            $table->index('ref_path', 'idx_ref_path');
        });
        
        // Add foreign key constraint after table creation
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('invited_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
