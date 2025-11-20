<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tron_hd_wallet', function (Blueprint $table) {
            $table->id();
            $table->text('encrypted_master_seed')->comment('加密后的主种子（mnemonic 或 seed）');
            $table->unsignedInteger('next_derivation_index')->default(0)->comment('下一个派生索引');
            $table->timestamps();
        });

        // 插入一条记录用于存储主种子
        DB::table('tron_hd_wallet')->insert([
            'encrypted_master_seed' => '',
            'next_derivation_index' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tron_hd_wallet');
    }
};

