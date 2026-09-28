<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des délais de réaction (statistiques + relances) :
 *  - collecte : premier appel au trouveur (par affectation), relance, alerte hiérarchique ;
 *  - correspondance : relance si la vérification traîne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_requests', function (Blueprint $table) {
            $table->timestamp('first_contacted_at')->nullable()->after('contact_attempts');
            $table->timestamp('reminded_at')->nullable()->after('owner_identified_at');
            $table->timestamp('escalated_at')->nullable()->after('reminded_at');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('collection_requests', function (Blueprint $table) {
            $table->dropColumn(['first_contacted_at', 'reminded_at', 'escalated_at']);
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
