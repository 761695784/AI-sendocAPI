<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux façons de récupérer un document :
 *  - direct  : publié par un citoyen, mise en relation immédiate avec le trouveur ;
 *  - counter : conservé par une structure, retrait au guichet (pas de trouveur citoyen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restitution_requests', function (Blueprint $table) {
            $table->string('channel', 20)->default('direct')->after('uuid')->index();
            $table->foreignId('organization_id')->nullable()->after('finder_user_id')->constrained('organizations')->nullOnDelete();
            $table->timestamp('terms_accepted_at')->nullable()->after('contact_shared_at');
        });

        Schema::table('restitution_requests', function (Blueprint $table) {
            // Retrait au guichet : pas de trouveur citoyen.
            $table->foreignId('finder_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('restitution_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['channel', 'terms_accepted_at']);
        });
    }
};
