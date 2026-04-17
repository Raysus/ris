<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();

            // Datos según tu JSON actual
            $table->string('group_code'); // Ej: RX, CT, MRI
            $table->string('name'); // Ej: Tórax, Cerebro, Abdomen
            $table->jsonb('sub_exams')->default('[]'); // Ej: ["Simple", "AP/Lateral", "Con Contraste"]

            $table->string('fonasa_code')->nullable(); // Tu "code" actual (Ej: 04-01-070)
            $table->decimal('price', 10, 2)->default(0);

            $table->integer('estimated_duration')->default(15); // Minutos base
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
