<?php

namespace App\Http\Controllers;

use App\Models\Charge;

/** Présentation d'un frais (reçu) commune aux API citoyen et agent. */
final class ChargeSummary
{
    public static function of(?Charge $c, bool $detailed = false): ?array
    {
        if (! $c) {
            return null;
        }
        $c->loadMissing(['service', 'organization', 'payments']);
        $paid = $c->payments->firstWhere('status', 'succeeded');

        return [
            'uuid'             => $c->uuid,
            'reference'        => $c->reference,
            'service'          => ['code' => $c->service?->code, 'name' => $c->service?->name],
            'status'           => $c->status,
            'amount'           => $c->amount,
            'list_amount'      => $c->list_amount,
            'currency'         => $c->currency,
            'organization'     => $c->organization ? ['uuid' => $c->organization->uuid, 'name' => $c->organization->name] : null,
            'paid_with'        => $paid?->provider,
            'settled_at'       => $c->settled_at,
            'exemption_reason' => $c->exemption_reason,
            'created_at'       => $c->created_at,
        ] + ($detailed ? [
            'authority_amount'    => $c->authority_amount,
            'platform_amount'     => $c->platform_amount,
            'authority_share_pct' => $c->authority_share_pct,
            'payments'            => $c->payments->map(fn ($p) => [
                'uuid' => $p->uuid, 'provider' => $p->provider, 'status' => $p->status, 'amount' => $p->amount,
                'confirmed_at' => $p->confirmed_at, 'transaction_id' => $p->transaction_id,
            ])->values()->all(),
        ] : []);
    }
}
