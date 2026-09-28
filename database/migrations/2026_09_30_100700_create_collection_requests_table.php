<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collecte d'un document publié par un citoyen : la structure la plus proche
 * contacte le trouveur, puis le document est déposé par lui ou récupéré
 * par un agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('status', 20)->index(); // unassigned|assigned|contacted|planned|collected|cancelled

            // Affectation
            $table->timestamp('assigned_at')->nullable();
            $table->string('assignment_reason', 20)->nullable(); // gps|department|region|transfer|manual
            $table->decimal('distance_km', 7, 2)->nullable();
            $table->json('declined_organization_ids')->nullable();

            // Trouveur (numéro chiffré, visible de la seule structure affectée)
            $table->foreignId('finder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finder_name')->nullable();
            $table->text('finder_phone_encrypted')->nullable();

            // Prise de contact
            $table->unsignedTinyInteger('contact_attempts')->default(0);
            $table->timestamp('last_contact_at')->nullable();
            $table->string('last_contact_outcome', 20)->nullable(); // reached|no_answer|wrong_number

            // Organisation de la récupération
            $table->string('method', 20)->nullable(); // drop_off|agent_pickup
            $table->timestamp('scheduled_at')->nullable();
            $table->string('meeting_place')->nullable();
            $table->foreignId('pickup_agent_id')->nullable()->constrained('users')->nullOnDelete();

            // Clôture
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_reason')->nullable();

            // Le propriétaire a été identifié par le moteur de correspondance : dossier prioritaire.
            $table->timestamp('owner_identified_at')->nullable();

            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_requests');
    }
};
