<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * No `view` ability: /household only ever renders currentHousehold(), which is
 * membership-checked by construction. A denial is a 403 throughout (KD3).
 */
class HouseholdPolicy
{
    public function invite(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    public function revokeInvite(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    public function removeMember(User $user, Household $household, User $target): bool
    {
        return $household->isOwnedBy($user) && $target->id !== $household->owner_id;
    }

    public function leave(User $user, Household $household): bool
    {
        return ! $household->isOwnedBy($user) && $user->isMemberOf($household);
    }

    /**
     * A null household (unknown id) is denied like a foreign one, so the two are indistinguishable.
     */
    public function switchTo(User $user, ?Household $household): bool
    {
        return $household !== null && $user->isMemberOf($household);
    }

    /**
     * A fresh membership query, so P5's spot store can re-run it under the household lock (FM18).
     */
    public function parkIn(User $user, ?Household $household): bool
    {
        return $household !== null && $user->isMemberOf($household);
    }
}
