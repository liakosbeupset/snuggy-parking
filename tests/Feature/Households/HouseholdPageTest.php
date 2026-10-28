<?php

use App\Models\HouseholdInvite;
use Inertia\Testing\AssertableInertia as Assert;

test('a member sees the current household and its members', function () {
    [$household, $owner, $member] = householdWithMember();

    $this->actingAs($member)
        ->get(route('household.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Household')
            ->where('household', ['id' => $household->id, 'name' => 'Beach house', 'owner_id' => $owner->id])
            ->where('members', [
                ['id' => $owner->id, 'name' => 'Alice Owner', 'email' => $owner->email, 'avatar' => null, 'is_owner' => true],
                ['id' => $member->id, 'name' => 'Bob Member', 'email' => $member->email, 'avatar' => null, 'is_owner' => false],
            ])
            ->where('canInvite', false),
        );
});

test('the owner sees pending invites with their links, a member gets null', function () {
    [$household, $owner, $member] = householdWithMember();
    $pending = HouseholdInvite::factory()->create(['household_id' => $household->id, 'email' => 'carol@example.com']);
    HouseholdInvite::factory()->expired()->create(['household_id' => $household->id]);
    HouseholdInvite::factory()->create(['household_id' => $household->id, 'accepted_at' => now(), 'accepted_by' => $member->id]);

    $this->actingAs($owner)
        ->get(route('household.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canInvite', true)
            ->has('pendingInvites', 1)
            ->where('pendingInvites.0', [
                'id' => $pending->id,
                'email' => 'carol@example.com',
                'expires_at' => $pending->expires_at->toIso8601String(),
                'link' => route('invites.show', $pending->token),
                'inviter_name' => 'Alice Owner',
            ]),
        );

    $this->actingAs($member)
        ->get(route('household.show'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingInvites', null));
});

test('a guest is sent to login', function () {
    $this->get(route('household.show'))->assertRedirect(route('login'));
});
