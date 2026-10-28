<?php

use App\Models\User;

test('a member leaves, and their current pointer is cleared then self-heals', function () {
    [$household, , $member] = householdWithMember();
    $memberOwned = $member->ownedHouseholds()->sole();

    $this->actingAs($member)
        ->post(route('households.leave', $household))
        ->assertRedirect(route('home'))
        ->assertInertiaFlash('toast.message', 'You left Beach house.');

    expect($member->isMemberOf($household))->toBeFalse()
        ->and($member->fresh()->current_household_id)->toBeNull()
        ->and($member->fresh()->currentHousehold()->id)->toBe($memberOwned->id);
});

test('the owner cannot leave', function () {
    [$household, $owner] = householdWithMember();

    $this->actingAs($owner)
        ->post(route('households.leave', $household))
        ->assertForbidden();

    expect($owner->isMemberOf($household))->toBeTrue()
        ->and($owner->fresh()->current_household_id)->toBe($household->id);
});

test('a non-member cannot leave', function () {
    [$household] = householdWithMember();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('households.leave', $household))
        ->assertForbidden();
});
