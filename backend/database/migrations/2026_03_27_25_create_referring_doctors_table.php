<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('referring_doctors', function (Blueprint $table) {
            $table->id();
            $table->string('rut')->nullable();
            $table->string('names');
            $table->string('last_name_1')->nullable();
            $table->string('last_name_2')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('referring_doctors');
    }
};