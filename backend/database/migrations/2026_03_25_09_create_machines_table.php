<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();

            $table->string('name'); // Ej: Resonador Philips 1.5T
            $table->string('group')->nullable(); // Ej: RM, TC, RX (Para el resourceGroupField de tu FullCalendar)
            $table->string('event_color')->default('#3788d8'); // Para pintar la columna en el JS
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};