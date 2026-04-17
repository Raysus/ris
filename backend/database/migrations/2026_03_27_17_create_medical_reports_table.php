<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medical_reports', function (Blueprint $table) {
            $table->id();
            // Se asocia a un estudio específico (Ej: El informe del "Scanner de Cerebro")
            $table->foreignId('appointment_study_id')->constrained()->cascadeOnDelete();

            // ¿Quién lo hizo?
            $table->foreignId('radiologist_id')->nullable()->constrained('users');
            $table->foreignId('transcriptionist_id')->nullable()->constrained('users');

            // El contenido
            $table->longText('report_text')->nullable();
            $table->string('audio_path')->nullable(); // Si usan dictado por voz y guardan el MP3

            // Estados y validación
            $table->string('status')->default('borrador'); // borrador, transcrito, firmado
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_reports');
    }
};
