<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameAnalysis;
use App\Models\GameMove;
use App\Models\PlayerRating;
use App\Models\TimeControl;
use ChessLab\Engine\FEN;
use ChessLab\Engine\Move;
use ChessLab\Engine\Board;
use ChessLab\Engine\IO\UCI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GameController extends Controller
{
    public function show(int $id)
    {
        $g = Game::findOrFail($id);
        $moves = GameMove::where('game_id', $id)->orderBy('ply')->get();
        return response()->json(['game' => $g, 'moves' => $moves]);
    }

    public function sync(int $id, Request $r)
    {
        $since = (int) $r->query('since', 0); // last known ply
        $g = Game::findOrFail($id);
        $new = GameMove::where('game_id', $id)->where('ply', '>', $since)->orderBy('ply')->get();
        return response()->json([
            'status' => $g->status,
            'result' => $g->result,
            'reason' => $g->reason,
            'lock_version' => $g->lock_version,
            'white_time_ms' => $g->white_time_ms,
            'black_time_ms' => $g->black_time_ms,
            'last_move_at' => $g->last_move_at?->toISOString(),
            'moves' => $new
        ]);
    }

    public function move(int $id, Request $r)
    {
        $user = $r->user();
        $data = $r->validate([
            'uci' => 'required|string', // e2e4, e7e8q
            'lock_version' => 'required|integer'
        ]);
        return DB::transaction(function () use ($id, $user, $data) {
            $g = Game::lockForUpdate()->findOrFail($id);
            if ($g->status !== 'active') return response()->json(['error' => 'not active'], 409);
            if ((int)$data['lock_version'] !== (int)$g->lock_version) return response()->json(['error' => 'version'], 409);

            $toMoveId = ($g->move_index % 2 === 0) ? $g->white_id : $g->black_id;
            if ($user->id !== $toMoveId) return response()->json(['error' => 'not your turn'], 403);

            $now = now();
            $elapsedMs = max(0, $now->diffInMilliseconds($g->last_move_at ?? $now));
            $tc = TimeControl::findOrFail($g->time_control_id);

            if ($g->move_index % 2 === 0) {
                if ($elapsedMs >= $g->white_time_ms) return $this->timeout($g, 'black');
                $g->white_time_ms -= $elapsedMs;
            } else {
                if ($elapsedMs >= $g->black_time_ms) return $this->timeout($g, 'white');
                $g->black_time_ms -= $elapsedMs;
            }

            // Setup the board position
            $board = new Board();
            if ($g->fen !== 'startpos') {
                $board = FEN::toBoard($g->fen);
            }
            
            // Process previous moves from the database to reach current position
            $previousMoves = GameMove::where('game_id', $g->id)
                ->orderBy('ply')
                ->get();
                
            foreach ($previousMoves as $prevMove) {
                $uciMove = new Move();
                $uciMove->fromUci($prevMove->uci);
                $board->playMove($uciMove);
            }

            // Try to apply the new move
            $uci = strtolower($data['uci']);
            $from = substr($uci, 0, 2);
            $to = substr($uci, 2, 2);
            $promotion = strlen($uci) === 5 ? substr($uci, 4, 1) : null;
            
            // Create and validate the move
            $move = new Move();
            $move->fromUci($uci);
            
            if (!$board->isLegal($move)) {
                return response()->json(['error' => 'illegal'], 422);
            }
            
            // Play the move
            $board->playMove($move);
            
            // Get SAN notation
            $san = UCI::toSan($board, $move);
            $fenAfter = FEN::fromBoard($board);

            if ($g->move_index % 2 === 0) $g->white_time_ms += $tc->increment_ms;
            else $g->black_time_ms += $tc->increment_ms;

            $g->move_index += 1;
            $g->fen = $fenAfter;
            $g->last_move_at = $now;
            $g->lock_version += 1;
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
                'white_time_ms_after' => $g->white_time_ms,
                'black_time_ms_after' => $g->black_time_ms,
            ]);
            
            // Check for game-ending conditions
            if ($board->isCheckmate()) {
                return $this->finish($g, $g->move_index % 2 === 1 ? '1-0' : '0-1', 'checkmate');
            }
            
            if ($board->isStalemate() || $board->isDraw()) {
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
