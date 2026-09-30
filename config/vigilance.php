<?php

/*
|--------------------------------------------------------------------------
| Vigilance : doublons et signaux de fraude
|--------------------------------------------------------------------------
| Une alerte n'est jamais une sanction : un responsable la relit, puis la
| confirme ou l'écarte. Seule une alerte HAUTE ouverte sur un document
| impose l'accord d'un responsable (motif écrit) avant sa restitution.
*/

return [
    // Photo : distance maximale entre deux empreintes (0 = identiques, 64 = tout différent).
    'photo_max_distance' => 6,
    'photo_window_days'  => 365,

    // Un même compte publie beaucoup de documents trouvés.
    'serial_finder' => ['count' => 5, 'days' => 7],

    // Un même compte déclare perdues les pièces de nombreuses personnes.
    'many_identities' => ['count' => 4, 'days' => 30],

    'labels' => [
        'duplicate_document'      => 'Document en double',
        'reused_photo'            => 'Photo réutilisée pour un autre nom',
        'ocr_inconsistency'       => 'Incohérence relevée à la lecture de la pièce',
        'serial_finder'           => 'Nombreuses publications par un même compte',
        'duplicate_declaration'   => 'Déclaration en double',
        'many_identities'         => 'Nombreuses identités déclarées par un même compte',
        'presented_same_document' => 'Pièce présentée identique au document à restituer',
        'identity_mismatch'       => 'Pièce présentée au nom d\'une autre personne',
    ],
];
