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
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('txid')->comment('审核备注');
            $table->timestamp('reviewed_at')->nullable()->after('review_note')->comment('审核时间');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete()->comment('审核人ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['review_note', 'reviewed_at', 'reviewed_by']);
        });
    }
};
