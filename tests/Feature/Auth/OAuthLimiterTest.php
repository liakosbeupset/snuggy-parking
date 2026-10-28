<?php

test('the OAuth routes allow 20 requests a minute per IP', function () {
    config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret']);

    for ($i = 0; $i < 20; $i++) {
        $this->get('/auth/google/redirect')->assertRedirect();
    }

    $this->get('/auth/google/redirect')->assertTooManyRequests();
    $this->get('/auth/google/callback?error=access_denied')->assertTooManyRequests();
});
