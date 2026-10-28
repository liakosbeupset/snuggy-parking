<?php

use App\Http\Controllers\CurrentHouseholdController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\HouseholdInviteController;
use App\Http\Controllers\HouseholdMemberController;
use App\Http\Controllers\InviteAcceptanceController;
use App\Http\Controllers\ParkingSpotController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::post('spots', [ParkingSpotController::class, 'store'])->name('spots.store');
    Route::get('spots/{spot}', [ParkingSpotController::class, 'show'])->name('spots.show');
    Route::patch('spots/{spot}/retrieve', [ParkingSpotController::class, 'retrieve'])->name('spots.retrieve');
    Route::get('spots/{spot}/photo', [ParkingSpotController::class, 'photo'])->name('spots.photo');

    Route::get('household', [HouseholdController::class, 'show'])->name('household.show');
    Route::put('current-household', CurrentHouseholdController::class)->name('current-household.update');

    Route::post('households/{household}/invites', [HouseholdInviteController::class, 'store'])
        ->middleware('throttle:invites')->name('households.invites.store');
    Route::delete('households/{household}/invites/{invite}', [HouseholdInviteController::class, 'destroy'])
        ->scopeBindings()->name('households.invites.destroy');
    Route::delete('households/{household}/members/{user}', [HouseholdMemberController::class, 'destroy'])
        ->name('households.members.destroy');
    Route::post('households/{household}/leave', [HouseholdMemberController::class, 'leave'])->name('households.leave');

    Route::get('invites/{token}', [InviteAcceptanceController::class, 'show'])->name('invites.show');
    Route::post('invites/{token}/accept', [InviteAcceptanceController::class, 'accept'])->name('invites.accept');
});

require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
