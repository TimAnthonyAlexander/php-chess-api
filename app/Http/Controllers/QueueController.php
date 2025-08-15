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
        $rating = PlayerRating::firstOrCreate(['user_id' => $user->id, 'time_class' => $tc->time_class])->rating;
        QueueEntry::updateOrCreate(
            ['user_id' => $user->id, 'time_control_id' => $tc->id],
            ['snapshot_rating' => $rating, 'joined_at' => now()]
        );
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
            $me = QueueEntry::where(['user_id' => $userId, 'time_control_id' => $tc->id])->lockForUpdate()->firstOrFail();
            $waitSec = max(0, now()->diffInSeconds($me->joined_at));
            $delta = min(400, 100 + intdiv($waitSec, 15) * 50);
            $minR = $me->snapshot_rating - $delta;
            $maxR = $me->snapshot_rating + $delta;

            $opp = QueueEntry::where('time_control_id', $tc->id)
                ->where('user_id', '!=', $userId)
                ->whereBetween('snapshot_rating', [$minR, $maxR])
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->first();

            if (!$opp) return response()->json(['status' => 'queued', 'widening' => ['delta' => $delta]], 202);

            QueueEntry::whereKey([$me->id, $opp->id])->delete();

            $initial = $tc->initial_sec * 1000;
            $whiteFirst = random_int(0, 1) === 1;
            $whiteId = $whiteFirst ? $me->user_id : $opp->user_id;
            $blackId = $whiteFirst ? $opp->user_id : $me->user_id;

            $game = Game::create([
                'time_control_id' => $tc->id,
                'white_id' => $whiteId,
                'black_id' => $blackId,
                'status' => 'active',
                'result' => null,
                'reason' => null,
                'fen' => 'startpos',
                'move_index' => 0,
                'white_time_ms' => $initial,
                'black_time_ms' => $initial,
                'last_move_at' => now(),
            ]);

            return response()->json(['status' => 'matched', 'game_id' => $game->id]);
        }, 3);
    }
}
