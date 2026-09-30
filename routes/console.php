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

// Demandes de restitution directe sans réponse du trouveur : la structure prend le relais.
Artisan::command('doctrack:expire-restitution-requests', function () {
    $n = app(\App\Services\Restitution\DirectRestitutionService::class)->expireOverdue();
    $this->info($n . ' demande(s) expirée(s).');
})->purpose('Expire les demandes de restitution sans réponse');

Schedule::command('doctrack:expire-restitution-requests')->hourly();

// Délais de réaction : relances aux structures et alertes à la hiérarchie.
Artisan::command('doctrack:sla-check', function () {
    $r = app(\App\Services\Stats\SlaMonitor::class)->run();
    $this->info("Relances : {$r['reminders']} — alertes : {$r['escalations']} — correspondances : {$r['review_reminders']}");
})->purpose('Relance les structures en retard sur les collectes et les vérifications');

Schedule::command('doctrack:sla-check')->hourly();

// Rapports d'activité du mois écoulé (national + chaque institution), envoyés aux responsables.
Artisan::command('doctrack:monthly-reports {--month= : AAAA-MM (défaut : mois précédent)}', function () {
    $month = $this->option('month') ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->option('month')) : null;
    $made  = app(\App\Services\Reports\ActivityReportService::class)->generateMonthly($month);
    $this->info(count($made) . ' rapport(s) généré(s) : ' . collect($made)->pluck('reference')->implode(', '));
})->purpose('Génère et envoie les rapports mensuels');

Schedule::command('doctrack:monthly-reports')->monthlyOn(1, '06:15');

// ── Assistant conversationnel ──

// Liste des phrases à enregistrer à l'avance (voix wolof générée sur Colab).
Artisan::command('doctrack:assistant-phrases {--lang=wo} {--missing : seulement celles sans fichier audio}', function () {
    $phrases = app(\App\Services\Assistant\Phrasebook::class);
    $lang    = $this->option('lang');
    $rows    = collect($phrases->staticPhrases($lang))
        ->when($this->option('missing'), fn ($c) => $c->reject(
            fn ($text, $key) => \Illuminate\Support\Facades\Storage::disk('local')->exists($phrases->audioPath($key, $lang))
        ))
        ->map(fn ($text, $key) => ['key' => $key, 'lang' => $lang, 'text' => $text])
        ->values();

    $path = "assistant/phrases_{$lang}.json";
    \Illuminate\Support\Facades\Storage::disk('local')->put($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info($rows->count() . " phrase(s) → storage/app/{$path}");
    $this->line('Déposez ensuite les fichiers {clé}.mp3 dans storage/app/' . trim(config('assistant.audio_dir'), '/') . "/{$lang}/");
})->purpose('Exporte les phrases de l\'assistant pour générer leur audio');

// Purge des conversations anciennes (confidentialité).
Artisan::command('doctrack:assistant-prune', function () {
    $days = (int) config('assistant.retention_days', 30);
    $n = \App\Models\AssistantConversation::where('last_activity_at', '<', now()->subDays($days))->delete();
    $this->info("{$n} conversation(s) de plus de {$days} jours supprimée(s).");
})->purpose('Supprime les conversations de l\'assistant au-delà de la durée de conservation');

Schedule::command('doctrack:assistant-prune')->dailyAt('03:30');

// Scans OCR jamais publiés : suppression des photos (pièces d'identité) après expiration.
Artisan::command('doctrack:ocr-purge', function () {
    $n = app(\App\Services\Ocr\OcrScanService::class)->purgeExpired();
    $this->info("{$n} scan(s) expiré(s) supprimé(s).");
})->purpose('Supprime les scans OCR non publiés et leurs images');

Schedule::command('doctrack:ocr-purge')->hourly();
