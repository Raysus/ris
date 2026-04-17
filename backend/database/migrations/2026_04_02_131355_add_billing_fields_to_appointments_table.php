<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Agregamos las columnas y las permitimos nulas por si no las llenan
            $table->string('tipo_bono')->nullable()->after('transaction_code');
            $table->string('entidad_pagadora')->nullable()->after('tipo_bono');
        });
    }

    public function down()
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Reversa por si necesitas hacer un rollback
            $table->dropColumn(['tipo_bono', 'entidad_pagadora']);
        });
    }
};