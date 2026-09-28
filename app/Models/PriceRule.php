<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Tarif daté d'un service (national si organization_id est null). */
class PriceRule extends Model
{
    use LogsActivity;

    protected $fillable = ['billing_service_id', 'organization_id', 'amount', 'currency', 'authority_share_pct', 'valid_from', 'valid_to', 'created_by', 'note'];

    protected $casts = ['valid_from' => 'datetime', 'valid_to' => 'datetime', 'amount' => 'integer', 'authority_share_pct' => 'integer'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['amount', 'authority_share_pct', 'valid_from', 'valid_to', 'organization_id'])->logOnlyDirty();
    }

    public function service()
    {
        return $this->belongsTo(BillableService::class, 'billing_service_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
