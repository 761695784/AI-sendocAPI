<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit : savoir QUELLE structure a agi, depuis quelle IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn(['organization_id', 'ip_address', 'user_agent']);
        });
    }
};
