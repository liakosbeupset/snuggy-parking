<?php

use App\Actions\CreatePersonalHousehold;
use App\Models\Household;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a current household the user is a member of is returned', function () {
    $user = User::factory()->create();
    $other = Household::factory()->create();
    $other->members()->attach($user);
    $user->forceFill(['current_household_id' => $other->id])->save();

    expect($user->fresh()->currentHousehold()->id)->toBe($other->id);
});

test('a stale current household falls back to the owned household and persists it', function () {
    $user = User::factory()->create();
    $owned = $user->ownedHouseholds()->sole();
    $notAMember = Household::factory()->create();
    $user->forceFill(['current_household_id' => $notAMember->id])->save();

    expect($user->fresh()->currentHousehold()->id)->toBe($owned->id)
        ->and($user->fresh()->current_household_id)->toBe($owned->id);
});

test('a user who owns no household gets a personal one created', function () {
    $user = User::factory()->withoutPersonalHousehold()->create(['name' => 'Bob Builder']);
    $foreign = Household::factory()->create();
    $user->forceFill(['current_household_id' => $foreign->id])->save();

    $household = $user->fresh()->currentHousehold();

    expect($household->name)->toBe("Bob's household")
        ->and($household->owner_id)->toBe($user->id)
        ->and($user->isMemberOf($household))->toBeTrue()
        ->and($user->fresh()->current_household_id)->toBe($household->id)
        ->and($user->fresh()->currentHousehold()->id)->toBe($household->id)
        ->and($user->ownedHouseholds()->count())->toBe(1);
});

test('the current household and the household list are shared with every page', function () {
    $user = User::factory()->create(['name' => 'Alice Example']);
    $other = Household::factory()->create(['name' => 'Beach house']);
    $other->members()->attach($user);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user', [
                'id' => $user->id,
                'name' => 'Alice Example',
                'email' => $user->email,
                'avatar' => null,
            ])
            ->where('currentHousehold', [
                'id' => $user->current_household_id,
                'name' => "Alice's household",
                'owner_id' => $user->id,
            ])
            ->where('households', [
                ['id' => $user->current_household_id, 'name' => "Alice's household"],
                ['id' => $other->id, 'name' => 'Beach house'],
            ]),
        );
});

test('creating a personal household twice returns the one the user already owns', function () {
    $user = User::factory()->create();
    $first = $user->ownedHouseholds()->sole();

    $second = app(CreatePersonalHousehold::class)($user);

    expect($second->id)->toBe($first->id)
        ->and(Household::count())->toBe(1);
});
