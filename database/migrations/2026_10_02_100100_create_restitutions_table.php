<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restitution officielle au guichet : identité vérifiée par un agent,
 * remise tracée, décharge numérotée (RS-AAAA-NNNNNN) et vérifiable.
 * Aucune copie de pièce d'identité : type + 4 derniers chiffres + empreinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restitutions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 20)->unique();
            $table->string('verification_code', 16)->unique();
            $table->string('status', 12)->index(); // completed | voided

            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('declaration_de_perte_id')->nullable()->constrained('declaration_de_pertes')->nullOnDelete();
            $table->foreignId('declaration_item_id')->nullable()->constrained('declaration_items')->nullOnDelete();
            $table->foreignId('owner_citizen_profile_id')->nullable()->constrained('citizen_profiles')->nullOnDelete();

            // Propriétaire (titulaire du document)
            $table->string('recipient_type', 10); // owner | proxy
            $table->string('owner_first_name');
            $table->string('owner_last_name');
            $table->date('owner_birth_date')->nullable();
            $table->string('owner_id_type', 30)->nullable();
            $table->string('owner_id_last4', 4)->nullable();
            $table->string('owner_id_hash', 64)->nullable();
            $table->text('owner_phone_encrypted')->nullable();

            // Mandataire (retrait par procuration)
            $table->string('proxy_first_name')->nullable();
            $table->string('proxy_last_name')->nullable();
            $table->string('proxy_id_type', 30)->nullable();
            $table->string('proxy_id_last4', 4)->nullable();
            $table->string('proxy_id_hash', 64)->nullable();
            $table->text('proxy_phone_encrypted')->nullable();
            $table->string('proxy_relationship', 60)->nullable();
            $table->string('power_of_attorney_ref', 100)->nullable();

            // Contrôles effectués par l'agent
            $table->json('checks');
            $table->decimal('name_similarity', 4, 3)->nullable();
            $table->string('name_override_reason')->nullable();
            $table->foreignId('name_override_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('handed_over_at');
            $table->string('pdf_path')->nullable();
            $table->string('signed_scan_path')->nullable();
            $table->timestamp('signed_scan_uploaded_at')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            $table->timestamps();
            $table->index(['organization_id', 'handed_over_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restitutions');
    }
};
