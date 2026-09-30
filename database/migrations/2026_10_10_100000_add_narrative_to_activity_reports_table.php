<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports : partie rédigée avec l'aide de l'IA (synthèse, constats,
 * commentaires par structure, recommandations, résumé anglais), soumise à
 * validation avant publication, et suivi de l'envoi des rapports mensuels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_reports', function (Blueprint $table) {
            $table->json('narrative')->nullable()->after('analysis');
            // none (IA non utilisée), pending (à valider), approved, rejected, failed (IA indisponible ou chiffres non vérifiés)
            $table->string('narrative_status', 20)->default('none')->after('narrative');
            $table->json('narrative_attempts')->nullable()->after('narrative_status');
            $table->foreignId('narrative_reviewed_by')->nullable()->after('narrative_attempts')->constrained('users')->nullOnDelete();
            $table->timestamp('narrative_reviewed_at')->nullable()->after('narrative_reviewed_by');
            $table->string('narrative_review_note', 500)->nullable()->after('narrative_reviewed_at');
            $table->timestamp('delivered_at')->nullable()->after('narrative_review_note');
        });
    }

    public function down(): void
    {
        Schema::table('activity_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('narrative_reviewed_by');
            $table->dropColumn(['narrative', 'narrative_status', 'narrative_attempts', 'narrative_reviewed_at', 'narrative_review_note', 'delivered_at']);
        });
    }
};
