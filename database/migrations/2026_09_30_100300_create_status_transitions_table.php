<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des changements de statut (déclarations, documents, correspondances,
 * et plus tard restitutions) : qui, quelle structure, quand, pourquoi.
 * Sert aussi au calcul des délais (statistiques).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('transitionable');
            $table->string('from', 30)->nullable();
            $table->string('to', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
    }
};
