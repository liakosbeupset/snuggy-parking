<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner removes a member, whose current pointer is cleared and then self-heals', function () {
    [$household, $owner, $member] = householdWithMember();
    $memberOwned = $member->ownedHouseholds()->sole();

    $this->actingAs($owner)
        ->from(route('household.show'))
        ->delete(route('households.members.destroy', [$household, $member]))
        ->assertRedirect(route('household.show'))
        ->assertInertiaFlash('toast.message', 'Bob Member was removed.');

    expect($member->isMemberOf($household))->toBeFalse()
        ->and($member->fresh()->current_household_id)->toBeNull();

    $this->actingAs($member->fresh())
        ->get(route('household.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('household.id', $memberOwned->id)
            ->where('currentHousehold.id', $memberOwned->id));

    expect($member->fresh()->current_household_id)->toBe($memberOwned->id);
});

test("removal leaves a member's current pointer alone when it points elsewhere", function () {
    [$household, $owner, $member] = householdWithMember();
    $memberOwned = $member->ownedHouseholds()->sole();
    $member->forceFill(['current_household_id' => $memberOwned->id])->save();

    $this->actingAs($owner)
        ->delete(route('households.members.destroy', [$household, $member]));

    expect($member->isMemberOf($household))->toBeFalse()
        ->and($member->fresh()->current_household_id)->toBe($memberOwned->id);
});

test('a member cannot remove another member', function () {
    [$household, , $member] = householdWithMember();
    $dave = User::factory()->create();
    $household->members()->attach($dave);

    $this->actingAs($member)
        ->delete(route('households.members.destroy', [$household, $dave]))
        ->assertForbidden();

    expect($dave->isMemberOf($household))->toBeTrue();
});

test('an outsider gets the same 403 for a member and a non-member, so membership cannot be probed', function () {
    [$household, , $member] = householdWithMember();
    $outsider = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($outsider)
        ->delete(route('households.members.destroy', [$household, $member]))
        ->assertForbidden();
    $this->actingAs($outsider)
        ->delete(route('households.members.destroy', [$household, $stranger]))
        ->assertForbidden();

    expect($member->isMemberOf($household))->toBeTrue();
});

test('the owner cannot be removed', function () {
    [$household, $owner] = householdWithMember();

    $this->actingAs($owner)
        ->delete(route('households.members.destroy', [$household, $owner]))
        ->assertForbidden();

    expect($owner->isMemberOf($household))->toBeTrue();
});

test('the owner removing a non-member is a 404', function () {
    [$household, $owner] = householdWithMember();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->delete(route('households.members.destroy', [$household, $stranger]))
        ->assertNotFound();
});
