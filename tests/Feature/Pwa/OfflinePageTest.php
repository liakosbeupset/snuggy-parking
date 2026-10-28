<?php

test('the offline page is served without a session', function () {
    $this->get('/offline')
        ->assertOk()
        ->assertSee('id="offline"', false)
        ->assertHeaderMissing('Set-Cookie');
});
