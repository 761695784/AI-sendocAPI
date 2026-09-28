<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieu précis où le citoyen a trouvé le document + son accord pour être
 * contacté par la structure la plus proche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('found_latitude', 10, 7)->nullable()->after('found_place');
            $table->decimal('found_longitude', 10, 7)->nullable()->after('found_latitude');
            $table->timestamp('contact_consent_at')->nullable()->after('found_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['found_latitude', 'found_longitude', 'contact_consent_at']);
        });
    }
};
