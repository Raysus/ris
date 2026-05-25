<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // === PERSONAS ===
        Schema::table('personas', function (Blueprint $table) {
            $table->index('rut'); // Búsqueda por RUT
            $table->index('email'); // Búsqueda por email
            $table->index('phone'); // Búsqueda por teléfono
            $table->fullText(['names', 'last_name_1', 'last_name_2']); // Búsqueda de texto completo
        });

        // === PATIENTS ===
        Schema::table('patients', function (Blueprint $table) {
            $table->index(['laboratory_id', 'persona_id']); // Búsqueda por laboratorio + persona
        });

        // === APPOINTMENTS ===
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('laboratory_id'); // Filtrado por laboratorio
            $table->index('patient_id'); // Búsqueda por paciente
            $table->index('machine_id'); // Búsqueda por máquina
            $table->index(['start_time', 'end_time']); // Búsqueda por rango de horarios
            $table->index('status'); // Filtrado por estado
            $table->index(['laboratory_id', 'status', 'start_time']); // Índice compuesto para queries complejas
        });

        // === APPOINTMENT_STUDIES ===
        Schema::table('appointment_studies', function (Blueprint $table) {
            $table->index('appointment_id'); // Búsqueda de estudios por cita
            $table->index('exam_id'); // Búsqueda por tipo de examen
            $table->index('status'); // Filtrado por estado de estudio
        });

        // === USERS ===
        Schema::table('users', function (Blueprint $table) {
            $table->index('username'); // Búsqueda por username
            $table->index('tipo_usuario_id'); // Filtrado por tipo de usuario
        });

        // === EXAMS ===
        Schema::table('exams', function (Blueprint $table) {
            $table->index('laboratory_id'); // Búsqueda por laboratorio
            $table->index('group_code'); // Búsqueda por código de grupo
        });

        // === MACHINES ===
        Schema::table('machines', function (Blueprint $table) {
            $table->index('laboratory_id'); // Búsqueda por laboratorio
            $table->index('group'); // Filtrado por tipo de máquina
        });

        // === INSURANCES ===
        Schema::table('insurances', function (Blueprint $table) {
            $table->index('code'); // Búsqueda por código
            $table->index('laboratory_id'); // Búsqueda por laboratorio
        });

        // === APPOINTMENT_LOGS ===
        Schema::table('appointment_logs', function (Blueprint $table) {
            $table->index('appointment_id'); // Búsqueda de logs por cita
            $table->index('user_id'); // Búsqueda de logs por usuario
            $table->index('created_at'); // Ordenamiento temporal
        });

        // === MEDICAL_REPORTS ===
        Schema::table('medical_reports', function (Blueprint $table) {
            $table->index('appointment_study_id'); // Búsqueda por estudio
            $table->index('radiologist_id'); // Búsqueda por radiólogo
            $table->index('status'); // Filtrado por estado
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // === PERSONAS ===
        Schema::table('personas', function (Blueprint $table) {
            $table->dropIndex(['rut']);
            $table->dropIndex(['email']);
            $table->dropIndex(['phone']);
            $table->dropFullText(['names', 'last_name_1', 'last_name_2']);
        });

        // === PATIENTS ===
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['laboratory_id', 'persona_id']);
        });

        // === APPOINTMENTS ===
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['laboratory_id']);
            $table->dropIndex(['patient_id']);
            $table->dropIndex(['machine_id']);
            $table->dropIndex(['start_time', 'end_time']);
            $table->dropIndex(['status']);
            $table->dropIndex(['laboratory_id', 'status', 'start_time']);
        });

        // === APPOINTMENT_STUDIES ===
        Schema::table('appointment_studies', function (Blueprint $table) {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['exam_id']);
            $table->dropIndex(['status']);
        });

        // === USERS ===
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['username']);
            $table->dropIndex(['tipo_usuario_id']);
        });

        // === EXAMS ===
        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex(['laboratory_id']);
            $table->dropIndex(['group_code']);
        });

        // === MACHINES ===
        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['laboratory_id']);
            $table->dropIndex(['group']);
        });

        // === INSURANCES ===
        Schema::table('insurances', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['laboratory_id']);
        });

        // === APPOINTMENT_LOGS ===
        Schema::table('appointment_logs', function (Blueprint $table) {
            $table->dropIndex(['appointment_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['created_at']);
        });

        // === MEDICAL_REPORTS ===
        Schema::table('medical_reports', function (Blueprint $table) {
            $table->dropIndex(['appointment_study_id']);
            $table->dropIndex(['radiologist_id']);
            $table->dropIndex(['status']);
        });
    }
};
