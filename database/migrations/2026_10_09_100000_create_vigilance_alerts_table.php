<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigilance : doublons et signaux de fraude. Une alerte n'est jamais une
 * sanction automatique : un responsable la lit, puis la confirme ou l'écarte.
 * Une alerte « haute » ouverte sur un document impose l'accord d'un
 * responsable avant sa restitution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vigilance_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('subject');                       // Document, DeclarationDePerte, User, Restitution…
            $table->string('kind', 40)->index();             // duplicate_document, reused_photo, ocr_inconsistency…
            $table->string('level', 10)->index();            // info, warning, high
            $table->json('details')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open')->index(); // open, confirmed, dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('dedupe_key', 150)->unique();
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            // Empreinte visuelle de la photo (dHash 64 bits) : repère une même photo publiée deux fois.
            $table->string('image_hash', 16)->nullable()->index()->after('doc_number_last4');
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('image_hash'));
        Schema::dropIfExists('vigilance_alerts');
    }
};
