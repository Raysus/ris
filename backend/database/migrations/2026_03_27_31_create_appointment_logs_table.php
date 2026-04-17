<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // Recepcionista o usuario

            $table->string('action'); // "reagendado", "anulado", "paciente_llego"
            $table->jsonb('details')->nullable(); // Ej: {"old_time": "10:00", "new_time": "11:00"}
            $table->string('ip_address')->nullable();

            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('appointment_logs');
    }
};