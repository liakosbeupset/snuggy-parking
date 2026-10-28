<?php

namespace App\Http\Controllers;

use App\Models\ParkingSpot;
use App\Support\SpotPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The current household's active spot and recent history. currentHousehold() is
     * membership-checked, so there is nothing else to authorize (I3).
     */
    public function __invoke(Request $request): Response
    {
        $household = $request->user()->currentHousehold();

        $activeSpot = $household->activeSpot()->with('user:id,name')->first();

        return Inertia::render('Home', [
            'household' => $household->only(['id', 'name']),
            'activeSpot' => $activeSpot ? SpotPresenter::from($activeSpot, $household) : null,
            'recentSpots' => $household->spots()->with('user:id,name')->latest('parked_at')->limit(10)->get()
                ->map(fn (ParkingSpot $spot) => SpotPresenter::from($spot, $household))
                ->values()->all(),
        ]);
    }
}
