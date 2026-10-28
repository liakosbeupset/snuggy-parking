<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\HouseholdInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InviteAcceptanceController extends Controller
{
    /**
     * Show an invite. Household and inviter are revealed only to the invited address
     * (the I1 exception); anyone else learns nothing about it.
     */
    public function show(Request $request, string $token): Response
    {
        $invite = HouseholdInvite::where('token', $token)
            ->with('household:id,name', 'inviter:id,name')
            ->firstOrFail();

        $matches = Str::lower($request->user()->email) === $invite->email;

        return Inertia::render('Invites/Show', [
            'token' => $invite->token,
            'state' => match (true) {
                $invite->accepted_at !== null => 'accepted',
                ! $invite->expires_at->isFuture() => 'expired',
                default => 'pending',
            },
            'matches' => $matches,
            'household' => $matches ? ['name' => $invite->household->name] : null,
            'inviter' => $matches ? ['name' => $invite->inviter->name] : null,
        ]);
    }

    /**
     * Accept an invite (I5). Lock order is household, then invite (KD11): the invite is
     * read unlocked only to learn its household, then locked and re-read.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();
        $peek = HouseholdInvite::where('token', $token)->firstOrFail();

        return DB::transaction(function () use ($user, $peek): RedirectResponse {
            $household = Household::whereKey($peek->household_id)->lockForUpdate()->firstOrFail();

            // Gone after the lock means it was cancelled meanwhile (FM23).
            $invite = HouseholdInvite::whereKey($peek->id)->lockForUpdate()->first();

            abort_if($invite === null, 404);

            if ($invite->accepted_at !== null) {
                // A double submit by the same user lands where the first one did (FM7).
                return $invite->accepted_by === $user->id
                    ? to_route('household.show')
                    : $this->refuse('This invite has already been used.');
            }

            if (! $invite->expires_at->isFuture()) {
                return $this->refuse('This invite has expired.');
            }

            if (Str::lower($user->email) !== $invite->email) {
                return $this->refuse('This invite was sent to a different email address.');
            }

            // An existing member just uses the invite up: the pivot stays unique (FM15).
            $household->members()->syncWithoutDetaching([$user->id]);
            $invite->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();
            $user->forceFill(['current_household_id' => $household->id])->save();

            Inertia::flash('toast', ['type' => 'success', 'message' => "Welcome to {$household->name}"]);

            return to_route('household.show');
        });
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
