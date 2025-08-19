<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameMove;
use App\Models\PlayerRating;
use App\Models\TimeControl;
use App\Models\GameAnalysis;
use App\Services\StockfishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Chess\Variant\Classical\FenToBoardFactory;

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

            $eloTarget = (int) max(200, min(3000, $rating));

            Log::info('bot_move.parameters', [
                'game_id' => $g->id,
                'bot_user_id' => $botUserId,
                'time_class' => $tc->time_class ?? null,
                'rating' => $rating,
                'wtime_ms' => $wRemain,
                'btime_ms' => $bRemain,
                'inc_ms' => $inc,
                'fen' => $fen,
                'move_index' => $g->move_index,
            ]);

            $baseCap = match ($tc->time_class ?? null) {
                'bullet'     => 300,
                'blitz'      => 900,
                'rapid'      => 2000,
                'classical'  => 3500,
                default      => 1200,
            };

            $sideRemain = $toMoveIsWhite ? $wRemain : $bRemain;
            $cap = (int) max(120, min($baseCap, $sideRemain / 40));

            Log::info('bot_move.parameters', [
                'game_id' => $g->id,
                'bot_user_id' => $botUserId,
                'time_class' => $tc->time_class ?? null,
                'rating' => $rating,
                'elo_target' => $eloTarget,
                'wtime_ms' => $wRemain,
                'btime_ms' => $bRemain,
                'inc_ms' => $inc,
                'fen' => $fen,
                'move_index' => $g->move_index,
            ]);

            $uci = $engine->bestMoveFromClock(
                $fen,
                $wRemain,
                $bRemain,
                $inc,
                $inc,
                $eloTarget,
                $cap,
            );

            if (!$uci) {
                Log::warning('Stockfish did not return a best move', [
                    'game_id' => $g->id,
                    'fen' => $fen,
                    'wtime_ms' => $wRemain,
                    'btime_ms' => $bRemain,
                    'inc_ms' => $inc,
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

            // Detect end-of-game states (mate/draw) after the bot's move
            try {
                $boardAfter = FenToBoardFactory::create($fenAfter);

                if (method_exists($boardAfter, 'isMate') && $boardAfter->isMate()) {
                    $result = $toMoveIsWhite ? '1-0' : '0-1';

                    $g->status = 'finished';
                    $g->result = $result;
                    $g->reason = 'checkmate';
                    $g->save();

                    // Apply Elo updates similar to other finish paths
                    $tc = TimeControl::find($g->time_control_id);
                    $wr = PlayerRating::firstOrCreate(['user_id' => $g->white_id, 'time_class' => $tc->time_class]);
                    $br = PlayerRating::firstOrCreate(['user_id' => $g->black_id, 'time_class' => $tc->time_class]);

                    $rW = (int) $wr->rating;
                    $rB = (int) $br->rating;
                    $gW = (int) $wr->games;
                    $gB = (int) $br->games;

                    $scoreW = $result === '1-0' ? 1.0 : 0.0;
                    $scoreB = 1.0 - $scoreW;

                    $kW = $gW < 30 ? 40 : 20;
                    $kB = $gB < 30 ? 40 : 20;

                    $eW = 1.0 / (1.0 + pow(10.0, ($rB - $rW) / 400.0));
                    $eB = 1.0 - $eW;

                    $wr->rating = (int) round($rW + $kW * ($scoreW - $eW));
                    $br->rating = (int) round($rB + $kB * ($scoreB - $eB));
                    $wr->games = $gW + 1;
                    $br->games = $gB + 1;
                    $wr->save();
                    $br->save();

                    GameAnalysis::firstOrCreate(['game_id' => $g->id], ['status' => 'queued']);

                    Log::info('bot_move.checkmate', [
                        'game_id' => $g->id,
                        'result' => $result,
                    ]);

                    return;
                }

                if (
                    (method_exists($boardAfter, 'isStalemate') && $boardAfter->isStalemate()) ||
                    (method_exists($boardAfter, 'isFivefoldRepetition') && $boardAfter->isFivefoldRepetition())
                ) {
                    $g->status = 'finished';
                    $g->result = '1/2-1/2';
                    $g->reason = 'draw';
                    $g->save();

                    $tc = TimeControl::find($g->time_control_id);
                    $wr = PlayerRating::firstOrCreate(['user_id' => $g->white_id, 'time_class' => $tc->time_class]);
                    $br = PlayerRating::firstOrCreate(['user_id' => $g->black_id, 'time_class' => $tc->time_class]);

                    $rW = (int) $wr->rating;
                    $rB = (int) $br->rating;
                    $gW = (int) $wr->games;
                    $gB = (int) $br->games;

                    $scoreW = 0.5;
                    $scoreB = 0.5;

                    $kW = $gW < 30 ? 40 : 20;
                    $kB = $gB < 30 ? 40 : 20;

                    $eW = 1.0 / (1.0 + pow(10.0, ($rB - $rW) / 400.0));
                    $eB = 1.0 - $eW;

                    $wr->rating = (int) round($rW + $kW * ($scoreW - $eW));
                    $br->rating = (int) round($rB + $kB * ($scoreB - $eB));
                    $wr->games = $gW + 1;
                    $br->games = $gB + 1;
                    $wr->save();
                    $br->save();

                    GameAnalysis::firstOrCreate(['game_id' => $g->id], ['status' => 'queued']);

                    Log::info('bot_move.draw', [
                        'game_id' => $g->id,
                        'reason' => 'draw',
                    ]);

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('bot_move.terminal_check_failed', [
                    'game_id' => $g->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
