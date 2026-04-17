<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();

            $table->jsonb('clinical_data')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['laboratory_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};