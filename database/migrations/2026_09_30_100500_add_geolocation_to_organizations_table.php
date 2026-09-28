<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Position des structures (pour trouver la plus proche d'un document trouvé)
 * et participation à la collecte des documents publiés par les citoyens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('email');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->boolean('accepts_collections')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accepts_collections']);
        });
    }
};
