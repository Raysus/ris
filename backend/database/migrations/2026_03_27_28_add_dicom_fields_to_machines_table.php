<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('ae_title')->nullable()->after('group');
            $table->string('ip_address')->nullable()->after('ae_title');
            $table->string('port')->nullable()->after('ip_address');
        });
    }
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['ae_title', 'ip_address', 'port']);
        });
    }
};