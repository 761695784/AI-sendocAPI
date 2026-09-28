<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité complète : citoyen → déclaration → agent → structure → institution.
 * Colonnes V1 conservées (Title, FirstNameInDoc, LastNameInDoc, DocIdentification,
 * document_type_id) : elles reprennent la première pièce déclarée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            // Une victime sans compte n'a pas de user_id.
            $table->foreignId('user_id')->nullable()->change();

            $table->string('reference', 30)->nullable()->unique()->after('uuid');
            $table->string('channel', 20)->default('online')->index()->after('reference'); // online | counter
            $table->string('status', 30)->default('registered')->index()->after('channel');
            $table->foreignId('citizen_profile_id')->nullable()->constrained('citizen_profiles')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('lost_at')->nullable();
            $table->string('lost_place')->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->text('circumstances')->nullable();
            $table->string('doc_number_hash', 64)->nullable()->index();
            $table->text('doc_number_encrypted')->nullable();
            $table->date('birth_date_in_doc')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            $table->dropForeign(['citizen_profile_id']);
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['recorded_by_user_id']);
            $table->dropForeign(['territory_id']);
            $table->dropUnique(['reference']);
            $table->dropIndex(['channel']);
            $table->dropIndex(['status']);
            $table->dropIndex(['doc_number_hash']);
            $table->dropColumn([
                'reference', 'channel', 'status', 'citizen_profile_id', 'organization_id',
                'recorded_by_user_id', 'lost_at', 'lost_place', 'territory_id', 'circumstances',
                'doc_number_hash', 'doc_number_encrypted', 'birth_date_in_doc', 'closed_at', 'closure_reason',
            ]);
        });
    }
};
