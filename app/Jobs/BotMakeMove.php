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

            $skill = (int) max(0, min(20, round(($rating - 800) / 40)));

            $base = match ($tc->time_class ?? null) {
                'bullet' => 80,
                'blitz'  => 180,
                'rapid'  => 350,
                default  => 250,
            };
            $ms = (int) max(40, $base + (20 - $skill) * 25);

            $uci = $engine->bestMove($fen, $skill, $ms);
            if (!$uci) {
                $g->status = 'finished';
                $g->result = '1/2-1/2';
                $g->reason = 'no-uci';
                $g->save();
                return;
            }

            $elapsedMs = 0;
            if ($g->last_move_at) {
                $elapsedMs = max(0, $g->last_move_at->diffInMilliseconds(now()));
            }

            $whiteMs = (int) $g->white_time_ms;
            $blackMs = (int) $g->black_time_ms;

            if ($toMoveIsWhite) {
                $whiteMs = max(0, $whiteMs - $elapsedMs) + (int) $tc->increment_ms;
            } else {
                $blackMs = max(0, $blackMs - $elapsedMs) + (int) $tc->increment_ms;
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
