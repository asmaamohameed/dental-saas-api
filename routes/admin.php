<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['api'])->group(function () {
    // Admin routes will go here
    Route::get('/ping', function () {
        return response()->json(['message' => 'Admin API works!']);
    });
});
