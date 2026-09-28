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
        'message', 'is_read', 'declaration_de_perte_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function declaration()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }
}
