<?php

use App\Models\DocumentType;
use App\Models\User;
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
        Schema::create('declaration_de_pertes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // Colonne UUID avec contrainte d'unicité
            $table->string('Title');
            $table->string('FirstNameInDoc');
            $table->string('LastNameInDoc');
            $table->string('DocIdentification')->nullable();
            $table->foreignIdFor(DocumentType::class)->constrained()->onDelete('cascade');
            $table->foreignIdFor(User::class)->constrained()->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes(); // Ajoute la colonne 'deleted_at' pour la suppression douce
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declaration_de_pertes');
    }
};
