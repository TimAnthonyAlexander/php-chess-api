<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameAnalysis extends Model
{
    protected $fillable = [
        'game_id',
        'status',
        'summary',
        'per_move'
    ];

    protected $casts = [
        'summary' => 'json',
        'per_move' => 'json'
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
