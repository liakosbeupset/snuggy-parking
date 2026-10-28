<?php

namespace Database\Factories;

use App\Actions\CreatePersonalHousehold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Whether afterCreating gives the user a personal household (I7).
     */
    protected bool $withPersonalHousehold = true;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'google_id' => (string) fake()->unique()->numerify('##################'),
            'avatar' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user should be created without a personal household.
     */
    public function withoutPersonalHousehold(): static
    {
        $factory = $this->newInstance();
        $factory->withPersonalHousehold = false;

        return $factory;
    }

    /**
     * Carry the personal-household flag across the factory's immutable copies.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function newInstance(array $arguments = []): static
    {
        $factory = parent::newInstance($arguments);
        $factory->withPersonalHousehold = $this->withPersonalHousehold;

        return $factory;
    }

    /**
     * Give each created user a personal household before any other afterCreating callback.
     * Not a configure() hook: those closures stay bound to the first factory copy, so they
     * could not see a flag set later by withoutPersonalHousehold().
     *
     * @param  Collection<int, User>  $instances
     */
    protected function callAfterCreating(Collection $instances, ?Model $parent = null): void
    {
        if ($this->withPersonalHousehold) {
            $instances->each(fn (User $user) => app(CreatePersonalHousehold::class)($user));
        }

        parent::callAfterCreating($instances, $parent);
    }
}
