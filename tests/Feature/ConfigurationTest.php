<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Notre config/database.php doit rester complet (toutes les connexions),
 * compatible PHP 8.2 à 8.5.
 */
class ConfigurationTest extends TestCase
{
    public function test_application_database_config_is_complete(): void
    {
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $connection) {
            $this->assertIsArray(config("database.connections.{$connection}"), "Connexion {$connection} absente");
        }
        $this->assertArrayHasKey('busy_timeout', config('database.connections.sqlite'));
        $this->assertArrayHasKey('persistent', config('database.redis.options'));
        $this->assertNotEmpty(config('database.migrations'));
    }
}
