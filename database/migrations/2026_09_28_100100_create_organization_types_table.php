<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Types d'organisation : Police nationale, Gendarmerie, commissariat, brigade…
 * Extensible sans modifier le code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('level', 20)->index(); // institution | intermediate | structure
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_types');
    }
};
