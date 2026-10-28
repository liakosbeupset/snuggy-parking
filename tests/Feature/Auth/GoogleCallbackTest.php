<?php

use App\Models\Household;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

function googleCallback(): string
{
    return '/auth/google/callback?code=fake-code&state=fake-state';
}

test('a first login creates the user and a current personal household', function () {
    fakeGoogleUser(['email' => 'Alice@Example.com']);

    $this->get(googleCallback())->assertRedirect('/');

    $user = User::sole();
    $household = Household::sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('alice@example.com')
        ->and($user->google_id)->toBe('100000000000000000001')
        ->and($user->name)->toBe('Alice Example')
        ->and($user->avatar)->toBe('https://lh3.googleusercontent.com/a/alice')
        ->and($user->current_household_id)->toBe($household->id)
        ->and($household->name)->toBe("Alice's household")
        ->and($household->owner_id)->toBe($user->id)
        ->and($household->members()->pluck('users.id')->all())->toBe([$user->id]);
});

test('a repeat login reuses the user, refreshes email and avatar, and keeps the name', function () {
    $user = User::factory()->create([
        'name' => 'Renamed In App',
        'email' => 'old@example.com',
        'google_id' => '100000000000000000001',
    ]);
    fakeGoogleUser(['email' => 'alice@example.com', 'avatar' => 'https://lh3.googleusercontent.com/a/new']);

    $this->get(googleCallback())->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect(User::count())->toBe(1)
        ->and(Household::count())->toBe(1)
        ->and($user->name)->toBe('Renamed In App')
        ->and($user->email)->toBe('alice@example.com')
        ->and($user->avatar)->toBe('https://lh3.googleusercontent.com/a/new');
});

test('an unverified Google email is refused without creating a user', function (array $attributes) {
    Socialite::fake('google', GoogleUser::fake($attributes));

    $this->get(googleCallback())
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', "Your Google account email isn't verified.");

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'email_verified false' => [['email' => 'alice@example.com', 'email_verified' => false]],
    'email_verified missing' => [['email' => 'alice@example.com']],
    'email_verified as a string' => [['email' => 'alice@example.com', 'email_verified' => 'true']],
    'no email' => [['email' => null, 'email_verified' => true]],
]);

test('an email linked to a different Google account is refused without overwriting it', function () {
    $owner = User::factory()->create(['email' => 'alice@example.com', 'google_id' => '999']);
    fakeGoogleUser(['id' => '100000000000000000001', 'email' => 'alice@example.com']);

    $this->get(googleCallback())
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', 'This email is linked to another Google account.');

    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and($owner->fresh()->google_id)->toBe('999');
});

test('a login whose new email belongs to another user is refused without taking it over', function () {
    $linked = User::factory()->create(['email' => 'old@example.com', 'google_id' => '100000000000000000001']);
    $other = User::factory()->create(['email' => 'alice@example.com', 'google_id' => '999']);
    fakeGoogleUser(['email' => 'alice@example.com']);

    $this->get(googleCallback())
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', 'This email is linked to another Google account.');

    $this->assertGuest();
    expect($linked->fresh()->email)->toBe('old@example.com')
        ->and($other->fresh()->google_id)->toBe('999');
});

test('a user without a google_id is linked to the Google account with the same email', function () {
    $user = User::factory()->create(['email' => 'alice@example.com', 'google_id' => null]);
    fakeGoogleUser();

    $this->get(googleCallback())->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)
        ->and($user->fresh()->google_id)->toBe('100000000000000000001');
});

test('a cancelled Google sign-in returns to the login page with a message', function () {
    $this->get('/auth/google/callback?error=access_denied')
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', 'Google sign-in was cancelled.');

    $this->assertGuest();
});

test('an invalid OAuth state returns to the login page with a message', function () {
    // Not faked: the real provider rejects a state the session never issued, before any network call.
    config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret']);

    $this->get(googleCallback())
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', 'Google sign-in failed, please try again.');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a failing Google API call returns to the login page with a message', function () {
    Socialite::fake('google', fn () => throw new RuntimeException('cURL error 28: timed out'));

    $this->get(googleCallback())
        ->assertRedirect('/login')
        ->assertInertiaFlash('toast.message', 'Google sign-in failed, please try again.');

    $this->assertGuest();
});
