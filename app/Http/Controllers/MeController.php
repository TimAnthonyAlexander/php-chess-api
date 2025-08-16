<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\PlayerRating;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function currentUser(Request $request)
    {
        $user = $request->user();
        return response()->json(['user' => $user]);
    }
    public function activeGame(Request $request)
    {
        $user = $request->user();
        
        $activeGame = Game::where(function ($query) use ($user) {
                $query->where('white_id', $user->id)
                      ->orWhere('black_id', $user->id);
            })
            ->where('status', 'active')
            ->first();
            
        if ($activeGame) {
            return response()->json(['game_id' => $activeGame->id]);
        }
        
        return response()->json(['game_id' => null]);
    }
    
    public function recentGames(Request $request)
    {
        $user = $request->user();
        $timeClass = $request->query('time_class');
        
        $query = Game::where(function ($query) use ($user) {
                $query->where('white_id', $user->id)
                      ->orWhere('black_id', $user->id);
            })
            ->where('status', 'finished')
            ->orderBy('updated_at', 'desc')
            ->with(['timeControl', 'white', 'black']);
            
        if ($timeClass) {
            $query->whereHas('timeControl', function ($q) use ($timeClass) {
                $q->where('time_class', $timeClass);
            });
        }
        
        $games = $query->take(10)->get();
        
        return response()->json($games);
    }
    
    public function ratings(Request $request)
    {
        $user = $request->user();
        $ratings = PlayerRating::where('user_id', $user->id)->get();
        
        return response()->json($ratings);
    }
}
