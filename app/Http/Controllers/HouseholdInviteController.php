<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHouseholdInviteRequest;
use App\Mail\HouseholdInviteMail;
use App\Models\Household;
use App\Models\HouseholdInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class HouseholdInviteController extends Controller
{
    /**
     * Create an invite and email its link. A mail failure keeps the invite: the owner can
     * still copy the link from the pending list (FM6).
     */
    public function store(StoreHouseholdInviteRequest $request, Household $household): RedirectResponse
    {
        $user = $request->user();

        $invite = $household->invites()->create([
            'email' => Str::lower($request->string('email')->toString()),
            'token' => Str::random(64),
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        try {
            Mail::to($invite->email)->send(new HouseholdInviteMail($invite));

            Inertia::flash('toast', ['type' => 'success', 'message' => "Invite sent to {$invite->email}."]);
        } catch (Throwable $e) {
            report($e);

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => "Invite created, but the email couldn't be sent — copy the link below and share it.",
            ]);
        }

        return to_route('household.show');
    }

    /**
     * Cancel a pending invite. Locks the household, then the invite (KD11), so a concurrent
     * accept either finds the row gone or has already used it (FM23, FM28).
     */
    public function destroy(Household $household, HouseholdInvite $invite): RedirectResponse
    {
        $this->authorize('revokeInvite', $household);

        $cancelled = DB::transaction(function () use ($household, $invite): bool {
            Household::whereKey($household->id)->lockForUpdate()->first();

            $fresh = HouseholdInvite::whereKey($invite->id)->lockForUpdate()->first();

            abort_if($fresh === null, 404);

            if (! $fresh->isPending()) {
                return false;
            }

            $fresh->delete();

            return true;
        });

        Inertia::flash('toast', $cancelled
            ? ['type' => 'success', 'message' => 'Invite cancelled.']
            : ['type' => 'error', 'message' => 'Only pending invites can be cancelled.']);

        return back();
    }
}
