<?php

declare(strict_types=1);

namespace routes;

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FormController;

Route::post('/form-endpoint', [FormController::class, 'handle']);
