<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('laboratory_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Para saber a qué laboratorio entra por defecto cuando hace login en el frontend
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            // Un usuario no puede estar asignado dos veces al mismo laboratorio
            $table->unique(['laboratory_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('laboratory_user');
    }
};