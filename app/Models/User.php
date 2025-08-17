<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    
    /**
     * Get the player ratings for the user.
     */
    public function ratings()
    {
        return $this->hasMany(PlayerRating::class);
    }
    
    /**
     * Get the queue entries for the user.
     */
    public function queueEntries()
    {
        return $this->hasMany(QueueEntry::class);
    }
    
    /**
     * Get games where user played as white.
     */
    public function gamesAsWhite()
    {
        return $this->hasMany(Game::class, 'white_id');
    }
    
    /**
     * Get games where user played as black.
     */
    public function gamesAsBlack()
    {
        return $this->hasMany(Game::class, 'black_id');
    }
    
    /**
     * Get all games for the user.
     */
    public function games()
    {
        return $this->gamesAsWhite()->union($this->gamesAsBlack());
    }
    
    /**
     * Get moves made by the user.
     */
    public function moves()
    {
        return $this->hasMany(GameMove::class, 'by_user_id');
    }
}
