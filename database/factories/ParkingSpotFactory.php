<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ParkingSpot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParkingSpot>
 */
class ParkingSpotFactory extends Factory
{
    /**
     * Define the model's default state: an active spot parked by the household's owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => fn (array $attributes) => Household::whereKey($attributes['household_id'])->value('owner_id'),
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
            'accuracy' => fake()->randomFloat(2, 3, 40),
            'parked_at' => now(),
        ];
    }

    /**
     * Indicate that the car has been collected.
     */
    public function retrieved(): static
    {
        return $this->state(['retrieved_at' => now()]);
    }
}
