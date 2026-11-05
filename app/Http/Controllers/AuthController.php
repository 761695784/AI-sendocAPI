<?php
namespace App\Http\Controllers;

use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Models\Activity;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Support\QrCodeUrl;

class AuthController extends Controller
{

    /**
     * Consulter tous les logs d'activité — admin uniquement.
     * ?q= recherche (description, nom ou email de l'auteur) ; ?per_page= (10 à 50).
     */
    public function getActivityLogs(Request $request)
    {
        if (! Auth::user()?->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
        }

        return $this->activityLogsResponse($request, null);
    }

    /**
     * Logs filtrés par type de sujet
     */
    public function getLogsByType(string $subjectType, Request $request)
    {
        if (! Auth::user()?->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Accès refusé.'], 403);
        }

        $map = [
            'documents'    => Document::class,
            'declarations' => DeclarationDePerte::class,
            'users'        => User::class,
        ];

        if (! isset($map[$subjectType])) {
            return response()->json(['success' => false, 'message' => 'Type de journal inconnu.'], 422);
        }

        return $this->activityLogsResponse($request, $map[$subjectType]);
    }

    /**
     * Page de journal légère : on ne charge plus l'objet concerné en entier (photos, textes longs,
     * relations…), seulement un résumé. C'est ce qui faisait « geler » l'écran du journal.
     */
    private function activityLogsResponse(Request $request, ?string $subjectClass)
    {
        $perPage = max(10, min(50, (int) $request->query('per_page', 20)));
        $search  = trim((string) $request->query('q', ''));

        $logs = Activity::query()
            ->with(['causer' => fn ($q) => $q->select('id', 'FirstName', 'LastName', 'email')])
            ->select('id', 'log_name', 'description', 'subject_type', 'subject_id',
                'causer_type', 'causer_id', 'properties', 'event', 'created_at')
            ->when($subjectClass, fn ($q) => $q->where('subject_type', $subjectClass))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)
                    ->orWhereHasMorph('causer', [User::class], fn ($c) => $c->where('FirstName', 'like', $like)
                        ->orWhere('LastName', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            })
            ->latest('id')
            ->paginate($perPage);

        $logs->getCollection()->transform(fn (Activity $a) => [
            'id'            => $a->id,
            'log_name'      => $a->log_name,
            'description'   => $a->description,
            'event'         => $a->event,
            'created_at'    => $a->created_at,
            'subject_type'  => $a->subject_type,
            'subject_label' => $a->subject_type ? class_basename($a->subject_type) : null,
            'subject_id'    => $a->subject_id,
            'causer'        => $a->causer ? [
                'FirstName' => $a->causer->FirstName ?? null,
                'LastName'  => $a->causer->LastName ?? null,
                'email'     => $a->causer->email ?? null,
            ] : null,
            'properties'    => self::summarizeLogProperties($a->properties),
        ]);

