<?php

use App\Models\HouseholdInvite;
use App\Models\User;

test('the owner cancels a pending invite: its link and accept then 404', function () {
    [$household, $owner] = householdWithMember();
    $invite = HouseholdInvite::factory()->create(['household_id' => $household->id, 'email' => 'carol@example.com']);

    $this->actingAs($owner)
        ->from(route('household.show'))
        ->delete(route('households.invites.destroy', [$household, $invite]))
        ->assertRedirect(route('household.show'))
        ->assertInertiaFlash('toast.message', 'Invite cancelled.');

    expect(HouseholdInvite::find($invite->id))->toBeNull();

    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->actingAs($carol)->get(route('invites.show', $invite->token))->assertNotFound();
    $this->actingAs($carol)->post(route('invites.accept', $invite->token))->assertNotFound();

    expect($carol->isMemberOf($household))->toBeFalse();
});

test('a member who is not the owner cannot cancel', function () {
    [$household, , $member] = householdWithMember();
    $invite = HouseholdInvite::factory()->create(['household_id' => $household->id]);

    $this->actingAs($member)
        ->delete(route('households.invites.destroy', [$household, $invite]))
        ->assertForbidden();

    expect(HouseholdInvite::find($invite->id))->not->toBeNull();
});

test("another household's invite is a 404 under this household", function () {
    [$household, $owner] = householdWithMember();
    $foreign = HouseholdInvite::factory()->create();

    $this->actingAs($owner)
        ->delete(route('households.invites.destroy', [$household, $foreign]))
        ->assertNotFound();

    expect(HouseholdInvite::find($foreign->id))->not->toBeNull();
});

test('an accepted invite cannot be cancelled', function () {
    [$household, $owner, $member] = householdWithMember();
    $invite = HouseholdInvite::factory()->create([
        'household_id' => $household->id,
        'accepted_at' => now(),
        'accepted_by' => $member->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('households.invites.destroy', [$household, $invite]))
        ->assertInertiaFlash('toast.message', 'Only pending invites can be cancelled.');

    expect(HouseholdInvite::find($invite->id))->not->toBeNull();
});

test('an expired invite cannot be cancelled', function () {
    [$household, $owner] = householdWithMember();
    $invite = HouseholdInvite::factory()->expired()->create(['household_id' => $household->id]);

    $this->actingAs($owner)
        ->delete(route('households.invites.destroy', [$household, $invite]))
        ->assertInertiaFlash('toast.message', 'Only pending invites can be cancelled.');

    expect(HouseholdInvite::find($invite->id))->not->toBeNull();
});
