<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture des pièces (OCR).
 *
 *  - ocr_scans : une photo lue, en attente de publication (durée de vie
 *    courte ; image supprimée ensuite). Le résultat, qui contient des
 *    numéros de pièce, est CHIFFRÉ.
 *  - document_numbers : numéros supplémentaires d'un document trouvé
 *    (ex. NIN au dos de la carte en plus du numéro de carte) — seulement
 *    leur empreinte, pour les correspondances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 30)->default('found_document');
            $table->string('status', 20);                  // read, partial, unreadable, used, expired
            $table->string('engine', 60)->nullable();
            $table->string('image_path')->nullable();      // disque privé « local »
            $table->string('masked_path')->nullable();
            $table->text('result')->nullable();            // chiffré
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->unsignedSmallInteger('masked_regions')->default(0);
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->json('corrections')->nullable();       // champs modifiés par la personne avant publication
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('document_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);                    // card_number, nin, passport_number…
            $table->string('hash', 64)->index();
            $table->string('last4', 4)->nullable();
            $table->string('source', 20)->default('ocr');
            $table->timestamps();

            $table->unique(['document_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_numbers');
        Schema::dropIfExists('ocr_scans');
    }
};
