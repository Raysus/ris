<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppointmentValidationTest extends TestCase
{
    /**
     * Test validación de cita - falta paciente
     */
    public function test_appointment_missing_patient(): void
    {
        $response = $this->postJson('/api/appointments', [
            'machine_id' => 'invalid-uuid',
            'start_time' => '2026-05-25 10:00',
            'end_time' => '2026-05-25 10:30'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['patient_id']);
    }

    /**
     * Test validación de cita - fecha en pasado
     */
    public function test_appointment_date_in_past(): void
    {
        $response = $this->postJson('/api/appointments', [
            'patient_id' => 'valid-uuid',
            'machine_id' => 'valid-uuid',
            'start_time' => '2025-05-25 10:00', // Pasado
            'end_time' => '2025-05-25 10:30'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time']);
    }

    /**
     * Test validación de cita - hora fin antes que inicio
     */
    public function test_appointment_end_before_start(): void
    {
        $response = $this->postJson('/api/appointments', [
            'patient_id' => 'valid-uuid',
            'machine_id' => 'valid-uuid',
            'start_time' => '2026-05-25 10:30',
            'end_time' => '2026-05-25 10:00' // Antes que start_time
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    /**
     * Test prioridad inválida
     */
    public function test_appointment_invalid_priority(): void
    {
        $response = $this->postJson('/api/appointments', [
            'patient_id' => 'valid-uuid',
            'machine_id' => 'valid-uuid',
            'start_time' => '2026-05-25 10:00',
            'end_time' => '2026-05-25 10:30',
            'priority' => 'Baja' // No es Normal, Alta, Urgente
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }
}
