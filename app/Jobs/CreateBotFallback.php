<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\QueueEntry;
use App\Models\TimeControl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateBotFallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $queueEntryId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $qe = QueueEntry::lockForUpdate()->find($this->queueEntryId);
            Log::info('bot_fallback.processing', [
                'queue_entry_id' => $this->queueEntryId,
                'user_id'        => $qe?->user_id,
                'time_control'   => $qe?->time_control_id,
            ]);
            if (!$qe) return;

            Log::info('bot_fallback.queue_entry', [
                'id'             => $qe->id,
                'user_id'        => $qe->user_id,
                'time_control'   => $qe->time_control_id,
                'snapshot_rating' => $qe->snapshot_rating,
            ]);
            if ($qe->matched_at) return;

            // If a game already exists for this user+TC, stop.
            $existing = Game::where('status', 'active')
                ->where('time_control_id', $qe->time_control_id)
                ->where(function ($q) use ($qe) {
                    $q->where('white_id', $qe->user_id)->orWhere('black_id', $qe->user_id);
                })
                ->exists();
            if ($existing) {
                QueueEntry::whereKey($qe->id)->delete();
                Log::info('bot_fallback.existing_game', [
                    'user_id' => $qe->user_id,
                    'time_control' => $qe->time_control_id,
                ]);
                return;
            }

            $tc = TimeControl::findOrFail($qe->time_control_id);

            // Closest-rating bot in SAME time_class
            $bot = DB::table('users')
                ->join('player_ratings', 'player_ratings.user_id', '=', 'users.id')
                ->where('users.is_bot', 1)
                ->where('player_ratings.time_class', $tc->time_class)
                ->select('users.id as user_id', 'player_ratings.rating')
                ->orderByRaw('ABS(CAST(player_ratings.rating AS SIGNED) - CAST(? AS SIGNED))', [$qe->snapshot_rating])
                ->first();

            Log::info('bot_fallback.attempt', [
                'user_id'       => $qe->user_id,
                'time_control'  => $tc->time_class,
                'snapshot_rating' => $qe->snapshot_rating,
            ]);

            if (!$bot) {
                return;
            }

            $initial = $tc->initial_sec * 1000;
            $humanAsWhite = random_int(0, 1) === 1;

            $whiteId = $humanAsWhite ? $qe->user_id : $bot->user_id;
            $blackId = $humanAsWhite ? $bot->user_id : $qe->user_id;

            $game = Game::create([
                'time_control_id' => $tc->id,
                'white_id'        => $whiteId,
                'black_id'        => $blackId,
                'status'          => 'active',
                'fen'             => 'startpos',
                'move_index'      => 0,
                'has_bot'         => 1,
                'white_time_ms'   => $initial,
                'black_time_ms'   => $initial,
            ]);

            QueueEntry::whereKey($qe->id)->delete();

            Log::info('bot_fallback.created', [
                'game_id'       => $game->id,
                'user_id'       => $qe->user_id,
                'user_snapshot' => $qe->snapshot_rating,
                'bot_user_id'   => $bot->user_id,
                'bot_rating'    => $bot->rating,
                'time_class'    => $tc->time_class,
            ]);

            // If bot is white, schedule its first move
            if ($game->white_id === $bot->user_id) {
                BotMakeMove::dispatch($game->id)->delay(now()->addMilliseconds(random_int(1200, 2600)));
            }
        });
    }
}
