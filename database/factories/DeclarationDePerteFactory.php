<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeclarationDePerte>
 */
class DeclarationDePerteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'Title'             => 'Perte de document',
            'FirstNameInDoc'    => fake()->firstName(),
            'LastNameInDoc'     => fake()->lastName(),
            'DocIdentification' => fake()->numerify('#############'),
            'document_type_id'  => DocumentType::factory(),
            'user_id'           => User::factory(),
        ];
    }
}
