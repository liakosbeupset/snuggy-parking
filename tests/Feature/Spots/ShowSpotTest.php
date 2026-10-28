<?php

use App\Models\ParkingSpot;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a member sees the spot with its map props and directions link', function () {
    [$household, $owner, $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'lat' => 51.5,
        'lng' => -0.12,
        'accuracy' => 8,
        'note' => 'Level 2',
    ]);

    $this->actingAs($member)
        ->get(route('spots.show', $spot))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Spots/Show')
            ->where('household', ['id' => $household->id, 'name' => 'Beach house'])
            ->where('spot.id', $spot->id)
            ->where('spot.household_name', 'Beach house')
            ->where('spot.lat', '51.5000000')
            ->where('spot.lng', '-0.1200000')
            ->where('spot.accuracy', '8.00')
            ->where('spot.note', 'Level 2')
            ->where('spot.parked_by', 'Alice Owner')
            ->where('spot.has_photo', false)
            ->where('spot.photo_url', null)
            ->where('spot.is_active', true)
            ->where('spot.retrieved_at', null)
            ->where('spot.directions_url', 'https://www.google.com/maps/dir/?api=1&destination=51.5000000%2C-0.1200000'));
});

test('a spot with a photo links to the authorized photo route', function () {
    [$household, , $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id, 'photo_path' => 'spots/1/p.jpg']);

    $this->actingAs($member)
        ->get(route('spots.show', $spot))
        ->assertInertia(fn (Assert $page) => $page
            ->where('spot.has_photo', true)
            ->where('spot.photo_url', route('spots.photo', $spot)));
});

test('a spot whose parker was deleted shows no name', function () {
    [$household, , $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id, 'user_id' => null]);

    $this->actingAs($member)
        ->get(route('spots.show', $spot))
        ->assertInertia(fn (Assert $page) => $page->where('spot.parked_by', null));
});

test("a non-member gets 404 for another household's spot", function () {
    [$household] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('spots.show', $spot))
        ->assertNotFound();
});

test('a removed member loses access to the spots they parked', function () {
    [$household, $owner, $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id, 'user_id' => $member->id]);
    $household->members()->detach($member);

    $this->actingAs($member)
        ->get(route('spots.show', $spot))
        ->assertNotFound();
});
