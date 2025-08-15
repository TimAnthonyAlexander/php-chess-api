<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeControl extends Model
{
    protected $fillable = [
        'slug',
        'initial_sec',
        'increment_ms',
        'time_class',
    ];

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function queueEntries()
    {
        return $this->hasMany(QueueEntry::class);
    }
}
