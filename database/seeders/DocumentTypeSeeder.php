<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $documentTypes = [
            ['TypeName' => 'Carte nationale d\'identité', 'IdentificationSize' => '13 chiffres'],
            ['TypeName' => 'Passeport', 'IdentificationSize' => '9 caractères (lettres & chiffres)'],
            ['TypeName' => 'Permis de conduire', 'IdentificationSize' => '8 chiffres'],
            ['TypeName' => 'Carte grise', 'IdentificationSize' => '6 à 8 chiffres'],
            ['TypeName' => 'Certificat de naissance', 'IdentificationSize' => '5 à 6 chiffres (références de l\'état civil)'],
            ['TypeName' => 'Diplômes Baccalauréat', 'IdentificationSize' => '17 caractères'],
            ['TypeName' => 'Carte professionnelle', 'IdentificationSize' => '7 à 10 chiffres'],
            ['TypeName' => 'Carte bancaire', 'IdentificationSize' => '16 chiffres'],
            ['TypeName' => 'Attestation d\'assurance', 'IdentificationSize' => '8 chiffres'],
            ['TypeName' => 'Carnet de santé', 'IdentificationSize' => '6 à 8 chiffres (numéro de dossier)'],
            ['TypeName' => 'Carte d\'étudiant', 'IdentificationSize' => '7 à 10 chiffres'],
        ];

        DB::table('document_types')->insert($documentTypes);
    }
}
