<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insurances', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Ej: FONASA, BANMEDICA
            $table->string('name');
            $table->string('type'); // Publico, Privado, Convenio
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Agregamos la llave foránea a las citas que ya existen
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('insurance_id')->nullable()->after('priority')->constrained();
            $table->string('insurance_plan')->nullable()->after('insurance_id'); // Ej: "Tramo D", "Plan Base"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurances');
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['insurance_id']);
            $table->dropColumn(['insurance_id', 'insurance_plan']);
        });
    }
};