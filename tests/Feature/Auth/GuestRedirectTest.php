<?php

test('guests are redirected to the login page', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with([
    '/',
    '/settings/profile',
    '/settings/appearance',
]);
