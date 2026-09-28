<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Garde-fou PHP 8.5 : le database.php par défaut de Laravel 11 (constante
 * PDO::MYSQL_ATTR_SSL_CA dépréciée) ne doit jamais être chargé.
 * Voir app/Bootstrap/LoadConfiguration.php.
 */
class ConfigurationTest extends TestCase
{
    public function test_framework_default_database_config_is_not_loaded(): void
    {
        $frameworkFile = realpath(base_path('vendor/laravel/framework/config/database.php'));

        $this->assertNotContains($frameworkFile, get_included_files());
    }

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
