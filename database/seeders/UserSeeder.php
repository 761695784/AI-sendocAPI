<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Comptes de démarrage.
 *
 * Les identifiants viennent du .env (SEED_ADMIN_EMAIL, SEED_ADMIN_PASSWORD…).
 * Sans mot de passe fourni, un mot de passe aléatoire est généré et affiché
 * une seule fois dans la console. Aucun mot de passe n'est écrit dans le code.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUser(
            role: 'Admin',
            email: env('SEED_ADMIN_EMAIL', 'admin@doctrack.local'),
            password: env('SEED_ADMIN_PASSWORD'),
            firstName: env('SEED_ADMIN_FIRSTNAME', 'Admin'),
            lastName: env('SEED_ADMIN_LASTNAME', 'DocTrack'),
            phone: env('SEED_ADMIN_PHONE', '+221700000000'),
        );

        // Comptes citoyens de démonstration : uniquement hors production.
        if (! app()->environment('production')) {
            $this->seedUser('SimpleUser', 'citoyen1@doctrack.local', env('SEED_DEMO_PASSWORD'), 'Awa', 'Ndiaye', '+221770000001');
            $this->seedUser('SimpleUser', 'citoyen2@doctrack.local', env('SEED_DEMO_PASSWORD'), 'Moussa', 'Diop', '+221770000002');
        }
    }

    private function seedUser(string $role, string $email, ?string $password, string $firstName, string $lastName, string $phone): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        $generated = false;
        if (empty($password)) {
            $password  = Str::password(16, symbols: false);
            $generated = true;
        }

        $user = User::create([
            'FirstName'          => $firstName,
            'LastName'           => $lastName,
            'Adress'             => 'Dakar',
            'Phone'              => $phone,
            'email'              => $email,
            'password'           => Hash::make($password),
            'email_verified_at'  => now(),
            'qr_code_token'      => (string) Str::uuid(),
            'qr_code_expires_at' => now()->addYear(),
        ]);
        $user->assignRole($role);

        if ($generated && $this->command) {
            $this->command->warn("[{$role}] {$email} — mot de passe généré : {$password}");
        }
    }
}
