# Tests réels : structures, comptes, lecture des pièces sur téléphone

## 1. Charger les structures et leurs comptes

Source : annuaire public ONLPL (221 structures : 57 police, 164 gendarmerie), fichier `database/data/structures_onlpl.json`.

1. Dans `.env`, choisissez le mot de passe commun des comptes de test : `SEED_DEMO_PASSWORD=...`
2. `php artisan db:seed --class=PilotStructuresSeeder`

| Compte | Adresse | Pour qui |
|---|---|---|
| Responsable | `chef.pol001@doctrack.local` | chaque structure (`SN-POL-001` → `pol001`, `SN-GEN-202` → `gen202`) |
| Agents | `agent1.pol001@…`, `agent2.pol001@…` | structures pilotes seulement (`SEED_PILOT_STRUCTURES`) |

- Structures pilotes par défaut : Commissariat Central de Dakar (`SN-POL-001`), Dieuppeul (`SN-POL-008`),
  Commissariat Urbain de Ziguinchor (`SN-POL-055`), Brigade Mixte de Ziguinchor (`SN-GEN-202`).
- La liste complète des comptes est écrite dans `storage/app/seed/comptes_structures.csv` (s'ouvre dans Excel).
- La commande est relançable : rien n'est créé en double. Pour ajouter des agents à d'autres structures,
  complétez `SEED_PILOT_STRUCTURES` et relancez.
- En production, les structures sont créées mais **aucun compte** : les vrais agents sont invités depuis l'écran Membres.

**À confirmer avant un usage réel** : l'annuaire ne donne ni adresse précise ni position GPS, et ne dit pas si la structure
est toujours active. Sans position GPS, une structure ne peut pas être proposée comme « la plus proche » :
renseignez la position des structures pilotes dans Administration → Structures.

## 2. Lecture automatique des pièces (OCR) sur votre PC

Le moteur de lecture tourne sur votre PC : les photos de pièces ne sortent pas de votre machine.

1. Installez **Python 3.12** (le moteur ne fonctionne pas avec Python 3.13 ou 3.14) :
   <https://www.python.org/downloads/release/python-31210/> → « Windows installer (64-bit) ».
2. Double-cliquez sur `ai/doctrack-ocr/lancer_ocr.bat` et laissez la fenêtre ouverte
   (première fois : 2 à 5 minutes d'installation). Elle affiche « OCR pret sur http://127.0.0.1:7860 ».
3. Dans `.env` :
   ```
   AI_OCR_PROVIDER=hf_space
   AI_OCR_URL=http://127.0.0.1:7860
   ```
   puis `php artisan config:clear`.

## 3. Tester depuis le téléphone

Le téléphone et le PC doivent être sur le **même Wi-Fi**.

1. Adresse du PC : `ipconfig` → « Adresse IPv4 » (exemple `192.168.1.20`).
2. API : `php artisan serve --host=0.0.0.0 --port=8000`
3. Front : `npm start` (écoute déjà sur tout le réseau).
4. Sur le téléphone : `http://192.168.1.20:4200`. En développement, le front appelle l'API sur la même adresse que la page.
5. Si la page ne s'ouvre pas : autorisez PHP et Node dans le pare-feu Windows (réseau privé).

Parcours à tester :
- **Citoyen** : connexion → *Publier un document trouvé* → *Prendre une photo* : l'appareil photo s'ouvre,
  la pièce est lue et le formulaire se remplit (champs surlignés en vert à vérifier).
- **Agent** : connexion avec `agent1.pol001@doctrack.local` → *Déposer un document* → même lecture.

Utilisez des pièces **fictives** ou la vôtre ; les scans non publiés sont supprimés au bout d'une heure (`doctrack:ocr-purge`).

## 4. Emails

| Événement | Destinataire |
|---|---|
| Correspondance confirmée (document chez un citoyen ou dans une structure) | propriétaire |
| Publication pouvant correspondre à une déclaration en ligne | déclarant |
| Demande de restitution | trouveur |
| Restitution acceptée (échange des contacts) | propriétaire et trouveur |
| Restitution refusée ou délai dépassé | propriétaire |
| Document signalé par QR code | propriétaire |
| Retrait attribué à une structure | adresse email de la structure |
| Rapport mensuel | super administrateurs |
| Code de vérification à l'inscription, mot de passe oublié, nouveau commentaire | utilisateur concerné |

1. Dans `.env`, configurez une vraie boîte d'envoi (voir `.env.example`, exemple Hostinger) et `FRONTEND_URL=http://192.168.1.6:4200`
   pour que les boutons des emails ouvrent votre front local ; puis `php artisan config:clear`.
2. `php artisan doctrack:mail-test votre@adresse --all` : un email de test puis un exemple de chaque email (données fictives).
3. Faites ensuite le vrai parcours ; chaque email envoyé ou en échec apparaît dans **Administration → Suivi des envois** (filtre « Email »), avec le message d'erreur s'il y en a un.
