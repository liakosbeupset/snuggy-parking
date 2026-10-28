<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdInvite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HouseholdInvite>
 */
class HouseholdInviteFactory extends Factory
{
    /**
     * Define the model's default state: a pending invite sent by the household's owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(64),
            'invited_by' => fn (array $attributes) => Household::whereKey($attributes['household_id'])->value('owner_id'),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Indicate that the invite has expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
