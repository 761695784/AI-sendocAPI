<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demande de restitution directe du propriétaire au trouveur (double accord) :
 * les numéros ne sont échangés qu'après l'accord du trouveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restitution_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('document_match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete(); // propriétaire confirmé
            $table->foreignId('finder_user_id')->constrained('users')->cascadeOnDelete();    // publicateur
            $table->string('status', 20)->index(); // pending|accepted|declined|expired|completed|cancelled
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('contact_shared_at')->nullable();
            $table->string('decline_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restitution_requests');
    }
};
