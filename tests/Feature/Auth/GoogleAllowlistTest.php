<?php

use App\Models\User;

// TEMPORARY: delete this file together with the allowlist.

beforeEach(fn () => config(['services.google.allowed_emails' => ['owner@example.com', 'partner@example.com']]));

test('an email outside the allowlist is refused without creating a user', function () {
    fakeGoogleUser(['email' => 'stranger@example.com']);

    $this->get('/auth/google/callback')
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', "This Google account isn't allowed to sign in.");

    $this->assertGuest();
    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
});

test('an existing user outside the allowlist cannot sign in either', function () {
    $user = User::factory()->create(['email' => 'stranger@example.com', 'google_id' => '100000000000000000001']);
    fakeGoogleUser(['email' => 'stranger@example.com']);

    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
});

test('an allowlisted email signs in, whatever its case', function () {
    fakeGoogleUser(['email' => 'Partner@Example.com']);

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

test('an empty allowlist lets nobody sign in', function () {
    config(['services.google.allowed_emails' => []]);
    fakeGoogleUser(['email' => 'owner@example.com']);

    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
});

test('a "*" allowlist lets anyone sign in', function () {
    config(['services.google.allowed_emails' => ['*']]);
    fakeGoogleUser(['email' => 'stranger@example.com']);

    $this->get('/auth/google/callback')->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

test('inviting an email outside the allowlist is refused', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($owner)
        ->post(route('households.invites.store', $owner->currentHousehold()), ['email' => 'stranger@example.com'])
        ->assertSessionHasErrors(['email' => "stranger@example.com can't sign in to Snuggy Parking yet."]);

    $this->actingAs($owner)
        ->post(route('households.invites.store', $owner->currentHousehold()), ['email' => 'Partner@Example.com'])
        ->assertSessionHasNoErrors();
});
