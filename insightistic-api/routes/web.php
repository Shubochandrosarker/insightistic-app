<?php

use Illuminate\Support\Facades\Route;

// This is an API-only backend -- api.insightistic.com should never show the
// default Laravel welcome page. Keep the root route to a minimal health/status
// response instead.
Route::get('/', function () {
    return response()->json([
        'service' => 'insightistic-api',
        'status' => 'ok',
    ]);
});
