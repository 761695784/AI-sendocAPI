<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * receipt  = récépissé DocTrack (déclaration en ligne, non vérifiée)
 * official = certificat émis par un agent d'une structure habilitée
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificat_de_pertes', function (Blueprint $table) {
            $table->string('kind', 20)->default('receipt')->index()->after('certificat_number');
            $table->string('verification_code', 20)->nullable()->unique()->after('kind');
            $table->foreignId('issued_by_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
        });

        // Les certificats existants deviennent vérifiables.
        DB::table('certificat_de_pertes')->whereNull('verification_code')->orderBy('id')->each(function ($row) {
            DB::table('certificat_de_pertes')->where('id', $row->id)
                ->update(['verification_code' => strtoupper(Str::random(12))]);
        });
    }

    public function down(): void
    {
        Schema::table('certificat_de_pertes', function (Blueprint $table) {
            $table->dropForeign(['issued_by_organization_id']);
            $table->dropForeign(['issued_by_user_id']);
            $table->dropUnique(['verification_code']);
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'verification_code', 'issued_by_organization_id', 'issued_by_user_id', 'revoked_at', 'revocation_reason']);
        });
    }
};
