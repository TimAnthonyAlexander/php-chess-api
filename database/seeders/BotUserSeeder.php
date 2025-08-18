<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\PlayerRating;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BotUserSeeder extends Seeder
{
    public function run(): void
    {
        // baseline ELOs per bot “strength”
        $bots = [
            ['DarkSquare Guest',       600],
            ['DarkSquare Challenger',  800],
            ['DarkSquare Rival',      1000],
            ['DarkSquare Expert',     1200],
            ['DarkSquare Master',     1400],
            ['DarkSquare Grandmaster', 1600],
            ['DarkSquare SuperGM',    1800],
            ['DarkSquare Bot',        2000],
            ['DarkSquare Bot Pro',    2200],
            ['DarkSquare Bot Elite',  2400],
            ['DarkSquare Bot Supreme', 2600],
            ['DarkSquare Bot Ultimate', 2800],
        ];

        // choose the time classes you actually use
        $timeClasses = ['bullet', 'blitz', 'rapid', 'classical'];

        foreach ($bots as [$name, $elo]) {
            $user = User::updateOrCreate(
                ['email' => Str::slug($name) . '@bots.darksquare'],
                ['name' => $name, 'password' => bcrypt(Str::random(24)), 'is_bot' => true]
            );

            foreach ($timeClasses as $tc) {
                PlayerRating::updateOrCreate(
                    ['user_id' => $user->id, 'time_class' => $tc],
                    ['rating'  => $elo, 'games' => 0]
                );
            }
        }
    }
}
