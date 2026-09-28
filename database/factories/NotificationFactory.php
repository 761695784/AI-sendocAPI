<?php

namespace Database\Factories;

use App\Models\DeclarationDePerte;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message'                 => 'Un document correspondant a été trouvé',
            'is_read'                 => false,
            'declaration_de_perte_id' => DeclarationDePerte::factory(),
        ];
    }
}
