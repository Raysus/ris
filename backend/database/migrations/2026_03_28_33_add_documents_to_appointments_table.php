<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('medical_order_path')->nullable()->after('status');
            $table->string('survey_path')->nullable()->after('medical_order_path');
        });
    }
    public function down()
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['medical_order_path', 'survey_path']);
        });
    }
};
