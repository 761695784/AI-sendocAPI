<?php

/*
|--------------------------------------------------------------------------
| Assistant DocTrack (texte + voix, français + wolof)
|--------------------------------------------------------------------------
| Dialogue GUIDÉ par règles : l'assistant pose les questions une à une,
| reconnaît des mots-clés en français et en wolof, récapitule et demande
| confirmation avant toute action. Aucun service externe n'est nécessaire.
|
| Les fournisseurs d'IA (voix → texte, modèle de langage) sont facultatifs
| et se règlent dans config/ai.php.
|
| ⚠️ Textes wolof : brouillon à faire relire par un locuteur natif avant
| toute démonstration publique. Les clés ne doivent pas changer (les fichiers
| audio pré-générés portent le nom de la clé).
|
| Mots-clés : écrits SANS accents ni apostrophes (le texte de l'utilisateur
| est normalisé de la même façon). Un « * » final = début de mot
| (ex. « perd* » reconnaît perdu, perdue, perdre).
*/

return [

    'enabled' => env('ASSISTANT_ENABLED', true),

    // Après ce délai sans message, le parcours en cours est oublié.
    'conversation_ttl_minutes' => (int) env('ASSISTANT_CONVERSATION_TTL', 30),

    // Durée de conservation des échanges (purge quotidienne).
    'retention_days' => (int) env('ASSISTANT_RETENTION_DAYS', 30),

    // Nombre d'incompréhensions successives avant de proposer un agent.
    'max_misunderstood' => 2,

    'languages' => ['fr', 'wo'],
    'default_language' => 'fr',

    // Voix des phrases fixes : storage/app/{audio_dir}/{langue}/{clé}.mp3 + manifest.json
    // (générées par Adia_TTS : php artisan doctrack:assistant-voices — voir ai/README.md, étape 4).
    'audio_dir' => 'assistant/audio',

    /*
    |----------------------------------------------------------------------
    | Intentions (ce que veut l'utilisateur)
    |----------------------------------------------------------------------
    | Poids d'un mot-clé = nombre de mots de l'expression (les expressions
    | longues l'emportent sur les mots isolés).
    */
    'intents' => [
        'declare_loss' => [
            'fr' => ['perd*', 'perte', 'egare*', 'declarer une perte', 'declaration de perte', 'on m a vole', 'vole', 'disparu*', 'je ne trouve plus', 'je ne retrouve plus'],
            'wo' => ['reer', 'rer', 'reeru', 'reer na', 'dafa reer', 'nakk', 'nakk naa', 'sacc', 'sacc nanu', 'sacc nañu'],
        ],
        'report_found' => [
            'fr' => ['trouv*', 'j ai trouve', 'ramasse*', 'je veux rendre', 'rendre un document', 'rendre une carte'],
            'wo' => ['gis naa', 'fekk', 'fekk naa', 'for naa', 'for', 'dama gis', 'dama fekk', 'dama for'],
        ],
        'where_to_go' => [
            'fr' => ['ou aller', 'ou recuperer', 'recuperer', 'ou dois je', 'ou se trouve', 'est retrouve*', 'a ete retrouve*', 'ont retrouve', 'structure', 'commissariat', 'gendarmerie', 'brigade'],
            'wo' => ['fan laa', 'fan la', 'fan laa war dem', 'fan laa koy jele', 'jel', 'jeli', 'jele'],
        ],
        'track_status' => [
            'fr' => ['ou en est', 'suivre', 'suivi', 'statut', 'mon dossier', 'ma declaration', 'des nouvelles', 'avancement', 'etat de'],
            'wo' => ['ana', 'naka la tollu', 'fan la tollu', 'xibaar', 'sama dossier', 'sama declaration'],
        ],
        'what_to_bring' => [
            'fr' => ['apporter', 'amener', 'quels documents', 'quelles pieces', 'que faut il', 'il faut quoi', 'necessaire', 'justificatif*'],
            'wo' => ['indi', 'lan laa war indi', 'lan laa war', 'lan la soxla', 'soxla'],
        ],
        'proxy' => [
            'fr' => ['a ma place', 'procuration', 'mon frere', 'ma soeur', 'mon pere', 'ma mere', 'quelqu un d autre', 'represent*', 'un proche'],
            'wo' => ['sama rakk', 'sama mag', 'sama doom', 'sama baay', 'sama yaay', 'ci sama wanaw'],
        ],
        'human' => [
            'fr' => ['agent', 'humain', 'parler a quelqu un', 'conseiller', 'operateur', 'une personne'],
            'wo' => ['nit', 'jokkale', 'waxal ak', 'waxal ak nit'],
        ],
        'help' => [
            'fr' => ['aide', 'menu', 'que peux tu faire', 'que pouvez vous faire', 'options', 'comment ca marche'],
            'wo' => ['dimbali', 'dimbal', 'dimbalil ma'],
        ],
        'greet' => [
            'fr' => ['bonjour', 'salut', 'bonsoir', 'hello', 'coucou'],
            'wo' => ['salaam', 'salam', 'asalamalekum', 'assalamu alaykum', 'salamalekum', 'nanga def', 'na nga def', 'naka nga def', 'jamm nga am'],
        ],
        'thanks' => [
            'fr' => ['merci'],
            'wo' => ['jerejef', 'jerre jef', 'jerrejef', 'jere jef'],
        ],
    ],

    // Commandes reconnues à tout moment, même au milieu d'un parcours.
    'commands' => [
        'cancel'  => ['annuler', 'annule', 'stop', 'arreter', 'arrete', 'laisse tomber', 'bayyi', 'bayil', 'bayyil', 'bayyi ko'],
        'restart' => ['recommencer', 'recommence', 'depuis le debut', 'tambali', 'tambaliwaat'],
        'lang_wo' => ['wolof', 'en wolof', 'ci wolof', 'wax ci wolof'],
        'lang_fr' => ['francais', 'en francais', 'ci farañse', 'ci faranse'],
    ],

    'yes'  => ['oui', 'ouais', 'ok', 'okay', 'd accord', 'exact', 'c est ca', 'c est bon', 'correct', 'valider', 'valide', 'je confirme', 'confirme', 'waaw', 'waw', 'wa', 'degg la', 'baax na', 'waaw degg la'],
    'no'   => ['non', 'faux', 'incorrect', 'pas du tout', 'c est faux', 'deedeet', 'deet', 'dedet', 'dedeet', 'du degg', 'deet kay'],
    'skip' => ['passer', 'passe', 'je ne sais pas', 'sais pas', 'j sais pas', 'aucune idee', 'inconnu', 'je ne connais pas', 'xamuma', 'xamu ma', 'xamou ma', 'suivant'],

    // Préfixes retirés d'une réponse « nom » (« je m'appelle Awa Ndiaye »).
    'name_prefixes' => [
        'je m appelle', 'je suis', 'mon nom est', 'mon nom c est', 'c est', 'le nom est', 'le nom c est',
        'il s appelle', 'elle s appelle', 'au nom de', 'maa ngi tudd', 'maa ngui tudd', 'sama tur mooy', 'sama tur', 'tudd na', 'mangi tudd',
    ],

    /*
    |----------------------------------------------------------------------
    | Types de pièce : mots entendus → nom exact du type (document_types)
    |----------------------------------------------------------------------
    */
    'document_types' => [
        "Carte nationale d'identité" => ['cni', 'carte d identite', 'carte nationale', 'carte identite', 'piece d identite', 'identite', 'carte d id', 'kart', 'karte', 'kart didantite', 'carte cedeao'],
        'Passeport'                  => ['passeport', 'passport', 'paspor', 'paspoor', 'pasepor'],
        'Permis de conduire'         => ['permis', 'permi', 'permis de conduire'],
        'Carte grise'                => ['carte grise', 'cart gris', 'kart gris'],
        'Certificat de naissance'    => ['extrait de naissance', 'acte de naissance', 'certificat de naissance', 'extrait', 'kayitu juddu', 'juddu'],
        'Diplômes Baccalauréat'      => ['bac', 'baccalaureat', 'diplome'],
        'Carte professionnelle'      => ['carte professionnelle', 'carte pro', 'badge'],
        'Carte bancaire'             => ['carte bancaire', 'carte de banque', 'carte visa', 'carte de credit', 'cb'],
        "Attestation d'assurance"    => ['assurance', 'attestation d assurance'],
        'Carnet de santé'            => ['carnet de sante', 'carnet'],
        "Carte d'étudiant"           => ['carte d etudiant', 'carte etudiant', 'carte etudiante'],
    ],

    // Types proposés en boutons (les plus fréquents).
    'document_type_buttons' => ["Carte nationale d'identité", 'Passeport', 'Permis de conduire', 'Carte grise', 'Certificat de naissance'],

    /*
    |----------------------------------------------------------------------
    | Libellés des statuts (suivi de dossier)
    |----------------------------------------------------------------------
    */
    'statuses' => [
        'registered'              => ['fr' => 'enregistrée, recherche en cours', 'wo' => 'bindu na, ñu ngi koy wut'],
        'matched'                 => ['fr' => 'pièce retrouvée !', 'wo' => 'gis nañu kayit bi !'],
        'restitution_in_progress' => ['fr' => 'restitution en cours', 'wo' => 'ñu ngi koy delloo'],
        'restituted'              => ['fr' => 'pièce restituée', 'wo' => 'delloo nañu la ko'],
        'closed'                  => ['fr' => 'dossier clos', 'wo' => 'dossier bi tëju na'],
        'cancelled'               => ['fr' => 'annulée', 'wo' => 'neenal nañu ko'],
        'expired'                 => ['fr' => 'expirée', 'wo' => 'jeex na'],
    ],

    /*
    |----------------------------------------------------------------------
    | Boutons (réponses rapides)
    |----------------------------------------------------------------------
    */
    'buttons' => [
        'declare_loss'  => ['fr' => 'Déclarer une perte', 'wo' => 'Kayit bu réer'],
        'report_found'  => ['fr' => "J'ai trouvé un document", 'wo' => 'Gis naa ab kayit'],
        'track_status'  => ['fr' => 'Suivre mon dossier', 'wo' => 'Sama dossier'],
        'where_to_go'   => ['fr' => 'Où récupérer ma pièce ?', 'wo' => 'Fan laa koy jëlé ?'],
        'what_to_bring' => ['fr' => 'Que faut-il apporter ?', 'wo' => 'Lan laa war indi ?'],
        'human'         => ['fr' => 'Parler à un agent', 'wo' => 'Waxal ak ab nit'],
        'yes'           => ['fr' => 'Oui', 'wo' => 'Waaw'],
        'no'            => ['fr' => 'Non', 'wo' => 'Déedéet'],
        'skip'          => ['fr' => 'Je ne sais pas', 'wo' => 'Xamuma'],
        'fix_type'      => ['fr' => 'La pièce', 'wo' => 'Kayit bi'],
        'fix_name'      => ['fr' => 'Le nom', 'wo' => 'Tur wi'],
        'fix_number'    => ['fr' => 'Le numéro', 'wo' => 'Nimero bi'],
        'fix_place'     => ['fr' => 'Le lieu', 'wo' => 'Bérab bi'],
        'cancel'        => ['fr' => 'Annuler', 'wo' => 'Bayyi ko'],
        'share_position' => ['fr' => 'Partager ma position'],
    ],

    /*
    |----------------------------------------------------------------------
    | Phrases de l'assistant
    |----------------------------------------------------------------------
    | Les phrases SANS « :paramètre » peuvent avoir un fichier audio
    | pré-généré (commande doctrack:assistant-phrases).
    */
    'phrases' => [
        // Accueil et menu
        'welcome' => [
            'fr' => 'Bonjour, je suis l\'assistant DocTrack. Je peux vous aider à déclarer une pièce perdue, signaler un document trouvé ou suivre votre dossier. Que voulez-vous faire ?',
            'wo' => 'Salaam aleekum, maa ngi tudd assistant DocTrack. Mën naa la dimbali nga yégle kayit bu la réer, walla nga wax ab kayit bu nga gis, walla nga xool sa dossier. Lan nga bëgg def ?',
        ],
        'menu' => [
            'fr' => 'Voici ce que je peux faire pour vous. Choisissez ou dites-le avec vos mots.',
            'wo' => 'Lii lañu mën a def. Tannal walla nga wax ko ci sa kàddu.',
        ],
        'thanks_reply' => [
            'fr' => 'Avec plaisir. Je reste disponible si vous avez besoin d\'autre chose.',
            'wo' => 'Ñoo ko bokk. Maa ngi fi su soxlaa leneen.',
        ],
        'not_understood' => [
            'fr' => 'Pardon, je n\'ai pas bien compris. Pouvez-vous reformuler, ou choisir une option ?',
            'wo' => 'Baal ma, dégguma bu baax. Mën nga ko waxaat, walla nga tann ci li ci suuf ?',
        ],
        'offer_human' => [
            'fr' => 'Je n\'arrive pas à vous comprendre. Voulez-vous parler à un agent ?',
            'wo' => 'Dégguma la bu baax. Ndax bëgg nga wax ak ab nit ?',
        ],
        'cancelled' => [
            'fr' => 'C\'est annulé, rien n\'a été enregistré. Que voulez-vous faire ?',
            'wo' => 'Neenal nañu ko, dara binduwul. Lan nga bëgg def ?',
        ],
        'language_switched' => [
            'fr' => 'D\'accord, continuons en français.',
            'wo' => 'Waaw, nañu wax ci wolof.',
        ],
        'expired' => [
            'fr' => 'Notre échange précédent a expiré. Reprenons depuis le début.',
            'wo' => 'Sunu waxtaan bu njëkk bi jeex na. Nañu tàmbaliwaat.',
        ],
        'login_required' => [
            'fr' => 'Pour cela, vous devez être connecté à votre compte DocTrack. Connectez-vous puis réessayez.',
            'wo' => 'Ngir def loolu, war nga dugg ci sa compte DocTrack. Duggal, ba noppi nga jéemaat.',
        ],
        'answer_yes_no' => [
            'fr' => 'Répondez simplement par oui ou par non.',
            'wo' => 'Tontu rekk waaw walla déedéet.',
        ],

        // Déclaration de perte
        'ask_doc_type_lost' => [
            'fr' => 'Quelle pièce avez-vous perdue ? Par exemple : carte d\'identité, passeport, permis.',
            'wo' => 'Ban kayit moo la réer ? Misaal : kart didantite, paspoor, permis.',
        ],
        'doc_type_unknown' => [
            'fr' => 'Je n\'ai pas reconnu ce type de pièce. Choisissez dans la liste.',
            'wo' => 'Xàmmiwuma kayit boobu. Tannal ci li ci suuf.',
        ],
        'ask_owner_name' => [
            'fr' => 'Quels sont le prénom et le nom écrits sur la pièce ?',
            'wo' => 'Lan mooy tur ak sant bi ñu bind ci kayit bi ?',
        ],
        'ask_full_name_again' => [
            'fr' => 'Il me faut le prénom ET le nom de famille. Par exemple : Awa Ndiaye.',
            'wo' => 'Soxla naa tur wi ak sant bi. Misaal : Awa Ndiaye.',
        ],
        'ask_first_name' => [
            'fr' => 'Quel est le prénom (ou les prénoms) écrit sur la pièce ?',
            'wo' => 'Lan mooy tur wi ñu bind ci kayit bi ?',
        ],
        'ask_last_name' => [
            'fr' => 'Et le nom de famille ?',
            'wo' => 'Ak sant bi nag ?',
        ],
        'ask_doc_number' => [
            'fr' => 'Connaissez-vous le numéro de la pièce ? Il aide beaucoup à la retrouver. Sinon, dites « je ne sais pas ».',
            'wo' => 'Ndax xam nga nimero kayit bi ? Dafay dimbali lool ngir gis ko. Su dul loolu, waxal « xamuma ».',
        ],
        'ask_lost_place' => [
            'fr' => 'Où l\'avez-vous perdue ? Quartier, ville ou lieu. Sinon, dites « je ne sais pas ».',
            'wo' => 'Fan la la réer ? Gox, dëkk walla bérab. Su dul loolu, waxal « xamuma ».',
        ],
        'confirm_loss' => [
            'fr' => 'Je récapitule : perte de :document_type au nom de :first_name :last_name:number:place. C\'est correct ?',
            'wo' => 'Ma dajale ko : :document_type bu réer, ci turu :first_name :last_name:number:place. Ndax dëgg la ?',
        ],
        'what_to_fix' => [
            'fr' => 'Qu\'est-ce qui n\'est pas correct ?',
            'wo' => 'Lan moo baaxul ?',
        ],
        'loss_created' => [
            'fr' => 'C\'est enregistré. Votre déclaration porte la référence :reference. Votre récépissé est disponible dans votre espace. DocTrack cherche déjà votre pièce et vous préviendra par SMS dès qu\'elle sera retrouvée.',
            'wo' => 'Bindu na. Sa référence mooy :reference. Sa récépissé mu ngi ci sa compte. DocTrack ngi koy wut, dinañu la yónnee SMS bu ñu ko gisee.',
        ],
        'loss_failed' => [
            'fr' => 'Désolé, l\'enregistrement n\'a pas abouti. Réessayez dans un instant ou passez par le formulaire.',
            'wo' => 'Baal ma, bindu gi antuwul. Jéemaatal ci kanam walla nga jaar ci formulaire bi.',
        ],

        // Document trouvé
        'ask_doc_type_found' => [
            'fr' => 'Merci pour votre geste ! Quel document avez-vous trouvé ?',
            'wo' => 'Jërëjëf ci sa jëf ju baax ! Ban kayit nga gis ?',
        ],
        'ask_owner_name_found' => [
            'fr' => 'Quel nom est écrit sur le document ? Prénom et nom.',
            'wo' => 'Ban tur lañu bind ci kayit bi ? Tur ak sant.',
        ],
        'ask_found_place' => [
            'fr' => 'Où l\'avez-vous trouvé ? Quartier, ville ou lieu précis.',
            'wo' => 'Fan nga ko gis ? Gox, dëkk walla bérab bi.',
        ],
        'ask_collect_consent' => [
            'fr' => 'Vous n\'avez pas besoin de vous déplacer : la structure la plus proche peut vous appeler pour venir le chercher ou vous indiquer où le déposer. Acceptez-vous d\'être contacté ?',
            'wo' => 'Soxlawul nga dem : bérab bi gën a jege mën na la woo, ñu ñëw jël ko walla ñu wax la fan nga koy denc. Ndax nangu nga ñu woo la ?',
        ],
        'confirm_found' => [
            'fr' => 'Je récapitule : :document_type au nom de :first_name :last_name, trouvé à :place. Contact par la structure : :consent. On continue ?',
            'wo' => 'Ma dajale ko : :document_type ci turu :first_name :last_name, gis nga ko ca :place. Ñu woo la : :consent. Ndax ñu dem ci kanam ?',
        ],
        'found_next_step' => [
            'fr' => 'Parfait. Dernière étape : prenez une photo du document dans le formulaire qui s\'ouvre, j\'ai déjà rempli le reste. Merci, grâce à vous quelqu\'un va retrouver sa pièce.',
            'wo' => 'Baax na. Li des : jël nataal kayit bi ci formulaire bi, bind naa li des. Jërëjëf, ci sa kaw kenn dina gis kayitam.',
        ],

        // Suivi
        'status_none' => [
            'fr' => 'Vous n\'avez aucune déclaration de perte pour le moment. Voulez-vous en faire une ?',
            'wo' => 'Amoo benn déclaration léegi. Ndax bëgg nga def benn ?',
        ],
        'status_intro' => [
            'fr' => 'Voici vos dernières déclarations :',
            'wo' => 'Yii ñooy sa déclaration yu mujj :',
        ],
        'status_line' => [
            'fr' => ':reference — :document_type (:name) : :status',
            'wo' => ':reference — :document_type (:name) : :status',
        ],

        // Où aller
        'where_custody' => [
            'fr' => 'Bonne nouvelle : votre :document_type est gardé(e) par :structure:address:phone. Présentez-vous avec une pièce d\'identité et votre référence :reference. La restitution est gratuite.',
            'wo' => 'Xibaar bu baax : sa :document_type mu ngi ca :structure:address:phone. Demal ak sa kayit didantite ak sa référence :reference. Delloo bi fey-woul dara.',
        ],
        'where_with_finder' => [
            'fr' => 'Votre :document_type a été retrouvé(e) par un citoyen. Depuis votre espace DocTrack, demandez-lui la restitution : dès qu\'il accepte, vous recevez son contact. Sinon, une structure viendra le récupérer.',
            'wo' => 'Ab nit gis na sa :document_type. Ci sa compte DocTrack, laajal ko mu delloo la ko : bu nangoo, dinga am nimeroom. Su dul loolu, ab bérab dina ko ñëw jël.',
        ],
        'where_not_found_yet' => [
            'fr' => 'Votre pièce n\'a pas encore été retrouvée. Je peux vous indiquer la structure la plus proche de chez vous. Dans quel quartier ou quelle ville êtes-vous ?',
            'wo' => 'Gisagunu sa kayit. Mën naa la wax bérab bi gën a jege sa kër. Ci ban gox walla ban dëkk nga nekk ?',
        ],
        'ask_location' => [
            'fr' => 'Dans quel quartier ou quelle ville êtes-vous ? Je vous indique la structure la plus proche.',
            'wo' => 'Ci ban gox walla ban dëkk nga nekk ? Ma wax la bérab bi gën a jege.',
        ],
        'nearest_structure' => [
            'fr' => 'La structure la plus proche est :structure:address:phone.',
            'wo' => 'Bérab bi gën a jege mooy :structure:address:phone.',
        ],
        'location_unknown' => [
            'fr' => 'Je ne trouve pas ce lieu. Donnez le nom de votre ville ou de votre département, par exemple : Dakar, Pikine, Ziguinchor.',
            'wo' => 'Gisuma bérab boobu. Waxal turu sa dëkk walla sa département, misaal : Dakar, Pikine, Ziguinchor.',
        ],
        'several_structures' => [
            'fr' => ':area compte plusieurs structures participantes : :list. Dans quel quartier êtes-vous ? Vous pouvez aussi partager votre position pour connaître la plus proche.',
        ],
        'why_gps' => [
            'fr' => 'Je vous ai indiqué :structure parce que c\'est la structure participante la plus proche de votre position (environ :km km).',
        ],
        'why_quarter' => [
            'fr' => 'Je vous ai indiqué :structure parce qu\'elle se trouve dans le quartier que vous avez cité (:area).',
        ],
        'why_area' => [
            'fr' => 'Vous avez indiqué :area sans préciser le quartier : :structure est une structure participante de ce secteur, pas forcément la plus proche de vous. Donnez votre quartier ou partagez votre position pour une réponse plus précise.',
        ],
        'other_structures' => [
            'fr' => 'Autres structures participantes dans ce secteur : :list.',
        ],
        'position_unavailable' => [
            'fr' => 'Je n\'ai pas reçu votre position. Indiquez le nom de votre quartier ou de votre ville.',
        ],
        'no_structure' => [
            'fr' => 'Aucune structure partenaire n\'est encore enregistrée près de ce lieu. Rendez-vous au commissariat ou à la brigade la plus proche.',
            'wo' => 'Amagul bérab bu bokk ci DocTrack ci wetu bérab boobu. Demal ci commissariat walla brigade bi gën a jege.',
        ],

        // Informations
        'what_to_bring_info' => [
            'fr' => 'Pour récupérer votre pièce, apportez : une autre pièce d\'identité si vous en avez une, votre référence de déclaration, et votre téléphone pour recevoir le code. La restitution est gratuite.',
            'wo' => 'Ngir jël sa kayit, indil : beneen kayit didantite su nga am, sa référence déclaration, ak sa téléphone ngir jot code bi. Delloo bi fey-woul dara.',
        ],
        'proxy_info' => [
            'fr' => 'Oui, un proche peut récupérer la pièce à votre place avec une procuration signée, sa propre pièce d\'identité et une copie de la vôtre si possible. L\'agent vérifie tout avant de remettre la pièce.',
            'wo' => 'Waaw, sa mbokk mën na la ko jëlal ak procuration bu nga xaatim, ak kayit didantiteem, ak copie bu sa bos su nekkee. Agent bi dina saytu lépp balaa muy joxe kayit bi.',
        ],

        // Relais humain
        'handoff_done' => [
            'fr' => 'J\'ai transmis votre demande à un agent DocTrack. Il vous recontactera au numéro de votre compte.',
            'wo' => 'Yónnee naa sa laaj ci ab agent DocTrack. Dina la woo ci nimero bi nekk ci sa compte.',
        ],
        'handoff_guest' => [
            'fr' => 'Pour parler à un agent, connectez-vous à votre compte DocTrack, ou rendez-vous au commissariat ou à la brigade la plus proche.',
            'wo' => 'Ngir wax ak ab agent, duggal ci sa compte DocTrack, walla nga dem ci commissariat walla brigade bi gën a jege.',
        ],

        // Voix
        'voice_not_understood' => [
            'fr' => 'Je n\'ai rien entendu de clair. Parlez près du téléphone et réessayez, ou écrivez votre message.',
            'wo' => 'Dégguma dara bu leer. Waxal ci wetu téléphone bi te jéemaat, walla nga bind sa message.',
        ],
        'voice_unavailable' => [
            'fr' => 'La reconnaissance vocale n\'est pas disponible pour le moment. Écrivez votre message, je vous réponds tout de suite.',
            'wo' => 'Dégg baat bi amul léegi. Bindal sa message, ma tontu la léegi.',
        ],
    ],
];
