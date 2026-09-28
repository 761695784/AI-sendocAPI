<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil d'une personne physique (victime, déclarant) qui peut exister
 * SANS compte : l'agent le crée au guichet. Il peut être rattaché plus tard
 * au compte citoyen de la personne (user_id).
 *
 * Le téléphone est stocké chiffré (affichage) + haché (recherche exacte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citizen_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->text('phone_encrypted')->nullable();
            $table->string('phone_hash', 64)->nullable()->index();
            $table->string('email')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizen_profiles');
    }
};
