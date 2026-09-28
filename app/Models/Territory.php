<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Territory extends Model
{
    use HasFactory;

    public const LEVEL_REGION     = 'region';
    public const LEVEL_DEPARTMENT = 'department';
    public const LEVEL_COMMUNE    = 'commune';

    protected $fillable = ['code', 'name', 'level', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(Territory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Territory::class, 'parent_id');
    }
}
