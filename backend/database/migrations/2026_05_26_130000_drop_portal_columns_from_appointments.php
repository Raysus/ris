<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('appointments', 'portal_token')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn(['portal_token', 'portal_token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable()->unique()->after('accession_number');
            $table->timestamp('portal_token_expires_at')->nullable()->after('portal_token');
        });
    }
};
