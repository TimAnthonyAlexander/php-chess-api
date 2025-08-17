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
        Schema::create('time_controls', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();        // '5+0', '3+2', ...
            $table->unsignedInteger('initial_sec');
            $table->unsignedInteger('increment_ms');
            $table->string('time_class');            // 'bullet' | 'blitz' | 'rapid'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_controls');
    }
};
