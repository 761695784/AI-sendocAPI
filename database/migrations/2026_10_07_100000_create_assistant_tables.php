<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant conversationnel (texte + voix, français + wolof).
 * Une conversation garde l'étape en cours du dialogue guidé et les réponses
 * déjà données (slots) ; chaque échange est journalisé pour la traçabilité
 * et l'amélioration (purge automatique après assistant.retention_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('web');     // web, whatsapp (plus tard)
            $table->string('language', 5)->default('fr');      // fr, wo
            $table->string('flow', 40)->nullable();            // parcours en cours
            $table->string('step', 40)->nullable();            // question en attente
            $table->json('slots')->nullable();                 // réponses déjà données
            $table->string('status', 20)->default('active');   // active, completed, handed_off, abandoned
            $table->unsignedTinyInteger('misunderstood')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('last_activity_at');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);                    // in, out
            $table->string('input_mode', 10)->default('text'); // text, voice, button
            $table->text('text');
            $table->string('language', 5)->nullable();
            $table->string('intent', 40)->nullable();
            $table->string('phrase_key', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['assistant_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
