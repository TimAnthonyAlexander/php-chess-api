<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FormController extends Controller
{
    public function handle(Request $request)
    {
        return response()->json([
            'received_field1' => $request->input('field1'),
            'received_field2' => $request->input('field2'),
        ]);
    }
}
