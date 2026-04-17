<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Quién hizo el cambio

            $table->string('action'); // creado, transcrito, editado, firmado, invalidado
            $table->longText('report_text')->nullable(); // El texto EXACTO en ese momento de la historia

            $table->string('ip_address')->nullable(); // Desde qué computador lo hizo (Auditoría estricta)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_versions');
    }
};