        return response()->json($logs);
    }

    /**
     * Propriétés d'un log réduites à une liste « clé → valeur » lisible :
     * 20 au plus, textes coupés à 160 caractères, données sensibles retirées.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private static function summarizeLogProperties($properties): array
    {
        $props = collect($properties ?? [])->toArray();
        // Spatie : { attributes: {...}, old: {...} } pour les modifications de modèles.
        $source = isset($props['attributes']) && is_array($props['attributes']) ? $props['attributes'] : $props;
        $hidden = '/(password|token|secret|hash|encrypted|remember|verification_code|docidentification|doc_number|id_number)/i';

        $out = [];
        foreach ($source as $key => $value) {
            if (count($out) >= 20) {
                break;
            }
            if ($key === 'id' || preg_match($hidden, (string) $key)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'oui' : 'non';
            }
            $out[] = ['key' => (string) $key, 'value' => Str::limit((string) ($value ?? '—'), 160)];
        }

        return $out;
    }

    /**
     * Inscription d'un utilisateur
     */
    public function register(Request $request)
    {
        // Validation des données
        $validator = Validator::make($request->all(), [
            'FirstName' => 'required|string|max:40',
            'LastName'  => 'required|string|max:20',
            'Adress'    => 'required|string|max:100',
            'Phone'     => ['required', 'string', 'max:20', \App\Support\PhoneNumbers::uniqueRule()],
            'email'     => 'required|string|email|max:50|unique:users',
            'password'  => 'required|string|min:8|confirmed',
        ]);

        // Gestion des erreurs de validation
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Échec de l\'inscription. Veuillez vérifier les erreurs ci-dessous.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Générer un token unique pour le QR code avec Ramsey UUID V4
        $qrCodeToken    = Uuid::uuid4()->toString();
        $expirationDate = now()->addMonths(config('doctrack.qr_code_validity_months'));

        // Générer un code à 6 chiffres pour Verification de la veracité de l'existence de l'email
        $email_verification_code = (string) random_int(100000, 999999);
        // Création de l'utilisateur
        $user = User::create([
            'FirstName'               => $request->FirstName,
            'LastName'                => $request->LastName,
            'Adress'                  => $request->Adress,
            'Phone'                   => $request->Phone,
            'email'                   => $request->email,
            'password'                => Hash::make($request->password),
            'qr_code_token'           => $qrCodeToken,
            'qr_code_expires_at'      => $expirationDate,
            'email_verification_code' => $email_verification_code,
        ]);
        // Envoi du code de verification par mail
        Mail::raw("Bienvenue sur DocTrack ! Votre code de vérification est : {$email_verification_code}", function ($message) use ($user) {
            $message->to($user->email)
                ->subject('Vérification de votre adresse email');
        });

        // Rôle citoyen par défaut
        $user->assignRole(User::ROLE_CITIZEN);

        // Le QR code n'est plus écrit sur le disque public : son image est
        // générée à la demande via une URL signée et temporaire.

        // ── Log d'activité ──
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'email'     => $user->email,
                'FirstName' => $user->FirstName,
                'LastName'  => $user->LastName,
            ])
            ->log('Nouvel utilisateur inscrit');

        // Génération du token JWT
        $token = JWTAuth::fromUser($user);

        // Retourner un message de succès
        return response()->json([
            'success'     => true,
            'message'     => 'Inscription réussie ! Bienvenue sur la plateforme.',
            'user'        => $user,
            'token'       => $token,
            'qr_code_url' => QrCodeUrl::imageFor($user),
        ], 201);
    }

    /**
     * Connexion d'un utilisateur
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // 5 échecs max par couple email + IP, blocage 5 minutes.
        $throttleKey = 'login:' . Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'success' => false,
                'message' => 'Trop de tentatives. Veuillez attendre ' . $seconds . ' secondes avant de réessayer.',
            ], 429);
        }

        $credentials = $request->only('email', 'password');

        // Même réponse pour un email inconnu et un mauvais mot de passe :
        // on ne révèle pas quels comptes existent.
        if (! $token = JWTAuth::attempt($credentials)) {
            RateLimiter::hit($throttleKey, 300);

            activity()
                ->withProperties(['ip' => $request->ip()])
                ->log('Tentative de connexion échouée');

            return response()->json([
                'success' => false,
                'message' => 'Vos identifiants sont invalides. Veuillez réessayer.',
            ], 401);
        }

        /** @var User $user */
        $user = JWTAuth::setToken($token)->toUser();

        // Email non vérifié : on ne le dit qu'après un mot de passe correct.
        if (is_null($user->email_verified_at) && ! $user->isSuperAdmin()) {
            JWTAuth::setToken($token)->invalidate();
            return response()->json([
                'success' => false,
                'message' => 'Email non vérifié.',
            ], 403);
        }

        if (! $user->isActive()) {
            JWTAuth::setToken($token)->invalidate();
            return response()->json([
                'success' => false,
                'message' => 'Ce compte est suspendu. Contactez votre responsable.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $roles = $user->roleNamesForApi();

        activity()
            ->causedBy($user)
            ->withProperties([
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log('Connexion réussie');

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie !',
            'user'    => $user,
            'token'   => $token,
            'roles'   => $roles,
            'organizations' => $this->organizationsSummary($user),
        ], 200);
    }

    /**
     * Affectations actives de l'utilisateur (vide pour un citoyen).
     */
    private function organizationsSummary(User $user): array
    {
        return app(\App\Services\Access\OrganizationAccess::class)->memberships($user)
            ->map(fn ($m) => [
                'membership_uuid' => $m->uuid,
                'organization'    => [
                    'uuid' => $m->organization->uuid,
                    'name' => $m->organization->name,
                ],
                'role'       => $m->role,
                'is_primary' => $m->is_primary,
            ])->values()->all();
    }
    /**
     * Déconnexion de l'utilisateur (invalidation du token)
     */
    public function logout()
    {
        // ── Log déconnexion ──
        activity()
            ->causedBy(Auth::user())
            ->log('Déconnexion');

        Auth::logout();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie. À bientôt !',
        ], 200);
    }

    /**
     * Récupérer les informations de l'utilisateur authentifié
     */
    public function me()
    {
        $user  = Auth::user();
        $roles = $user->roleNamesForApi();

        return response()->json([
            'success'     => true,
            'user'        => $user,
            'roles'       => $roles,
            'qr_code_url'        => QrCodeUrl::imageFor($user),
            'qr_code_expires_at' => $user->qr_code_expires_at,
            'organizations'      => $this->organizationsSummary($user),
        ], 200);
    }

    /**
     * Rafraîchir le token
     */
    public function refresh()
    {
        return response()->json([
            'success' => true,
            'token'   => Auth::refresh(),
            'message' => 'Le token a été rafraîchi avec succès.',
        ], 200);
    }

    /**
     * Modification du mot de passe de l'utilisateur
     */
    public function changePassword(Request $request)
    {
        // Validation des données entrées
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string|min:8',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        // Gestion des erreurs de validation
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les entrées.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        // Vérifier que le mot de passe actuel est correct
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 400);
        }

        // Mettre à jour le mot de passe de l'utilisateur
        $user->password = Hash::make($request->new_password);
        $user->save();

        // ── Log ──
        /** @var \App\Models\User $user */
        $user = Auth::user();
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->log('Mot de passe modifié');

        // Retourner un message de succès
        return response()->json([
            'success' => true,
            'message' => 'Votre mot de passe a été mis à jour avec succès.',
        ], 200);
    }

    /**
     * Methode pour la liste des utilisateurs avec leurs rôles
     */
    public function getAllUsersWithRoles()
    {
        // Vérifier si l'utilisateur est authentifié et a le rôle 'Admin'
        if (! Auth::user() || ! Auth::user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour voir cette liste.',
            ], 403);
        }

        // Récupérer tous les utilisateurs avec leurs rôles
        $users = User::with('roles')->get();

        // Parcourir chaque utilisateur pour récupérer son ou ses rôles
        $usersWithRoles = $users->map(function ($user) {
            return [
                'id'        => $user->id,
                'uuid'      => $user->uuid,
                'FirstName' => $user->FirstName,
                'LastName'  => $user->LastName,
                'Adress'    => $user->Adress,
                'Phone'     => $user->Phone,
                'email'     => $user->email,
                'account_type' => $user->account_type,
                'status'    => $user->status,
                'roles'     => $user->roleNamesForApi(),
            ];
        });

        // Retourner la réponse en JSON
        return response()->json([
            'success' => true,
            'users'   => $usersWithRoles,
        ], 200);
    }

    /**
     * Methode pour la suppression d'un user par l'admin
     */

    // public function deleteUser($id)
    public function deleteUser($uuid)
    {
        // Vérifier si l'utilisateur est authentifié et a le rôle 'Admin'
        if (! Auth::user() || ! Auth::user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Trouver l'utilisateur à supprimer
        // $user = User::find($id);
        // Trouver l'utilisateur à supprimer par son uuid
        $user = User::where('uuid', $uuid)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé.',
            ], 404);
        }

        // Vérifier que l'utilisateur a le rôle 'SimpleUser'
        if ($user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas supprimer un autre administrateur.',
            ], 403);
        }

        // ── Log ──
        activity()
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->withProperties(['deleted_uuid' => $user->uuid, 'email' => $user->email])
            ->log('Utilisateur supprimé par un administrateur');

        // Pas de suppression physique : les déclarations, publications et
        // journaux d'audit restent liés à un compte anonymisé.
        $user->forceFill([
            'FirstName'               => 'Utilisateur',
            'LastName'                => 'supprimé',
            'Adress'                  => '-',
            'Phone'                   => '-',
            'email'                   => 'deleted+' . $user->uuid . '@doctrack.invalid',
            'password'                => Hash::make(Str::random(40)),
            'email_verification_code' => null,
            'qr_code_token'           => null,
            'qr_code_expires_at'      => null,
            'status'                  => User::STATUS_SUSPENDED,
        ])->saveQuietly();
        $user->memberships()->whereNull('ends_at')->update(['ends_at' => now()]);
        // La fiche du guichet (déclarations officielles) est conservée mais n'est plus liée au compte supprimé.
        \App\Models\CitizenProfile::where('user_id', $user->id)->update(['user_id' => null]);
        $user->delete(); // soft delete

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé avec succès.',
        ], 200);
    }

    /**
     * Methode pour la creation d'un admin
     */

    public function createAdmin(Request $request)
    {
        // Vérifier si l'utilisateur est authentifié et a le rôle 'Admin'
        if (! Auth::user() || ! Auth::user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Vous devez être un administrateur pour effectuer cette action.',
            ], 403);
        }

        // Validation des données
        $validator = Validator::make($request->all(), [
            'FirstName' => 'required|string|max:40',
            'LastName'  => 'required|string|max:20',
            'Adress'    => 'required|string|max:100',
            'Phone'     => ['required', 'string', 'max:20', \App\Support\PhoneNumbers::uniqueRule()],
            'email'     => 'required|string|email|max:50|unique:users',
            'password'  => 'required|string|min:8|confirmed',
        ]);

        // Gestion des erreurs de validation
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Échec de la création de l\'utilisateur. Veuillez vérifier les erreurs ci-dessous.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Création de l'utilisateur
        $user = User::create([
            'FirstName' => $request->FirstName,
            'LastName'  => $request->LastName,
            'Adress'    => $request->Adress,
            'Phone'     => $request->Phone,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
        ]);

        $user->forceFill([
            'account_type'      => User::TYPE_ADMIN,
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        // Génération du token JWT
        $token = JWTAuth::fromUser($user);

        // ── Log ──
        activity()
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->withProperties(['new_admin_email' => $user->email])
            ->log('Nouvel administrateur créé');

        // Retourner un message de succès
        return response()->json([
            'success' => true,
            'message' => 'Utilisateur Admin créé avec succès !',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    /**
     * Méthode pour mettre à jour les informations du profil de l'utilisateur
     */
    public function updateProfile(Request $request)
    {
        // Vérifier si l'utilisateur est authentifié
        if (! Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être connecté pour modifier votre profil.',
            ], 401);
        }

        // Validation des données entrées
        $validator = Validator::make($request->all(), [
            'FirstName' => 'sometimes|required|string|max:40',
            'LastName'  => 'sometimes|required|string|max:20',
            'Adress'    => 'sometimes|required|string|max:100',
            'Phone'     => ['sometimes', 'required', 'string', 'max:20', \App\Support\PhoneNumbers::uniqueRule(Auth::id())],
            'email'     => 'sometimes|required|string|email|max:50|unique:users,email,' . Auth::id(),
        ]);

        // Gestion des erreurs de validation
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation. Veuillez vérifier les entrées.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        // Mettre à jour les informations de l'utilisateur
        $user->update($request->only('FirstName', 'LastName', 'Adress', 'Phone', 'email'));

        // ── Log ──
        /** @var \App\Models\User $user */
        $user = Auth::user();
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties($request->only('FirstName', 'LastName', 'Adress', 'Phone', 'email'))
            ->log('Profil utilisateur mis à jour');

        // Retourner un message de succès
        return response()->json([
            'success' => true,
            'message' => 'Informations du profil mises à jour avec succès.',
            'user'    => $user,
        ], 200);
    }

    /**
     * Envoyer un lien de réinitialisation de mot de passe
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = Str::lower(trim($request->input('email')));
        $user  = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if ($user->email_verified_at === null && $user->isCitizen()) {
                // Compte créé au guichet, jamais activé : on renvoie le lien d'activation (7 jours).
                app(\App\Services\Citizens\CitizenAccountService::class)->sendActivation($user);
            } else {
                Password::sendResetLink(['email' => $user->email]);
            }
        } else {
            // Personne enregistrée au guichet avec cet email, mais sans compte : on crée son compte
            // et on lui envoie le lien pour choisir son mot de passe (activation en un clic).
            $profile = \App\Models\CitizenProfile::whereRaw('LOWER(email) = ?', [$email])->whereNull('user_id')->latest('id')->first();
            if ($profile) {
                try {
                    app(\App\Services\Citizens\CitizenAccountService::class)->createForProfile($profile, $email, null, $profile->createdByOrganization);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Log::info('Activation depuis « mot de passe oublié » impossible pour une fiche du guichet', ['profile' => $profile->uuid]);
                }
            }
        }

        // Réponse identique que le compte existe ou non (pas d'énumération).

        return response()->json([
            'success' => true,
            'message' => 'Si un compte (ou une fiche enregistrée au guichet) existe pour cet email, un lien pour choisir votre mot de passe vient d\'être envoyé.',
        ], 200);
    }

    /**
     * Réinitialiser le mot de passe
     */
    public function resetPassword(Request $request)
    {
        // Validation des données
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Réinitialisation du mot de passe. Le même écran sert à l'activation d'un compte créé au guichet
        // (lien valable 7 jours, broker « activations ») : choisir son mot de passe prouve aussi l'email.
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $apply = function ($user, $password) {
            $user->forceFill([
                'password'          => Hash::make($password),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            app(\App\Services\Citizens\CitizenAccountService::class)->attachOrphanProfiles($user);
        };

        $status = Password::reset($credentials, $apply);
        if ($status === Password::INVALID_TOKEN) {
            $status = Password::broker(\App\Services\Citizens\CitizenAccountService::ACTIVATION_BROKER)->reset($credentials, $apply);
        }

        return $status === Password::PASSWORD_RESET
            ? response()->json(['success' => true, 'message' => __($status)], 200)
            : response()->json(['success' => false, 'message' => __($status)], 400);
    }

    /**
     * Connecter un utilisateur avec un compte Google
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            // Récupérer le token envoyé par le front-end
            $token = $request->input('token');

            // Récupérer l'utilisateur Google à partir du token
            $googleUser = Socialite::driver('google')->userFromToken($token);

            // Vérifier que les données essentielles sont présentes
            if (! $googleUser || ! $googleUser->email) {
                throw new \Exception('Données utilisateur Google invalides');
            }

            // Chercher un utilisateur existant avec cet email
            $user = User::where('email', $googleUser->email)->first();

            if (! $user) {
                // Si l'utilisateur n'existe pas, on renvoie une réponse indiquant
                // que certaines informations complémentaires sont requises pour finaliser l'inscription.
                return response()->json([
                    'success'         => false,
                    'message'         => 'Veuillez fournir votre adresse et votre numéro de téléphone pour finaliser la création du compte.',
                    'required_fields' => ['Adress', 'Phone'],
                    'google_user'     => [
                        'email'      => $googleUser->email,
                        'first_name' => $googleUser->user['given_name'] ?? $googleUser->name,
                        'last_name'  => $googleUser->user['family_name'] ?? '',
                    ],
                ], 400);
            }

            if (! $user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce compte est suspendu. Contactez votre responsable.',
                ], 403);
            }

            $jwtToken = JWTAuth::fromUser($user);
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            // ── Log ──
            activity()
                ->causedBy($user)
                ->withProperties(['provider' => 'google', 'ip' => $request->ip()])
                ->log('Connexion via Google');

            return response()->json([
                'success' => true,
                'message' => 'Connexion avec Google réussie !',
                'user'    => $user,
                'token'   => $jwtToken,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erreur Google Auth : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Échec de la connexion avec Google. Veuillez réessayer.',
            ], 401);
        }
    }

    /**
     * Finaliser l'inscription avec Google
     */
    public function finalizeAccountCreation(Request $request)
    {
        try {
            // Valider les données reçues, y compris le token Google
            $request->validate([
                'email'  => 'required|email',
                'Adress' => 'required|string',
                'Phone'  => 'required|string',
                'token'  => 'required|string',
            ]);

            $token = $request->input('token');

            // Revalider l'utilisateur Google à partir du token
            $googleUser = Socialite::driver('google')->userFromToken($token);
            if (! $googleUser || ! $googleUser->email || $googleUser->email !== $request->input('email')) {
                throw new \Exception('Les informations de Google ne correspondent pas.');
            }

            // Vérifier si un utilisateur avec cet email existe déjà
            if (User::where('email', $request->input('email'))->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un utilisateur avec cet email existe déjà.',
                ], 400);
            }

            // Créer l'utilisateur avec un mot de passe aléatoire (puisque la connexion se fait via Google)
            $user = User::create([
                'FirstName' => $googleUser->user['given_name'] ?? $googleUser->name,
                'LastName'  => $googleUser->user['family_name'] ?? '',
                'email'     => $request->input('email'),
                'password'  => Hash::make(Str::random(16)), // Mot de passe généré de façon aléatoire
                'Adress'    => $request->input('Adress'),
                'Phone'     => $request->input('Phone'),
                'email_verified_at'  => now(), // email déjà vérifié par Google
                'qr_code_token'      => Uuid::uuid4()->toString(),
                'qr_code_expires_at' => now()->addMonths(config('doctrack.qr_code_validity_months')),
            ]);

            // Rôle citoyen par défaut
            $user->assignRole(User::ROLE_CITIZEN);

            // Générer un token JWT pour l'utilisateur nouvellement créé
            $jwtToken = JWTAuth::fromUser($user);

            // ── Log ──
            activity()
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['provider' => 'google', 'email' => $user->email])
                ->log('Compte créé via Google');

            return response()->json([
                'success' => true,
                'message' => 'Compte créé avec succès !',
                'user'    => $user,
                'token'   => $jwtToken,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erreur finalisation compte Google : ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Échec de la création du compte. Veuillez réessayer.',
            ], 400);
        }
    }

    /**
     * Renouvelle le QR code de l'utilisateur authentifié.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function renewQrCode(Request $request)
    {
        /** @var \App\Models\User $user */
        $user   = Auth::user();
        $charge = app(\App\Services\Billing\BillingManager::class)->bill('qr_renewal', $user, null, $user, null, $user);

        // Mode live : le renouvellement attend le paiement (Wave).
        if (! $charge->isSettled()) {
            return response()->json([
                'success'          => false,
                'payment_required' => true,
                'message'          => 'Le renouvellement du QR code coûte ' . $charge->amount . ' FCFA. Payez par Wave pour l\'activer.',
                'charge'           => \App\Http\Controllers\ChargeSummary::of($charge),
            ], 402);
        }

        $user->refresh();
        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['new_expiration' => $user->qr_code_expires_at, 'charge' => $charge->reference])
            ->log('QR Code renouvelé');

        return response()->json([
            'success'        => true,
            'message'        => 'QR code renouvelé avec succès.',
            'qr_code_url'    => QrCodeUrl::imageFor($user),
            'new_expiration' => $user->qr_code_expires_at,
            'charge'         => \App\Http\Controllers\ChargeSummary::of($charge),
        ], 200);
    }
}
