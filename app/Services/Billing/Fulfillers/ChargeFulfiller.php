<?php

namespace App\Services\Billing\Fulfillers;

use App\Models\Charge;

/** Ce qui se passe une fois un frais réglé (payé, exonéré ou gratuit en pilote). */
interface ChargeFulfiller
{
    public function fulfill(Charge $charge): void;
}
