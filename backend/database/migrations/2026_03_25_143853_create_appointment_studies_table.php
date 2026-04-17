<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_studies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            // ¿En qué máquina exacta se hará este estudio particular?
            $table->foreignId('machine_id')->constrained()->restrictOnDelete();

            // Detalles del estudio (Vienen de tu eExam, eSubExam, eQty)
            $table->string('exam_name');
            $table->string('sub_exam_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('fonasa_code')->nullable();
            $table->decimal('price', 10, 2)->default(0);

            // Estado interno del estudio (Para cuando pase al Tecnólogo/Radiólogo)
            $table->string('status')->default('espera'); // espera, en_curso, dictado, finalizado

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_studies');
    }
};