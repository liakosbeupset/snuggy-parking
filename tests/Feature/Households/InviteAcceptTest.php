<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;

beforeEach(function () {
    $this->household = Household::factory()->create(['name' => 'Beach house']);
    $this->invite = HouseholdInvite::factory()->create([
        'household_id' => $this->household->id,
        'email' => 'carol@example.com',
    ]);
});

test('a matching user accepts: joins, the household becomes current, the invite is used', function () {
    // Stored email casing differs from the invite: the comparison is case-insensitive.
    $carol = User::factory()->create(['email' => 'Carol@Example.com']);

    $this->actingAs($carol)
        ->post(route('invites.accept', $this->invite->token))
        ->assertRedirect(route('household.show'))
        ->assertInertiaFlash('toast.message', 'Welcome to Beach house');

    $invite = $this->invite->fresh();

    expect($carol->isMemberOf($this->household))->toBeTrue()
        ->and($carol->fresh()->current_household_id)->toBe($this->household->id)
        ->and($invite->accepted_at)->not->toBeNull()
        ->and($invite->accepted_by)->toBe($carol->id);
});

test('a second matching user cannot reuse an accepted invite', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->actingAs($carol)->post(route('invites.accept', $this->invite->token));

    // A second account with the same address (a renamed Google account, say).
    $carol->forceFill(['email' => 'carol-old@example.com'])->save();
    $other = User::factory()->create(['email' => 'carol@example.com']);

    $this->actingAs($other)
        ->from(route('invites.show', $this->invite->token))
        ->post(route('invites.accept', $this->invite->token))
        ->assertRedirect(route('invites.show', $this->invite->token))
        ->assertInertiaFlash('toast.message', 'This invite has already been used.');

    expect($other->isMemberOf($this->household))->toBeFalse()
        ->and($this->invite->fresh()->accepted_by)->toBe($carol->id);
});

test('the same user accepting twice is redirected to the household', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->actingAs($carol)->post(route('invites.accept', $this->invite->token));

    $this->actingAs($carol)
        ->post(route('invites.accept', $this->invite->token))
        ->assertRedirect(route('household.show'));

    expect($this->household->members()->whereKey($carol->id)->count())->toBe(1)
        ->and($this->invite->fresh()->accepted_by)->toBe($carol->id);
});

test('an expired invite is rejected without membership', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->invite->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->actingAs($carol)
        ->post(route('invites.accept', $this->invite->token))
        ->assertInertiaFlash('toast.message', 'This invite has expired.');

    expect($carol->isMemberOf($this->household))->toBeFalse()
        ->and($this->invite->fresh()->accepted_at)->toBeNull();
});

test('an email mismatch is rejected without membership', function () {
    $mallory = User::factory()->create(['email' => 'mallory@example.com']);
    $before = $mallory->current_household_id;

    $this->actingAs($mallory)
        ->post(route('invites.accept', $this->invite->token))
        ->assertInertiaFlash('toast.message', 'This invite was sent to a different email address.');

    expect($mallory->isMemberOf($this->household))->toBeFalse()
        ->and($mallory->fresh()->current_household_id)->toBe($before)
        ->and($this->invite->fresh()->accepted_at)->toBeNull();
});

test('an unknown token is a 404', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    $this->actingAs($carol)
        ->post(route('invites.accept', str_repeat('x', 64)))
        ->assertNotFound();
});

test('an existing member accepting marks the invite used without a duplicate membership', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->household->members()->attach($carol);

    $this->actingAs($carol)
        ->post(route('invites.accept', $this->invite->token))
        ->assertRedirect(route('household.show'));

    expect($this->household->members()->whereKey($carol->id)->count())->toBe(1)
        ->and($this->invite->fresh()->accepted_by)->toBe($carol->id);
});

test('a guest is sent to login', function () {
    $this->post(route('invites.accept', $this->invite->token))->assertRedirect(route('login'));
});

test('an invite at exactly its expiry moment is expired everywhere', function () {
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    $this->freezeSecond();
    $this->invite->forceFill(['expires_at' => now()])->save();

    expect($this->invite->fresh()->isPending())->toBeFalse();

    $this->actingAs($carol)
        ->post(route('invites.accept', $this->invite->token))
        ->assertInertiaFlash('toast.message', 'This invite has expired.');

    expect($carol->isMemberOf($this->household))->toBeFalse();
});
