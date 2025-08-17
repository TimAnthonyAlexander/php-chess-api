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
