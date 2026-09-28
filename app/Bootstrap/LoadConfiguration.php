<?php

namespace App\Bootstrap;

use Illuminate\Foundation\Bootstrap\LoadConfiguration as BaseLoadConfiguration;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Chargement de la configuration, sans le fichier database.php par défaut
 * de Laravel 11.
 *
 * Pourquoi : Laravel charge TOUJOURS ses fichiers de config internes
 * (vendor/laravel/framework/config/*.php) pour compléter ceux de l'application.
 * Sous PHP 8.5, vendor/laravel/framework/config/database.php utilise la
 * constante PDO::MYSQL_ATTR_SSL_CA, dépréciée : l'avertissement s'affiche
 * au démarrage et dans les pages, avant même que Laravel ne gère les erreurs.
 * Laravel 11.50 est la dernière version 11 : ce fichier ne sera pas corrigé.
 *
 * Notre config/database.php est complet (même clés que celui du framework,
 * avec la constante compatible PHP 8.5) : le fichier du framework est inutile.
 *
 * À supprimer lors du passage à Laravel 12 (qui corrige ce fichier) :
 * retirer aussi la ligne correspondante dans bootstrap/app.php.
 */
class LoadConfiguration extends BaseLoadConfiguration
{
    /** Fichiers du framework remplacés intégralement par ceux de l'application. */
    private const REPLACED_BY_APP = ['database'];

    protected function getBaseConfiguration()
    {
        $frameworkConfigDir = dirname((new ReflectionClass(BaseLoadConfiguration::class))->getFileName(), 5) . '/config';
        $config = [];

        foreach (Finder::create()->files()->name('*.php')->in($frameworkConfigDir) as $file) {
            $name = basename($file->getRealPath(), '.php');

            if (in_array($name, self::REPLACED_BY_APP, true) && is_file(config_path($name . '.php'))) {
                continue;
            }

            $config[$name] = require $file->getRealPath();
        }

        return $config;
    }
}
