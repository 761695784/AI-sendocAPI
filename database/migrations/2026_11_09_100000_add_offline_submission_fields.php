<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envois préparés hors connexion (déclarations en ligne, publications) :
 *  - client_uuid       : identifiant créé par l'appareil, pour ne jamais enregistrer deux fois le même envoi ;
 *  - client_created_at : heure de saisie sur l'appareil (l'heure de réception reste created_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['declaration_de_pertes', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('client_uuid')->nullable();
                $t->timestamp('client_created_at')->nullable();
                $t->unique(['user_id', 'client_uuid']);
            });
        }
    }

    public function down(): void
    {
        foreach (['declaration_de_pertes', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['user_id', 'client_uuid']);
                $t->dropColumn(['client_uuid', 'client_created_at']);
            });
        }
    }
};
