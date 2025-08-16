<?php

namespace App\Http\Controllers;

use Chess\Variant\Classical\Board;
use Chess\Variant\Classical\FenToBoardFactory;
use App\Models\Game;
use App\Models\GameAnalysis;
use App\Models\GameMove;
use App\Models\PlayerRating;
use App\Models\TimeControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GameController extends Controller
{
    // Debug endpoint to diagnose time issue
    public function debugTime(Request $r)
    {
        $gameId = $r->input('game_id', 0);
        
        // 1. Get the raw database state directly
        $rawGame = DB::table('games')->where('id', $gameId)->first();
        $lastMoves = DB::table('game_moves')
            ->where('game_id', $gameId)
            ->orderBy('ply', 'desc')
            ->limit(3)
            ->get();
            
        // 2. Attempt a direct DB update with explicit time values
        $currentWhiteTime = $rawGame->white_time_ms;
        $currentBlackTime = $rawGame->black_time_ms;
        
        // Test update - set white time to exactly 6000000 ms (100 minutes)
        $updated = DB::table('games')
            ->where('id', $gameId)
            ->update([
                'white_time_ms' => 6000000
            ]);
            
        // 3. Verify the update succeeded by reading back directly
        $afterUpdate = DB::table('games')->where('id', $gameId)->first();
        
        // 4. Try to find any places in code where time might be reset
        // Check if the TimeControl record is somehow affecting this
        $tc = DB::table('time_controls')->where('id', $rawGame->time_control_id)->first();
        
        return response()->json([
            'raw_game' => $rawGame,
            'last_moves' => $lastMoves,
            'update_success' => $updated === 1,
            'after_update' => $afterUpdate,
            'time_control' => $tc,
            'diagnosis' => [
                'current_white_time' => $currentWhiteTime,
                'current_black_time' => $currentBlackTime,
                'updated_white_time' => $afterUpdate->white_time_ms,
                'time_ms_from_initial_sec' => ($tc->initial_sec * 1000)
            ]
        ]);
    }

    public function show(int $id)
    {
        $g = Game::with(['white', 'black', 'timeControl'])->findOrFail($id);
        $moves = GameMove::where('game_id', $id)->orderBy('ply')->get();

        [$toMove, $toMoveUserId] = $this->computeToMove($g);

        return response()->json([
            'game' => $g,
            'moves' => $moves,
            'to_move' => $toMove,               // 'white' | 'black' | null
            'to_move_user_id' => $toMoveUserId, // int | null
            'server_now' => now()->toISOString(),
        ]);
    }

    private function computeToMove(Game $g): array
    {
        $activeColor = null;

        if ($g->status === 'active') {
            if ($g->fen === 'startpos') {
                $activeColor = 'w';
            } else {
                $parts = explode(' ', (string) $g->fen); // "<pieces> <activeColor> ..."
                $c = $parts[1] ?? null;
                if ($c === 'w' || $c === 'b') {
                    $activeColor = $c;
                } else {
                    $activeColor = ($g->move_index % 2 === 0) ? 'w' : 'b';
                }
            }
        }

        $toMove = $activeColor ? ($activeColor === 'w' ? 'white' : 'black') : null;
        $toMoveUserId = $activeColor
            ? ($activeColor === 'w' ? (int) $g->white_id : (int) $g->black_id)
            : null;

        return [$toMove, $toMoveUserId];
    }

    public function sync(int $id, Request $r)
    {
        $since = (int) $r->query('since', 0);
        $g = Game::with(['white', 'black', 'timeControl'])->findOrFail($id);
        
        // Check for timeout if game is active and has had at least one move
        // For new games, last_move_at is null until the first move is made
        if ($g->status === 'active' && $g->last_move_at && $g->move_index > 0) {
            // Determine who moves based on fen string or move_index
            // For FEN format: The 2nd component after splitting by spaces indicates the active color
            // - 'w' means white to move, 'b' means black to move
            $toMoveIsWhite = false;
            if ($g->fen === 'startpos') {
                $toMoveIsWhite = ($g->move_index % 2 === 0);
            } else {
                $fenParts = explode(' ', (string) $g->fen);
                $activeColor = $fenParts[1] ?? 'w';
                $toMoveIsWhite = ($activeColor === 'w');
            }
            
            // SIMPLIFIED: Use native MySQL TIMESTAMPDIFF directly without timezone conversions
            // This avoids timezone conversion issues entirely
            $result = DB::select('
                SELECT 
                    TIMESTAMPDIFF(MICROSECOND, last_move_at, NOW(6)) as usec_diff,
                    last_move_at as db_last_move,
                    NOW(6) as db_now
                FROM games WHERE id = ?
            ', [$g->id])[0];
            
            $usec = max(0, (int)$result->usec_diff); // Never allow negative values
            
            // Calculate elapsed milliseconds
            $elapsedMs = (int) floor($usec / 1000);
            
            // Prevent timeout in the first couple of moves if the time difference is too large
            // This handles timezone/clock issues during game start
            if ($g->move_index <= 2 && $elapsedMs > 10000) { // More than 10 seconds on first moves is suspicious
                Log::warning('Suspicious large time difference in early move - capping at 1000ms', [
                    'game_id' => $g->id,
                    'move_index' => $g->move_index,
                    'original_elapsed_ms' => $elapsedMs,
                    'capped_to' => 1000
                ]);
                $elapsedMs = 1000; // Cap at 1 second for early moves
            }
            
            // Add enhanced debug logging
            Log::info('Time calculation debug', [
                'game_id' => $g->id,
                'move_index' => $g->move_index,
                'db_last_move' => $result->db_last_move,
                'db_now' => $result->db_now,
                'usec_diff' => $usec,
                'elapsed_ms' => $elapsedMs,
                'to_move_is_white' => $toMoveIsWhite,
                'remaining_ms' => $toMoveIsWhite ? $g->white_time_ms : $g->black_time_ms
            ]);
            
            $remaining = $toMoveIsWhite ? $g->white_time_ms : $g->black_time_ms;
            
            Log::debug('Sync time check', [
                'game_id' => $g->id,
                'move_index' => $g->move_index,
                'to_move_is_white' => $toMoveIsWhite,
                'white_time_ms' => $g->white_time_ms,
                'black_time_ms' => $g->black_time_ms,
                'elapsed_ms' => $elapsedMs,
                'remaining_ms' => $remaining,
                'last_move_at' => $g->last_move_at ? $g->last_move_at->toISOString() : null
            ]);

            if ($elapsedMs >= $remaining) {
                $winner = $toMoveIsWhite ? 'black' : 'white';
                return $this->timeout($g, $winner);
            }
        }
        
        $new = GameMove::where('game_id', $id)->where('ply', '>', $since)->orderBy('ply')->get();

        [$toMove, $toMoveUserId] = $this->computeToMove($g);

        return response()->json([
            'status' => $g->status,
            'result' => $g->result,
            'reason' => $g->reason,
            'lock_version' => $g->lock_version,
            'white_time_ms' => $g->white_time_ms,
            'black_time_ms' => $g->black_time_ms,
            'last_move_at' => $g->last_move_at?->toISOString(),
            'white' => $g->white,
            'black' => $g->black,
            'timeControl' => $g->timeControl,
            'moves' => $new,
            'since' => $since,
            'to_move' => $toMove,                // 'white' | 'black' | null
            'to_move_user_id' => $toMoveUserId,  // int | null
            'server_now' => now()->toISOString(),
        ]);
    }

    public function move(int $id, Request $r)
    {
        $user = $r->user();
        $data = $r->validate([
            'uci' => 'required|string',
            'lock_version' => 'required|integer',
        ]);

        return DB::transaction(function () use ($id, $user, $data) {
            $g = Game::lockForUpdate()->findOrFail($id);

            if ($g->status !== 'active') return response()->json(['error' => 'not active'], 409);
            if ((int)$data['lock_version'] !== (int)$g->lock_version) return response()->json(['error' => 'version'], 409);

            [$toMove, $toMoveUserId] = $this->computeToMove($g);
            if (!$toMoveUserId || (int)$user->id !== (int)$toMoveUserId) return response()->json(['error' => 'not your turn'], 403);

            $tc = TimeControl::findOrFail($g->time_control_id);

            // SIMPLIFIED: Use native MySQL TIMESTAMPDIFF directly without timezone conversions
            $usec = 0; // Default for first move
            $elapsedMs = 0;
            
            // Only calculate elapsed time if this is not the first move
            if ($g->last_move_at) {
                // Calculate in database using consistent time reference
                $result = DB::select('
                    SELECT 
                        TIMESTAMPDIFF(MICROSECOND, last_move_at, NOW(6)) as usec_diff,
                        last_move_at as db_last_move,
                        NOW(6) as db_now
                    FROM games WHERE id = ?
                ', [$g->id])[0];
                
                $usec = max(0, (int)$result->usec_diff); // Never allow negative values
                $elapsedMs = (int) floor($usec / 1000);
                
                // Prevent timeout in the first couple of moves if the time difference is too large
                if ($g->move_index <= 2 && $elapsedMs > 10000) { // More than 10 seconds on first moves is suspicious
                    Log::warning('Suspicious large time difference in early move - capping at 1000ms', [
                        'game_id' => $g->id,
                        'move_index' => $g->move_index,
                        'original_elapsed_ms' => $elapsedMs,
                        'capped_to' => 1000,
                        'db_last_move' => $result->db_last_move,
                        'db_now' => $result->db_now
                    ]);
                    $elapsedMs = 1000; // Cap at 1 second for early moves
                }
                
                // Add enhanced debug logging
                Log::info('Time calculation debug (move)', [
                    'game_id' => $g->id,
                    'move_index' => $g->move_index,
                    'db_last_move' => $result->db_last_move ?? 'null',
                    'db_now' => $result->db_now ?? now()->toDateTimeString(),
                    'usec_diff' => $usec,
                    'elapsed_ms' => $elapsedMs,
                    'toMove' => $toMove
                ]);
            } else {
                // First move in the game
                Log::info('First move in game', ['game_id' => $g->id]);
            }

            $whiteMs = (int)$g->white_time_ms;
            $blackMs = (int)$g->black_time_ms;

            if ($toMove === 'white') {
                if ($elapsedMs >= $whiteMs) return $this->timeout($g, 'black');
                $whiteMs = $whiteMs - $elapsedMs + (int)$tc->increment_ms;
            } else {
                if ($elapsedMs >= $blackMs) return $this->timeout($g, 'white');
                $blackMs = $blackMs - $elapsedMs + (int)$tc->increment_ms;
            }

            $board = $g->fen === 'startpos' ? new Board() : FenToBoardFactory::create($g->fen);

            $uci = strtolower($data['uci']);
            $from = substr($uci, 0, 2);
            $to = substr($uci, 2, 2);
            $promotion = strlen($uci) === 5 ? substr($uci, 4, 1) : null;
            $color = $toMove === 'white' ? 'w' : 'b';

            try {
                $board->playLan($color, $uci);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'illegal'], 422);
            }

            $last = end($board->history) ?: null;
            $san = is_array($last) && isset($last['pgn']) ? $last['pgn'] : null;
            $fenAfter = $board->toFen();

            // Freeze new base times at the moment of this move
            $g->white_time_ms = $whiteMs;
            $g->black_time_ms = $blackMs;
            $g->move_index = $g->move_index + 1;
            $g->fen = $fenAfter;
            $g->last_move_at = now(); // server time mark for the opponent's countdown window
            $g->lock_version = $g->lock_version + 1;
            $g->save();

            GameMove::create([
                'game_id' => $g->id,
                'ply' => $g->move_index,
                'by_user_id' => $user->id,
                'uci' => $uci,
                'san' => $san,
                'from_sq' => $from,
                'to_sq' => $to,
                'promotion' => $promotion,
                'fen_after' => $fenAfter,
                'white_time_ms_after' => $whiteMs,
                'black_time_ms_after' => $blackMs,
            ]);

            if (method_exists($board, 'isMate') && $board->isMate()) {
                $result = ($g->move_index % 2 === 1) ? '1-0' : '0-1';
                return $this->finish($g, $result, 'checkmate');
            }

            if (
                (method_exists($board, 'isStalemate') && $board->isStalemate()) ||
                (method_exists($board, 'isFivefoldRepetition') && $board->isFivefoldRepetition())
            ) {
                return $this->finish($g, '1/2-1/2', 'draw');
            }

            return response()->json(['ok' => true, 'lock_version' => $g->lock_version]);
        }, 3);
    }

    public function resign(int $id, Request $r)
    {
        $user = $r->user();
        return DB::transaction(function () use ($id, $user) {
            $g = Game::lockForUpdate()->findOrFail($id);
            if ($g->status !== 'active') {
                return response()->json(['error' => 'game not active'], 409);
            }

            $result = $user->id === $g->white_id ? '0-1' : '1-0';
            return $this->finish($g, $result, 'resign');
        });
    }

    public function offerDraw(int $id, Request $r)
    {
        $user = $r->user();
        $g = Game::findOrFail($id);

        if ($g->status !== 'active') {
            return response()->json(['error' => 'game not active'], 409);
        }

        if ($user->id !== $g->white_id && $user->id !== $g->black_id) {
            return response()->json(['error' => 'not your game'], 403);
        }

        // In a real app, you'd store the draw offer in a separate table
        // and check for it in the acceptDraw method

        return response()->json(['status' => 'draw offered']);
    }

    public function acceptDraw(int $id, Request $r)
    {
        $user = $r->user();
        return DB::transaction(function () use ($id, $user) {
            $g = Game::lockForUpdate()->findOrFail($id);
            if ($g->status !== 'active') {
                return response()->json(['error' => 'game not active'], 409);
            }

            if ($user->id !== $g->white_id && $user->id !== $g->black_id) {
                return response()->json(['error' => 'not your game'], 403);
            }

            // In a real app, you'd check if there's an actual draw offer from the opponent
            // For now, we'll just accept any draw acceptance request

            return $this->finish($g, '1/2-1/2', 'draw');
        });
    }

    private function timeout(Game $g, string $winnerColor)
    {
        $g->status = 'finished';
        $g->result = $winnerColor === 'white' ? '1-0' : '0-1';
        $g->reason = 'timeout';
        $g->save();
        $this->applyElo($g);
        return response()->json(['finished' => true, 'result' => $g->result, 'reason' => 'timeout'], 200);
    }

    private function finish(Game $g, string $result, string $reason)
    {
        $g->status = 'finished';
        $g->result = $result;
        $g->reason = $reason;
        $g->save();
        $this->applyElo($g);
        GameAnalysis::firstOrCreate(['game_id' => $g->id], ['status' => 'queued']);
        return response()->json(['finished' => true, 'result' => $result, 'reason' => $reason], 200);
    }

    private function applyElo(Game $g)
    {
        $tc = TimeControl::find($g->time_control_id);
        $wr = PlayerRating::firstOrCreate(['user_id' => $g->white_id, 'time_class' => $tc->time_class]);
        $br = PlayerRating::firstOrCreate(['user_id' => $g->black_id, 'time_class' => $tc->time_class]);
        $scoreW = $g->result === '1-0' ? 1.0 : ($g->result === '1/2-1/2' ? 0.5 : 0.0);
        $scoreB = 1.0 - $scoreW;
        [$wr->rating, $wr->games] = [$this->elo($wr->rating, $br->rating, $scoreW, $wr->games), $wr->games + 1];
        [$br->rating, $br->games] = [$this->elo($br->rating, $wr->rating, $scoreB, $br->games), $br->games + 1];
        $wr->save();
        $br->save();
    }

    private function elo(int $ra, int $rb, float $sa, int $gamesA): int
    {
        $k = $gamesA < 30 ? 40 : 20;
        $ea = 1.0 / (1.0 + pow(10.0, ($rb - $ra) / 400.0));
        return (int) round($ra + $k * ($sa - $ea));
    }
}