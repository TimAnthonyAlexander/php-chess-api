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
        Schema::create('game_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->unsignedInteger('ply');       // 1-based ply counter
            $table->foreignId('by_user_id')->constrained('users');
            $table->string('uci');                // e2e4, e7e8q
            $table->string('san');                // e4, exd5, Qxe7+, etc.
            $table->string('from_sq', 2);
            $table->string('to_sq', 2);
            $table->string('promotion', 1)->nullable();
            $table->text('fen_after');
            $table->bigInteger('white_time_ms_after')->unsigned();
            $table->bigInteger('black_time_ms_after')->unsigned();
            $table->timestamp('moved_at')->useCurrent();
            $table->unique(['game_id','ply']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_moves');
    }
};
