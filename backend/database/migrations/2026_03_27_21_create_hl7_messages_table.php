<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hl7_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();

            $table->string('message_control_id')->unique(); // ID único del mensaje (MSH-10)
            $table->string('message_type'); // ADT (Pacientes), ORM (Órdenes), ORU (Resultados)

            $table->longText('raw_message'); // Todo el texto con los Pipes (|)
            $table->string('status')->default('recibido'); // recibido, procesado, error
            $table->text('error_log')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hl7_messages');
    }
};