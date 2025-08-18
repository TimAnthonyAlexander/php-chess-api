<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_ratings', function (Blueprint $table) {
            $table->index(['time_class', 'rating'], 'player_ratings_timeclass_rating_index');
        });
    }

    public function down(): void
    {
        Schema::table('player_ratings', function (Blueprint $table) {
            $table->dropIndex('player_ratings_timeclass_rating_index');
        });
    }
};
