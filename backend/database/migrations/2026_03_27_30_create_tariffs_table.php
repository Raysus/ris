<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurance_plan_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('copay', 10, 2)->default(0); // Lo que paga el paciente vs lo que paga la Isapre
            $table->timestamps();

            // Un examen solo puede tener un precio por cada plan
            $table->unique(['exam_id', 'insurance_plan_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};