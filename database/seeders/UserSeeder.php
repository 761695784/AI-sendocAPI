<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // // Création d'un utilisateur admin
        $user = User::create([
            'FirstName' => 'Malcom',
            'LastName' => 'Marna',
            'Adress' => 'Dakar',
            'Phone' => '+221761695784',
            'email' => 'malcom70976@gmail.com',
            'password' => Hash::make('0123456789'),
        ]);

        // Assigner le rôle admin
        $user->assignRole('Admin');

         // Liste de données d'utilisateurs fictifs
         $users = [
            [
                'FirstName' => 'Malang',
                'LastName' => 'Marna',
                'Adress' => 'Dakar',
                'Phone' => '+221709764709',
                'email' => 'malangmarna2018@gmail.com',
                'password' => Hash::make('0123456789')
            ],
            [
                'FirstName' => 'Elisa',
                'LastName' => 'Leye',
                'Adress' => 'Dakar',
                'Phone' => '+221762956580',
                'email' => 'majeli061@gmail.com',
                'password' => Hash::make('0123456789')
            ],

        ];

        //  Créer les utilisateurs et leur assigner le rôle SimpleUser
         foreach ($users as $userData) {
            $user = User::create($userData);
            $user->assignRole('SimpleUser'); // Assigner le rôle SimpleUser
        }

    }
}
