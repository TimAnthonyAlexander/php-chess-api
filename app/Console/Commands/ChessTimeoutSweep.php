<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameAnalysis;
use App\Models\PlayerRating;
use App\Models\TimeControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

class ChessTimeoutSweep extends Command
{
    protected $signature = 'chess:timeout-sweep';
    protected $description = 'Check active games for time expired and flag them accordingly';

    public function handle()
    {
        $this->info('Running chess timeout sweep...');

        // Always work in UTC to avoid any TZ skew
        $nowUtc = CarbonImmutable::now('UTC');

        // Keep it simple: check all active games that have a last_move_at
        // (first move should set last_move_at; see notes below)
        $activeGames = Game::where('status', 'active')
            ->whereNotNull('last_move_at')
            ->get();

        if ($activeGames->isEmpty()) {
            $this->info('No active games to check for timeouts.');
            return Command::SUCCESS;
        }

        $this->info("Found {$activeGames->count()} active games to check.");
        $timeouts = 0;

        foreach ($activeGames as $row) {
            $result = DB::transaction(function () use ($row, $nowUtc) {
                $g = Game::lockForUpdate()->find($row->id);
                if (!$g || $g->status !== 'active') return false;

                // Determine side to move; even ply => White to move
                $whiteToMove = ($g->move_index % 2 === 0);

                // Remaining time for the side to move
                $remainingMs = $whiteToMove ? (int)$g->white_time_ms : (int)$g->black_time_ms;

                // Fallback: if somehow last_move_at is null, treat as not timed out yet
                if (!$g->last_move_at) return false;

                // Work in UTC consistently
                $lastMoveUtc = $g->last_move_at->copy()->setTimezone('UTC');

                // Simple, robust rule: time expires when now >= last_move_at + remaining
                $deadline = $lastMoveUtc->addMilliseconds($remainingMs);

                if ($nowUtc->greaterThanOrEqualTo($deadline)) {
                    $winnerColor = $whiteToMove ? 'black' : 'white';
                    $this->applyTimeout($g, $winnerColor);
                    return true;
                }

                return false;
            });

            if ($result) $timeouts++;
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

        // Elo update based on original pre-game ratings (not sequentially mutated)
        $tc = TimeControl::find($g->time_control_id);
        $wr = PlayerRating::firstOrCreate(['user_id' => $g->white_id, 'time_class' => $tc->time_class]);
        $br = PlayerRating::firstOrCreate(['user_id' => $g->black_id, 'time_class' => $tc->time_class]);

        $rW0 = (int)$wr->rating;
        $rB0 = (int)$br->rating;

        $scoreW = $g->result === '1-0' ? 1.0 : 0.0;
        $scoreB = 1.0 - $scoreW;

        [$rW1, $rB1] = $this->eloPair($rW0, $rB0, $scoreW, $scoreB, $wr->games, $br->games);

        $wr->rating = $rW1; $wr->games = $wr->games + 1; $wr->save();
        $br->rating = $rB1; $br->games = $br->games + 1; $br->save();

        GameAnalysis::firstOrCreate(['game_id' => $g->id], ['status' => 'queued']);

        $this->info("Game #{$g->id}: {$winnerColor} wins by timeout");
    }

    protected function eloPair(int $rW, int $rB, float $sW, float $sB, int $gW, int $gB): array
    {
        $kW = $gW < 30 ? 40 : 20;
        $kB = $gB < 30 ? 40 : 20;

        $eW = 1.0 / (1.0 + pow(10.0, ($rB - $rW) / 400.0));
        $eB = 1.0 - $eW;

        $newW = (int) round($rW + $kW * ($sW - $eW));
        $newB = (int) round($rB + $kB * ($sB - $eB));

        return [$newW, $newB];
    }
}
