<?php

use App\Models\Household;
use App\Models\User;

test('a member can switch to another of their households', function () {
    $user = User::factory()->create();
    $other = Household::factory()->create();
    $other->members()->attach($user);

    $this->actingAs($user)
        ->put(route('current-household.update'), ['household_id' => $other->id])
        ->assertRedirect(route('home'));

    expect($user->fresh()->current_household_id)->toBe($other->id);
});

test('switching to a household the user is not a member of is forbidden', function () {
    $user = User::factory()->create();
    $before = $user->current_household_id;
    $foreign = Household::factory()->create();

    $this->actingAs($user)
        ->put(route('current-household.update'), ['household_id' => $foreign->id])
        ->assertForbidden();

    expect($user->fresh()->current_household_id)->toBe($before);
});

test('switching to an unknown household is the same 403', function () {
    $user = User::factory()->create();
    $before = $user->current_household_id;

    $this->actingAs($user)
        ->put(route('current-household.update'), ['household_id' => 999999])
        ->assertForbidden();

    expect($user->fresh()->current_household_id)->toBe($before);
});

test('a missing household id is a validation error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('current-household.update'), [])
        ->assertSessionHasErrors('household_id');
});
