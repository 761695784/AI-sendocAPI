<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Str;
use App\Notifications\ResetPassword;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable  implements JWTSubject
{
    use HasFactory, HasRoles, Notifiable, LogsActivity, SoftDeletes;

    public const TYPE_CITIZEN = 'citizen';
    public const TYPE_AGENT   = 'agent';
    public const TYPE_ADMIN   = 'admin';

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /** Rôles globaux (Spatie). Les rôles d'agent sont portés par organization_memberships. */
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_CITIZEN     = 'citizen';
    public const ROLE_AUDITOR     = 'auditor';

    /** Anciens noms renvoyés au front V1 en plus des nouveaux. */
    public const LEGACY_ROLE_NAMES = [
        self::ROLE_SUPER_ADMIN => 'Admin',
        self::ROLE_CITIZEN     => 'SimpleUser',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'qr_code_token',
        'qr_code_expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'qr_code_expires_at' => 'datetime',
            'phone_verified_at'  => 'datetime',
            'last_login_at'      => 'datetime',
        ];
    }


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['FirstName', 'LastName', 'email', 'Phone', 'Adress'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
    public function documents()
   {
     return $this->hasMany(Document::class);
   }

   public function declarations(){
     return $this->hasMany(DeclarationDePerte::class);
   }
   public function commentaires(){
     return $this->hasMany(Commentaire::class);
   }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Rôles globaux + anciens noms (compatibilité front V1). */
    public function roleNamesForApi(): array
    {
        $names = $this->getRoleNames()->all();
        foreach ($names as $name) {
            if (isset(self::LEGACY_ROLE_NAMES[$name])) {
                $names[] = self::LEGACY_ROLE_NAMES[$name];
            }
        }

        return array_values(array_unique($names));
    }

    // ── Organisations ─────────────────────────────────

    public function memberships()
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function citizenProfile()
    {
        return $this->hasOne(CitizenProfile::class);
    }

    public function activeMemberships()
    {
        return $this->memberships()->active();
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['role', 'matricule', 'is_primary', 'ends_at'])
            ->wherePivotNull('ends_at');
    }

   public function sendPasswordResetNotification($token)
{
    $this->notify(new ResetPassword($token));
}

protected static function boot()
{
    parent::boot();

    static::creating(function ($model) {
        $model->uuid = (string) Str::uuid();
    });
}

}
