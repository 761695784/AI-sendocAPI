<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Témoignages des usagers : envoyés depuis le site, publiés seulement après
 * validation par un super administrateur (prénom et ville affichés, rien d'autre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('declaration_de_perte_id')->nullable()->constrained('declaration_de_pertes')->nullOnDelete();
            $table->string('first_name', 60);
            $table->string('city', 80);
            $table->string('role', 20)->default('other');      // owner | finder | other
            $table->unsignedTinyInteger('rating')->nullable(); // 1 à 5
            $table->text('body');
            $table->text('contact_encrypted')->nullable();     // pour vérifier, jamais publié
            $table->string('locale', 5)->default('fr');
            $table->boolean('verified')->default(false);       // restitution réelle constatée dans DocTrack
            $table->timestamp('consent_at');
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 255)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
