<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ── Planification des backups DocTrack ──

// Backup complet chaque nuit à 02h00
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info(
            'DocTrack backup réussi — ' . now()
        );
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error(
            'DocTrack backup ÉCHOUÉ — ' . now()
        );
    });

// Nettoyage des vieux backups à 01h00
Schedule::command('backup:clean')
    ->dailyAt('01:00');

// Monitoring santé à 03h00
Schedule::command('backup:monitor')
    ->dailyAt('03:00');

// Nettoyage logs activité (90 jours) chaque semaine
Schedule::command('activitylog:clean --days=90')
    ->weekly();
