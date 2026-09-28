<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR code v2 : un QR par document (à coller sur la CNI, le passeport…).
 * Le scan par un trouveur prévient le propriétaire et ouvre une collecte
 * vers la structure la plus proche. Aucune donnée personnelle dans le QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 40)->unique();              // seul contenu du QR
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('label', 60)->nullable();           // « CNI de ma fille »
            $table->string('doc_number_last4', 4)->nullable();
            $table->string('status', 20)->index();              // active|pending_payment|revoked|replaced
            $table->foreignId('replaces_id')->nullable()->constrained('document_tags')->nullOnDelete();
            $table->unsignedInteger('scans_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tag_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_tag_id')->constrained('document_tags')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('finder_name')->nullable();
            $table->text('finder_phone_encrypted')->nullable();
            $table->string('finder_phone_hash', 64)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('place')->nullable();
            $table->boolean('allow_owner_contact')->default(true);
            $table->boolean('contact_consent')->default(false);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            // Un document signalé par scan de QR n'a pas de « publicateur » inscrit.
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('document_tag_id')->nullable()->after('finder_citizen_profile_id')->constrained('document_tags')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_tag_id');
        });
        Schema::dropIfExists('tag_scans');
        Schema::dropIfExists('document_tags');
    }
};
