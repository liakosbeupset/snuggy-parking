<?php

namespace App\Actions;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePersonalHousehold
{
    /**
     * Create the user's personal household and make it current. Locks the user row and
     * re-checks for an owned household first, so parallel callers cannot create two.
     */
    public function __invoke(User $user): Household
    {
        return DB::transaction(function () use ($user): Household {
            User::whereKey($user->id)->lockForUpdate()->first();

            $household = $user->ownedHouseholds()->orderBy('id')->first();

            if ($household === null) {
                $household = Household::create([
                    'name' => Str::before(trim($user->name), ' ')."'s household",
                    'owner_id' => $user->id,
                ]);

                $user->households()->attach($household);
            }

            $user->forceFill(['current_household_id' => $household->id])->save();

            return $household;
        });
    }
}
