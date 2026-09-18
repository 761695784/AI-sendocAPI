<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapports_generes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('annee');
            $table->unsignedTinyInteger('mois')->nullable(); // null = rapport annuel
            $table->string('periode_label');                 // ex: "Janvier 2026", "Année 2026"
            $table->string('pdf_path')->nullable();          // chemin storage/app/public/rapports/
            $table->json('stats_json');                      // snapshot complet des stats
            $table->json('analyse_json');                    // snapshot de l'analyse règles
            $table->foreignId('genere_par')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();

            // Unicité : un seul rapport par période
            $table->unique(['annee', 'mois']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapports_generes');
    }
};
