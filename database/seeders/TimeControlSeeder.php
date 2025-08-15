<?php

namespace Database\Seeders;

use App\Models\TimeControl;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TimeControlSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        TimeControl::insert([
            ['slug' => '1+0', 'initial_sec' => 60, 'increment_ms' => 0, 'time_class' => 'bullet', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => '3+0', 'initial_sec' => 180, 'increment_ms' => 0, 'time_class' => 'blitz', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => '3+2', 'initial_sec' => 180, 'increment_ms' => 2000, 'time_class' => 'blitz', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => '5+0', 'initial_sec' => 300, 'increment_ms' => 0, 'time_class' => 'blitz', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => '10+0', 'initial_sec' => 600, 'increment_ms' => 0, 'time_class' => 'rapid', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
