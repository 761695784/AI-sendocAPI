<?php

namespace App\Providers;

use App\Http\Middleware\ResolveActiveOrganization;
use App\Services\Access\OrganizationAccess;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped" : une instance par requête (y compris sous Octane).
        $this->app->scoped(OrganizationAccess::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping(); // ← retourne tableau direct sans "data"

        // Audit : structure active, IP et navigateur dans chaque entrée.
        Activity::saving(function (Activity $activity) {
            if ($activity->organization_id === null && ($org = ResolveActiveOrganization::current())) {
                $activity->organization_id = $org->id;
            }
            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                $request = request();
                $activity->ip_address ??= $request->ip();
                $activity->user_agent ??= mb_substr((string) $request->userAgent(), 0, 255) ?: null;
            }
        });
    }
}
