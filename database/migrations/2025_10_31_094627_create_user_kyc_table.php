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
        Schema::create('user_kyc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->enum('level', ['none', 'basic', 'advanced'])->default('none');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('front_image_url')->nullable();
            $table->string('back_image_url')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_kyc');
    }
};
