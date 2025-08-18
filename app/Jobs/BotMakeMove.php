<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameMove;
use App\Models\PlayerRating;
use App\Services\StockfishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            $toMoveIsWhite = ($g->move_index % 2 === 0);
            $botUserId = $toMoveIsWhite ? $g->white_id : $g->black_id;

            $tc = $g->timeControl;
            $rating = PlayerRating::query()
                ->where('user_id', $botUserId)
                ->when($tc && isset($tc->time_class), fn($q) => $q->where('time_class', $tc->time_class))
                ->value('rating') ?? 1500;

            Log::info('rating', [
                'game_id' => $g->id,
                'bot_user_id' => $botUserId,
                'time_class' => $tc->time_class ?? null,
                'rating' => $rating,
            ]);

            $elapsedMs = $g->last_move_at ? max(0, $g->last_move_at->diffInMilliseconds(now())) : 0;

            $whiteMs = (int) $g->white_time_ms;
            $blackMs = (int) $g->black_time_ms;

            $wRemain = $toMoveIsWhite ? max(0, $whiteMs - $elapsedMs) : $whiteMs;
            $bRemain = $toMoveIsWhite ? $blackMs : max(0, $blackMs - $elapsedMs);

            $inc = (int) ($tc->increment_ms ?? 0);

            $eloCap = $rating > 0 && $rating <= 3000 ? (int) $rating : null;

            Log::info('bot_move.parameters', [
                'game_id' => $g->id,
                'bot_user_id' => $botUserId,
                'time_class' => $tc->time_class ?? null,
                'rating' => $rating,
                'elo_cap' => $eloCap,
                'wtime_ms' => $wRemain,
                'btime_ms' => $bRemain,
                'inc_ms' => $inc,
                'fen' => $fen,
                'move_index' => $g->move_index,
            ]);

            $cap = match ($tc->time_class ?? null) {
                'bullet' => 800,
                'blitz'  => 2500,
                'rapid'  => 6000,
                'classical' => 10000,
                default  => 3000,
            };

            $uci = $engine->bestMoveFromClock(
                $fen,
                $wRemain,
                $bRemain,
                $inc,
                $inc,
                $eloCap,
                $cap
            );

            if (!$uci) {
                Log::warning('Stockfish did not return a best move', [
                    'game_id' => $g->id,
                    'fen' => $fen,
                    'wtime_ms' => $wRemain,
                    'btime_ms' => $bRemain,
                    'inc_ms' => $inc,
                    'elo_cap' => $eloCap,
                ]);

                $g->status = 'finished';
                $g->result = '1/2-1/2';
                $g->reason = 'no-uci';
                $g->save();
                return;
            }

            Log::info('bot_move.result', [
                'game_id' => $g->id,
                'uci' => $uci,
            ]);

            if ($toMoveIsWhite) {
                $whiteMs = max(0, $whiteMs - $elapsedMs) + $inc;
            } else {
                $blackMs = max(0, $blackMs - $elapsedMs) + $inc;
            }

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
            $g->save();

            GameMove::create([
                'game_id'               => $g->id,
                'ply'                   => $g->move_index,
                'by_user_id'            => $botUserId,
                'uci'                   => $uci,
                'san'                   => null,
                'from_sq'               => $from,
                'to_sq'                 => $to,
                'promotion'             => $promotion,
                'fen_after'             => $fenAfter,
                'white_time_ms_after'   => $whiteMs,
                'black_time_ms_after'   => $blackMs,
            ]);
        });
    }
}
