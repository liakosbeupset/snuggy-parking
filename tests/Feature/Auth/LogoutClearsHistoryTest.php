<?php

use App\Models\User;
use Inertia\Support\SessionKey;

test('logout survives the session invalidation with a history clear for the login page', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/login')
        ->assertSessionHas(SessionKey::CLEAR_HISTORY, true);

    expect($this->get('/login')->inertiaPage())->toHaveKey('clearHistory', true);
});
