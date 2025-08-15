<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameMove extends Model
{
    protected $fillable = [
        'game_id',
        'ply',
        'by_user_id',
        'uci',
        'san',
        'from_sq',
        'to_sq',
        'promotion',
        'fen_after',
        'white_time_ms_after',
        'black_time_ms_after',
        'moved_at'
    ];

    protected $casts = [
        'ply' => 'integer',
        'white_time_ms_after' => 'integer',
        'black_time_ms_after' => 'integer',
        'moved_at' => 'datetime'
    ];

    public $timestamps = false;

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function byUser()
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
