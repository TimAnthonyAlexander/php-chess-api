<?php

namespace App\Http\Controllers;

use App\Models\TimeControl;
use Illuminate\Http\Request;

class ModeController extends Controller
{
    public function index()
    {
        $timeControls = TimeControl::all();
        return response()->json($timeControls);
    }
}
