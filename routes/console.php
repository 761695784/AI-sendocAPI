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

// Liste des phrases à enregistrer à l'avance (autre méthode : voix générée sur Colab, plus rapide avec une carte graphique).
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

// Voix de l'assistant : fait lire les phrases fixes par le service de synthèse (Adia_TTS, wolof) et enregistre les MP3.
Artisan::command('doctrack:assistant-voices
    {--lang=wo : langue}
    {--key=* : seulement ces phrases (clé de config/assistant.php)}
    {--force : régénérer aussi les audios déjà à jour}
    {--adopt : créer la fiche des MP3 déposés à la main (ZIP Colab), sans rien générer}
    {--status : afficher seulement l\'état}', function () {
    $voices = app(\App\Services\Assistant\VoiceLibrary::class);
    $lang   = $this->option('lang');
    if (! in_array($lang, config('assistant.languages', []), true)) {
        $this->error("Langue inconnue : {$lang}");

        return 1;
    }

    if ($this->option('adopt')) {
        $this->info($voices->adopt($lang) . ' fichier(s) déposé(s) à la main enregistré(s).');
    }

    $rows = collect($voices->status($lang));
    if ($this->option('status') || $this->option('adopt')) {
        $s = $voices->summary($lang);
        $this->table(['Phrase', 'État', 'Modèle', 'Durée (s)'], $rows->map(fn ($r) => [$r['key'], $r['state'], $r['model'] ?? '—', $r['duration'] ?? '—'])->all());
        $this->info("{$s['ready']} à jour · {$s['stale']} à refaire · {$s['missing']} sans audio · {$s['manual']} sans fiche — sur {$s['total']} phrases.");

        return 0;
    }

    if (! $voices->canGenerate($lang)) {
        $this->error($lang === 'wo'
            ? 'Synthèse vocale non configurée : AI_TTS_PROVIDER=hf_space et AI_TTS_URL dans le .env (voir ai/README.md).'
            : 'Le modèle de synthèse (Adia_TTS) ne lit que le wolof.');

        return 1;
    }

    $keys = $this->option('key');
    $todo = $rows
        ->when($keys, fn ($c) => $c->whereIn('key', $keys))
        ->when(! $this->option('force') && ! $keys, fn ($c) => $c->whereIn('state', [
            \App\Services\Assistant\VoiceLibrary::MISSING, \App\Services\Assistant\VoiceLibrary::STALE,
        ]))
        ->values();
    if ($todo->isEmpty()) {
        $this->info('Rien à générer : toutes les phrases ont un audio à jour (--force pour tout refaire).');

        return 0;
    }

    $this->info($todo->count() . ' phrase(s) à faire lire. Sur un service gratuit : 30 s à 2 min par phrase, la première réveille le service.');
    $failed = 0;
    foreach ($todo as $i => $row) {
        $this->output->write(sprintf('%2d/%d  %-28s ', $i + 1, $todo->count(), $row['key']));
        try {
            $meta = $voices->generate($lang, $row['key']);
            $this->line(sprintf('✔ %s s d\'audio en %s s', $meta['duration'] ?? '?', $meta['elapsed'] ?? '?'));
        } catch (\App\Services\Ai\AiUnavailableException $e) {
            $failed++;
            $this->line('✘ ' . $e->getMessage());
            // Service endormi ou en panne : inutile d'insister sur toutes les phrases.
            if ($failed >= 3) {
                $this->error('Trois échecs : arrêt. Ouvrez l\'adresse du service dans un navigateur, attendez "loaded": true, puis relancez la commande (elle reprend où elle s\'est arrêtée).');

                return 1;
            }
        }
    }
    $s = $voices->summary($lang);
    $this->info("Terminé : {$s['ready']} phrase(s) avec audio à jour sur {$s['total']}." . ($failed ? " {$failed} échec(s) : relancez la commande." : ''));

    return $failed ? 1 : 0;
})->purpose('Génère la voix (wolof) des phrases fixes de l\'assistant avec le service de synthèse');

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

// ── Vérification des emails : configuration SMTP et aperçu de chaque email envoyé aux citoyens ──
// php artisan doctrack:mail-test vous@exemple.sn          → un email de test (le serveur d'envoi fonctionne-t-il ?)
// php artisan doctrack:mail-test vous@exemple.sn --all    → en plus, un exemple de chaque email (données fictives)
Artisan::command('doctrack:mail-test {email} {--all : Envoyer aussi un exemple de chaque email de DocTrack}', function () {
    $email  = (string) $this->argument('email');
    $mailer = config('mail.default');
    $smtp   = config("mail.mailers.{$mailer}", []);

    $this->info('Configuration des emails');
    $this->table(['Réglage', 'Valeur'], [
        ['MAIL_MAILER', $mailer],
        ['MAIL_HOST', $smtp['host'] ?? '—'],
        ['MAIL_PORT', (string) ($smtp['port'] ?? '—')],
        ['Chiffrement', $smtp['scheme'] ?? $smtp['encryption'] ?? '—'],
        ['Expéditeur', config('mail.from.address') . ' (' . config('mail.from.name') . ')'],
        ['Lien des boutons', config('doctrack.frontend_url')],
    ]);
    if (in_array($mailer, ['log', 'array'], true)) {
        $this->warn("MAIL_MAILER={$mailer} : les emails ne partent pas, ils sont seulement écrits dans storage/logs/laravel.log.");
    }

    // Destinataire fictif (non enregistré en base) : les emails sont envoyés tout de suite, sans file d'attente.
    $to = new \App\Models\User();
    $to->forceFill(['FirstName' => 'Awa', 'LastName' => 'Test', 'email' => $email]);

    $send = function (string $label, callable $fn) {
        try {
            $fn();
            $this->line("  <info>✔</info> {$label}");

            return true;
        } catch (\Throwable $e) {
            $this->line("  <error>✘</error> {$label} — " . $e->getMessage());

            return false;
        }
    };

    $this->newLine();
    $ok = $send('Email de test', fn () => $to->notifyNow(new \App\Services\Notifications\TemplatedMailNotification(
        'DocTrack — email de test',
        ['Si vous lisez ce message, l\'envoi des emails de DocTrack fonctionne.', 'Envoyé le ' . now()->format('d/m/Y à H:i') . '.'],
    )));

    if (! $ok) {
        $this->error('L\'envoi a échoué : vérifiez MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD et MAIL_ENCRYPTION dans .env, puis php artisan config:clear.');

        return 1;
    }
    if (! $this->option('all')) {
        $this->info("Email de test envoyé à {$email}. Ajoutez --all pour recevoir un exemple de chaque email.");

        return 0;
    }

    // ── Exemples avec des données fictives (rien n'est enregistré) ──
    $type = (new \App\Models\DocumentType())->forceFill(['TypeName' => "Carte nationale d'identité"]);
    $structure = (new \App\Models\Organization())->forceFill(['name' => "Commissariat d'arrondissement de Dieuppeul", 'address' => 'Dieuppeul, Dakar', 'phone' => '+221775290074']);
    $doc = fn (?\App\Models\Organization $custody) => (new \App\Models\Document())
        ->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'reference' => 'DT-2026-000123', 'OwnerFirstName' => 'Awa', 'OwnerLastName' => 'Ndiaye'])
        ->setRelation('documentType', $type)
        ->setRelation('custodyOrganization', $custody);
    $declaration = (new \App\Models\DeclarationDePerte())->forceFill(['reference' => 'DP-2026-000045']);
    $match = fn ($document) => (new \App\Models\DocumentMatch())->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid()])
        ->setRelation('document', $document)->setRelation('declaration', $declaration);
    $finder = (new \App\Models\User())->forceFill(['FirstName' => 'Moussa', 'LastName' => 'Diop']);
    $request = (new \App\Models\RestitutionRequest())->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid()])
        ->setRelation('document', $doc(null))->setRelation('finder', $finder)->setRelation('requester', $to);
    $templated = function (string $key, array $params) use ($to) {
        $notifier = app(\App\Services\Notifications\Notifier::class);
        $tpl = $notifier->template('mail', $key);
        $to->notifyNow(new \App\Services\Notifications\TemplatedMailNotification(
            $notifier->render($tpl['subject'], $params),
            array_map(fn ($l) => $notifier->render($l, $params), $tpl['lines']),
        ));
    };
    $params = ['document_type' => "Carte nationale d'identité", 'owner_name' => 'A. Ndiaye', 'reference' => 'DT-2026-000123',
        'place' => 'Marché Sandaga, Dakar', 'distance' => '1,2 km', 'label' => 'Carte d\'identité', 'next' => 'Le commissariat vous appellera.'];

    $results = [
        $send('Correspondance confirmée — document chez un citoyen', fn () => $to->notifyNow(new \App\Notifications\MatchConfirmedNotification($match($doc(null))))),
        $send('Correspondance confirmée — document dans une structure', fn () => $to->notifyNow(new \App\Notifications\MatchConfirmedNotification($match($doc($structure))))),
        $send('Publication pouvant correspondre à une déclaration', fn () => \Illuminate\Support\Facades\Mail::to($email)
            ->send(new \App\Mail\DocumentPublishedNotification($doc(null), '+221770000002', '', 'Awa'))),
        $send('Restitution : contact du trouveur (au propriétaire)', fn () => $to->notifyNow(new \App\Notifications\RestitutionUpdateNotification($request, \App\Notifications\RestitutionUpdateNotification::CONTACT_FOR_OWNER, '+221 77 000 00 02'))),
        $send('Restitution : contact du propriétaire (au trouveur)', fn () => $to->notifyNow(new \App\Notifications\RestitutionUpdateNotification($request, \App\Notifications\RestitutionUpdateNotification::CONTACT_FOR_FINDER, '+221 77 000 00 01'))),
        $send('Restitution : refus ou délai dépassé', fn () => $to->notifyNow(new \App\Notifications\RestitutionUpdateNotification($request, \App\Notifications\RestitutionUpdateNotification::DECLINED, 'La structure la plus proche prend le relais.'))),
        $send('Document signalé par QR code', fn () => $templated('tag.found', $params)),
        $send('Retrait attribué à une structure', fn () => $templated('collection.assigned', $params)),
        $send('Compte créé au guichet — lien d\'activation', fn () => $to->notifyNow(new \App\Notifications\CitizenAccountActivationNotification('exemple-de-jeton', "Commissariat d'arrondissement de Dieuppeul"))),
    ];

    $this->newLine();
    $this->info(count(array_filter($results)) . ' / ' . count($results) . " exemples envoyés à {$email}.");
    $this->line('La demande de restitution au trouveur s\'essaie dans le vrai parcours (elle est enregistrée dans le journal des emails).');

    return 0;
})->purpose('Vérifie l\'envoi des emails (SMTP) et envoie un exemple de chaque email DocTrack');

