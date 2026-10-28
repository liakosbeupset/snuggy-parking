<?php

namespace App\Http\Controllers;

use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HouseholdController extends Controller
{
    /**
     * Show the current household. Takes no parameter: currentHousehold() is membership-checked,
     * so there is no other household to authorize. Invite tokens reach the owner only.
     */
    public function show(Request $request): Response
    {
        $user = $request->user();
        $household = $user->currentHousehold();
        $canInvite = $user->can('invite', $household);

        return Inertia::render('Household', [
            'household' => $household->only(['id', 'name', 'owner_id']),
            // Mapped explicitly: `is_owner` is not a column, and get() would also serialise `pivot`.
            'members' => $household->members()->orderBy('name')->get()
                ->map(fn (User $member) => [
                    ...$member->only(['id', 'name', 'email', 'avatar']),
                    'is_owner' => $member->id === $household->owner_id,
                ])->values()->all(),
            'pendingInvites' => $canInvite
                ? $household->invites()->pending()->with('inviter:id,name')->latest()->get()
                    ->map(fn (HouseholdInvite $invite) => [
                        'id' => $invite->id,
                        'email' => $invite->email,
                        'expires_at' => $invite->expires_at->toIso8601String(),
                        'link' => route('invites.show', $invite->token),
                        'inviter_name' => $invite->inviter->name,
                    ])->values()->all()
                : null,
            'canInvite' => $canInvite,
        ]);
    }
}
