<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            // Si user_id es null, es una plantilla global de la clínica
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // Si exam_id es null, es un texto genérico (ej. "Recomendaciones")
            $table->foreignId('exam_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title'); // Ej: "RM Cerebro Normal"
            $table->longText('report_text');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};