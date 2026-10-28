<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_url-probe', fn () => url('/'));
});

$forwarded = [
    'X-Forwarded-Proto' => 'https',
    'X-Forwarded-Host' => 'x.ngrok.app',
];

test('a trusted proxy sets the scheme and host of generated urls', function () use ($forwarded) {
    config(['trustedproxy.proxies' => '*']);

    $this->get('/_url-probe', $forwarded)->assertContent('https://x.ngrok.app');
});

test('forwarded headers are ignored when no proxy is trusted', function () use ($forwarded) {
    $this->get('/_url-probe', $forwarded)->assertContent('http://localhost');
});
