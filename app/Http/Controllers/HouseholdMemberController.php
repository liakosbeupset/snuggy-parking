<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class HouseholdMemberController extends Controller
{
    /**
     * Remove a member. Authorized before any membership lookup, so only the owner can
     * tell a member from a non-member (404); everyone else gets 403 (I1).
     */
    public function destroy(Household $household, User $user): RedirectResponse
    {
        $this->authorize('removeMember', [$household, $user]);

        $this->detach($household, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} was removed."]);

        return back();
    }

    /**
     * Leave a household. The owner cannot leave (I4).
     */
    public function leave(Request $request, Household $household): RedirectResponse
    {
        $this->authorize('leave', $household);

        $this->detach($household, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "You left {$household->name}."]);

        return to_route('home');
    }

    /**
     * Under the household lock: re-check membership, detach, and clear the member's current
     * pointer only if it points here; their next request self-heals (FM8).
     */
    private function detach(Household $household, User $user): void
    {
        DB::transaction(function () use ($household, $user): void {
            Household::whereKey($household->id)->lockForUpdate()->first();

            abort_unless($user->isMemberOf($household), 404);

            $household->members()->detach($user->id);

            User::whereKey($user->id)
                ->where('current_household_id', $household->id)
                ->update(['current_household_id' => null]);
        });
    }
}
