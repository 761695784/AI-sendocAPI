<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passerelle « déclaration en ligne → certificat de perte au guichet ».
 *
 * Une déclaration faite en ligne (récépissé DocTrack, rattachée à aucune
 * structure) peut être certifiée par l'agent de n'importe quelle structure
 * habilitée, après vérification de l'identité de la personne présente.
 * La déclaration est alors rattachée à la structure (organization_id) et un
 * certificat officiel est émis ; le récépissé reste valable.
 *
 * Pièce présentée : jamais le numéro en clair (4 derniers chiffres + empreinte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            $table->timestamp('certified_at')->nullable()->index();
            $table->foreignId('certified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Personne présente au guichet (titulaire du document), telle que vérifiée par l'agent.
            $table->string('certification_first_name', 100)->nullable();
            $table->string('certification_last_name', 100)->nullable();
            $table->date('certification_birth_date')->nullable();
            $table->string('certification_id_type', 30)->nullable();
            $table->string('certification_id_last4', 4)->nullable();
            $table->string('certification_id_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            $table->dropForeign(['certified_by_user_id']);
            $table->dropIndex(['certified_at']);
            $table->dropColumn([
                'certified_at', 'certified_by_user_id',
                'certification_first_name', 'certification_last_name', 'certification_birth_date',
                'certification_id_type', 'certification_id_last4', 'certification_id_hash',
            ]);
        });
    }
};
