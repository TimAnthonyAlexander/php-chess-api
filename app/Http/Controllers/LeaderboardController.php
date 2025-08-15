<?php

namespace App\Http\Controllers;

use App\Models\PlayerRating;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function leaderboard(Request $r)
    {
        $tc = $r->validate(['time_class' => 'required|string'])['time_class'];
        $limit = min(100, (int) $r->query('limit', 100));
        
        $rows = PlayerRating::where('time_class', $tc)
            ->orderByDesc('rating')
            ->limit($limit)
            ->with('user:id,name')
            ->get();
            
        return response()->json($rows);
    }
}
