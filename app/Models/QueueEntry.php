<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueEntry extends Model
{
    protected $fillable = [
        'user_id',
        'time_control_id',
        'snapshot_rating',
        'joined_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function timeControl()
    {
        return $this->belongsTo(TimeControl::class);
    }
}
