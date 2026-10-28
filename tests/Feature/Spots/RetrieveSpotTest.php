<?php

use App\Models\ParkingSpot;
use App\Models\User;

test('"got the car" retrieves the spot and returns home', function () {
    [$household, , $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id]);
    $this->travelTo(now()->startOfSecond());

    $this->actingAs($member)
        ->patch(route('spots.retrieve', $spot))
        ->assertRedirect(route('home'));

    expect($spot->fresh()->retrieved_at?->equalTo(now()))->toBeTrue()
        ->and($household->spots()->active()->exists())->toBeFalse();
});

test('retrieving twice keeps the first retrieval time', function () {
    [$household, , $member] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id]);
    $this->travelTo(now()->startOfSecond());

    $this->actingAs($member)->patch(route('spots.retrieve', $spot))->assertRedirect(route('home'));
    $first = $spot->fresh()->retrieved_at;

    $this->travel(5)->minutes();
    $this->actingAs($member)->patch(route('spots.retrieve', $spot))->assertRedirect(route('home'));

    expect($spot->fresh()->retrieved_at?->equalTo($first))->toBeTrue();
});

test("a non-member gets 404 and cannot retrieve another household's spot", function () {
    [$household] = householdWithMember();
    $spot = ParkingSpot::factory()->create(['household_id' => $household->id]);

    $this->actingAs(User::factory()->create())
        ->patch(route('spots.retrieve', $spot))
        ->assertNotFound();

    expect($spot->fresh()->retrieved_at)->toBeNull();
});
