<?php

declare(strict_types=1);

namespace routes;

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FormController;

Route::post('/form-endpoint', [FormController::class, 'handle']);
Route::post('/register', [AuthController::class, 'register']);
