<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Llaves foráneas a la persona física y a su perfil
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tipo_usuario_id')->constrained('tipo_usuarios')->restrictOnDelete();

            $table->string('username')->unique();
            $table->string('password');

            // Atajos de teclado, perfiles de transcripción, y vistas guardadas de la worklist
            $table->jsonb('settings')->default('{}');

            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }

};
