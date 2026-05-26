<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonasa_bonos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('folio');
            $table->string('tipo')->default('Electronico');
            $table->string('estado')->default('pendiente');
            $table->string('rut_beneficiario')->nullable();
            $table->string('prestacion_codigo')->nullable();
            $table->decimal('monto_bonificacion', 12, 2)->default(0);
            $table->decimal('monto_copago', 12, 2)->default(0);
            $table->decimal('monto_total', 12, 2)->default(0);
            $table->jsonb('validation_response')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUuid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['appointment_id', 'folio']);
        });

        Schema::create('electronic_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('document_type')->default('boleta');
            $table->string('folio')->nullable();
            $table->string('status')->default('borrador');
            $table->string('receptor_rut')->nullable();
            $table->string('receptor_name')->nullable();
            $table->decimal('monto_neto', 12, 2)->default(0);
            $table->decimal('monto_iva', 12, 2)->default(0);
            $table->decimal('monto_exento', 12, 2)->default(0);
            $table->decimal('monto_total', 12, 2)->default(0);
            $table->string('provider_reference')->nullable();
            $table->jsonb('payload')->nullable();
            $table->jsonb('provider_response')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('emitted_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['laboratory_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
        Schema::dropIfExists('fonasa_bonos');
    }
};
