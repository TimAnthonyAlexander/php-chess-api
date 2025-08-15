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
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_control_id')->constrained('time_controls');
            $table->foreignId('white_id')->constrained('users');
            $table->foreignId('black_id')->constrained('users');
            $table->string('status'); // queued|active|finished|aborted
            $table->string('result')->nullable(); // '1-0','0-1','1/2-1/2'
            $table->string('reason')->nullable(); // checkmate|resign|timeout|draw|abort
            $table->text('fen')->default('startpos');
            $table->unsignedInteger('move_index')->default(0);
            $table->bigInteger('white_time_ms')->unsigned();
            $table->bigInteger('black_time_ms')->unsigned();
            $table->timestamp('last_move_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['status','updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
