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
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->unique(['user_id', 'time_control_id'], 'queue_entries_user_tc_unique');
            $table->index(['time_control_id', 'joined_at']);
            $table->index(['time_control_id', 'snapshot_rating', 'joined_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropUnique('queue_entries_user_tc_unique');
            $table->dropIndex(['time_control_id', 'joined_at']);
            $table->dropIndex(['time_control_id', 'snapshot_rating', 'joined_at']);
        });
    }
};
