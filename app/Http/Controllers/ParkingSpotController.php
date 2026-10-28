<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreParkingSpotRequest;
use App\Models\Household;
use App\Models\ParkingSpot;
use App\Support\SpotPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ParkingSpotController extends Controller
{
    /**
     * Park in the submitted household, never the current-household pointer (KD10). The photo is
     * stored first; the household lock then serializes parallel parks and a member removal,
     * and parkIn is re-checked under it as the only gate (FM1, FM18). Any failure after the
     * photo is stored deletes it (FM2).
     */
    public function store(StoreParkingSpotRequest $request): RedirectResponse
    {
        $user = $request->user();
        $householdId = $request->integer('household_id');

        $path = null;

        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $path = $photo->storeAs("spots/{$householdId}", Str::uuid().'.'.$photo->extension(), 'local');

            throw_if($path === false, new RuntimeException('Photo could not be stored.'));
        }

        try {
            $spot = DB::transaction(function () use ($request, $user, $householdId, $path): ParkingSpot {
                $household = Household::whereKey($householdId)->lockForUpdate()->first();

                // The class name routes a null household (unknown id) to the policy, which denies it.
                Gate::forUser($user)->authorize('parkIn', [Household::class, $household]);

                /** @var Household $household */
                $household->spots()->active()->update(['retrieved_at' => now()]);

                return $household->spots()->create([
                    'lat' => $request->input('lat'),
                    'lng' => $request->input('lng'),
                    'accuracy' => $request->input('accuracy'),
                    'note' => $request->input('note'),
                    'photo_path' => $path,
                    'parked_at' => now(),
                    'user_id' => $user->id,
                ]);
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return to_route('spots.show', $spot);
    }

    public function show(ParkingSpot $spot): Response
    {
        $this->authorize('view', $spot);

        $spot->load(['household:id,name', 'user:id,name']);

        return Inertia::render('Spots/Show', [
            'spot' => SpotPresenter::from($spot, $spot->household),
            'household' => $spot->household->only(['id', 'name']),
        ]);
    }

    /**
     * "Got the car". A conditional query-builder update: atomic, idempotent, and the only
     * way to write retrieved_at, which is not fillable.
     */
    public function retrieve(ParkingSpot $spot): RedirectResponse
    {
        $this->authorize('retrieve', $spot);

        ParkingSpot::whereKey($spot->id)->whereNull('retrieved_at')->update(['retrieved_at' => now()]);

        return to_route('home');
    }

    /**
     * Stream the photo from the private disk (I6). no-store keeps it out of the HTTP cache.
     */
    public function photo(ParkingSpot $spot): StreamedResponse
    {
        $this->authorize('view', $spot);

        abort_if($spot->photo_path === null || ! Storage::disk('local')->exists($spot->photo_path), 404);

        return Storage::disk('local')->response($spot->photo_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
