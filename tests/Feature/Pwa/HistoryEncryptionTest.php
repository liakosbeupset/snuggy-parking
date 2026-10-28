<?php

use App\Models\User;

test('page data in the browser history is encrypted', function () {
    expect(config('inertia.history.encrypt'))->toBeTrue();

    $page = $this->actingAs(User::factory()->create())->get(route('home'))->inertiaPage();

    expect($page)->toHaveKey('encryptHistory', true);
});
