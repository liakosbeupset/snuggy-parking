<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('generated_at is the response time, resent on partial reloads', function () {
    Carbon::setTestNow('2026-10-28 09:30:00');

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generated_at', '2026-10-28T09:30:00+00:00')
            ->reloadOnly('household', fn (Assert $reload) => $reload
                ->missing('activeSpot')
                ->where('generated_at', '2026-10-28T09:30:00+00:00')));
});
