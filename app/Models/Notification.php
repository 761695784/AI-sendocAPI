<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Notification extends Model
{
    use HasFactory;

    // protected $guarded = [];
    protected $fillable = [
        'message', 'user_id', 'is_read',
    ];

    public function user()
    {
        return $this->belongsTo(User::class); // Assurez-vous que cela correspond à votre modèle utilisateur
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }
}
