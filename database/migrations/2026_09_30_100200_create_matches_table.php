<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correspondance entre UNE pièce déclarée perdue et UN document trouvé.
 * Une correspondance n'est jamais une certitude : score, statut, revue humaine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('declaration_de_perte_id')->constrained('declaration_de_pertes')->cascadeOnDelete();
            $table->foreignId('declaration_item_id')->constrained('declaration_items')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // Structure chargée de la revue : celle qui détient le document, sinon celle de la déclaration.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('engine', 30);
            $table->string('engine_version', 30);
            $table->unsignedTinyInteger('score');
            $table->json('score_details');
            $table->string('status', 30)->index(); // proposed | pending_review | confirmed | rejected | superseded
            $table->boolean('number_match')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['declaration_item_id', 'document_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
