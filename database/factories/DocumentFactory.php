<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'OwnerFirstName'    => fake()->firstName(),
            'OwnerLastName'     => fake()->lastName(),
            'DocIdentification' => fake()->numerify('#############'),
            'Location'          => 'Dakar',
            'statut'            => 'non récupéré',
            'document_type_id'  => DocumentType::factory(),
            'user_id'           => User::factory(),
        ];
    }
}
