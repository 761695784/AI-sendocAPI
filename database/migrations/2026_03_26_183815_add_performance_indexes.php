<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Documents ──
        Schema::table('documents', function (Blueprint $table) {
            $table->index('uuid');
            $table->index('statut');
            $table->index('document_type_id');
            $table->index('created_at');
            $table->index(['deleted_at', 'statut']); // filtres combinés fréquents
        });

        // ── Déclarations ──
        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            $table->index('uuid');
            $table->index('document_type_id');
            $table->index('created_at');
            // Matching déclarations ↔ documents (toLowerCase)
            $table->index(['FirstNameInDoc', 'LastNameInDoc']);
        });

        // ── Activity log ──
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('causer_id');
            $table->index('subject_type');
            $table->index('created_at');
        });

        // ── Media (MediaLibrary) ──
        Schema::table('media', function (Blueprint $table) {
            // $table->index(['model_type', 'model_id']);
            $table->index('collection_name');
        });

        // ── Email logs ──
        Schema::table('email_logs', function (Blueprint $table) {
            $table->index('document_id'); // uuid du document
            $table->index('requester_user_id');
            $table->index('created_at');
        });

        // ── Notifications ──
        Schema::table('notifications', function (Blueprint $table) {
            $table->index('is_read');
            $table->index('created_at');
        });

        // ── Certificats ──
        Schema::table('certificat_de_pertes', function (Blueprint $table) {
            $table->index('uuid');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropIndex(['statut']);
            $table->dropIndex(['document_type_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['deleted_at', 'statut']);
        });

        Schema::table('declaration_de_pertes', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropIndex(['document_type_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['FirstNameInDoc', 'LastNameInDoc']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['causer_id']);
            $table->dropIndex(['subject_type']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('media', function (Blueprint $table) {
            // $table->dropIndex(['model_type', 'model_id']);
            $table->dropIndex(['collection_name']);
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['document_id']);
            $table->dropIndex(['requester_user_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['is_read']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('certificat_de_pertes', function (Blueprint $table) {
            $table->dropIndex(['uuid']);
            $table->dropIndex(['created_at']);
        });
    }
};
