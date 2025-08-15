<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the timeout sweep every minute
        $schedule->command('chess:timeout-sweep')->everyMinute();
        
        // Run the game analysis every 5 minutes, with a limit of 5 games per run
        $schedule->command('chess:analyze --limit=5')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
