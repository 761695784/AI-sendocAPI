<?php

namespace Database\Factories;

use App\Models\CitizenProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CitizenProfile>
 */
class CitizenProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name'  => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(fn (CitizenProfile $p) => $p->phone_hash ?: $p->setPhone('+2217' . fake()->numerify('########')));
    }
}
