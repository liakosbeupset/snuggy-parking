<?php

namespace App\Models;

use App\Actions\CreatePersonalHousehold;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $google_id
 * @property string|null $avatar
 * @property int|null $current_household_id
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
#[Hidden(['google_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private ?Household $resolvedCurrentHousehold = null;

    /**
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)->withTimestamps();
    }

    /**
     * @return HasMany<Household, $this>
     */
    public function ownedHouseholds(): HasMany
    {
        return $this->hasMany(Household::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function currentHouseholdRelation(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'current_household_id');
    }

    /**
     * The only reader of current_household_id: a household the user is a member of,
     * self-healing a stale pointer to the owned household or a new personal one.
     */
    public function currentHousehold(): Household
    {
        if ($this->resolvedCurrentHousehold !== null) {
            return $this->resolvedCurrentHousehold;
        }

        if ($this->current_household_id !== null && $this->isMemberOf($this->current_household_id)) {
            $household = $this->currentHouseholdRelation()->first();

            if ($household !== null) {
                return $this->resolvedCurrentHousehold = $household;
            }
        }

        $owned = $this->ownedHouseholds()->orderBy('id')->first();

        if ($owned !== null) {
            $this->forceFill(['current_household_id' => $owned->id])->save();

            return $this->resolvedCurrentHousehold = $owned;
        }

        return $this->resolvedCurrentHousehold = app(CreatePersonalHousehold::class)($this);
    }

    public function isMemberOf(Household|int $household): bool
    {
        $id = $household instanceof Household ? $household->id : $household;

        return $this->households()->whereKey($id)->exists();
    }
}
