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
        
        // Check for timeout if game is active
        $now = now();
        if ($g->status === 'active' && $g->last_move_at) {
            $toMoveIsWhite = ($g->fen === 'startpos')
                ? ($g->move_index % 2 === 0)
                : (explode(' ', (string) $g->fen)[1] ?? 'w') === 'w';

            $elapsed = $now->diffInMilliseconds($g->last_move_at);
            $remaining = $toMoveIsWhite ? $g->white_time_ms : $g->black_time_ms;
            
            \Log::debug('Sync time check', [
                'game_id' => $g->id,
                'move_index' => $g->move_index,
                'to_move_is_white' => $toMoveIsWhite,
                'white_time_ms' => $g->white_time_ms,
                'black_time_ms' => $g->black_time_ms,
                'elapsed_ms' => $elapsed,
                'remaining_ms' => $remaining,
                'last_move_at' => $g->last_move_at ? $g->last_move_at->toISOString() : null
            ]);

            if ($elapsed >= $remaining) {
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
            'uci' => 'required|string', // e2e4, e7e8q
            'lock_version' => 'required|integer',
        ]);

        return DB::transaction(function () use ($id, $user, $data) {
            $g = Game::lockForUpdate()->findOrFail($id);

            if ($g->status !== 'active') {
                return response()->json(['error' => 'not active'], 409);
            }
            if ((int)$data['lock_version'] !== (int)$g->lock_version) {
                return response()->json(['error' => 'version'], 409);
            }

            // Turn validation
            $toMoveId = ($g->move_index % 2 === 0) ? $g->white_id : $g->black_id;
            if ($user->id !== $toMoveId) {
                return response()->json(['error' => 'not your turn'], 403);
            }

            // CRITICAL: Fetch the CURRENT time values directly from database
            $currentTimes = DB::table('games')
                ->where('id', $g->id)
                ->select(['white_time_ms', 'black_time_ms'])
                ->first();
                
            // Use the database values, not the model values
            $whiteTimeMs = (int)$currentTimes->white_time_ms;
            $blackTimeMs = (int)$currentTimes->black_time_ms;
            
            // Time control
            $now = now();
            $elapsedMs = (int) max(0, $now->diffInMilliseconds($g->last_move_at ?? $now));
            $tc = TimeControl::findOrFail($g->time_control_id);
            
            Log::debug('RAW TIME VALUES FROM DB', [
                'game_id' => $g->id,
                'white_time_ms_from_db' => $whiteTimeMs,
                'black_time_ms_from_db' => $blackTimeMs,
                'white_time_ms_from_model' => $g->white_time_ms,
                'black_time_ms_from_model' => $g->black_time_ms,
                'elapsed_ms' => $elapsedMs
            ]);

            // Calculate new time values based on move and elapsed time
            $newWhiteTimeMs = $whiteTimeMs;
            $newBlackTimeMs = $blackTimeMs;
            
            // Detailed time calculation - White's move (even index)
            if ($g->move_index % 2 === 0) {
                Log::debug('White to move time calc', [
                    'white_time_before' => $whiteTimeMs,
                    'elapsed_ms' => $elapsedMs
                ]);
                
                if ($elapsedMs >= $whiteTimeMs) {
                    return $this->timeout($g, 'black');
                }
                
                // Subtract elapsed time for white
                $newWhiteTimeMs = (int) max(0, $whiteTimeMs - $elapsedMs);
                
                // Add increment for white
                $newWhiteTimeMs += (int)$tc->increment_ms;
                
                Log::debug('White time after calculation', [
                    'new_white_time_ms' => $newWhiteTimeMs
                ]);
            } 
            // Black's move (odd index)
            else {
                Log::debug('Black to move time calc', [
                    'black_time_before' => $blackTimeMs,
                    'elapsed_ms' => $elapsedMs
                ]);
                
                if ($elapsedMs >= $blackTimeMs) {
                    return $this->timeout($g, 'white');
                }
                
                // Subtract elapsed time for black
                $newBlackTimeMs = (int) max(0, $blackTimeMs - $elapsedMs);
                
                // Add increment for black
                $newBlackTimeMs += (int)$tc->increment_ms;
                
                Log::debug('Black time after calculation', [
                    'new_black_time_ms' => $newBlackTimeMs
                ]);
            }
            
            // Debug logging - after time update
            \Log::debug('Time after update', [
                'white_time_ms' => $g->white_time_ms,
                'black_time_ms' => $g->black_time_ms
            ]);

            // Build board from current position
            if ($g->fen === 'startpos') {
                $board = new Board();
            } else {
                $board = FenToBoardFactory::create($g->fen);
            }

            // Parse incoming UCI
            $uci = strtolower($data['uci']);      // e.g. e2e4, e7e8q
            $from = substr($uci, 0, 2);
            $to = substr($uci, 2, 2);
            $promotion = strlen($uci) === 5 ? substr($uci, 4, 1) : null;

            // Apply move in LAN (UCI) for the side to move
            $color = ($g->move_index % 2 === 0) ? 'w' : 'b';

            try {
                // Throws on illegal move; if it doesn’t, returns void.
                $board->playLan($color, $uci);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'illegal'], 422);
            }

            // Last move info from history (includes SAN)
            $last = end($board->history) ?: null;
            $san = is_array($last) && isset($last['pgn']) ? $last['pgn'] : null;

            $fenAfter = $board->toFen();

            // NOTE: Increment is now applied directly during the time calculation above
            Log::debug('FINAL TIME VALUES TO SAVE', [
                'new_white_time_ms' => $newWhiteTimeMs,
                'new_black_time_ms' => $newBlackTimeMs,
            ]);

            // Persist game state
            $g->move_index += 1;
            $g->fen = $fenAfter;
            $g->last_move_at = $now;
            $g->lock_version += 1;
            
            // COMPLETELY SEPARATE TRANSACTION for updating time values
            $timeUpdateResult = DB::transaction(function() use ($g, $newWhiteTimeMs, $newBlackTimeMs, $fenAfter, $now) {
                // Super direct raw query to update time values
                $updated = DB::update(
                    'UPDATE games SET white_time_ms = ?, black_time_ms = ?, move_index = ?, fen = ?, last_move_at = ?, lock_version = ? WHERE id = ?', 
                    [$newWhiteTimeMs, $newBlackTimeMs, $g->move_index + 1, $fenAfter, $now, $g->lock_version + 1, $g->id]
                );
                
                // Check if update worked
                if ($updated !== 1) {
                    Log::error('Failed to update game times', [
                        'game_id' => $g->id,
                        'rows_affected' => $updated
                    ]);
                    return false;
                }
                
                // Verify the actual values in the database after update
                $verifyGame = DB::table('games')->where('id', $g->id)->first();
                Log::debug('VERIFICATION after direct DB update', [
                    'white_time_ms_saved' => $verifyGame->white_time_ms,
                    'black_time_ms_saved' => $verifyGame->black_time_ms,
                    'expected_white_time_ms' => $newWhiteTimeMs,
                    'expected_black_time_ms' => $newBlackTimeMs,
                    'success' => (
                        $verifyGame->white_time_ms == $newWhiteTimeMs && 
                        $verifyGame->black_time_ms == $newBlackTimeMs
                    )
                ]);
                
                return $verifyGame;
            }, 5);
            
            if ($timeUpdateResult === false) {
                return response()->json(['error' => 'Failed to update game times'], 500);
            }
            
            // Update local variables for subsequent code
            $g->move_index += 1;
            $g->fen = $fenAfter;
            $g->last_move_at = $now;
            $g->lock_version += 1;
            $g->white_time_ms = $newWhiteTimeMs;
            $g->black_time_ms = $newBlackTimeMs;

            // SEPARATE TRANSACTION for game move insertion
            $moveInsertResult = DB::transaction(function() use ($g, $user, $uci, $san, $from, $to, $promotion, $fenAfter, $now, $newWhiteTimeMs, $newBlackTimeMs) {
                // Raw SQL insert for the game move
                $result = DB::insert(
                    'INSERT INTO game_moves (game_id, ply, by_user_id, uci, san, from_sq, to_sq, promotion, fen_after, white_time_ms_after, black_time_ms_after, moved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$g->id, $g->move_index, $user->id, $uci, $san, $from, $to, $promotion, $fenAfter, $newWhiteTimeMs, $newBlackTimeMs, $now]
                );
                
                if (!$result) {
                    Log::error('Failed to insert game move', [
                        'game_id' => $g->id,
                        'ply' => $g->move_index
                    ]);
                    return false;
                }
                
                // Verify the move was inserted with correct time values
                $verifyMove = DB::table('game_moves')
                    ->where('game_id', $g->id)
                    ->where('ply', $g->move_index)
                    ->first();
                
                Log::debug('VERIFICATION after game move insert', [
                    'white_time_ms_after_saved' => $verifyMove->white_time_ms_after,
                    'black_time_ms_after_saved' => $verifyMove->black_time_ms_after,
                    'expected_white_time_ms' => $newWhiteTimeMs,
                    'expected_black_time_ms' => $newBlackTimeMs,
                    'success' => (
                        $verifyMove->white_time_ms_after == $newWhiteTimeMs && 
                        $verifyMove->black_time_ms_after == $newBlackTimeMs
                    )
                ]);
                
                return $verifyMove;
            }, 5);
            
            if ($moveInsertResult === false) {
                return response()->json(['error' => 'Failed to insert game move'], 500);
            }

            // Game end checks
            if (method_exists($board, 'isMate') && $board->isMate()) {
                // After a successful move, odd ply => White just moved.
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
