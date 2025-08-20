<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\StockfishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class StockfishController extends Controller
{
    public function bestMove(int $id, Request $request, StockfishService $stockfish)
    {
        $user = $request->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $game = Game::findOrFail($id);
        if ($game->status !== 'active') {
            return response()->json(['error' => 'game not active'], 409);
        }

        $fen = $game->fen;
        $fenOrStart = ($fen === 'startpos' || $fen === null || $fen === '') ? 'startpos' : $fen;

        // Use current clocks; think up to 5s with full strength
        $wtime = (int) $game->white_time_ms;
        $btime = (int) $game->black_time_ms;

        // Use a generous hard cap of 5000ms, and a high elo target to ensure full strength
        $hardCapMs = 5000;
        $eloTarget = 3200;

        $move = $stockfish->bestMoveFromClock(
            $fenOrStart,
            $wtime,
            $btime,
            0,
            0,
            $eloTarget,
            $hardCapMs
        );

        if (!$move) {
            Log::warning('Stockfish returned no move', ['game_id' => $game->id]);
            return response()->json(['error' => 'no move'], 422);
        }

        return response()->json(['bestmove' => $move]);
    }
}


