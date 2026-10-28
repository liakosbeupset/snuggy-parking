<?php

use App\Models\Household;
use App\Models\ParkingSpot;
use Inertia\Testing\AssertableInertia as Assert;

test("home shows the current household's active spot and recent history only", function () {
    [$household, $owner, $member] = householdWithMember();
    $old = ParkingSpot::factory()->retrieved()->create([
        'household_id' => $household->id, 'user_id' => $owner->id, 'parked_at' => now()->subDays(2),
    ]);
    $older = ParkingSpot::factory()->retrieved()->create([
        'household_id' => $household->id, 'user_id' => $member->id, 'parked_at' => now()->subDays(3),
    ]);
    $active = ParkingSpot::factory()->create([
        'household_id' => $household->id, 'user_id' => $member->id, 'parked_at' => now()->subHour(),
    ]);
    // Another household's active spot: neither the active card nor the history may show it.
    ParkingSpot::factory()->create(['household_id' => Household::factory()->create()->id]);

    $this->actingAs($member)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('household', ['id' => $household->id, 'name' => 'Beach house'])
            ->where('activeSpot.id', $active->id)
            ->where('activeSpot.parked_by', 'Bob Member')
            ->where('activeSpot.household_name', 'Beach house')
            ->where('activeSpot.is_active', true)
            ->has('recentSpots', 3)
            ->where('recentSpots.0.id', $active->id)
            ->where('recentSpots.1.id', $old->id)
            ->where('recentSpots.1.parked_by', 'Alice Owner')
            ->where('recentSpots.1.is_active', false)
            ->where('recentSpots.2.id', $older->id));
});

test('home has no active spot once the car is collected', function () {
    [$household, , $member] = householdWithMember();
    ParkingSpot::factory()->retrieved()->create(['household_id' => $household->id]);
    ParkingSpot::factory()->create();

    $this->actingAs($member)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeSpot', null)
            ->has('recentSpots', 1));
});

test('home lists at most the ten most recent spots', function () {
    [$household, , $member] = householdWithMember();
    ParkingSpot::factory()->retrieved()->count(11)
        ->sequence(fn ($sequence) => ['parked_at' => now()->subHours($sequence->index + 1)])
        ->create(['household_id' => $household->id]);

    $this->actingAs($member)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->has('recentSpots', 10));
});
