<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte de réception de l'espace institutionnel.
 * Un message est adressé à une structure (tous ses agents le voient)
 * ou à une personne ; la lecture est suivie par utilisateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('event', 60)->index();
            $table->string('title');
            $table->text('body');
            $table->string('link')->nullable();      // écran du front à ouvrir (ex. collections/{uuid})
            $table->nullableMorphs('subject');
            $table->boolean('priority')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('inbox_message_reads', function (Blueprint $table) {
            $table->foreignId('inbox_message_id')->constrained('inbox_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['inbox_message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_message_reads');
        Schema::dropIfExists('inbox_messages');
    }
};
