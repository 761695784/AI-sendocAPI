<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation (D6) : catalogue de services, tarifs datés (national ou par
 * institution), frais à payer, paiements (espèces au guichet, Wave…).
 * Le modèle économique se règle par la configuration, pas par le code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();   // counter_declaration, qr_renewal, restitution
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_service_id')->constrained('billing_services')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete(); // null = tarif national
            $table->unsignedInteger('amount');                  // en FCFA
            $table->string('currency', 3)->default('XOF');
            $table->unsignedTinyInteger('authority_share_pct')->default(50); // part des autorités (reste : DocTrack)
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['billing_service_id', 'organization_id', 'valid_from']);
        });

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 20)->unique();          // FA-2026-000001 (n° de reçu)
            $table->foreignId('billing_service_id')->constrained('billing_services')->restrictOnDelete();
            $table->foreignId('price_rule_id')->nullable()->constrained('price_rules')->nullOnDelete();
            $table->nullableMorphs('subject');                  // déclaration, QR, utilisateur…
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payer_citizen_profile_id')->nullable()->constrained('citizen_profiles')->nullOnDelete();
            $table->string('currency', 3)->default('XOF');
            $table->unsignedInteger('list_amount');             // prix du catalogue (valeur théorique)
            $table->unsignedInteger('amount');                  // montant réellement dû
            $table->unsignedInteger('authority_amount')->default(0);
            $table->unsignedInteger('platform_amount')->default(0);
            $table->unsignedTinyInteger('authority_share_pct')->default(50);
            $table->string('status', 16)->index();              // pending|paid|exempted|waived_pilot|cancelled
            $table->string('exemption_reason')->nullable();
            $table->foreignId('exempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('charge_id')->constrained('charges')->cascadeOnDelete();
            $table->string('provider', 16);                     // cash | wave
            $table->string('status', 16)->index();              // initiated|succeeded|failed|expired
            $table->unsignedInteger('amount');
            $table->string('provider_reference')->nullable()->index(); // id de session Wave
            $table->string('transaction_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete(); // agent (espèces)
            $table->timestamp('confirmed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('charges');
        Schema::dropIfExists('price_rules');
        Schema::dropIfExists('billing_services');
    }
};
