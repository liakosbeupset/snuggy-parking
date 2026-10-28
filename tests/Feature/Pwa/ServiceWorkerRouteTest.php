<?php

test('the built service worker is served from the root without a session', function () {
    $path = tempnam(sys_get_temp_dir(), 'sw');
    file_put_contents($path, '// sw');
    config(['pwa.sw_path' => $path]);

    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeaderMissing('Set-Cookie');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('// sw');

    unlink($path);
});

test('the service worker route is a 404 without a build', function () {
    config(['pwa.sw_path' => sys_get_temp_dir().'/missing-'.uniqid().'/sw.js']);

    $this->get('/sw.js')->assertNotFound();
});
