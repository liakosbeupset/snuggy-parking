<?php

use App\Models\User;

test('the login page is shown to guests', function () {
    $this->get('/login')->assertOk();
});

test('password, registration and two-factor routes do not exist', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    '/register',
    '/forgot-password',
    '/reset-password/x',
    '/two-factor-challenge',
    '/user/confirm-password',
    '/email/verify',
    '/settings/security',
    '/settings/password',
    '/.well-known/passkey-endpoints',
]);

test('posting credentials to the login page is not allowed', function () {
    $this->post('/login', ['email' => 'alice@example.com', 'password' => 'password'])
        ->assertMethodNotAllowed();
});

test('users can log out', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});
