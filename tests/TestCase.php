<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function citizen(array $attributes = []): User
    {
        return tap(User::factory()->create($attributes))->assignRole('SimpleUser');
    }

    protected function admin(array $attributes = []): User
    {
        return tap(User::factory()->create($attributes))->assignRole('Admin');
    }

    /**
     * Chaque requête simulée repart sans utilisateur en mémoire, comme une
     * vraie requête HTTP (sinon le guard JWT garde l'utilisateur précédent).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        // L'en-tête d'authentification ne vaut que pour la requête suivante.
        $this->defaultHeaders = [];

        return $response;
    }

    /**
     * Authentifie la requête avec un vrai jeton JWT (comme le front).
     */
    protected function asUser(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . JWTAuth::fromUser($user));
    }
}
