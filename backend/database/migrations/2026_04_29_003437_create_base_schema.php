<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ==================================================
        // NIVEL 1: Tablas Independientes (Raíces)
        // ==================================================

        Schema::create('laboratory_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('personas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('rut')->nullable();
            $table->string('names');
            $table->string('last_name_1');
            $table->string('last_name_2')->nullable();
            $table->string('gender', 15)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('has_sso_account')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tipo_usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('permissions')->default('{}');
            $table->timestamps();
        });

        Schema::create('referring_doctors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('rut')->nullable();
            $table->string('names');
            $table->string('last_name_1')->nullable();
            $table->string('last_name_2')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        // ==================================================
        // NIVEL 2: Tablas de Configuración Core
        // ==================================================

        Schema::create('laboratories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_type_id')->constrained('laboratory_types');
            $table->uuid('parent_id')->nullable();
            $table->string('rut')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->string('logo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('persona_id')->constrained('personas');
            $table->foreignUuid('tipo_usuario_id')->constrained('tipo_usuarios');
            $table->string('username');
            $table->string('password');
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->string('medical_title')->nullable();
            $table->string('pacs_ae')->nullable();
            $table->string('dragon_profile')->nullable();
            $table->string('signature_path')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->foreignUuid('persona_id')->constrained('personas');
            $table->jsonb('clinical_data')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        // ==================================================
        // NIVEL 3: Catálogos de la Clínica
        // ==================================================

        Schema::create('insurances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('laboratory_id')->nullable()->constrained('laboratories'); // Corregido de integer a UUID
            $table->timestamps();
        });

        Schema::create('insurance_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('insurance_id')->constrained('insurances')->cascadeOnDelete();
            $table->string('name');
            $table->float('percentage')->nullable();
            $table->foreignUuid('laboratory_id')->nullable()->constrained('laboratories'); // Corregido de integer a UUID
            $table->timestamps();
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('name');
            $table->string('group')->nullable();
            $table->string('event_color')->default('#3788d8');
            $table->string('ae_title')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('port')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('group_code');
            $table->string('name');
            $table->jsonb('sub_exams')->default('[]');
            $table->string('fonasa_code')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('estimated_duration')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sub_exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('name');
            $table->integer('additional_price')->default(0);
            $table->string('fonasa_code')->nullable();
            $table->timestamps();
        });

        Schema::create('supplies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('category')->default('FUNGIBLE');
            $table->string('name');
            $table->integer('stock')->default(0);
            $table->integer('max_stock')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supply_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supply_pack_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supply_pack_id')->constrained('supply_packs')->cascadeOnDelete();
            $table->foreignUuid('supply_id')->constrained('supplies')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->foreignUuid('laboratory_id')->nullable()->constrained('laboratories'); // Corregido de integer a UUID
            $table->string('group_code')->nullable();
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('pacs_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('name');
            $table->string('ae_title');
            $table->string('ip_address');
            $table->string('port');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->constrained('exams');
            $table->foreignUuid('insurance_plan_id')->constrained('insurance_plans');
            $table->decimal('price', 10, 2);
            $table->decimal('copay', 10, 2)->default(0);
            $table->timestamps();
        });

        // ==================================================
        // NIVEL 4: Operación Clínica (Citas, Estudios, Pagos)
        // ==================================================

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->foreignUuid('machine_id')->constrained('machines');
            $table->foreignUuid('insurance_id')->nullable()->constrained('insurances');
            $table->foreignUuid('insurance_plan_id')->nullable()->constrained('insurance_plans');
            $table->foreignUuid('referring_doctor_id')->nullable()->constrained('referring_doctors');
            $table->foreignUuid('destination_doctor_id')->nullable()->constrained('users');
            
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status')->default('agendado');
            $table->boolean('needs_review')->default(false);
            $table->string('priority')->default('Normal');
            $table->string('origin')->default('Ambulatorio');
            $table->string('payment_method')->nullable();
            $table->string('transaction_code')->nullable();
            $table->string('tipo_bono')->nullable();
            $table->string('entidad_pagadora')->nullable();
            $table->string('accession_number')->nullable();
            $table->string('return_reason')->nullable();
            $table->string('medical_order_path')->nullable();
            $table->string('survey_path')->nullable();
            $table->string('referring_doctor')->nullable(); // Campo de texto legacy según el SQL
            $table->string('destination_doctor')->nullable(); // Campo de texto legacy según el SQL
            $table->string('insurance_plan')->nullable(); // Campo de texto legacy según el SQL
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('appointment_studies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('machine_id')->constrained('machines');
            $table->foreignUuid('exam_id')->nullable()->constrained('exams');
            $table->foreignUuid('sub_exam_id')->nullable()->constrained('sub_exams');
            $table->foreignUuid('radiologist_user_id')->nullable()->constrained('users'); // Corregido de integer a UUID
            
            $table->string('exam_name')->nullable();
            $table->string('sub_exam_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('fonasa_code')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status')->default('espera');
            $table->string('anamnesis')->nullable();
            $table->text('report')->nullable();
            $table->string('dictation_method')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_supplies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('supply_id')->constrained('supplies');
            $table->integer('quantity')->default(1);
            $table->decimal('price_charged', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('appointment_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('action');
            $table->jsonb('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('delivered_by')->constrained('users');
            $table->string('receiver_rut');
            $table->string('receiver_name');
            $table->string('relationship');
            $table->string('delivery_method')->default('Presencial');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('transaction_code')->nullable();
            $table->string('status')->default('completado');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('medical_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_study_id')->constrained('appointment_studies')->cascadeOnDelete();
            $table->foreignUuid('radiologist_id')->nullable()->constrained('users');
            $table->foreignUuid('transcriptionist_id')->nullable()->constrained('users');
            $table->text('report_text')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('status')->default('borrador');
            $table->dateTime('signed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_report_id')->constrained('medical_reports')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('action');
            $table->text('report_text')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('hl7_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('laboratory_id')->constrained('laboratories');
            $table->string('message_control_id');
            $table->string('message_type');
            $table->text('raw_message');
            $table->string('status')->default('recibido');
            $table->text('error_log')->nullable();
            $table->timestamps();
        });

        // ==================================================
        // NIVEL 5: Tablas Propias de Laravel
        // ==================================================

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // uuidMorphs crea tokenable_type (varchar) y tokenable_id (uuid)
            $table->uuidMorphs('tokenable'); 
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('laboratories', function (Blueprint $table) {
            $table->foreign('parent_id')
                  ->references('id')
                  ->on('laboratories')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Al deshabilitar las llaves foráneas temporalmente, podemos hacer un Drop 
        // sin preocuparnos del orden y sin que la base de datos tire errores.
        Schema::disableForeignKeyConstraints();
        
        $tables = [
            'personal_access_tokens', 'password_reset_tokens', 'hl7_messages', 'report_versions',
            'medical_reports', 'payments', 'appointment_deliveries', 'appointment_logs',
            'appointment_supplies', 'appointment_studies', 'appointments', 'tariffs',
            'pacs_servers', 'report_templates', 'services', 'supply_pack_items', 'supply_packs',
            'supplies', 'sub_exams', 'exams', 'machines', 'insurance_plans', 'insurances',
            'patients', 'laboratory_user', 'users', 'laboratories', 'referring_doctors',
            'tipo_usuarios', 'personas', 'laboratory_types'
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};