<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ping', function (Request $request) {
        return response()->json([
            'message' => 'Admin API works!',
            'admin' => $request->user(),
        ]);
    });
});
