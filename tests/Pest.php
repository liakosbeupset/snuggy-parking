<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Fake the Google account the OAuth callback receives. The attributes are also its raw
 * payload, so `email_verified` reaches the callback the way Google's userinfo sends it.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeGoogleUser(array $attributes = []): GoogleUser
{
    $googleUser = GoogleUser::fake([
        'id' => '100000000000000000001',
        'name' => 'Alice Example',
        'email' => 'alice@example.com',
        'avatar' => 'https://lh3.googleusercontent.com/a/alice',
        'email_verified' => true,
        ...$attributes,
    ]);

    Socialite::fake('google', $googleUser);

    return $googleUser;
}

/**
 * "Beach house", owned by "Alice Owner" with "Bob Member" joined; current for both.
 * Each user also keeps their factory personal household.
 *
 * @return array{Household, User, User}
 */
function householdWithMember(): array
{
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $member = User::factory()->create(['name' => 'Bob Member']);
    $household = Household::factory()->create(['name' => 'Beach house', 'owner_id' => $owner->id]);
    $household->members()->attach($member);
    $owner->forceFill(['current_household_id' => $household->id])->save();
    $member->forceFill(['current_household_id' => $household->id])->save();

    return [$household, $owner, $member];
}
