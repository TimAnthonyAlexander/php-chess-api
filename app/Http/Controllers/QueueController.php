<?php

namespace App\Http\Controllers;

use App\Models\PlayerRating;
use App\Models\QueueEntry;
use App\Models\Game;
use App\Models\TimeControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class QueueController extends Controller
{
    public function join(Request $r)
    {
        $user = $r->user();
        $tc = TimeControl::where('slug', $r->validate(['tc' => 'required|string'])['tc'])->firstOrFail();

        // 1) If I'm already in an active game for this TC, don't enqueue again.
        if ($g = $this->findActiveGameFor($tc->id, $user->id)) {
            QueueEntry::where(['user_id' => $user->id, 'time_control_id' => $tc->id])->delete();
            return response()->json(['status' => 'matched', 'game_id' => $g->id]);
        }

        // Ensure rating exists
        $playerRating = PlayerRating::firstOrCreate(
            ['user_id' => $user->id, 'time_class' => $tc->time_class],
            ['rating' => 1500]
        );

        // 2) Only create row if missing; do NOT reset joined_at on every poll.
        $qe = QueueEntry::where(['user_id' => $user->id, 'time_control_id' => $tc->id])->first();
        if (!$qe) {
            QueueEntry::create([
                'user_id'         => $user->id,
                'time_control_id' => $tc->id,
                'snapshot_rating' => $playerRating->rating,
                'joined_at'       => now(),
            ]);
        } else {
            // keep place in line; update only snapshot_rating
            if ($qe->snapshot_rating !== $playerRating->rating) {
                $qe->snapshot_rating = $playerRating->rating;
                $qe->save();
            }
        }

        return $this->attemptMatch($tc, $user->id);
    }

    public function leave(Request $r)
    {
        $user = $r->user();
        $tc = TimeControl::where('slug', $r->validate(['tc' => 'required|string'])['tc'])->firstOrFail();
        QueueEntry::where(['user_id' => $user->id, 'time_control_id' => $tc->id])->delete();
        return response()->noContent();
    }

    private function attemptMatch(TimeControl $tc, int $userId)
    {
        return DB::transaction(function () use ($tc, $userId) {
            // If a concurrent match already created my game, short-circuit.
            if ($g = $this->findActiveGameFor($tc->id, $userId)) {
                QueueEntry::where(['user_id' => $userId, 'time_control_id' => $tc->id])->delete();
                return response()->json(['status' => 'matched', 'game_id' => $g->id]);
            }

            // Lock my queue row if it exists; it might have been deleted by the opponent's txn.
            $me = QueueEntry::where([
                    'user_id' => $userId,
                    'time_control_id' => $tc->id,
                ])
                ->lockForUpdate()
                ->first();

            // If I no longer have a row but I have a game, return matched; otherwise I'm simply not queued.
            if (!$me) {
                if ($g = $this->findActiveGameFor($tc->id, $userId)) {
                    return response()->json(['status' => 'matched', 'game_id' => $g->id]);
                }
                return response()->json(['status' => 'queued', 'widening' => ['delta' => 100]], 202);
            }

            $waitSec = max(0, now()->diffInSeconds($me->joined_at));
            $delta   = min(400, 100 + intdiv($waitSec, 15) * 50);
            $minR    = $me->snapshot_rating - $delta;
            $maxR    = $me->snapshot_rating + $delta;

            // Oldest compatible opponent; skip rows locked elsewhere (MySQL 8+)
            $oppQuery = QueueEntry::where('time_control_id', $tc->id)
                ->where('user_id', '!=', $userId)
                ->whereBetween('snapshot_rating', [$minR, $maxR])
                ->orderBy('joined_at');

            $opp = $oppQuery
                ->lock(DB::raw('FOR UPDATE SKIP LOCKED'))
                ->first();

            if (!$opp) {
                // Final race repair: if a game appeared just now, treat as matched.
                if ($g = $this->findActiveGameFor($tc->id, $userId)) {
                    QueueEntry::whereKey($me->id)->delete();
                    return response()->json(['status' => 'matched', 'game_id' => $g->id]);
                }

                return response()->json(['status' => 'queued', 'widening' => ['delta' => $delta]], 202);
            }

            // Delete both rows atomically; assert exactly 2 rows gone.
            $deleted = QueueEntry::whereIn('id', [$me->id, $opp->id])->delete();
            if ($deleted !== 2) {
                throw new \RuntimeException('Queue cleanup failed; retry');
            }

            $initial    = $tc->initial_sec * 1000;
            $whiteFirst = random_int(0, 1) === 1;
            $whiteId    = $whiteFirst ? $me->user_id  : $opp->user_id;
            $blackId    = $whiteFirst ? $opp->user_id : $me->user_id;

            $game = Game::create([
                'time_control_id' => $tc->id,
                'white_id'        => $whiteId,
                'black_id'        => $blackId,
                'status'          => 'active',
                'result'          => null,
                'reason'          => null,
                'fen'             => 'startpos',
                'move_index'      => 0,
                'white_time_ms'   => $initial,
                'black_time_ms'   => $initial,
                'last_move_at'    => now(),
            ]);

            return response()->json(['status' => 'matched', 'game_id' => $game->id]);
        }, 3);
    }

    private function findActiveGameFor(int $timeControlId, int $userId): ?Game
    {
        return Game::where('status', 'active')
            ->where('time_control_id', $timeControlId)
            ->where(function ($q) use ($userId) {
                $q->where('white_id', $userId)->orWhere('black_id', $userId);
            })
            ->latest('id')
            ->first();
    }
}
