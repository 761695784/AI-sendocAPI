<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'TypeName'           => 'Type ' . fake()->unique()->word(),
            'IdentificationSize' => '13 chiffres',
        ];
    }
}
