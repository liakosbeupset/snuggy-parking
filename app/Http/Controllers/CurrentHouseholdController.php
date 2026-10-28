<?php

namespace App\Http\Controllers;

use App\Models\Household;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CurrentHouseholdController extends Controller
{
    /**
     * Switch the current household. No `exists` rule: an unknown id and a foreign one
     * both end in the same 403 from switchTo (I1).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['household_id' => ['required', 'integer']]);

        $household = Household::find($request->integer('household_id'));

        // The class name routes a null household to the policy instead of failing before it.
        Gate::authorize('switchTo', [Household::class, $household]);

        /** @var Household $household */
        $request->user()->forceFill(['current_household_id' => $household->id])->save();

        return to_route('home');
    }
}
