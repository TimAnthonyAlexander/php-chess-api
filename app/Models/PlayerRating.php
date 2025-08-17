<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerRating extends Model
{
    protected $fillable = [
        'user_id',
        'time_class',
        'rating',
        'games',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
