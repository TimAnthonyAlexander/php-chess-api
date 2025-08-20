<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ModeController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\StockfishController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Game modes
    Route::get('/modes', [ModeController::class, 'index']);
    
    // Queue management
    Route::post('/queue/join', [QueueController::class, 'join']);
    Route::post('/queue/leave', [QueueController::class, 'leave']);
    Route::post('/queue/matched-human', [QueueController::class, 'matchedHuman']);
    
    // User specific
    Route::get('/me', [MeController::class, 'currentUser']);
    Route::get('/me/active-game', [MeController::class, 'activeGame']);
    Route::get('/me/recent-games', [MeController::class, 'recentGames']);
    Route::get('/me/ratings', [MeController::class, 'ratings']);
    
    // Game access
    Route::get('/games/{id}', [GameController::class, 'show']);
    Route::get('/games/{id}/sync', [GameController::class, 'sync']);
    Route::post('/games/{id}/move', [GameController::class, 'move']);
    Route::post('/games/{id}/resign', [GameController::class, 'resign']);
    // Support both legacy and new draw endpoints
    Route::post('/games/{id}/draw', [GameController::class, 'offerDraw']);
    Route::post('/games/{id}/acceptDraw', [GameController::class, 'acceptDraw']);
    Route::post('/games/{id}/draw/offer', [GameController::class, 'offerDraw']);
    Route::post('/games/{id}/draw/accept', [GameController::class, 'acceptDraw']);
    
    // Stockfish best move (admin only)
    Route::get('/games/{id}/best-move', [StockfishController::class, 'bestMove']);
    
    // Debug endpoint - for debugging time issues
    Route::get('/games/debug/time', [GameController::class, 'debugTime']);
    
    // Leaderboard
    Route::get('/leaderboard', [LeaderboardController::class, 'leaderboard']);
});
