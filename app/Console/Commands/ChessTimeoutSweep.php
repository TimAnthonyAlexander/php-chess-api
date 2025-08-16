<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameAnalysis;
use App\Models\PlayerRating;
use App\Models\TimeControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ChessTimeoutSweep extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chess:timeout-sweep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check active games for time expired and flag them accordingly';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Running chess timeout sweep...');
        
        // Get all active games that have had at least one move
        $activeGames = Game::where('status', 'active')
            ->whereNotNull('last_move_at') // Make sure there's a last move to check against
            ->where('move_index', '>', 0) // Skip games that haven't had any moves yet
            ->get();
            
        if ($activeGames->isEmpty()) {
            $this->info('No active games to check for timeouts.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$activeGames->count()} active games to check.");
        $timeouts = 0;
        
        foreach ($activeGames as $game) {
            $result = DB::transaction(function () use ($game) {
                // Re-query with lock to prevent race conditions
                $g = Game::lockForUpdate()->find($game->id);
                
                // Game might have been finished between our initial query and now
                if (!$g || $g->status !== 'active') {
                    return false;
                }
                
                // Calculate elapsed time using the database's own time measurement
                // avoiding any timezone issues completely
                $result = DB::select('
                    SELECT 
                        TIMESTAMPDIFF(MICROSECOND, last_move_at, NOW(6)) as usec_diff,
                        last_move_at as db_last_move,
                        NOW(6) as db_now
                    FROM games WHERE id = ?
                ', [$g->id])[0];
                
                $usec = max(0, (int)$result->usec_diff); // Never allow negative values
                $elapsedMs = (int) floor($usec / 1000);
                
                // Prevent timeout in early moves
                if ($g->move_index <= 2 && $elapsedMs > 10000) { // More than 10 seconds on first moves is suspicious
                    $this->warn("Game #{$g->id}: Suspicious large time difference in early move - capping at 1000ms (was {$elapsedMs}ms)");
                    $elapsedMs = 1000; // Cap at 1 second for early moves
                }
                
                $this->info("Game #{$g->id}: Calculated elapsed time: {$elapsedMs}ms, Move: {$g->move_index}, Last move at: {$result->db_last_move}, Now: {$result->db_now}");
                
                // Determine whose turn it is based on FEN or move index
                $isWhiteTurn = false;
                if ($g->fen === 'startpos') {
                    $isWhiteTurn = ($g->move_index % 2 === 0);
                } else {
                    $fenParts = explode(' ', (string) $g->fen);
                    $activeColor = $fenParts[1] ?? 'w';
                    $isWhiteTurn = ($activeColor === 'w');
                }
                
                if ($isWhiteTurn && $elapsedMs >= $g->white_time_ms) {
                    // White timed out, black wins
                    $this->applyTimeout($g, 'black');
                    return true;
                } elseif (!$isWhiteTurn && $elapsedMs >= $g->black_time_ms) {
                    // Black timed out, white wins
                    $this->applyTimeout($g, 'white');
                    return true;
                }
                
                return false;
            });
            
            if ($result) {
                $timeouts++;
            }
        }
        
        $this->info("Processed $timeouts timeouts.");
        return Command::SUCCESS;
    }
    
    protected function applyTimeout(Game $g, string $winnerColor)
    {
        $g->status = 'finished';
        $g->result = $winnerColor === 'white' ? '1-0' : '0-1';
        $g->reason = 'timeout';
        $g->save();
        
        // Apply ELO changes
        $tc = TimeControl::find($g->time_control_id);
        $wr = PlayerRating::firstOrCreate(['user_id' => $g->white_id, 'time_class' => $tc->time_class]);
        $br = PlayerRating::firstOrCreate(['user_id' => $g->black_id, 'time_class' => $tc->time_class]);
        
        $scoreW = $g->result === '1-0' ? 1.0 : 0.0;
        $scoreB = 1.0 - $scoreW;
        
        [$wr->rating, $wr->games] = [$this->elo($wr->rating, $br->rating, $scoreW, $wr->games), $wr->games + 1];
        [$br->rating, $br->games] = [$this->elo($br->rating, $wr->rating, $scoreB, $br->games), $br->games + 1];
        
        $wr->save();
        $br->save();
        
        // Queue analysis
        GameAnalysis::firstOrCreate(['game_id' => $g->id], ['status' => 'queued']);
        
        $this->info("Game #{$g->id}: {$winnerColor} wins by timeout");
    }
    
    protected function elo(int $ra, int $rb, float $sa, int $gamesA): int
    {
        $k = $gamesA < 30 ? 40 : 20;
        $ea = 1.0 / (1.0 + pow(10.0, ($rb - $ra) / 400.0));
        return (int) round($ra + $k * ($sa - $ea));
    }
}
