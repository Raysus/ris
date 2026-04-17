<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();

            $table->string('category')->default('FUNGIBLE'); // CONTRASTE, FUNGIBLE, PROTECCION
            $table->string('name');
            $table->integer('stock')->default(0);
            $table->integer('max_stock')->default(0); // El campo 'total' de tu JS
            $table->decimal('price', 10, 2)->default(0); // Si se cobra al paciente

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};