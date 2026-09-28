<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arbre Institution → (Direction) → Structure.
 * "path" = chemin matérialisé des ids ("/1/7/") pour requêter un sous-arbre
 * en une seule condition (WHERE path LIKE '/1/%').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('organization_type_id')->constrained('organization_types')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('path')->default('/')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
