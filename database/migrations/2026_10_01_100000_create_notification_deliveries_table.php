<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal de TOUS les envois (SMS, email, boîte de réception) :
 * qui a été prévenu, de quoi, quand, et si l'envoi a réussi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event', 60)->index();          // ex. collection.assigned
            $table->string('channel', 10)->index();        // sms | email | inbox
            $table->nullableMorphs('recipient');           // User, CitizenProfile, Organization…
            $table->string('destination_masked')->nullable(); // •••••••4567 / a•••@gmail.com
            $table->nullableMorphs('subject');             // objet concerné (collecte, correspondance…)
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete(); // périmètre de consultation
            $table->text('payload_encrypted')->nullable(); // texte du SMS (chiffré : peut contenir un numéro)
            $table->string('status', 12)->index();         // pending | sent | failed | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->string('dedupe_key')->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
