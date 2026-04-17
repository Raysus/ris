<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // El cajero/recepcionista que recibió el pago

            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // Efectivo, Tarjeta, Transferencia, Bono FONASA
            $table->string('transaction_code')->nullable(); // Código de Transbank o N° de Bono

            $table->string('status')->default('completado'); // completado, anulado, reembolsado

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};