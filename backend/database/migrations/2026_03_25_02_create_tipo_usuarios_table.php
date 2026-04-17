<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tipo_usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ej: Administrador, Radiólogo, Recepción
            $table->string('description')->nullable();

            $table->jsonb('permissions')->default('{}');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_usuarios');
    }
};