<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Agregamos los nuevos campos después de 'port' para mantener el orden lógico
            $table->string('manufacturer')->nullable()->after('port');
            $table->string('model_name')->nullable()->after('manufacturer');
            $table->text('description')->nullable()->after('model_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Eliminamos las columnas si necesitamos revertir la migración
            $table->dropColumn(['manufacturer', 'model_name', 'description']);
        });
    }
};