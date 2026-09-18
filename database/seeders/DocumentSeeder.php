<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Document;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\Storage;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        for ($i = 0; $i < 5; $i++) {
            Document::create([
                'OwnerFirstName' => $faker->firstName, // Génère un prénom aléatoire
                'OwnerLastName' => $faker->lastName, // Génère un nom de famille aléatoire
                'Location' => $faker->city, // Génère un nom de ville aléatoire
                'statut' => 'non récupéré', // Statut par défaut
                'document_type_id' => rand(1, 4), // ID aléatoire pour le type de document (à ajuster selon tes types de document)
                'user_id' => rand(2, 7), // ID aléatoire pour l'utilisateur (à ajuster selon tes utilisateurs)
                'image' => $this->generateDummyImage(), // Génère une image fictive (voir fonction)
            ]);
        }
    }

    /**
     * Génère une image fictive pour remplir le champ 'image'.
     */
    private function generateDummyImage()
    {
        // Générer une image factice et la stocker dans 'storage/app/public/documents'
        $imageContent = 'DUMMY IMAGE CONTENT'; // Remplacer par un vrai contenu si nécessaire
        $imageName = 'dummy_image_' . uniqid() . '.jpg';
        Storage::disk('public')->put('documents/' . $imageName, $imageContent);

        return 'documents/' . $imageName; // Retourner le chemin de l'image
    }
}
