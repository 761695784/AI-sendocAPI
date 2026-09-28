<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)->default('citizen')->index()->after('uuid'); // citizen | agent | admin
            $table->string('status', 20)->default('active')->index()->after('account_type'); // active | suspended
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['account_type']);
            $table->dropIndex(['status']);
            $table->dropColumn(['account_type', 'status', 'phone_verified_at', 'last_login_at']);
        });
    }
};
