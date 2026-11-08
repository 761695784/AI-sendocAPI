# Base de connaissances de l'assistant DocTrack

<!--
  Seule source des réponses rédigées par l'IA dans la fenêtre « Une question ? ».
  Règles d'écriture :
  - une section = un sujet, titre « ## Titre {#identifiant} » (identifiant en minuscules, sans accents, avec « _ ») ;
  - phrases simples, faits vérifiés uniquement : l'IA ne peut citer QUE ce qui est écrit ici ;
  - ligne facultative « Liens : [Libellé](/chemin) » : boutons affichés sous la réponse (non envoyée à l'IA) ;
    liens externes acceptés : https://wa.me/… et mailto:… ;
  - tout nombre (délai, nombre d'essais…) doit être écrit ici pour que l'IA ait le droit de le donner ;
  - jamais de données personnelles réelles, de numéros de pièces ni de mots de passe.
  Une question sans réponse ici est renvoyée vers le service technique (section « contact »).
  Banc d'essai : resources/assistant/questions-citoyens.txt → php artisan doctrack:assistant-eval
  Après modification : vider le cache (php artisan cache:clear) pour effacer les anciennes réponses.
-->

## Présentation de DocTrack {#presentation}
DocTrack est une plateforme sénégalaise en ligne pour déclarer la perte d'un document officiel, publier un document trouvé, rechercher les documents retrouvés et organiser leur restitution à leur propriétaire.
DocTrack est développée par Majeli Connect. Ce n'est pas un service de l'État ; la plateforme travaille avec des structures participantes (commissariats, brigades de gendarmerie) qui ont un espace agent.
DocTrack centralise les déclarations de perte et les documents trouvés, puis compare automatiquement les deux pour prévenir le propriétaire quand un document qui lui correspond est publié ou déposé.
Le site est accessible partout au Sénégal avec une connexion internet. Les structures participantes, elles, ne sont pas présentes dans toutes les localités : l'assistant indique celles qui existent près de chez vous.
DocTrack ne cherche pas physiquement les documents : il met en relation les personnes qui ont perdu un document et celles qui l'ont trouvé, avec l'aide des structures participantes.
Liens : [À propos](/apropos)

## Ce que DocTrack ne fait pas {#limites}
DocTrack ne fabrique pas et ne renouvelle pas les pièces d'identité : pour refaire un document, il faut s'adresser à l'administration compétente.
DocTrack ne garantit pas qu'un document perdu sera retrouvé : cela dépend du fait qu'une personne le trouve et le publie ou le dépose.
DocTrack concerne uniquement les documents (pièces d'identité et documents officiels), pas les objets comme les téléphones, les sacs ou l'argent.
DocTrack n'enregistre pas de plainte : en cas de vol, la plainte se fait auprès de la police ou de la gendarmerie.
DocTrack ne garantit pas la sécurité des rencontres entre particuliers et n'est pas responsable des demandes d'argent entre particuliers.
DocTrack ne demande jamais d'argent pour rendre un document, ni de mot de passe ou de code reçu par e-mail ou SMS dans la discussion.

## Gratuité {#gratuite}
La création de compte, la déclaration en ligne, la recherche et la restitution d'un document sont gratuites.
Personne n'a à payer le trouveur, la structure ou DocTrack pour récupérer un document : la restitution au guichet est gratuite.
Le propriétaire n'a pas à verser de récompense au trouveur pour récupérer son document.

## Confiance et sécurité de la plateforme {#confiance}
DocTrack protège les informations : les photos publiées sont floutées, les numéros des documents sont masqués et le contact du trouveur n'est donné qu'après vérification.
Les structures participantes vérifient l'identité avant de remettre un document conservé au guichet.
DocTrack ne demande jamais d'argent.

## Créer un compte et se connecter {#compte}
Pour déclarer une perte ou publier un document trouvé en ligne, il faut un compte : il sert à vous prévenir, à suivre vos démarches et à protéger vos informations. La consultation de la liste des documents retrouvés est ouverte à tous.
Pour créer un compte : « Créer un compte », puis nom, numéro de téléphone, e-mail et mot de passe. Un code à 6 chiffres est envoyé par e-mail pour activer le compte.
Le code est valable 48 heures et s'annule après 5 erreurs. Si le code n'arrive pas, regarder dans les courriers indésirables (spam) ; sinon utiliser « Mot de passe oublié » avec son e-mail : un lien permet d'activer le compte et de choisir un mot de passe.
Mot de passe oublié ou connexion impossible : sur la page de connexion, « Mot de passe oublié », puis saisir son e-mail pour recevoir un lien.
Pour changer l'adresse e-mail du compte, il faut saisir son mot de passe actuel ; l'ancienne adresse est prévenue du changement.
Une personne qui a déclaré sa perte au guichet et dont l'e-mail a été enregistré peut utiliser « Mot de passe oublié » avec cet e-mail pour activer son compte et retrouver sa déclaration dans « Mon espace ».
Liens : [Créer un compte](/inscription), [Mot de passe oublié](/reset)

## Sans e-mail, sans compte ou sans savoir lire {#sans_compte}
Un compte en ligne demande une adresse e-mail. Sans e-mail ou sans compte, vous pouvez vous présenter au guichet d'une structure participante : l'agent enregistre la déclaration de perte et vous remet un certificat de perte.
Si vous avez du mal à lire ou à écrire, un proche peut vous aider à utiliser le site, ou vous pouvez aller au guichet d'une structure participante.
L'assistant comprend des expressions courantes en wolof. Un assistant vocal en wolof est en préparation : il n'est pas encore disponible.

## Utiliser DocTrack sur téléphone {#utilisation}
DocTrack est un site internet : il fonctionne sur téléphone et sur ordinateur, avec une connexion internet.
Sur certains téléphones, le navigateur propose d'installer le site comme une application ; il n'est pas nécessaire de passer par une boutique d'applications.
Un assistant DocTrack sur WhatsApp est en préparation : il n'est pas encore disponible. Le numéro WhatsApp du service technique permet déjà de poser vos questions.

## Déclarer la perte d'un document {#declarer}
Une fois connecté, ouvrir « Déclarer une perte ». Indiquer le type de document, le nom inscrit sur le document (prénom et nom), puis le lieu et la date de la perte.
Si vous ne connaissez pas le numéro du document, vous pouvez quand même déclarer ; s'il est connu, il aide à reconnaître votre document.
Dans le formulaire, les noms s'écrivent en lettres sans accents.
La déclaration reçoit aussitôt une référence qui commence par DT- et un récépissé DocTrack.
La déclaration reste active : DocTrack la compare avec chaque nouveau document publié ou déposé.
En cas de vol, déclarez le document sur DocTrack comme une perte pour être prévenu s'il est retrouvé, et portez plainte auprès de la police ou de la gendarmerie.
Vous pouvez déclarer en ligne, ou directement au guichet d'une structure participante. Il n'est pas obligatoire de passer d'abord par la police pour déclarer sur DocTrack.
Liens : [Déclarer une perte](/declarer)

## Être prévenu quand son document est retrouvé {#etre_prevenu}
Le plus sûr pour savoir si son document a été retrouvé est de déclarer sa perte sur DocTrack.
DocTrack compare automatiquement chaque nouvelle publication et chaque dépôt avec les déclarations. Dès qu'un document correspond, le propriétaire est prévenu par SMS et par e-mail.
La comparaison tolère les variantes d'orthographe des noms (par exemple Ndiaye et Njaay), les prénoms composés, les doubles noms de famille et le prénom et le nom inversés.
On peut aussi consulter soi-même la liste des documents retrouvés et chercher son nom. Si votre nom n'y apparaît pas, votre document n'a pas encore été publié ni déposé : gardez votre déclaration active pour être prévenu.
Le suivi de ses déclarations et des correspondances se fait dans « Mon espace ».
Liens : [Documents retrouvés](/liste), [Déclarer une perte](/declarer)

## Messages suspects et arnaques {#arnaques}
DocTrack ne demande jamais d'argent, de mot de passe ni de code reçu par SMS ou e-mail.
Si un message ou un appel affirme que votre document est retrouvé, vérifiez dans « Mon espace » ou dans la liste des documents retrouvés sur le site.
Si quelqu'un réclame de l'argent pour rendre un document, refusez : ne vous déplacez pas, demandez que le document passe par une structure, ou rendez-vous au commissariat ou à la brigade la plus proche. En cas de doute, contactez le service technique.
Liens : [Mon espace](/mon-espace)

## Récépissé DocTrack et certificat de perte {#recepisse_certificat}
Une déclaration faite en ligne donne un récépissé DocTrack (numéro qui commence par REC-). Il prouve que la perte a été déclarée sur DocTrack ; ce n'est pas un certificat de perte et il ne remplace pas une pièce d'identité.
Une déclaration enregistrée au guichet d'une structure participante donne un certificat de perte (numéro qui commence par CP-), au nom de la structure, avec l'agent et un QR code de vérification.
Pour obtenir un certificat après une déclaration en ligne : se présenter dans une structure participante avec la référence DT- ou le QR code du récépissé, et une pièce justificative d'identité. L'agent vérifie l'identité, complète la déclaration (lieu, date, circonstances) et délivre le certificat. Il n'y a rien à ressaisir et la déclaration n'est pas dupliquée ; le récépissé reste valable.
DocTrack ne peut pas garantir que toutes les administrations acceptent le récépissé ou le certificat : renseignez-vous auprès de l'administration concernée.

## Vérifier un récépissé ou un certificat {#verifier}
La page « Vérifier un document » permet de saisir le code imprimé ou de scanner le QR code d'un récépissé ou d'un certificat.
Elle est ouverte à tous : une administration, une banque ou un employeur peut l'utiliser sans compte.
DocTrack indique si le document est authentique et, pour une déclaration en ligne, si elle a été certifiée dans une structure.
Liens : [Vérifier un document](/verifier)

## Publier un document trouvé {#publier}
Merci aux personnes qui rendent les documents. Une fois connecté, ouvrir « Publier un document trouvé » et photographier la pièce. Chaque document trouvé se publie séparément.
DocTrack lit automatiquement les informations de la photo. La lecture est la plus complète pour la carte d'identité ; pour le passeport, le permis et la carte grise, ou si la lecture échoue (photo floue, sombre ou de travers), vérifiez et complétez vous-même les champs.
Le trouveur choisit ensuite de remettre lui-même le document à son propriétaire, ou de passer par la structure la plus proche, qui prend le relais.
Sans compte, le trouveur peut aussi remettre le document à une structure participante (commissariat ou brigade).
Après la remise, le trouveur indique « Document remis » dans « Mon espace » (ou marque sa publication comme récupérée).
Liens : [Publier un document trouvé](/publier)

## Le trouveur et ses coordonnées {#trouveur}
Le numéro du trouveur n'est donné au demandeur que si la propriété est vérifiée (déclaration correspondante ou 4 derniers caractères du numéro de la pièce) ou si le trouveur accepte la demande.
Quand une demande est envoyée au trouveur, il voit le nom du demandeur et le compare avec le document avant d'accepter ou de refuser.
Le trouveur qui ne veut pas rencontrer le propriétaire choisit « Passer par une structure » : la structure la plus proche récupère le document.
Si le propriétaire ne vient pas au rendez-vous, le trouveur peut aussi passer par une structure.

## Protection des données sur les photos publiées {#confidentialite}
Le public ne voit jamais la pièce en clair : la photo publiée est floutée et les numéros sont masqués. Aucun chiffre du numéro du document n'est affiché dans la liste publique.
La photo d'origine reste privée : seuls le publicateur et les agents habilités y ont accès.
La liste publique des documents retrouvés affiche le nom inscrit sur le document pour que le propriétaire puisse se reconnaître.
Liens : [Politique de confidentialité](/private-policy)

## Récupérer son document auprès du trouveur {#restitution_directe}
Sur la fiche du document, cliquer sur « C'est mon document ».
DocTrack accepte la demande directement si la déclaration du propriétaire correspond au document, ou si le propriétaire donne les 4 derniers caractères du numéro de la pièce (les 4 derniers chiffres ou lettres à la fin du numéro). Ce contrôle est limité à 3 essais par jour pour un même document.
Sans le numéro, la demande est envoyée au trouveur : il voit le nom du demandeur et le compare avec le document, puis accepte ou refuse. La demande reste en attente 48 heures.
Avant d'obtenir le contact, le propriétaire accepte un avertissement : lieu public, en journée, rien à payer.
Quand la demande est acceptée, le numéro du trouveur s'affiche et le trouveur reçoit le nom et le numéro du demandeur par SMS et par e-mail.
Après la remise, le trouveur indique « Document remis » dans son espace ; la déclaration passe alors en « restituée ».
Liens : [Documents retrouvés](/liste)

## Le trouveur ne répond pas ou refuse {#trouveur_sans_reponse}
Une demande envoyée au trouveur reste en attente 48 heures. Sans réponse, ou s'il refuse la remise directe, une structure proche peut prendre le relais pour récupérer le document.
Le trouveur peut aussi choisir lui-même « Passer par une structure ».
L'avancement se suit dans « Mon espace ».
Liens : [Mon espace](/mon-espace)

## Retirer son document au guichet d'une structure {#retrait_guichet}
Quand le document est conservé par une structure (commissariat, brigade), cliquer sur « Le retirer au guichet » sur la fiche du document.
La structure est prévenue ; le propriétaire reçoit par SMS l'adresse, le téléphone de la structure et la référence.
Se présenter avec une pièce justificative d'identité et la référence : l'agent vérifie l'identité et remet le document contre une décharge. La décharge est le document signé qui prouve la remise.
Sans autre pièce d'identité, présentez-vous quand même avec votre référence : c'est l'agent qui décide des justificatifs acceptés.
La restitution au guichet est gratuite. Pour les horaires d'ouverture, appelez la structure : son numéro est affiché sur sa fiche.
Un proche peut retirer le document à la place du propriétaire au guichet : il présente sa propre pièce d'identité et une procuration ; l'agent l'enregistre sur la décharge.

## Sécurité de la rencontre {#securite}
Pour une remise directe : se rencontrer dans un lieu public et fréquenté, de préférence en journée, idéalement accompagné.
Rien n'est à payer. Si le trouveur demande de l'argent, on peut refuser : ne pas se déplacer, demander que le document passe par une structure, ou se rendre au commissariat ou à la brigade la plus proche.
DocTrack ne garantit pas la sécurité des rencontres entre particuliers.

## QR codes DocTrack {#qr_codes}
Chaque utilisateur peut créer un QR code pour ses documents, depuis « Mon espace », et le coller dessus.
Si quelqu'un trouve la pièce et scanne le code, le propriétaire est prévenu sans que cette personne voie ses coordonnées.
Liens : [Mon espace](/mon-espace)

## Documents concernés {#types_documents}
Pièces d'identité et documents officiels : carte d'identité, passeport, permis de conduire, carte grise et les autres types proposés dans la liste du formulaire.

## Délai pour retrouver un document {#delai}
DocTrack ne peut pas prévoir le délai : tout dépend du moment où le document est trouvé, puis publié ou déposé.
Il faut garder sa déclaration active pour être prévenu automatiquement.

## Structures participantes {#structures}
Les structures participantes sont des commissariats et des brigades qui disposent d'un espace agent sur DocTrack.
Elles peuvent conserver des documents trouvés, enregistrer des déclarations au guichet, certifier une déclaration en ligne et faire la restitution officielle.
L'assistant peut indiquer les structures participantes à partir du quartier, de la ville ou de la position de l'utilisateur.
Une structure (commissariat, brigade ou autre organisation) qui souhaite rejoindre DocTrack contacte l'équipe DocTrack (voir « Contact »).

## Mon espace {#mon_espace}
« Mon espace » est la partie personnelle du compte. Elle regroupe les déclarations, les publications, les correspondances trouvées, les demandes de restitution, les remises et les QR codes de l'utilisateur.
On peut y supprimer ses publications et ses déclarations, par exemple quand on a retrouvé son document soi-même.
Liens : [Mon espace](/mon-espace)

## Supprimer son compte ou ses données {#suppression}
Les publications et déclarations se suppriment depuis « Mon espace ».
Pour supprimer son compte et ses données, contacter le service technique par e-mail à sendoctrack@gmail.com.
Liens : [Politique de confidentialité](/private-policy)

## Contact et service technique {#contact}
Service technique DocTrack : WhatsApp au +221 76 169 57 84, ou e-mail à sendoctrack@gmail.com.
Le contacter pour une question sans réponse ici, un problème sur le site ou sur un compte, ou une demande de partenariat.
Pour une démarche liée à un document conservé par une structure, contacter directement cette structure.
Liens : [WhatsApp](https://wa.me/221761695784), [E-mail](mailto:sendoctrack@gmail.com)

## L'assistant DocTrack {#assistant}
L'assistant est un programme automatique, pas une personne. Certaines réponses sont rédigées par une intelligence artificielle à partir de cette aide : il faut vérifier les informations importantes.
Il répond aux questions sur l'utilisation de DocTrack et guide pour déclarer une perte, publier un document trouvé, suivre son dossier ou trouver la structure la plus proche.
Il comprend le français et des expressions courantes en wolof.
Il ne demande jamais de mot de passe, de code reçu par e-mail ou SMS, ni le numéro complet d'une pièce. Il ne parle pas au nom de la police, de la gendarmerie ou de l'État.
Pour parler à une personne, contactez le service technique (voir « Contact »).
