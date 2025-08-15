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
        Schema::create('game_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('status'); // queued|running|done|failed
            $table->json('summary')->nullable();  // {avg_centipawn_loss_white,...}
            $table->json('per_move')->nullable(); // [{ply,score,type,depth,seldepth,pv},...]
            $table->timestamps();
            $table->unique('game_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_analyses');
    }
};
