<?php

use App\Models\DeclarationDePerte;
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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();; // Colonne UUID avec contrainte d'unicité
            $table->text('message');
            $table->boolean('is_read')->default(false); // Colonne is_read avec valeur par défaut false
            $table->foreignIdFor(DeclarationDePerte::class)->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
