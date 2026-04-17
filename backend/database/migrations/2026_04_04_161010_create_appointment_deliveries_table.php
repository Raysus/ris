<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('appointment_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->foreignId('delivered_by')->constrained('users'); // La secretaria que lo entregó
            $table->string('receiver_rut');
            $table->string('receiver_name');
            $table->string('relationship');
            $table->string('delivery_method')->default('Presencial');
            $table->timestamps();
        });
    }
};
