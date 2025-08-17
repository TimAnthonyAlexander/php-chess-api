<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameMove;
use App\Services\StockfishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class BotMakeMove implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $gameId) {}

    public function handle(StockfishService $engine): void
    {
        DB::transaction(function () use ($engine) {
            $g = Game::lockForUpdate()->with('timeControl')->find($this->gameId);
            if (!$g || $g->status !== 'active' || !$g->has_bot) {
                return;
            }

            $fen = $g->fen === 'startpos' ? 'startpos' : $g->fen;

            $skill = 6 + ($g->id % 4);
            $ms    = 250 + ($g->id % 250);

            $uci = $engine->bestMove($fen, $skill, $ms);
            if (!$uci) {
                // No legal move (shouldn't happen often) -> flag draw as fallback
                $g->status = 'finished';
                $g->result = '1/2-1/2';
                $g->reason = 'no-uci';
                $g->save();
                return;
            }

            // Timekeeping for the mover (it is currently bot's turn)
            $elapsedMs = 0;
            if ($g->last_move_at) {
                $elapsedMs = max(0, $g->last_move_at->diffInMilliseconds(now()));
            }

            $tc = $g->timeControl;
            $whiteMs = (int) $g->white_time_ms;
            $blackMs = (int) $g->black_time_ms;
            $toMoveIsWhite = ($g->move_index % 2 === 0);

            if ($toMoveIsWhite) {
                $whiteMs = max(0, $whiteMs - $elapsedMs) + (int) $tc->increment_ms;
            } else {
                $blackMs = max(0, $blackMs - $elapsedMs) + (int) $tc->increment_ms;
            }

            // Apply move via engine to get the post-move FEN (authoritative)
            $fenAfter = $engine->fenAfter($fen, $uci) ?? $g->fen;

            $from = substr($uci, 0, 2);
            $to   = substr($uci, 2, 2);
            $promotion = strlen($uci) === 5 ? substr($uci, 4, 1) : null;

            $g->white_time_ms = $whiteMs;
            $g->black_time_ms = $blackMs;
            $g->move_index    = ($g->move_index ?? 0) + 1;
            $g->fen           = $fenAfter;
            $g->last_move_at  = now();
            $g->lock_version  = ($g->lock_version ?? 0) + 1;

            // Optional: detect checkmate/stalemate quickly by checking no best move next turn.
            // Keep it simple and let your existing finish logic handle terminal states.

            $g->save();

            GameMove::create([
                'game_id'               => $g->id,
                'ply'                   => $g->move_index,
                'by_user_id'            => ($toMoveIsWhite ? $g->white_id : $g->black_id),
                'uci'                   => $uci,
                'san'                   => null, // Explicitly set to null since we don't calculate SAN
                'from_sq'               => $from,
                'to_sq'                 => $to,
                'promotion'             => $promotion,
                'fen_after'             => $fenAfter,
                'white_time_ms_after'   => $whiteMs,
                'black_time_ms_after'   => $blackMs,
            ]);
        });
        // Do NOT self-reschedule here. You already:
        // - schedule the first bot move if bot is white (CreateBotFallback)
        // - schedule after human moves (GameController::move)
    }
}