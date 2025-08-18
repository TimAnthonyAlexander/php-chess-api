<?php

namespace App\Jobs;

use App\Models\QueueEntry;
use App\Models\User;
use App\Models\Game;
use App\Models\TimeControl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class CreateBotFallback implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $queueEntryId) {}

    public function handle(): void {
        $qe = QueueEntry::with('user')->find($this->queueEntryId);
        if (!$qe || $qe->matched_at) return;

        $bot = User::where('is_bot', true)->inRandomOrder()->firstOrFail();
        $tc = TimeControl::findOrFail($qe->time_control_id);

        $whiteId = random_int(0, 1) ? $qe->user_id : $bot->id;
        $blackId = ($whiteId === $qe->user_id) ? $bot->id : $qe->user_id;

        $game = Game::create([
            'white_id'        => $whiteId,
            'black_id'        => $blackId,
            'time_control_id' => $tc->id,
            'status'          => 'active',
            'fen'             => 'startpos',
            'move_index'      => 0,
            'has_bot'         => true,
            'white_time_ms'   => $tc->initial_sec * 1000,
            'black_time_ms'   => $tc->initial_sec * 1000,
        ]);

        $qe->matched_at = now();
        $qe->save();

        if ($game->white_id === $bot->id) {
            BotMakeMove::dispatch($game->id)->delay(now()->addMilliseconds(random_int(1200, 2600)));
        }
    }
}