// Assistant « Une question ? » : vérifie la configuration de l'IA et appelle chaque fournisseur (aucune clé affichée).
Artisan::command('doctrack:assistant-ai-check {question? : question à poser à l\'assistant (facultatif)}', function () {
    $chat      = app(\App\Services\Ai\ChatClient::class);
    $providers = config('assistant_faq.providers', []);

    $this->line('Configuration en cache : ' . (app()->configurationIsCached() ? 'OUI → après chaque modification du .env : php artisan config:clear' : 'non'));
    $sections = app(\App\Services\Assistant\KnowledgeBase::class)->sections();
    $this->line('Base de connaissances : ' . count($sections) . ' section(s) — ' . config('assistant_faq.knowledge_file'));
    if (! $providers) {
        $this->error('ASSISTANT_AI_PROVIDERS est vide : ajoutez par exemple ASSISTANT_AI_PROVIDERS=groq,glm dans le .env.');

        return 1;
    }
    $this->line('Fournisseurs (dans l\'ordre) : ' . implode(', ', $providers));

    $ok = 0;
    foreach ($providers as $provider) {
        $cfg = config("ai.endpoints.{$provider}");
        if (! is_array($cfg)) {
            $this->error("  {$provider} : inconnu (choix possibles : " . implode(', ', array_keys(config('ai.endpoints', []))) . ')');
            continue;
        }
        $this->line("  {$provider} : url={$cfg['url']} modèle={$cfg['model']} clé=" . (empty($cfg['key']) ? 'ABSENTE' : 'présente'));
        if (! $chat->configured($provider)) {
            $this->error("  {$provider} : non configuré (clé ou modèle manquant).");
            continue;
        }
        try {
            $r = $chat->complete($provider, [['role' => 'user', 'content' => 'Réponds seulement : OK']], 0, 400, 30);
            $this->info("  {$provider} : répond (" . $r['model'] . ') → ' . trim(mb_substr($r['text'], 0, 40)));
            $ok++;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->error("  {$provider} : ERREUR → " . mb_substr($msg, 0, 300));
            if (str_contains($msg, 'cURL error 60') || str_contains($msg, 'certificate')) {
                $this->warn('    PHP ne trouve pas les certificats SSL : renseignez curl.cainfo et openssl.cafile (fichier cacert.pem) dans php.ini, puis redémarrez PHP.');
            } elseif (str_contains($msg, '(401)') || str_contains($msg, '(403)')) {
                $this->warn('    Clé refusée : vérifiez la clé (sans espace ni guillemets) dans le .env.');
            } elseif (str_contains($msg, '(404)')) {
                $this->warn('    Modèle introuvable ou non autorisé pour cette clé. Modèles accessibles avec votre clé :');
                try {
                    $ids = \Illuminate\Support\Facades\Http::timeout(30)->withToken($cfg['key'])->acceptJson()
                        ->get(rtrim($cfg['url'], '/') . '/models')->json('data.*.id') ?? [];
                    sort($ids);
                    $this->line($ids ? '    - ' . implode("\n    - ", $ids) : '    (liste vide : vérifiez les réglages de votre compte chez le fournisseur)');
                } catch (\Throwable $e) {
                    $this->line('    (liste indisponible : ' . mb_substr($e->getMessage(), 0, 120) . ')');
                }
            } elseif (str_contains($msg, '(429)')) {
                $this->warn('    Quota atteint ou épuisé chez ce fournisseur.');
            }
        }
    }

    if ($question = $this->argument('question')) {
        \Illuminate\Support\Facades\Cache::forget('assistant-faq:' . sha1(\App\Services\Assistant\TextNormalizer::normalize(\App\Services\Assistant\FaqResponder::mask($question))));
        $responder = app(\App\Services\Assistant\FaqResponder::class);
        $answer    = $responder->answerWithAi($question, 'check-' . \Illuminate\Support\Str::random(8), 'diagnostic');
        foreach ($responder->trace as $t) {
            $this->line("  [{$t['provider']}] {$t['verdict']}" . ($t['raw'] !== '' ? "\n    brut : " . str_replace("\n", ' ', $t['raw']) : ''));
        }
        $answer
            ? $this->info("Réponse IA ({$answer['provider']}, sources : " . implode(', ', array_column($answer['entries'], 'id')) . ") :\n" . $answer['answer'])
            : $this->warn('Pas de réponse IA retenue (hors base de connaissances, réponse rejetée par les garde-fous, ou plafond du jour atteint).');
    }

    return $ok > 0 ? 0 : 1;
})->purpose('Vérifie les clés et la connexion des fournisseurs IA de l\'assistant');

