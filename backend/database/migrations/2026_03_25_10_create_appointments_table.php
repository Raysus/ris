<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            // Relaciones Multi-Tenant y Clínicas
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->restrictOnDelete();

            // Tiempos (El start y end de FullCalendar)
            $table->dateTime('start_time');
            $table->dateTime('end_time');

            // Estado y metadatos clínicos
            $table->string('status')->default('agendado'); // pre-agendado, agendado, confirmado, etc.
            $table->boolean('needs_review')->default(false);

            $table->string('referring_doctor')->nullable(); // mTratante en tu JS
            $table->string('destination_doctor')->nullable(); // mDestinado en tu JS
            $table->string('priority')->default('Normal'); // Normal, Urgencia
            $table->string('origin')->default('Ambulatorio'); // procedencia en tu JS

            // Finanzas básicas
            $table->string('payment_method')->nullable();
            $table->string('transaction_code')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};