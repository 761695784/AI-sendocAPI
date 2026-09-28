<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une déclaration peut couvrir plusieurs pièces (portefeuille perdu :
 * CNI + permis + carte grise…). Chaque pièce est matchée séparément.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declaration_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('declaration_de_perte_id')->constrained('declaration_de_pertes')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date_in_doc')->nullable();
            $table->string('doc_number_hash', 64)->nullable()->index();
            $table->text('doc_number_encrypted')->nullable();
            $table->string('doc_number_last4', 4)->nullable();
            $table->string('status', 30)->default('lost')->index(); // lost | found | restituted
            $table->timestamps();

            $table->index(['document_type_id', 'last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declaration_items');
    }
};
