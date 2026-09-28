<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Service facturable du catalogue (table billing_services). */
class BillableService extends Model
{
    protected $table = 'billing_services';

    protected $fillable = ['code', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function priceRules()
    {
        return $this->hasMany(PriceRule::class, 'billing_service_id');
    }
}
