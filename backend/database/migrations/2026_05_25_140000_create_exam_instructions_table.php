<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->unique()->constrained('exams')->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_instructions');
    }
};
