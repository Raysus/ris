<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->restrictOnDelete();

            $table->integer('quantity')->default(1);
            $table->decimal('price_charged', 10, 2)->default(0); // El precio en el momento exacto de la cita

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_supplies');
    }
};