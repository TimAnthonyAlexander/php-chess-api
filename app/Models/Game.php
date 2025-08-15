<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'time_control_id',
        'white_id',
        'black_id',
        'status',
        'result',
        'reason',
        'fen',
        'move_index',
        'white_time_ms',
        'black_time_ms',
        'last_move_at',
        'lock_version'
    ];

    protected $casts = [
        'last_move_at' => 'datetime',
        'lock_version' => 'integer',
        'move_index' => 'integer',
        'white_time_ms' => 'integer',
        'black_time_ms' => 'integer',
    ];

    public function timeControl()
    {
        return $this->belongsTo(TimeControl::class);
    }

    public function white()
    {
        return $this->belongsTo(User::class, 'white_id');
    }

    public function black()
    {
        return $this->belongsTo(User::class, 'black_id');
    }

    public function moves()
    {
        return $this->hasMany(GameMove::class)->orderBy('ply');
    }

    public function analysis()
    {
        return $this->hasOne(GameAnalysis::class);
    }
}
