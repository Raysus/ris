<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('referring_doctor_id')->nullable()->after('referring_doctor')->constrained('referring_doctors');
        });
    }
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['referring_doctor_id']);
            $table->dropColumn('referring_doctor_id');
        });
    }
};