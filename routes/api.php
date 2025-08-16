<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ModeController;
use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Game modes
    Route::get('/modes', [ModeController::class, 'index']);
    
    // Queue management
    Route::post('/queue/join', [QueueController::class, 'join']);
    Route::post('/queue/leave', [QueueController::class, 'leave']);
    
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
    Route::post('/games/{id}/draw/offer', [GameController::class, 'offerDraw']);
    Route::post('/games/{id}/draw/accept', [GameController::class, 'acceptDraw']);
    
    // Leaderboard
    Route::get('/leaderboard', [LeaderboardController::class, 'leaderboard']);
});
