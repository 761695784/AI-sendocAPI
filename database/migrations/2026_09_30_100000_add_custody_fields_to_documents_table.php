<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document trouvé : publié par un citoyen OU déposé dans une structure
 * (garde physique), avec son cycle de vie complet.
 * La colonne V1 "statut" reste alimentée (récupéré / non récupéré).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('reference', 30)->nullable()->unique()->after('uuid');
            $table->string('found_channel', 20)->default('citizen')->index()->after('reference'); // citizen | counter
            $table->string('lifecycle_status', 30)->default('published')->index()->after('found_channel');
            $table->foreignId('custody_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('deposited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finder_citizen_profile_id')->nullable()->constrained('citizen_profiles')->nullOnDelete();
            $table->timestamp('custody_since')->nullable();
            $table->date('found_at')->nullable();
            $table->string('found_place')->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->date('birth_date_in_doc')->nullable();
            $table->string('doc_number_hash', 64)->nullable()->index();
            $table->text('doc_number_encrypted')->nullable();
            $table->string('doc_number_last4', 4)->nullable();
            // Clés phonétiques (pré-filtrage du moteur de correspondance)
            $table->string('first_name_key', 60)->nullable()->index();
            $table->string('last_name_key', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['custody_organization_id']);
            $table->dropForeign(['deposited_by_user_id']);
            $table->dropForeign(['finder_citizen_profile_id']);
            $table->dropForeign(['territory_id']);
            $table->dropUnique(['reference']);
            foreach (['found_channel', 'lifecycle_status', 'doc_number_hash', 'first_name_key', 'last_name_key'] as $col) {
                $table->dropIndex([$col]);
            }
            $table->dropColumn([
                'reference', 'found_channel', 'lifecycle_status', 'custody_organization_id', 'deposited_by_user_id',
                'finder_citizen_profile_id', 'custody_since', 'found_at', 'found_place', 'territory_id', 'birth_date_in_doc',
                'doc_number_hash', 'doc_number_encrypted', 'doc_number_last4', 'first_name_key', 'last_name_key',
            ]);
        });
    }
};
