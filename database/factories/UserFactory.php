<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'FirstName'          => fake()->firstName(),
            'LastName'           => fake()->lastName(),
            'Adress'             => fake()->city(),
            'Phone'              => '+2217' . fake()->numerify('########'),
            'email'              => fake()->unique()->safeEmail(),
            'email_verified_at'  => now(),
            'password'           => static::$password ??= Hash::make('password'),
            'remember_token'     => Str::random(10),
            'qr_code_token'      => (string) Str::uuid(),
            'qr_code_expires_at' => now()->addYear(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at'       => null,
            'email_verification_code' => (string) random_int(100000, 999999),
        ]);
    }
}
