<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::inertia('login', 'auth/Login')->name('login');

    Route::middleware('throttle:google-oauth')->group(function () {
        Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
    });
});

Route::post('logout', [GoogleAuthController::class, 'logout'])->middleware('auth')->name('logout');