// Banc d'essai de l'assistant : pose les questions de resources/assistant/questions-citoyens.txt
// (comme un citoyen anonyme, sans rien enregistrer) et compare avec la réponse attendue.
Artisan::command('doctrack:assistant-eval
    {--file= : fichier de questions (défaut : resources/assistant/questions-citoyens.txt)}
    {--limit=0 : nombre maximum de questions}
    {--sleep=2 : pause en secondes après un appel IA (quota gratuit par minute)}
    {--fresh : ignorer les réponses IA déjà en cache}', function () {
    $file = $this->option('file') ?: resource_path('assistant/questions-citoyens.txt');
    if (! is_file($file)) {
        $this->error("Fichier introuvable : {$file}");

        return 1;
    }
    $cases = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '||')) {
            continue;
        }
        [$question, $expected] = array_map('trim', explode('||', $line, 2));
        $cases[] = [$question, $expected];
    }
    if ((int) $this->option('limit') > 0) {
        $cases = array_slice($cases, 0, (int) $this->option('limit'));
    }

    // Le banc d'essai ne doit pas buter sur les plafonds prévus pour le public.
    config(['assistant_faq.limits' => ['per_conversation' => 100000, 'per_ip_per_day' => 100000, 'global_per_day' => 100000]]);
    $this->line('IA : ' . (config('assistant_faq.providers') ? implode(', ', config('assistant_faq.providers')) : 'aucune (fiches et parcours seulement)'));

    $rows  = [];
    $count = ['OK' => 0, 'KO' => 0, 'A VERIFIER' => 0];
    foreach ($cases as $i => [$question, $expected]) {
        if ($this->option('fresh')) {
            \Illuminate\Support\Facades\Cache::forget('assistant-faq:' . sha1(\App\Services\Assistant\TextNormalizer::normalize(\App\Services\Assistant\FaqResponder::mask($question))));
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $conv  = \App\Models\AssistantConversation::create(['channel' => 'web', 'language' => 'fr']);
            $reply = app(\App\Services\Assistant\AssistantEngine::class)->handle($conv, $question, null, ['ip' => 'banc-essai']);
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();   // rien n'est enregistré
        }

        $first = $reply->messages[0]['key'] ?? '';
        $faq   = $reply->meta['faq'] ?? null;
        $got   = match (true) {
            $faq && ($faq['source'] ?? '') === 'ai' => 'ia:' . implode('+', $faq['entries'] ?? []),
            str_starts_with($first, 'faq:')         => $first,
            $first === 'no_answer_contact'          => 'contact',
            in_array($first, ['not_understood', 'offer_human'], true) => 'incompris',
            default                                 => 'parcours:' . ($reply->intent ?? $first),
        };

        $verdict = match (true) {
            $expected === 'ia'                    => str_starts_with($got, 'ia:') || str_starts_with($got, 'faq:') ? 'OK' : 'KO',
            str_starts_with($expected, 'faq:')    => $got === $expected || (str_starts_with($got, 'ia:') && str_contains($got, substr($expected, 4))) ? 'OK'
                : (str_starts_with($got, 'ia:') || str_starts_with($got, 'faq:') ? 'A VERIFIER' : 'KO'),
            $expected === 'contact'               => $got === 'contact' ? 'OK' : (str_starts_with($got, 'ia:') ? 'A VERIFIER' : 'KO'),
            default                               => $got === $expected ? 'OK' : 'KO',
        };
        $count[$verdict]++;

        $answer = str_replace("\n", ' ', $reply->text());
        $this->line(sprintf('%3d. [%s] %s', $i + 1, $verdict, $question));
        $this->line("     attendu : {$expected} — obtenu : {$got}");
        if ($verdict !== 'OK') {
            $this->line('     réponse : ' . mb_substr($answer, 0, 220));
        }
        $rows[] = [$i + 1, $question, $expected, $got, $verdict, $answer];

        if (str_starts_with($got, 'ia:') || $got === 'contact') {
            sleep(max(0, (int) $this->option('sleep')));
        }
    }

    $csv = fopen(storage_path('app/assistant-eval.csv'), 'w');
    fwrite($csv, "\xEF\xBB\xBF"); // accents lisibles dans Excel
    fputcsv($csv, ['n', 'question', 'attendu', 'obtenu', 'verdict', 'reponse'], ';');
    foreach ($rows as $row) {
        fputcsv($csv, $row, ';');
    }
    fclose($csv);

    $total = count($rows);
    $this->newLine();
    $this->info("Résultat : {$count['OK']}/{$total} OK — {$count['A VERIFIER']} à vérifier — {$count['KO']} KO");
    $this->line('Rapport complet : ' . storage_path('app/assistant-eval.csv'));

    return 0;
})->purpose('Pose les 100 questions du banc d\'essai à l\'assistant et produit un rapport');
