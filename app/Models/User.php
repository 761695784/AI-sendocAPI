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
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable  implements JWTSubject
{
    use HasFactory, HasRoles,Notifiable; use LogsActivity;

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
