<?php

use App\Models\Household;
use App\Models\ParkingSpot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function rawSpotRow(Household $household, array $overrides = []): array
{
    return [
        'household_id' => $household->id,
        'user_id' => $household->owner_id,
        'lat' => 51.5,
        'lng' => -0.12,
        'accuracy' => 10,
        'parked_at' => now(),
        'retrieved_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

test('the database refuses a second active spot in a household', function () {
    $household = Household::factory()->create();
    ParkingSpot::factory()->create(['household_id' => $household->id]);

    DB::table('parking_spots')->insert(rawSpotRow($household));
})->throws(UniqueConstraintViolationException::class);

test('retrieved spots and other households do not count against the one active spot', function () {
    $household = Household::factory()->create();
    $other = Household::factory()->create();
    ParkingSpot::factory()->create(['household_id' => $household->id]);

    DB::table('parking_spots')->insert([
        rawSpotRow($household, ['retrieved_at' => now()]),
        rawSpotRow($household, ['retrieved_at' => now()]),
        rawSpotRow($other),
    ]);

    expect(ParkingSpot::count())->toBe(4)
        ->and($household->spots()->active()->count())->toBe(1);
});
