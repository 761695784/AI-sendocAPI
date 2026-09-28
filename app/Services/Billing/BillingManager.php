<?php

namespace App\Services\Billing;

use App\Models\BillableService;
use App\Models\Charge;
use App\Models\CitizenProfile;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PriceRule;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Billing\Providers\PaymentProvider;
use App\Services\Billing\Providers\WaveProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Facturation : tarifs, frais, encaissements, exonérations, paiement en ligne,
 * partage des revenus autorités / DocTrack. Le modèle économique se règle
 * par la configuration (mode pilot/live, tarifs datés), jamais dans le code.
 */
class BillingManager
{
    /** Tarif en vigueur : celui de l'institution s'il existe, sinon le national. */
    public function quote(string $code, ?Organization $organization = null): PriceRule
    {
        $service = $this->service($code);
        $institutionId = $organization?->institution()->id;

        $rules = PriceRule::where('billing_service_id', $service->id)
            ->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))
            ->where(fn ($q) => $q->whereNull('organization_id')->when($institutionId, fn ($q) => $q->orWhere('organization_id', $institutionId)))
            ->orderByRaw('organization_id IS NULL')      // l'institution d'abord
            ->orderByDesc('valid_from')
            ->get();

        return $rules->first() ?? $this->seedDefaultRule($service);
    }

    /**
     * Crée le frais d'une démarche. En pilote : gratuit mais valeur théorique conservée.
     * Si le frais est réglé d'office (pilote, gratuit), la démarche est accomplie tout de suite.
     */
    public function bill(string $code, Model $subject, ?Organization $organization, ?User $payerUser = null, ?CitizenProfile $payerProfile = null, ?User $actor = null): Charge
    {
        $rule  = $this->quote($code, $organization);
        $pilot = config('billing.mode') !== 'live';
        $list  = (int) $rule->amount;

        $status = match (true) {
            $pilot && $list > 0 => Charge::STATUS_WAIVED_PILOT,
            $list === 0         => Charge::STATUS_PAID, // gratuit
            default             => Charge::STATUS_PENDING,
        };
        $amount = $status === Charge::STATUS_PENDING ? $list : 0;

        $charge = Charge::create([
            'billing_service_id'       => $rule->billing_service_id,
            'price_rule_id'            => $rule->id,
            'subject_type'             => $subject->getMorphClass(),
            'subject_id'               => $subject->getKey(),
            'organization_id'          => $organization?->id,
            'payer_user_id'            => $payerUser?->id,
            'payer_citizen_profile_id' => $payerProfile?->id,
            'currency'                 => $rule->currency,
            'list_amount'              => $list,
            'amount'                   => $amount,
            'authority_share_pct'      => $rule->authority_share_pct,
            'status'                   => $status,
            'settled_at'               => $status === Charge::STATUS_PENDING ? null : now(),
            'created_by'               => $actor?->id,
        ]);
        StatusTransition::record($charge, null, $status, $pilot && $list > 0 ? 'Phase pilote : gratuit' : null, $actor, $organization?->id);

        if ($charge->isSettled()) {
            $this->fulfill($charge);
        }

        return $charge->fresh();
    }

    /** Encaissement en espèces au guichet (reçu = référence du frais). */
    public function payCash(Charge $charge, User $agent, ?int $amountReceived = null): array
    {
        $this->assertPending($charge);
        if ($amountReceived !== null && $amountReceived < $charge->amount) {
            throw ValidationException::withMessages(['amount_received' => 'Montant insuffisant : ' . $charge->amount . ' FCFA attendus.']);
        }

        DB::transaction(function () use ($charge, $agent) {
            $payment = $charge->payments()->create([
                'provider'     => Payment::PROVIDER_CASH,
                'status'       => Payment::STATUS_SUCCEEDED,
                'amount'       => $charge->amount,
                'received_by'  => $agent->id,
                'confirmed_at' => now(),
            ]);
            $this->markPaid($charge, $agent, 'Espèces reçues au guichet');
            activity()->causedBy($agent)->performedOn($charge)
                ->withProperties(['reference' => $charge->reference, 'amount' => $charge->amount, 'payment' => $payment->uuid])
                ->log('Paiement en espèces encaissé');
        });

        $this->fulfill($charge->fresh());

        return ['charge' => $charge->fresh(), 'change_due' => $amountReceived !== null ? $amountReceived - $charge->amount : 0];
    }

    /** Exonération motivée (indigence, victime…) accordée par un responsable. */
    public function exempt(Charge $charge, User $manager, string $reason): Charge
    {
        $this->assertPending($charge);

        $charge->fill([
            'status'           => Charge::STATUS_EXEMPTED,
            'amount'           => 0,
            'authority_amount' => 0,
            'platform_amount'  => 0,
            'exemption_reason' => $reason,
            'exempted_by'      => $manager->id,
            'settled_at'       => now(),
        ])->save();
        StatusTransition::record($charge, Charge::STATUS_PENDING, Charge::STATUS_EXEMPTED, $reason, $manager, $charge->organization_id);
        activity()->causedBy($manager)->performedOn($charge)->withProperties(['reference' => $charge->reference, 'reason' => $reason])->log('Frais exonéré');

        $this->fulfill($charge->fresh());

        return $charge->fresh();
    }

    public function cancel(Charge $charge, User $actor, string $reason): Charge
    {
        $this->assertPending($charge);
        $charge->update(['status' => Charge::STATUS_CANCELLED]);
        StatusTransition::record($charge, Charge::STATUS_PENDING, Charge::STATUS_CANCELLED, $reason, $actor, $charge->organization_id);

        return $charge->fresh();
    }

    /** Lien de paiement mobile money (Wave) pour un frais en attente. */
    public function checkout(Charge $charge, ?string $payerPhone = null): Payment
    {
        $this->assertPending($charge);
        $provider = $this->provider();

        $payment = $charge->payments()->create([
            'provider' => $provider->name(),
            'status'   => Payment::STATUS_INITIATED,
            'amount'   => $charge->amount,
        ]);

        try {
            $session = $provider->createCheckout($payment, $charge, $payerPhone);
        } catch (\RuntimeException $e) {
            $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => $e->getMessage()]);
            throw $e;
        }

        $payment->update(['provider_reference' => $session['reference'], 'checkout_url' => $session['url']]);

        return $payment->fresh();
    }

    /** Notification du prestataire (webhook). Idempotent. */
    public function handleWebhook(string $providerName, Request $request): string
    {
        $provider = $this->provider($providerName);
        $event    = $provider->parseWebhook($request);
        if ($event === null) {
            return 'invalid_signature';
        }

        $payment = Payment::where('provider', $provider->name())
            ->where(fn ($q) => $q->when($event['reference'], fn ($q, $r) => $q->where('provider_reference', $r))
                ->when($event['client_reference'], fn ($q, $r) => $q->orWhere('uuid', $r)))
            ->first();
        if (! $payment) {
            return 'unknown_payment';
        }
        if ($payment->status === Payment::STATUS_SUCCEEDED || $event['status'] === 'ignored') {
            return 'ok';
        }

        if ($event['status'] !== Payment::STATUS_SUCCEEDED) {
            $payment->update(['status' => $event['status'], 'failure_reason' => $event['event'], 'payload' => $event]);

            return 'ok';
        }

        $charge = $payment->charge;
        if ($event['amount'] !== null && $event['amount'] !== (int) $charge->amount) {
            $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => 'Montant incohérent : ' . $event['amount'], 'payload' => $event]);

            return 'amount_mismatch';
        }

        DB::transaction(function () use ($payment, $charge, $event) {
            $payment->update([
                'status'         => Payment::STATUS_SUCCEEDED,
                'transaction_id' => $event['transaction_id'],
                'confirmed_at'   => now(),
                'payload'        => $event,
            ]);
            if ($charge->status === Charge::STATUS_PENDING) {
                $this->markPaid($charge, null, 'Paiement ' . $payment->provider . ' confirmé (' . $event['transaction_id'] . ')');
            }
        });

        $this->fulfill($charge->fresh());

        return 'ok';
    }

    /** Démarche accomplie une seule fois, après règlement. */
    public function fulfill(Charge $charge): void
    {
        if (! $charge->isSettled() || $charge->fulfilled_at) {
            return;
        }

        $class = config('billing.fulfillers.' . $charge->service->code);
        if ($class) {
            app($class)->fulfill($charge);
        }
        $charge->forceFill(['fulfilled_at' => now()])->save();
    }

    public function provider(?string $name = null): PaymentProvider
    {
        return match ($name ?? config('billing.online_provider')) {
            'wave'  => app(WaveProvider::class),
            default => throw new \InvalidArgumentException('Prestataire de paiement inconnu.'),
        };
    }

    public function service(string $code): BillableService
    {
        $default = config("billing.catalog.{$code}");
        abort_unless($default, 500, "Service facturable inconnu : {$code}");

        return BillableService::firstOrCreate(['code' => $code], [
            'name'        => $default['name'],
            'description' => $default['description'] ?? null,
        ]);
    }

    /** Nouveau tarif : le précédent (même service, même périmètre) est clôturé. */
    public function setPrice(string $code, int $amount, int $authoritySharePct, ?Organization $institution, User $admin, ?string $validFrom = null, ?string $note = null): PriceRule
    {
        $service = $this->service($code);
        $this->quote($code); // l'historique commence toujours par le tarif initial
        $from    = $validFrom ? \Illuminate\Support\Carbon::parse($validFrom) : now();

        return DB::transaction(function () use ($service, $amount, $authoritySharePct, $institution, $admin, $from, $note) {
            PriceRule::where('billing_service_id', $service->id)
                ->where('organization_id', $institution?->id)
                ->whereNull('valid_to')
                ->update(['valid_to' => $from]);

            return PriceRule::create([
                'billing_service_id'  => $service->id,
                'organization_id'     => $institution?->id,
                'amount'              => $amount,
                'currency'            => config('billing.currency', 'XOF'),
                'authority_share_pct' => $authoritySharePct,
                'valid_from'          => $from,
                'created_by'          => $admin->id,
                'note'                => $note,
            ]);
        });
    }

    private function markPaid(Charge $charge, ?User $actor, string $reason): void
    {
        $authority = intdiv($charge->amount * $charge->authority_share_pct + 50, 100);
        $charge->fill([
            'status'           => Charge::STATUS_PAID,
            'authority_amount' => $authority,
            'platform_amount'  => $charge->amount - $authority,
            'settled_at'       => now(),
        ])->save();
        StatusTransition::record($charge, Charge::STATUS_PENDING, Charge::STATUS_PAID, $reason, $actor, $charge->organization_id);
    }

    private function assertPending(Charge $charge): void
    {
        if ($charge->status !== Charge::STATUS_PENDING) {
            throw ValidationException::withMessages(['charge' => 'Ce frais n\'est plus en attente de paiement.']);
        }
    }

    private function seedDefaultRule(BillableService $service): PriceRule
    {
        $default = config("billing.catalog.{$service->code}");

        return PriceRule::create([
            'billing_service_id'  => $service->id,
            'amount'              => (int) $default['amount'],
            'currency'            => config('billing.currency', 'XOF'),
            'authority_share_pct' => (int) ($default['authority_share_pct'] ?? 50),
            'valid_from'          => now()->subSecond(),
            'note'                => 'Tarif initial (config/billing.php)',
        ]);
    }
}
