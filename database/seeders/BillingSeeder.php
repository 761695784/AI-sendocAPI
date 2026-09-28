<?php

namespace Database\Seeders;

use App\Services\Billing\BillingManager;
use Illuminate\Database\Seeder;

/** Catalogue et tarifs initiaux (config/billing.php) — idempotent. */
class BillingSeeder extends Seeder
{
    public function run(BillingManager $billing): void
    {
        foreach (array_keys(config('billing.catalog')) as $code) {
            $billing->quote($code);
        }
    }
}
