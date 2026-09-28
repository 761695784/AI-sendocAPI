<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationType extends Model
{
    use HasFactory;

    public const LEVEL_INSTITUTION  = 'institution';
    public const LEVEL_INTERMEDIATE = 'intermediate';
    public const LEVEL_STRUCTURE    = 'structure';

    public const LEVELS = [self::LEVEL_INSTITUTION, self::LEVEL_INTERMEDIATE, self::LEVEL_STRUCTURE];

    protected $fillable = ['code', 'name', 'level'];

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }
}
