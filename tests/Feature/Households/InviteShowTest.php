<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $this->household = Household::factory()->create(['name' => 'Beach house', 'owner_id' => $owner->id]);
    $this->invite = HouseholdInvite::factory()->create([
        'household_id' => $this->household->id,
        'email' => 'carol@example.com',
    ]);
});

test('the invited address sees the household and inviter', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $this->actingAs($carol)
        ->get(route('invites.show', $this->invite->token))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invites/Show')
            ->where('token', $this->invite->token)
            ->where('state', 'pending')
            ->where('matches', true)
            ->where('household', ['name' => 'Beach house'])
            ->where('inviter', ['name' => 'Alice Owner']),
        );
});

test('anyone else sees neither household nor inviter', function () {
    $mallory = User::factory()->create(['email' => 'mallory@example.com']);

    $this->actingAs($mallory)
        ->get(route('invites.show', $this->invite->token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invites/Show')
            ->where('matches', false)
            ->where('household', null)
            ->where('inviter', null),
        )
        ->assertDontSee('Beach house')
        ->assertDontSee('Alice Owner')
        ->assertDontSee('carol@example.com');
});

test('expired and accepted invites report their state', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $this->invite->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->actingAs($carol)
        ->get(route('invites.show', $this->invite->token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));

    $this->invite->forceFill(['accepted_at' => now(), 'accepted_by' => $carol->id])->save();
    $this->actingAs($carol)
        ->get(route('invites.show', $this->invite->token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'accepted'));
});

test('an unknown token is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('invites.show', str_repeat('x', 64)))
        ->assertNotFound();
});

test('a guest is sent to login', function () {
    $this->get(route('invites.show', $this->invite->token))->assertRedirect(route('login'));
});
