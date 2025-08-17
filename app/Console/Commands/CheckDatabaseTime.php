<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CheckDatabaseTime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:check-time';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and compare database and system time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking system and database time comparison...');
        
        // Get system time
        $systemTime = Carbon::now();
        $this->line('PHP System Time: ' . $systemTime->format('Y-m-d H:i:s.u') . ' ' . $systemTime->tzName);
        
        // Get PHP timezone setting
        $this->line('PHP Default Timezone: ' . date_default_timezone_get());
        $this->line('Laravel App Timezone: ' . config('app.timezone'));
        
        // Get MySQL time info
        $dbInfo = DB::select('
            SELECT 
                NOW(6) as db_now,
                UTC_TIMESTAMP(6) as db_utc,
                UNIX_TIMESTAMP(NOW(6)) as db_unix_ts,
                @@session.time_zone as db_session_tz,
                @@global.time_zone as db_global_tz,
                @@system_time_zone as db_system_tz
        ')[0];
        
        // Calculate diff between PHP and MySQL
        $dbTimeStr = $dbInfo->db_now;
        $dbTime = Carbon::parse($dbTimeStr);
        $diffMs = $systemTime->diffInMilliseconds($dbTime);
        $diffDirection = $systemTime->gt($dbTime) ? 'ahead of' : 'behind';
        
        $this->line('');
        $this->line('MySQL Current Time: ' . $dbInfo->db_now);
        $this->line('MySQL UTC Time: ' . $dbInfo->db_utc);
        $this->line('MySQL Session Timezone: ' . $dbInfo->db_session_tz);
        $this->line('MySQL Global Timezone: ' . $dbInfo->db_global_tz);
        $this->line('MySQL System Timezone: ' . $dbInfo->db_system_tz);
        
        // Show difference
        $absDiff = abs($diffMs);
        $this->line('');
        $this->info("Time difference: PHP time is {$absDiff}ms {$diffDirection} MySQL time");
        
        // Simulation of TIMESTAMPDIFF
        $test1 = Carbon::now();
        sleep(1);
        $test2 = Carbon::now();
        $this->line('');
        $this->line('Simulating a 1 second delay:');
        $this->line('Start: ' . $test1->format('Y-m-d H:i:s.u'));
        $this->line('End: ' . $test2->format('Y-m-d H:i:s.u'));
        $this->line('Diff: ' . $test1->diffInMicroseconds($test2) . ' microseconds');
        
        // DB microsecond precision test
        $this->line('');
        $this->line('Testing DB microsecond precision with NOW(6):');
        
        $query = "SELECT NOW(6) as time1, SLEEP(1), NOW(6) as time2, 
                 TIMESTAMPDIFF(MICROSECOND, time1, time2) as usec_diff";
                 
        $dbTimeDiff = DB::select($query)[0];
        $this->line('Time1: ' . $dbTimeDiff->time1);
        $this->line('Time2: ' . $dbTimeDiff->time2);
        $this->line('Diff microseconds: ' . $dbTimeDiff->usec_diff);
        $this->line('Diff milliseconds: ' . floor($dbTimeDiff->usec_diff / 1000));
        
        // Test inserting and reading timestamp(3)
        $this->testMicrosecondColumn();
        
        return 0;
    }
    
    private function testMicrosecondColumn()
    {
        $this->line('');
        $this->line('Testing microsecond precision with database column:');
        
        // Create temporary test table
        DB::statement('DROP TABLE IF EXISTS time_test');
        DB::statement('CREATE TABLE time_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP(3) NULL,
            updated_at TIMESTAMP(3) NULL
        )');
        
        // Insert current time
        $startTime = Carbon::now();
        DB::table('time_test')->insert([
            'created_at' => $startTime,
            'updated_at' => $startTime
        ]);
        
        // Wait a bit
        usleep(500000); // 500ms
        
        // Get the saved time and compare
        $record = DB::table('time_test')->first();
        $savedTime = Carbon::parse($record->created_at);
        
        $this->line('Original time: ' . $startTime->format('Y-m-d H:i:s.u'));
        $this->line('Saved time: ' . $savedTime->format('Y-m-d H:i:s.u'));
        $this->line('Precision loss: ' . ($startTime->format('u') - $savedTime->format('u')) . ' microseconds');
        
        // Clean up
        DB::statement('DROP TABLE IF EXISTS time_test');
    }
}
