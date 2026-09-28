<?php

namespace App\Services\Billing\Fulfillers;

use App\Models\Charge;
use App\Models\DocumentTag;
use App\Models\User;
use Ramsey\Uuid\Uuid;

/** Renouvellement réglé → le nouveau QR s'active et l'ancien est désactivé. */
class QrRenewalFulfiller implements ChargeFulfiller
{
    public function fulfill(Charge $charge): void
    {
        $subject = $charge->subject;

        if ($subject instanceof DocumentTag && $subject->status === DocumentTag::STATUS_PENDING_PAYMENT) {
            $subject->update(['status' => DocumentTag::STATUS_ACTIVE, 'activated_at' => now()]);
            $subject->replaces?->update(['status' => DocumentTag::STATUS_REPLACED, 'revoked_at' => now()]);
        }

        if ($subject instanceof User) { // QR personnel (V1)
            $subject->forceFill([
                'qr_code_token'      => Uuid::uuid4()->toString(),
                'qr_code_expires_at' => now()->addMonths(config('doctrack.qr_code_validity_months')),
            ])->save();
        }
    }
}
