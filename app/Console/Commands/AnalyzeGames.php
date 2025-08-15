<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameAnalysis;
use App\Models\GameMove;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AnalyzeGames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chess:analyze {--limit=5}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze finished chess games using Stockfish';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jobs = GameAnalysis::where('status', 'queued')
                ->limit((int)$this->option('limit'))
                ->get();
                
        if ($jobs->isEmpty()) {
            $this->info('No games to analyze.');
            return self::SUCCESS;
        }
        
        $this->info("Found {$jobs->count()} games to analyze.");
        
        foreach ($jobs as $job) {
            $this->analyze($job);
        }
        
        return self::SUCCESS;
    }
    
    private function analyze(GameAnalysis $job): void
    {
        $this->info("Analyzing game #{$job->game_id}");
        $job->update(['status' => 'running']);
        
        try {
            $game = Game::with('moves')->findOrFail($job->game_id);
            
            // Check if Stockfish is installed
            if (!$this->isStockfishInstalled()) {
                $this->error('Stockfish not found. Please install Stockfish first.');
                $job->update(['status' => 'failed']);
                return;
            }
            
            // Start Stockfish process
            $proc = new Process(['stockfish']);
            $proc->setTimeout(60);
            $proc->start();
            
            $write = fn($cmd) => $proc->write($cmd . "\n");
            
            // Initialize Stockfish
            $write('uci');
            $write('isready');
            $write('ucinewgame');
            
            // Build moves string from UCI moves
            $uciMoves = $game->moves->pluck('uci')->all();
            
            if (!empty($uciMoves)) {
                $write('position startpos moves ' . implode(' ', $uciMoves));
            } else {
                $write('position startpos');
            }
            
            $perMove = [];
            
            // Analyze each position after each move
            foreach ($game->moves as $i => $move) {
                // Adjust position to the state after this move
                $movesList = array_slice($uciMoves, 0, $i + 1);
                $write('position startpos moves ' . implode(' ', $movesList));
                
                // Start analysis at a depth of 14 (adjust as needed)
                $write('go depth 14');
                
                // Wait for analysis to complete (when "bestmove" is output)
                $proc->waitUntil(fn($type, $out) => str_contains($out, 'bestmove'));
                
                $output = $proc->getIncrementalOutput();
                $score = $this->extractScore($output);
                $depth = $this->extractDepth($output);
                $bestMove = $this->extractBestMove($output);
                
                // Collect analysis results
                $perMove[] = [
                    'ply' => $move->ply,
                    'score' => $score,
                    'depth' => $depth,
                    'best_move' => $bestMove,
                ];
                
                $this->info("  Move {$move->ply} ({$move->san}): score = {$score}, depth = {$depth}");
            }
            
            // Create summary statistics
            $summary = $this->summarize($perMove, $game);
            
            // Update analysis record
            $job->update([
                'status' => 'done',
                'per_move' => $perMove,
                'summary' => $summary
            ]);
            
            // Quit Stockfish
            $write('quit');
            
            $this->info("Analysis complete for game #{$job->game_id}");
        } catch (\Exception $e) {
            $this->error("Error analyzing game #{$job->game_id}: " . $e->getMessage());
            $job->update(['status' => 'failed']);
        }
    }
    
    private function isStockfishInstalled(): bool
    {
        $process = new Process(['which', 'stockfish']);
        $process->run();
        return $process->isSuccessful();
    }
    
    private function extractScore(string $output): int
    {
        // Parse score from Stockfish output
        if (preg_match('/score cp\s+(-?\d+)/', $output, $matches)) {
            return (int) $matches[1];
        }
        
        // Look for mate score
        if (preg_match('/score mate\s+(-?\d+)/', $output, $matches)) {
            // Indicate mate with a large score value
            $mateIn = (int) $matches[1];
            return $mateIn > 0 ? 10000 - $mateIn : -10000 - $mateIn;
        }
        
        return 0;
    }
    
    private function extractDepth(string $output): int
    {
        if (preg_match('/depth\s+(\d+)/', $output, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
    
    private function extractBestMove(string $output): ?string
    {
        if (preg_match('/bestmove\s+([a-h][1-8][a-h][1-8][qrbnk]?)/', $output, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    private function summarize(array $perMove, Game $game): array
    {
        // Calculate summary statistics
        $whiteScores = [];
        $blackScores = [];
        
        foreach ($perMove as $move) {
            if ($move['ply'] % 2 === 1) { // White's move (ply is 1-based)
                $whiteScores[] = $move['score'];
            } else { // Black's move
                $blackScores[] = -$move['score']; // Negate score for black's perspective
            }
        }
        
        // Calculate average centipawn loss
        $whiteLosses = $this->calculateCentipawnLosses($whiteScores);
        $blackLosses = $this->calculateCentipawnLosses($blackScores);
        
        $avgWhiteLoss = !empty($whiteLosses) ? array_sum($whiteLosses) / count($whiteLosses) : 0;
        $avgBlackLoss = !empty($blackLosses) ? array_sum($blackLosses) / count($blackLosses) : 0;
        
        // Count blunders (losses greater than 100 centipawns)
        $whiteBlunders = count(array_filter($whiteLosses, fn($loss) => $loss > 100));
        $blackBlunders = count(array_filter($blackLosses, fn($loss) => $loss > 100));
        
        return [
            'white_player_id' => $game->white_id,
            'black_player_id' => $game->black_id,
            'avg_centipawn_loss_white' => round($avgWhiteLoss, 1),
            'avg_centipawn_loss_black' => round($avgBlackLoss, 1),
            'blunders_white' => $whiteBlunders,
            'blunders_black' => $blackBlunders,
            'total_moves' => count($perMove),
        ];
    }
    
    private function calculateCentipawnLosses(array $scores): array
    {
        $losses = [];
        
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $currentScore = $scores[$i];
            $nextScore = $scores[$i + 1];
            
            $loss = max(0, $currentScore - $nextScore);
            $losses[] = $loss;
        }
        
        return $losses;
    }
}
