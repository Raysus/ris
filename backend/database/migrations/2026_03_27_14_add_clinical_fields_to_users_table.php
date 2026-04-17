<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('medical_title')->nullable()->after('password'); // Ej: Dr., T.M.
            $table->string('pacs_ae')->nullable()->after('medical_title'); // RIS_PRO_ADMIN
            $table->string('dragon_profile')->nullable()->after('pacs_ae');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['medical_title', 'pacs_ae', 'dragon_profile']);
        });
    }
};