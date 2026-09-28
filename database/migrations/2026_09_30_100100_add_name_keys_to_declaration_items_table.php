<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declaration_items', function (Blueprint $table) {
            $table->string('first_name_key', 60)->nullable()->index();
            $table->string('last_name_key', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('declaration_items', function (Blueprint $table) {
            $table->dropIndex(['first_name_key']);
            $table->dropIndex(['last_name_key']);
            $table->dropColumn(['first_name_key', 'last_name_key']);
        });
    }
};
