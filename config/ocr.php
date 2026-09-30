<?php

/*
|--------------------------------------------------------------------------
| Lecture des pièces (OCR) — règles d'interprétation
|--------------------------------------------------------------------------
| Le service OCR ne fait que LIRE le texte ; DocTrack l'interprète ici :
| type de pièce, champs, zone MRZ (chiffres de contrôle OACI), cohérence,
| zones sensibles à masquer sur la photo publiée.
|
| Mots écrits SANS accents, en minuscules (le texte lu est normalisé ;
| les espaces sont ignorés car l'OCR les perd souvent : « Datedenaissance »).
*/

return [

    // Type de pièce : mots repérés sur la photo → nom exact dans document_types.
    'document_types' => [
        "Carte nationale d'identité" => ['carte d identite', 'identity card', 'cedeao', 'ecowas', 'carte nationale d identite'],
        'Passeport'                  => ['passeport', 'passport'],
        'Permis de conduire'         => ['permis de conduire', 'driving licence', 'driving license'],
        'Carte grise'                => ['carte grise', 'certificat d immatriculation'],
    ],

    // Code MRZ (1re lettre) → type de pièce.
    'mrz_document_types' => [
        'P' => 'Passeport',
        'I' => "Carte nationale d'identité",
        'A' => "Carte nationale d'identité",
        'C' => "Carte nationale d'identité",
    ],

    // Type de pièce lu → type de pièce d'identité enregistré à la restitution (Restitution::ID_TYPES).
    'restitution_id_types' => [
        "Carte nationale d'identité" => 'cni',
        'Passeport'                  => 'passeport',
        'Permis de conduire'         => 'permis',
    ],

    // Libellés des champs sur la face visible (français / anglais).
    'labels' => [
        'first_name'  => ['prenoms', 'prenom', 'given names', 'given name', 'forenames'],
        'last_name'   => ['nom', 'surname', 'nom de famille', 'last name'],
        'birth_date'  => ['date de naissance', 'date of birth', 'ne le', 'nee le', 'ne e le'],
        'sex'         => ['sexe', 'sex'],
        'expiry_date' => ['date d expiration', 'date of expiry', 'date d expiry', 'valable jusqu au', 'expire le'],
        'doc_number'  => ['n de la carte d identite', 'numero de la carte', 'n de la carte', 'n du passeport', 'passport no', 'numero du passeport', 'n du permis', 'numero', 'card no', 'document no'],
        'nin'         => ['nin', 'numero d identification nationale', 'n i n'],
    ],

    // Libellés à ne jamais prendre pour une valeur.
    'noise' => ['specimen', 'republique du senegal', 'photo', 'signature', 'cedeao', 'ecowas', 'passeport', 'passport'],

    // Seuils de qualité.
    'min_line_confidence' => 0.5,
    'blurry_average'      => 0.80,
    'min_lines'           => 4,

    // Marge autour d'une zone masquée (proportion de la hauteur de la ligne).
    'mask_padding' => 0.35,
];
