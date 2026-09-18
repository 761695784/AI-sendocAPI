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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('from'); // Expéditeur
            $table->string('to'); // Destinataire
            $table->string('subject'); // Objet de l'email
            $table->text('body'); // Contenu de l'email
            $table->foreignId('publisher_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('declarant_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('document_id')->nullable(); // Nouvelle colonne
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
