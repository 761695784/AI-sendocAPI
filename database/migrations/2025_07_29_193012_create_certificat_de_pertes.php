<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('certificat_de_pertes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('declaration_de_perte_id')->constrained()->onDelete('cascade');
            $table->string('certificat_number')->unique();
            $table->foreignId('document_type_id')->constrained('document_types')->onDelete('cascade');
            $table->text('description')->nullable();
            $table->string('pdf_path')->nullable(); // Pour stocker le chemin du fichier PDF
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificat_de_pertes');
    }
};
