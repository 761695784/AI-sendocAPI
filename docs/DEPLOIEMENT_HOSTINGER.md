# Mise en production de la V2 (API sur Hostinger mutualisé, front sur Netlify)

**Ordre obligatoire : l'API d'abord, le front ensuite.** Le nouveau front appelle `/api/v2/...` : s'il part
avant l'API, le site en ligne affiche des erreurs.

## 0. Avant tout : sauvegarder et répéter en local

1. hPanel → Bases de données → phpMyAdmin → base de DocTrack → **Exporter** (fichier `.sql`). Gardez ce fichier.
2. Sauvegardez aussi le dossier `storage/app` du serveur (photos des publications, certificats) et le fichier `.env`.
3. **Répétition** sur votre PC :
   - créez une base vide `doctrack_prod_test`, importez le `.sql` ;
   - dans `.env` local, pointez `DB_DATABASE=doctrack_prod_test` ;
   - `php artisan migrate` : les 45 migrations V2 doivent passer sur les vraies données V1
     (rôles renommés, numéros de documents chiffrés, correspondances recalculées) ;
   - connectez-vous avec un vrai compte, ouvrez une publication, une déclaration, un certificat.
   Si une migration échoue, envoyez-moi le message : on corrige avant de toucher au serveur.

## 1. API (Hostinger)

### Pré-requis du serveur
- **PHP 8.2 ou plus** (hPanel → Avancé → Configuration PHP). Extensions : `gd`, `intl`, `zip`, `mbstring`, `bcmath`, `fileinfo`, `pdo_mysql`.
- **Accès SSH** (hPanel → Avancé → Accès SSH) : nécessaire pour `composer` et `php artisan`.

### Fichier `.env` du serveur (à compléter, ne jamais committer)
Gardez `APP_KEY`, `JWT_SECRET`, `DB_*` actuels. Ajoutez ou vérifiez :

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://doctrack.majeliconnect.com
FRONTEND_URL=https://sendoctrack.netlify.app
CERTIFICATE_VERIFICATION_URL=https://sendoctrack.netlify.app/verifier
QUEUE_CONNECTION=sync

# À générer UNE FOIS (php -r "echo bin2hex(random_bytes(32));") et ne JAMAIS changer :
# il sert à retrouver les numéros de documents. À mettre AVANT les migrations.
DOCTRACK_HASH_KEY=

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=sendoctrack@gmail.com
MAIL_PASSWORD=<mot de passe d'application Gmail>
MAIL_FROM_ADDRESS=sendoctrack@gmail.com
MAIL_FROM_NAME=DocTrack

AI_OCR_PROVIDER=none          # voir § 3
BILLING_MODE=pilot
API_DOCS_ENABLED=false
```

### Commandes (SSH, dans le dossier de l'API)
```
php artisan down
git pull                                  # ou envoi des fichiers si vous n'utilisez pas Git sur le serveur
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=DocumentTypeSeeder --force
php artisan db:seed --class=TerritorySeeder --force
php artisan db:seed --class=OrganizationTypeSeeder --force
php artisan db:seed --class=OrganizationSeeder --force      # Police et Gendarmerie (sans comptes en production)
php artisan db:seed --class=PilotStructuresSeeder --force   # les 221 structures (sans comptes en production)
php artisan db:seed --class=BillingSeeder --force
php artisan storage:link
php artisan optimize
php artisan up
php artisan doctrack:mail-test sendoctrack@gmail.com
```
Ne lancez **pas** `php artisan db:seed` tout court en production (il créerait des comptes de démonstration).

En production, les structures sont créées **sans comptes**. Les agents sont ajoutés depuis l'écran
**Membres** de chaque structure : ils reçoivent un email pour choisir leur mot de passe.

### Tâches planifiées (hPanel → Avancé → Tâches Cron), toutes les minutes
```
/usr/bin/php /home/<compte>/domains/doctrack.majeliconnect.com/<dossier-api>/artisan schedule:run
```
(relances des délais, rapports mensuels, purge des scans OCR, sauvegardes).

### Vérifications
- `https://doctrack.majeliconnect.com/api/v2/assistant/capabilities` répond en JSON ;
- connexion super admin, liste des publications, création d'une déclaration au guichet + certificat PDF.

## 2. Front (Netlify)

- Le dépôt contient `netlify.toml` : construction `npx ng build`, Node 24, dossier publié
  `dist/doc-track-front/browser` (site statique). La construction a été vérifiée (aucune erreur, aucun dépassement).
- Netlify → *Site configuration* → *Build & deploy* : si une section **Runtime** affiche « Angular Runtime »,
  **retirez-la**. Ce runtime sert au rendu serveur et refuse les `server.ts` personnalisés ;
  DocTrack est publié en statique sur Netlify.
- Prudence : poussez d'abord sur une **branche** (ex. `v2`) : Netlify en fait un aperçu sans toucher au site en ligne.
  Si l'aperçu se construit, fusionnez dans la branche principale **après** la mise à jour de l'API.

## 3. Lecture des pièces (OCR) en production

Un hébergement mutualisé ne peut pas faire tourner le moteur Python (RapidOCR).
- **Maintenant** : `AI_OCR_PROVIDER=none`. Le scan reste utilisable : la photo est envoyée, le formulaire se remplit à la main
  (message « Lecture automatique indisponible »). Pour les démonstrations, gardez l'OCR sur votre PC.
- **Ensuite**, au choix :
  - service gratuit de conteneurs (ex. Render, offre gratuite : 512 Mo, mise en veille après 15 min, réveil ≈ 1 min) —
    pratique pour un pilote, mais des photos de pièces d'identité transitent chez un prestataire étranger :
    à éviter pour de vraies pièces sans accord de la CDP ;
  - **VPS** (ex. Hostinger KVM 1 : 1 vCPU, 4 Go) : API + OCR sur le même serveur, données maîtrisées. C'est la cible.
  Dans les deux cas : `AI_OCR_PROVIDER=hf_space`, `AI_OCR_URL=<adresse du service>`, `AI_OCR_TOKEN=<clé partagée>`.
