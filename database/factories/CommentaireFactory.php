<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Commentaire>
 */
class CommentaireFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contenu'     => fake()->sentence(),
            'user_id'     => User::factory(),
            'document_id' => Document::factory(),
        ];
    }
}
