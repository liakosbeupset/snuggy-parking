<?php

test('the Google redirect always asks for the account chooser', function () {
    // Not faked: Socialite::fake returns a constant URL and drops with() parameters.
    config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret']);

    $response = $this->get('/auth/google/redirect')->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(parse_url($location, PHP_URL_HOST))->toBe('accounts.google.com')
        ->and($query['prompt'] ?? null)->toBe('select_account')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/auth/google/callback');
});
