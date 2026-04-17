<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id();

            // 1. ¿Qué tipo de laboratorio es? (Clínico, Veterinario, etc.)
            $table->foreignId('laboratory_type_id')->constrained()->restrictOnDelete();

            // 2. ¿Tiene una Casa Matriz? (Apunta a esta misma tabla. Si es null, ÉSTE es la Casa Matriz)
            $table->foreignId('parent_id')->nullable()->constrained('laboratories')->cascadeOnDelete();

            $table->string('rut')->unique()->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('laboratories');
    }
};