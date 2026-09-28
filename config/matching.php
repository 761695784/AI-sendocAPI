<?php

/*
|--------------------------------------------------------------------------
| Moteur de correspondance DocTrack
|--------------------------------------------------------------------------
| Tout est réglable ici, sans toucher au code. Chaque correspondance
| enregistre le détail de son score (score_details) pour être expliquée.
*/

return [
    // Moteur utilisé (un moteur IA pourra être branché ici plus tard).
    'engine'  => env('MATCHING_ENGINE', 'rules'),
    'version' => 'rules-1.0',

    'thresholds' => [
        // ≥ review : correspondance probable → validation par un agent, puis notification.
        'review'  => (int) env('MATCHING_REVIEW_THRESHOLD', 75),
        // ≥ propose : possible → visible par l'agent uniquement, aucune notification.
        'propose' => (int) env('MATCHING_PROPOSE_THRESHOLD', 55),
        // Même numéro de document + même type : confirmation automatique.
        'auto_confirm_on_number_match' => true,
    ],

    // Poids des critères (sans numéro de document). Total = 100.
    'weights' => [
        'names'      => 70,
        'birth_date' => 15,
        'temporal'   => 10,
        'location'   => 5,
    ],

    // Numéros différents (connus des deux côtés) : le score est plafonné.
    'number_mismatch_cap' => 30,
    // Date de naissance différente (connue des deux côtés) : plafond.
    'birth_date_mismatch_cap' => 45,
    // Tolérance (jours) si le document est "trouvé" avant la date de perte déclarée.
    'temporal_tolerance_days' => 3,

    /*
    | Équivalences de graphies (prénoms et noms fréquents au Sénégal).
    | Chaque groupe = variantes considérées comme le même nom.
    | Ajoutez des groupes au fil des cas rencontrés sur le terrain.
    */
    'equivalences' => [
        ['mouhamadou', 'mouhammadou', 'mohamadou', 'mouhamed', 'mouhammed', 'mohamed', 'mohammed', 'muhammad', 'mohammad', 'mamadou', 'modou', 'mouhamad', 'mohamet'],
        ['abdoulaye', 'abdoulay', 'abdullahi', 'abdallah', 'abdoulahi', 'ablaye', 'laye'],
        ['ousmane', 'ousman', 'usman', 'oumane', 'othmane'],
        ['ibrahima', 'ibrahim', 'ibrahime', 'ebrahima'],
        ['cheikh', 'cheick', 'cheik', 'sheikh', 'seikh', 'shaikh'],
        ['aminata', 'aminatou', 'amina'],
        ['fatou', 'fatoumata', 'fatimata', 'fatima', 'fatim'],
        ['aissatou', 'aissata', 'aïssatou'],
        ['aicha', 'aisha', 'aïcha'],
        ['khadija', 'khadidiatou', 'khady', 'hadja', 'khadidja'],
        ['mariama', 'mariam', 'mariem', 'maryam'],
        ['serigne', 'sering', 'seriñ'],
        ['babacar', 'bacar', 'bakar', 'boubacar', 'aboubacar', 'abubakar'],
        ['souleymane', 'souleyman', 'sulayman', 'suleiman', 'soulaymane'],
        ['ndeye', 'ndey', 'ndèye'],
        ['diallo', 'jallo', 'djallo'],
        ['ndiaye', 'ndiay', 'njaay', 'ndjaye'],
        ['diop', 'joop', 'djop'],
        ['diouf', 'juuf', 'djouf'],
        ['diagne', 'jaañ', 'jagne'],
        ['cisse', 'cissé', 'sisse', 'cissè'],
        ['toure', 'touré', 'ture'],
        ['mbaye', 'mbay', 'mbaay'],
        ['thiam', 'tiam', 'caam'],
        ['sarr', 'sar'],
        ['fall', 'faal'],
        ['seck', 'sek', 'sèk'],
        ['sow', 'so'],
        ['ba', 'bah', 'baa'],
        ['faye', 'fay', 'faay'],
        ['ndour', 'ndure', 'nduur'],
        ['gueye', 'guèye', 'gey', 'geye'],
        ['kane', 'kan'],
        ['sy', 'sii'],
    ],
];
