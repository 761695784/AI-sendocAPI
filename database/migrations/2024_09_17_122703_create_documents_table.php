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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();// Colonne UUID avec contrainte d'unicité
            // $table->string('image');
            $table->string('OwnerFirstName')->nullable();
            $table->string('OwnerLastName')->nullable();
            $table->string('DocIdentification')->nullable();
            $table->string('Location');
            $table->enum('statut', ['récupéré', 'non récupéré'])->default('non récupéré');
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
        Schema::dropIfExists('documents');
    }
};
