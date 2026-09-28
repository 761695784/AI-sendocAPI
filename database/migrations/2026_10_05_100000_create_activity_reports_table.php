<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapports d'activité V2 (par périmètre et par période), en PDF + Excel.
 * Les rapports nationaux V1 (rapports_generes) restent disponibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 20)->unique();                 // RP-2026-000001
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete(); // null = national
            $table->date('period_from');
            $table->date('period_to');
            $table->string('period_label');
            $table->string('title');
            $table->json('data');                                      // photographie des chiffres
            $table->json('analysis');                                  // constats, alertes, recommandations
            $table->string('pdf_path')->nullable();
            $table->string('xlsx_path')->nullable();
            $table->boolean('automatic')->default(false);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'period_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_reports');
    }
};
