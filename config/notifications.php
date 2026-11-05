<?php

/*
|--------------------------------------------------------------------------
| Textes des notifications DocTrack
|--------------------------------------------------------------------------
| Modifiables sans toucher au code. Les mots précédés de « : » sont
| remplacés automatiquement (ex. :reference → DT-2026-000123).
| SMS : rester court (160 caractères = 1 SMS ; au-delà, 2 SMS facturés).
| Pour ajouter une langue plus tard : dupliquer ce fichier par langue.
*/

return [

    'sms' => [
        // Guichet : déclaration de perte
        'counter_declaration.registered' => 'DocTrack : déclaration :reference enregistrée par :structure. Certificat vérifiable : :verify_url . Vous serez prévenu si votre pièce est retrouvée.',
        'counter_declaration.cancelled'  => 'DocTrack : votre déclaration :reference a été annulée par :structure. Motif : :reason',
        'online_declaration.certified'   => 'DocTrack : votre déclaration en ligne :reference a été certifiée par :structure. Certificat de perte vérifiable : :verify_url',

        // Guichet : document déposé par un trouveur
        'found_document.deposited'       => 'DocTrack : merci d\'avoir déposé ce document à :structure (réf. :reference). Votre geste aide son propriétaire.',

        // Collecte des documents publiés en ligne
        'collection.assigned.structure'  => 'DocTrack : nouveau document à récupérer (:document_type, :place:distance). Voir l\'espace agent, dossier :reference.',
        'collection.assigned.finder'     => 'DocTrack : merci ! :structure:structure_phone va vous appeler pour récupérer le document que vous avez trouvé.',
        'collection.planned.drop_off'    => 'DocTrack : :structure vous attend :when pour recevoir le document (:address). Merci !',
        'collection.planned.agent_pickup'=> 'DocTrack : un agent de :structure passera récupérer le document :when:place. Merci !',
        'collection.collected.finder'    => 'DocTrack : :structure a bien reçu le document. Merci pour votre geste citoyen !',

        // Correspondances et restitution
        'match.confirmed.online'         => 'DocTrack : votre :document_type au nom de :owner_name a été retrouvé. Demandez la restitution au trouveur sur :url',
        'match.confirmed.custody'        => 'DocTrack : votre :document_type au nom de :owner_name a été retrouvé. Il est conservé par :structure:structure_phone. Réf. :reference.',
        'match.custody_changed'          => 'DocTrack : votre document au nom de :owner_name est maintenant conservé par :structure:structure_phone. Présentez-vous avec une pièce justificative. Réf. :reference.',
        'restitution.requested'          => 'DocTrack : le propriétaire du document au nom de :owner_name (confirmé par DocTrack) demande à le récupérer. Acceptez-vous d\'être mis en contact ? Répondez ici : :url',
        'restitution.accepted.owner'     => 'DocTrack : le trouveur accepte de vous remettre le document au nom de :owner_name. Contactez :finder_name au :finder_phone. Rencontrez-vous dans un lieu public, sans jamais payer.',
        'restitution.accepted.finder'    => 'DocTrack : merci ! Le propriétaire (:owner_first_name, :owner_phone) va vous contacter pour le document au nom de :owner_name. Une fois remis, indiquez-le sur DocTrack.',
        // Demande de restitution d'un document publié par un citoyen : coordonnées échangées aussitôt.
        'restitution.contact.finder'     => 'DocTrack : :owner_first_name (:owner_phone) réclame le document au nom de :owner_name que vous avez trouvé. Rencontrez-vous dans un lieu public, puis marquez la publication « récupéré ».',
        'restitution.contact.owner'      => 'DocTrack : contactez :finder_name au :finder_phone pour récupérer le document au nom de :owner_name. Lieu public conseillé ; ne payez jamais.',
        'restitution.requested.check'    => 'DocTrack : :requester_name dit être le propriétaire du document au nom de :owner_name que vous avez trouvé. Comparez avec le document, puis acceptez ou refusez : :url',
        // Demande de restitution d'un document conservé par une structure.
        'restitution.counter_request.structure' => 'DocTrack : :requester demande à retirer le document :reference (:owner_name) conservé chez vous. Il viendra avec une pièce justificative.',
        'restitution.counter_request.owner'     => 'DocTrack : votre demande est transmise à :structure:structure_phone. Présentez-vous avec une pièce d\'identité et la référence :reference. Adresse : :address',
        'restitution.declined'           => 'DocTrack : le trouveur n\'a pas accepté la remise directe. :next',
        // Restitution officielle au guichet
        'restitution.official.owner'     => 'DocTrack : votre document au nom de :owner_name vous a été remis par :structure. Décharge :reference, vérifiable ici : :verify_url',
        'restitution.official.finder'    => 'DocTrack : le document que vous avez trouvé a été rendu à son propriétaire par :structure. Merci pour votre geste citoyen !',

        // QR code par document (v2)
        'tag.found.both'                 => 'DocTrack : votre :document_type:label a été trouvé(e) (:place). :structure va le récupérer. Vous pouvez aussi joindre :finder_name au :finder_phone.',
        'tag.found.collection'           => 'DocTrack : votre :document_type:label a été trouvé(e) (:place). :structure va le récupérer auprès du trouveur et vous préviendra.',
        'tag.found.contact'              => 'DocTrack : votre :document_type:label a été trouvé(e) (:place). Contactez :finder_name au :finder_phone pour le récupérer.',
        'tag.found.minimal'              => 'DocTrack : votre :document_type:label a été trouvé(e) (:place). Surveillez vos notifications DocTrack.',
        'tag.collected'                  => 'DocTrack : votre :document_type est conservé par :structure:structure_phone. Présentez-vous avec une pièce justificative. Réf. :reference.',

        // QR code personnel scanné par un trouveur / ancien avertissement V1
        'qr.found'                       => 'DocTrack : un de vos documents a été trouvé. Contactez le trouveur au :finder_phone.',
        'v1.match_found'                 => 'DocTrack : un document correspondant à votre déclaration a été trouvé : :owner_name. Consultez-le ici : :url',
        'restitution.expired'            => 'DocTrack : le trouveur n\'a pas répondu à temps. :next',
    ],

    'inbox' => [
        'collection.assigned' => [
            'title' => 'Document à récupérer — :document_type',
            'body'  => 'Un citoyen a trouvé un document (:owner_name) à :place:distance. Appelez-le pour organiser le dépôt ou le passage d\'un agent.',
        ],
        'collection.unassigned' => [
            'title' => 'Collecte sans structure',
            'body'  => 'Aucune structure disponible pour le document :reference (:place). Affectez-le manuellement.',
        ],
        'collection.direct_restitution' => [
            'title' => 'Remise directe en cours',
            'body'  => 'Le propriétaire et le trouveur du document :reference organisent une remise directe. :detail',
        ],
        'collection.sla_reminder' => [
            'title' => 'Relance — trouveur pas encore appelé',
            'body'  => 'Le dossier :reference (:place) vous a été affecté il y a :hours h et le trouveur n\'a pas encore été appelé.',
        ],
        'collection.sla_escalation' => [
            'title' => 'Alerte — collecte en retard à :structure',
            'body'  => ':structure n\'a pas appelé le trouveur du dossier :reference depuis :hours h. Vous pouvez relancer la structure ou transférer le dossier.',
        ],
        'match.review_reminder' => [
            'title' => 'Relance — correspondance à vérifier',
            'body'  => 'La correspondance :reference (score :score) attend une vérification depuis :hours h.',
        ],
        'vigilance.alert' => [
            'title' => 'Vigilance : :kind',
            'body'  => ':reference — :message Ouvrez l\'alerte pour la confirmer ou l\'écarter.',
        ],
        'assistant.handoff' => [
            'title' => 'Assistant : un citoyen demande un agent',
            'body'  => ':name (:phone) souhaite parler à un agent (échange en :language). Dernier message : « :question ». Conversation :conversation.',
        ],
        'report.narrative_pending' => [
            'title' => 'Rapport à valider — :period (:scope)',
            'body'  => 'La synthèse rédigée avec l\'aide de l\'IA pour le rapport :reference attend votre validation. Relisez-la, corrigez si besoin, puis validez ou refusez : le rapport sera alors envoyé.',
        ],
        'report.monthly' => [
            'title' => 'Rapport d\'activité — :period (:scope)',
            'body'  => ':summary Alertes : :alerts. Référence :reference.',
        ],
        'restitution.counter_request' => [
            'title' => 'Demande de retrait — :document_type :reference',
            'body'  => ':requester souhaite retirer le document au nom de :owner_name (réf. :reference) conservé dans votre structure. Préparez le document : vérifiez son identité à son passage, puis faites la restitution (décharge).',
        ],
        'match.pending_review' => [
            'title' => 'Correspondance à vérifier (score :score)',
            'body'  => 'Déclaration :declaration_reference ↔ document :reference (:owner_name). Comparez les deux fiches puis confirmez ou rejetez.',
        ],
    ],

    'mail' => [
        'report.monthly' => [
            'subject' => 'DocTrack — rapport d\'activité :period (:scope)',
            'lines'   => [
                ':summary',
                'Nombre d\'alertes : :alerts.',
                'Le rapport complet (PDF et Excel, référence :reference) est disponible dans votre espace DocTrack, rubrique Rapports.',
            ],
        ],
        'tag.found' => [
            'subject' => 'DocTrack — votre document a été trouvé',
            'lines'   => [
                'Quelqu\'un a scanné le QR code collé sur votre :document_type:label.',
                ':next',
                'Si vous ne reconnaissez pas ce signalement, désactivez ce QR depuis votre espace DocTrack.',
            ],
        ],
        'restitution.counter_request' => [
            'subject' => 'DocTrack — demande de retrait du document :reference',
            'lines'   => [
                ':requester souhaite retirer le document :document_type au nom de :owner_name (référence :reference), conservé par :structure.',
                'Préparez le document. À son passage, vérifiez son identité puis enregistrez la restitution dans l\'espace agent (décharge de restitution).',
            ],
        ],
        'collection.assigned' => [
            'subject' => 'DocTrack — nouveau document à récupérer',
            'lines'   => [
                'Un citoyen a trouvé un document (:document_type au nom de :owner_name) à :place:distance.',
                'Il a accepté d\'être contacté. Ouvrez le dossier :reference dans l\'espace agent pour l\'appeler.',
            ],
        ],
    ],
];
