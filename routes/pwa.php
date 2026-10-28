<?php

use Illuminate\Support\Facades\Route;

// Registered outside the web group (bootstrap/app.php): no session, no cookies.
Route::get('sw.js', function () {
    $path = config('pwa.sw_path');

    abort_unless(is_string($path) && is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'text/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache',
    ]);
})->name('pwa.sw');

// Static and session-free: precached by the worker as the navigation fallback.
Route::view('offline', 'offline')->name('pwa.offline');
