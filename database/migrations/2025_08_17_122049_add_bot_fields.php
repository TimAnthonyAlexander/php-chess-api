<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_bot')->default(false)->index();
        });
        Schema::table('games', function (Blueprint $t) {
            $t->boolean('has_bot')->default(false)->index();
        });
        Schema::table('queue_entries', function (Blueprint $t) {
            $t->timestamp('matched_at')->nullable()->index();
            $t->boolean('is_active')->storedAs('IF(matched_at IS NULL, 1, 0)')->index();
            $t->unique(['user_id', 'time_control_id', 'is_active'], 'queue_unique_active');
        });
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_bot'));
        Schema::table('games', fn (Blueprint $t) => $t->dropColumn('has_bot'));
        Schema::table('queue_entries', function (Blueprint $t) {
            $t->dropUnique('queue_unique_active');
            $t->dropColumn('is_active');
            $t->dropColumn('matched_at');
        });
    }
};