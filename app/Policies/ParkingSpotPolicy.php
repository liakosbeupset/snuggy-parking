<?php

namespace App\Policies;

use App\Models\ParkingSpot;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A non-member gets 404, so a spot id never reveals that the spot exists (KD3, I1).
 * Parking itself is HouseholdPolicy::parkIn, checked under the household lock.
 */
class ParkingSpotPolicy
{
    public function view(User $user, ParkingSpot $spot): Response
    {
        return $user->isMemberOf($spot->household_id) ? Response::allow() : Response::denyAsNotFound();
    }

    public function retrieve(User $user, ParkingSpot $spot): Response
    {
        return $user->isMemberOf($spot->household_id) ? Response::allow() : Response::denyAsNotFound();
    }
}
