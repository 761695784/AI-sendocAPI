<?php

/*
|--------------------------------------------------------------------------
| Aide de l'assistant DocTrack (questions fréquentes)
|--------------------------------------------------------------------------
| Réponses générales de l'assistant :
|  1. une question proche d'une fiche → la réponse de la fiche, mot pour mot ;
|  2. sinon (si un fournisseur d'IA est configuré) → le modèle rédige une réponse
|     À PARTIR DE LA BASE DE CONNAISSANCES UNIQUEMENT (resources/assistant/base-de-connaissances.md),
|     en citant les sections utilisées ; un chiffre absent des sections citées fait rejeter sa réponse.
|
| Pour ajouter ou corriger une réponse : modifier cette liste (aucun autre code).
| Mots-clés : SANS accents, en minuscules ; « * » final = début de mot ; poids = nombre de mots.
| Liens : chemins du site (ex. /declarer), affichés en boutons dans la fenêtre de discussion.
*/

return [

    // Fournisseurs d'IA pour les questions hors fiches (config/ai.php → endpoints), dans l'ordre.
    // Vide = l'assistant ne répond qu'avec les fiches (aucun appel externe).
    'providers' => array_filter(array_map('trim', explode(',', (string) env('ASSISTANT_AI_PROVIDERS', '')))),

    // Garde-fous de coût et d'abus pour les réponses rédigées par l'IA.
    'limits' => [
        'per_conversation' => 10,
        'per_ip_per_day'   => 40,
        'global_per_day'   => 1500,
    ],
    'timeout'    => (int) env('ASSISTANT_AI_TIMEOUT', 20),
    'cache_days' => 7,

    // Base de connaissances complète : seule source des réponses rédigées par l'IA (voir le fichier pour le format).
    'knowledge_file' => resource_path('assistant/base-de-connaissances.md'),

    // Score minimal pour répondre directement avec une fiche.
    'min_score' => 1.5,

    'entries' => [

        [
            'id'       => 'declarer_perte',
            'question' => 'Comment déclarer la perte d\'un document ?',
            'keywords' => ['comment declarer', 'declarer une perte', 'faire une declaration', 'declaration de perte', 'declarer la perte', 'signaler une perte'],
            'answer'   => "Connectez-vous (ou créez un compte gratuit), puis ouvrez « Déclarer une perte ». Indiquez le type de document, le nom inscrit dessus, puis le lieu et la date de la perte. "
                . "Vous recevez aussitôt une référence (DT-…) et un récépissé DocTrack. Si un document correspondant est publié ou déposé, vous êtes prévenu par SMS et par e-mail.",
            'links'    => [['label' => 'Déclarer une perte', 'url' => '/declarer']],
        ],
        [
            'id'       => 'recepisse_certificat',
            'question' => 'Quelle différence entre le récépissé DocTrack et le certificat de perte ?',
            'keywords' => ['recepisse', 'certificat de perte', 'certificat', 'difference', 'attestation de perte', 'valeur legale', 'papier officiel'],
            'answer'   => "Le récépissé prouve que vous avez déclaré votre perte sur DocTrack ; ce n'est pas un certificat de perte. "
                . "Le certificat de perte est délivré par un agent, au nom de sa structure (commissariat ou brigade participant à DocTrack), après vérification de votre identité.",
        ],
        [
            'id'       => 'obtenir_certificat',
            'question' => 'Comment obtenir un certificat de perte ?',
            'keywords' => ['obtenir un certificat', 'avoir un certificat', 'certifier', 'certification', 'faire certifier', 'aller au commissariat', 'aller a la gendarmerie'],
            'answer'   => "Présentez-vous dans une structure participant à DocTrack avec la référence de votre déclaration (DT-…) ou le QR code de votre récépissé, et un justificatif d'identité. "
                . "L'agent vérifie votre identité, complète la déclaration et vous remet le certificat de perte. Vous n'avez rien à ressaisir.",
        ],
        [
            'id'       => 'publier_trouve',
            'question' => 'J\'ai trouvé un document, que faire ?',
            'keywords' => ['publier', 'publication', 'j ai trouve', 'document trouve', 'carte trouvee', 'rendre un document', 'ramasse*'],
            'answer'   => "Merci ! Connectez-vous, ouvrez « Publier un document trouvé » et photographiez la pièce : DocTrack lit les informations et masque les numéros sur la photo publiée. "
                . "Vous choisissez ensuite de remettre le document vous-même à son propriétaire, ou d'être contacté par la structure la plus proche, sans avoir à vous déplacer.",
            'links'    => [['label' => 'Publier un document trouvé', 'url' => '/publier']],
        ],
        [
            'id'       => 'confidentialite_photo',
            'question' => 'Mes données sont-elles protégées sur la photo publiée ?',
            'keywords' => ['donnees', 'confidentialite', 'vie privee', 'protege*', 'masque*', 'flou*', 'photo publiee', 'securite des donnees'],
            'answer'   => "Oui. Le public ne voit jamais la pièce en clair : les numéros sont masqués et la photo est floutée. Aucun chiffre du numéro n'est affiché. "
                . "La photo d'origine reste privée : seuls le publicateur et les agents habilités y ont accès.",
            'links'    => [['label' => 'Politique de confidentialité', 'url' => '/private-policy']],
        ],
        [
            'id'       => 'rechercher',
            'question' => 'Comment savoir si mon document a été retrouvé ?',
            'keywords' => ['rechercher', 'chercher', 'recherche', 'retrouve*', 'liste des documents', 'mon document est la', 'savoir si',
                'comment savoir', 'comment je saurai', 'je saurai que', 'serai prevenu', 'etre prevenu', 'prevenir', 'averti*', 'alerte*', 'notifi*',
                'mon document est trouve', 'mon document a ete trouve', 'ma carte est trouvee', 'ma carte a ete trouvee', 'si on trouve', 'quelqu un trouve'],
            'answer'   => "Consultez la liste des documents retrouvés et cherchez votre nom. Le plus sûr est de déclarer votre perte : "
                . "DocTrack compare chaque nouvelle publication avec votre déclaration et vous prévient par SMS et par e-mail dès qu'un document correspond.",
            'links'    => [['label' => 'Documents retrouvés', 'url' => '/liste'], ['label' => 'Déclarer une perte', 'url' => '/declarer']],
        ],
        [
            'id'       => 'recuperer_trouveur',
            'question' => 'Comment récupérer mon document chez la personne qui l\'a trouvé ?',
            'keywords' => ['recuperer mon document', 'restitution', 'demander la restitution', 'contacter le trouveur', 'numero du trouveur', 'c est mon document',
                'a trouve ma', 'a trouve mon', 'a trouve mes', 'a retrouve ma', 'a retrouve mon', 'on a trouve', 'quelqu un a trouve'],
            'answer'   => "Ouvrez la fiche du document et cliquez sur « C'est mon document ». Si votre déclaration correspond ou si vous donnez les 4 derniers caractères du numéro de la pièce, "
                . "vous obtenez le numéro du trouveur. Sinon, votre demande lui est envoyée : il compare avec le document et accepte ou refuse. Vous pouvez aussi passer par une structure.",
            'links'    => [['label' => 'Documents retrouvés', 'url' => '/liste']],
        ],
        [
            'id'       => 'retrait_guichet',
            'question' => 'Mon document est dans un commissariat ou une brigade, comment le récupérer ?',
            'keywords' => ['retirer au guichet', 'au guichet', 'conserve par', 'en garde', 'structure garde', 'deposer au commissariat'],
            'answer'   => "Sur la fiche du document, cliquez sur « Le retirer au guichet » : la structure est prévenue et vous recevez son adresse, son téléphone et la référence. "
                . "Présentez-vous avec une pièce justificative ; l'agent vérifie votre identité et vous remet le document contre une décharge.",
        ],
        [
            'id'       => 'securite_rencontre',
            'question' => 'Comment rencontrer le trouveur en sécurité ?',
            'keywords' => ['rencontre*', 'securite', 'danger', 'rendez vous', 'arnaque*', 'confiance'],
            'answer'   => "Rencontrez-vous dans un lieu public et fréquenté, de préférence en journée, idéalement accompagné. Vous n'avez rien à payer. "
                . "En cas de doute, passez par le commissariat ou la brigade la plus proche. DocTrack ne garantit pas la sécurité des rencontres entre particuliers.",
        ],
        [
            'id'       => 'gratuit',
            'question' => 'DocTrack est-il payant ou gratuit ?',
            'keywords' => ['payant', 'gratuit*', 'prix', 'combien ca coute', 'cout*', 'tarif*', 'frais'],
            'answer'   => "La création de compte, la déclaration en ligne, la recherche et la restitution d'un document sont gratuites. "
                . "DocTrack ne vous demandera jamais d'argent pour vous rendre un document.",
        ],
        [
            'id'       => 'trouveur_argent',
            'question' => 'Le trouveur me demande de l\'argent, que faire ?',
            'keywords' => ['demande de l argent', 'demande argent', 'payer le trouveur', 'recompense', 'il veut de l argent', 'rancon'],
            'answer'   => "Vous n'avez rien à payer et vous pouvez refuser. Dans ce cas, ne vous déplacez pas : demandez que le document passe par une structure, "
                . "ou présentez-vous au commissariat ou à la brigade la plus proche. DocTrack n'est pas responsable des demandes d'argent entre particuliers.",
        ],
        [
            'id'       => 'trouveur_ne_repond_pas',
            'question' => 'Le trouveur ne répond pas à ma demande, que se passe-t-il ?',
            'keywords' => ['ne repond pas', 'pas de reponse', 'sans reponse', 'injoignable', 'refuse*', 'il a refuse'],
            'answer'   => "Une demande envoyée au trouveur reste en attente 48 heures. Sans réponse, ou s'il refuse la remise directe, une structure proche peut prendre le relais pour récupérer le document. "
                . "Vous suivez l'avancement dans « Mon espace ».",
            'links'    => [['label' => 'Mon espace', 'url' => '/mon-espace']],
        ],
        [
            'id'       => 'creer_compte',
            'question' => 'Comment créer un compte ?',
            'keywords' => ['creer un compte', 'inscription', 's inscrire', 'inscrire', 'ouvrir un compte', 'nouveau compte'],
            'answer'   => "Cliquez sur « Créer un compte », renseignez votre nom, votre numéro de téléphone, votre e-mail et un mot de passe. "
                . "Saisissez ensuite le code à 6 chiffres reçu par e-mail pour activer votre compte. Le compte est gratuit.",
            'links'    => [['label' => 'Créer un compte', 'url' => '/inscription']],
        ],
        [
            'id'       => 'code_verification',
            'question' => 'Je n\'ai pas reçu le code de vérification, ou il ne marche plus.',
            'keywords' => ['code de verification', 'pas recu le code', 'code expire', 'code incorrect', 'activer mon compte', 'activation', 'code ne marche'],
            'answer'   => "Regardez dans vos courriers indésirables (spam). Le code est valable 48 heures et s'annule après 5 erreurs. "
                . "Dans ces cas, utilisez « Mot de passe oublié » avec votre e-mail : vous recevrez un lien pour activer votre compte et choisir votre mot de passe.",
            'links'    => [['label' => 'Mot de passe oublié', 'url' => '/reset']],
        ],
        [
            'id'       => 'mot_de_passe',
            'question' => 'J\'ai oublié mon mot de passe.',
            'keywords' => ['mot de passe', 'oublie mon mot', 'reinitialiser', 'connexion impossible', 'je n arrive pas a me connecter'],
            'answer'   => "Sur la page de connexion, cliquez sur « Mot de passe oublié » et saisissez votre e-mail : vous recevrez un lien pour en choisir un nouveau.",
            'links'    => [['label' => 'Mot de passe oublié', 'url' => '/reset']],
        ],
        [
            'id'       => 'compte_guichet',
            'question' => 'J\'ai déclaré ma perte au guichet, comment accéder à mon dossier en ligne ?',
            'keywords' => ['declare au guichet', 'declare au commissariat', 'pas de compte', 'acceder a mon dossier', 'mon dossier en ligne'],
            'answer'   => "Si votre e-mail a été enregistré au guichet, utilisez « Mot de passe oublié » avec cet e-mail : votre compte est activé et vous retrouvez votre déclaration dans « Mon espace ».",
            'links'    => [['label' => 'Mot de passe oublié', 'url' => '/reset']],
        ],
        [
            'id'       => 'qr_code',
            'question' => 'À quoi servent les QR codes DocTrack ?',
            'keywords' => ['qr code', 'qr', 'autocollant', 'etiquette'],
            'answer'   => "Vous pouvez créer un QR code pour chacun de vos documents et le coller dessus. Si quelqu'un trouve la pièce et scanne le code, "
                . "vous êtes prévenu sans que cette personne voie vos coordonnées.",
            'links'    => [['label' => 'Mon espace', 'url' => '/mon-espace']],
        ],
        [
            'id'       => 'verifier_certificat',
            'question' => 'Comment vérifier qu\'un certificat ou un récépissé est authentique ?',
            'keywords' => ['verifier', 'authentique', 'faux certificat', 'vrai certificat', 'authenticite', 'controle'],
            'answer'   => "Ouvrez « Vérifier un document » et saisissez le code imprimé, ou scannez le QR code du document. DocTrack indique s'il est authentique "
                . "et, pour une déclaration en ligne, si elle a été certifiée dans une structure.",
            'links'    => [['label' => 'Vérifier un document', 'url' => '/verifier']],
        ],
        [
            'id'       => 'types_documents',
            'question' => 'Quels documents peut-on déclarer ou publier ?',
            // Pas de noms de pièces seuls ici : « ma carte d'identité » lance la déclaration, pas cette fiche.
            'keywords' => ['quels documents', 'quel document', 'quels types', 'type de document', 'types de documents', 'documents acceptes', 'quelles pieces peut'],
            'answer'   => "Les pièces d'identité et documents officiels : carte d'identité, passeport, permis de conduire, carte grise et autres documents proposés dans la liste du formulaire.",
        ],
        [
            'id'       => 'procuration',
            'question' => 'Un proche peut-il récupérer mon document à ma place ?',
            'keywords' => ['procuration', 'un proche', 'a ma place', 'quelqu un d autre', 'mon frere', 'ma soeur', 'mon pere', 'ma mere'],
            'answer'   => "Au guichet, oui : la personne présente sa propre pièce d'identité et une procuration ; l'agent l'enregistre sur la décharge. "
                . "Pour une remise directe avec le trouveur, c'est à vous de vous entendre avec lui.",
        ],
        [
            'id'       => 'delai',
            'question' => 'Combien de temps faut-il pour retrouver un document ?',
            'keywords' => ['combien de temps', 'delai', 'quand', 'attendre', 'toujours rien'],
            'answer'   => "Cela dépend du moment où le document est trouvé et publié : DocTrack ne peut pas le prévoir. "
                . "Gardez votre déclaration active : vous serez prévenu automatiquement par SMS et par e-mail dès qu'un document correspondant apparaît.",
        ],
        [
            'id'       => 'qui_sommes_nous',
            'question' => 'Qui est derrière DocTrack ?',
            'keywords' => ['qui etes vous', 'c est quoi doctrack', 'qu est ce que doctrack', 'qui a cree', 'officiel', 'etat', 'gouvernement'],
            'answer'   => "DocTrack est une plateforme sénégalaise développée par Majeli Connect pour faciliter la déclaration, la recherche et la restitution des documents perdus, "
                . "en lien avec les structures participantes. Ce n'est pas un service de l'État.",
            'links'    => [['label' => 'À propos', 'url' => '/apropos']],
        ],
        [
            'id'       => 'contact',
            'question' => 'Comment contacter DocTrack ?',
            'keywords' => ['contact*', 'joindre', 'email', 'telephone de doctrack', 'service client', 'reclamation'],
            'answer'   => "Écrivez à contact@majeliconnect.com. Pour une démarche liée à un document conservé par une structure, contactez directement cette structure.",
        ],
        [
            'id'       => 'supprimer_compte',
            'question' => 'Comment supprimer mon compte ou mes données ?',
            'keywords' => ['supprimer mon compte', 'supprimer mes donnees', 'effacer', 'droit a l oubli', 'retirer ma publication'],
            'answer'   => "Vous pouvez supprimer vos publications et déclarations depuis « Mon espace ». Pour supprimer votre compte et vos données, écrivez à contact@majeliconnect.com.",
            'links'    => [['label' => 'Politique de confidentialité', 'url' => '/private-policy']],
        ],
    ],
];
