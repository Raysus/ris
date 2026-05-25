<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Paciente;
use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Machine;
use App\Models\Laboratory;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\MedicalReport;
use App\Models\AppointmentDelivery;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * TEST E2E COMPLETO
 * 
 * Flujo: Login → Crear Paciente → Agendar Cita → Agregar Exámenes 
 *        → Registrar Pago → Registrar Resultados → Entregar al Paciente
 * 
 * Roles simulados:
 * - Recepcionista: Crea paciente, agenda cita, registra pago
 * - Radiólogo: Registra resultados
 * - Sistema: Notifica entrega al paciente
 */
class CompleteFlowTest extends TestCase
{
    use RefreshDatabase;

    private $laboratory;
    private $receptionist;
    private $radiologist;
    private $machine;
    private $exam;
    private $insurance;
    private $insurancePlan;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear laboratorio (contexto multi-tenencia)
        $this->laboratory = Laboratory::factory()->create([
            'name' => 'Laboratorio Central Santiago',
            'code' => 'LAB-STGO-001',
        ]);

        // Crear recepcionista
        $this->receptionist = User::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'username' => 'recepcionista_001',
            'email' => 'recepcionista@lab.cl',
            'tipo_usuario_id' => 2, // Recepcionista
        ]);

        // Crear radiólogo
        $this->radiologist = User::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'username' => 'radiologist_001',
            'email' => 'radiologist@lab.cl',
            'tipo_usuario_id' => 4, // Radiólogo
        ]);

        // Crear máquina de rayos X
        $this->machine = Machine::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'name' => 'Rayos X Tórax 1',
            'group' => 'Radiología',
        ]);

        // Crear examen
        $this->exam = Exam::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'name' => 'Radiografía de Tórax',
            'price' => 25000,
            'codigo_fonasa' => '29001',
        ]);

        // Crear previsión
        $this->insurance = Insurance::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'code' => 'FONASA',
            'name' => 'FONASA',
        ]);

        // Crear plan de previsión (15% copago)
        $this->insurancePlan = InsurancePlan::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'insurance_id' => $this->insurance->id,
            'name' => 'FONASA',
            'percentage' => 15,
        ]);
    }

    /**
     * PASO 1: LOGIN - Recepcionista inicia sesión
     */
    public function test_step_1_receptionist_login(): void
    {
        $response = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password', // Default factory password
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => ['id', 'username', 'email']
        ]);

        // Guardar token para siguientes requests
        $this->token = $response->json('access_token');
        $this->assertTrue(!empty($this->token), 'Token debe estar disponible');
    }

    /**
     * PASO 2: CREAR PACIENTE - Recepcionista registra nuevo paciente
     */
    public function test_step_2_create_patient(): void
    {
        // Login primero
        $loginResponse = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password',
        ]);

        $token = $loginResponse->json('access_token');

        // Crear paciente
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/patients', [
            'rut' => '12345678-9',
            'names' => 'Juan',
            'last_name_1' => 'Pérez',
            'last_name_2' => 'García',
            'email' => 'juan.perez@example.com',
            'phone' => '+56912345678',
            'birth_date' => '1990-05-15',
            'gender' => 'Masculino',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'rut', 'names']
        ]);

        $this->patientId = $response->json('data.id');
        $this->assertTrue(!empty($this->patientId), 'Patient ID debe estar disponible');
    }

    /**
     * PASO 3: AGENDAR CITA - Recepcionista crea cita para el paciente
     */
    public function test_step_3_create_appointment(): void
    {
        // Login
        $loginResponse = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password',
        ]);
        $token = $loginResponse->json('access_token');

        // Crear paciente primero
        $patientResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/patients', [
            'rut' => '12345678-9',
            'names' => 'Juan',
            'last_name_1' => 'Pérez',
            'last_name_2' => 'García',
            'email' => 'juan.perez@example.com',
            'phone' => '+56912345678',
            'birth_date' => '1990-05-15',
            'gender' => 'Masculino',
        ]);
        $patientId = $patientResponse->json('data.id');

        // Crear cita
        $appointmentTime = now()->addHours(2)->format('Y-m-d H:i');
        $appointmentEndTime = now()->addHours(2)->addMinutes(30)->format('Y-m-d H:i');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentEndTime,
            'priority' => 'Normal',
            'insurance_id' => $this->insurance->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'patient_id', 'start_time']
        ]);

        $this->appointmentId = $response->json('data.id');
        $this->assertTrue(!empty($this->appointmentId), 'Appointment ID debe estar disponible');
    }

    /**
     * PASO 4: AGREGAR EXÁMENES - Agregar estudios a la cita
     */
    public function test_step_4_add_studies_to_appointment(): void
    {
        // Setup
        $loginResponse = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password',
        ]);
        $token = $loginResponse->json('access_token');

        // Crear paciente
        $patientResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/patients', [
            'rut' => '12345678-9',
            'names' => 'Juan',
            'last_name_1' => 'Pérez',
            'last_name_2' => 'García',
            'email' => 'juan.perez@example.com',
            'phone' => '+56912345678',
            'birth_date' => '1990-05-15',
            'gender' => 'Masculino',
        ]);
        $patientId = $patientResponse->json('data.id');

        // Crear cita
        $appointmentTime = now()->addHours(2)->format('Y-m-d H:i');
        $appointmentEndTime = now()->addHours(2)->addMinutes(30)->format('Y-m-d H:i');

        $appointmentResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentEndTime,
            'priority' => 'Normal',
            'insurance_id' => $this->insurance->id,
        ]);
        $appointmentId = $appointmentResponse->json('data.id');

        // Agregar estudio/examen a la cita
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/appointments/{$appointmentId}/studies", [
            'exam_id' => $this->exam->id,
            'urgency' => 'Normal',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'), 'Estudio debe agregarse exitosamente');

        $this->studyId = $response->json('data.id');
    }

    /**
     * PASO 5: REGISTRAR PAGO - Recepcionista registra el pago de la cita
     */
    public function test_step_5_register_payment(): void
    {
        // Setup: Login y crear cita
        $loginResponse = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password',
        ]);
        $token = $loginResponse->json('access_token');

        // Crear paciente
        $patientResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/patients', [
            'rut' => '87654321-0',
            'names' => 'María',
            'last_name_1' => 'López',
            'last_name_2' => 'Martínez',
            'email' => 'maria.lopez@example.com',
            'birth_date' => '1985-03-20',
            'gender' => 'Femenino',
        ]);
        $patientId = $patientResponse->json('data.id');

        // Crear cita
        $appointmentTime = now()->addHours(2)->format('Y-m-d H:i');
        $appointmentResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => now()->addHours(2)->addMinutes(30)->format('Y-m-d H:i'),
            'priority' => 'Normal',
            'insurance_id' => $this->insurance->id,
        ]);
        $appointmentId = $appointmentResponse->json('data.id');

        // Obtener desglose de precios
        $breakdownResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/payments/breakdown/{$appointmentId}");

        $breakdownResponse->assertStatus(200);
        $totalToPay = $breakdownResponse->json('data.resumen.total_a_pagar');

        // Registrar pago
        $paymentResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/payments', [
            'appointment_id' => $appointmentId,
            'amount' => $totalToPay,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
            'transaction_code' => null,
        ]);

        $paymentResponse->assertStatus(200);
        $paymentResponse->assertJsonPath('success', true);
        $paymentResponse->assertJsonPath('message', 'Pago registrado exitosamente');

        $this->paymentId = $paymentResponse->json('data.id');
        $this->appointmentId = $appointmentId;
    }

    /**
     * PASO 6: REGISTRAR RESULTADOS - Radiólogo procesa y registra los resultados
     */
    public function test_step_6_radiologist_registers_results(): void
    {
        // Setup: Crear cita con estudio
        $this->actingAs($this->receptionist);

        // Crear paciente
        $patient = Paciente::factory()->create([
            'laboratory_id' => $this->laboratory->id,
        ]);

        // Crear cita
        $appointmentTime = now()->addHours(1);
        $appointment = Appointment::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'patient_id' => $patient->id,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentTime->addMinutes(30),
            'payment_status' => 'Pagado',
        ]);

        // Crear estudio/examen
        $study = $appointment->studies()->create([
            'exam_id' => $this->exam->id,
            'machine_id' => $this->machine->id,
            'status' => 'Procesado',
        ]);

        // Cambiar a radiólogo
        $this->actingAs($this->radiologist);

        // Radiólogo registra reporte médico
        $response = $this->postJson("/api/appointments/{$appointment->id}/medical-reports", [
            'appointment_study_id' => $study->id,
            'findings' => 'Hallazgos: Se observa patrón radiográfico normal. No se evidencian alteraciones pleuropulmonares agudas. Silueta cardiomediastínica dentro de los límites normales.',
            'conclusion' => 'Radiografía de tórax: NORMAL',
            'recommendations' => 'Seguimiento clínico',
            'status' => 'Completado',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'findings', 'conclusion']
        ]);

        $this->reportId = $response->json('data.id');
    }

    /**
     * PASO 7: ENTREGAR RESULTADOS AL PACIENTE - Notificar y entregar resultados
     */
    public function test_step_7_deliver_results_to_patient(): void
    {
        // Setup: Crear cita completada con resultados
        $this->actingAs($this->receptionist);

        $patient = Paciente::factory()->create([
            'laboratory_id' => $this->laboratory->id,
        ]);

        $appointmentTime = now()->addHours(1);
        $appointment = Appointment::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'patient_id' => $patient->id,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentTime->addMinutes(30),
            'payment_status' => 'Pagado',
            'status' => 'Completado',
        ]);

        // Crear reporte
        $study = $appointment->studies()->create([
            'exam_id' => $this->exam->id,
            'machine_id' => $this->machine->id,
            'status' => 'Procesado',
        ]);

        $this->actingAs($this->radiologist);
        $report = MedicalReport::create([
            'appointment_study_id' => $study->id,
            'radiologist_id' => $this->radiologist->id,
            'findings' => 'Normal',
            'conclusion' => 'Sin alteraciones',
            'status' => 'Completado',
        ]);

        // Cambiar a recepcionista para entregar
        $this->actingAs($this->receptionist);

        // Registrar entrega de resultados
        $response = $this->postJson("/api/appointments/{$appointment->id}/deliveries", [
            'delivery_method' => 'En Persona',
            'patient_signature' => true,
            'delivery_date' => now()->toDateString(),
            'notes' => 'Paciente recibe copia de resultados. Recomendado seguimiento con especialista.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'delivery_method', 'delivery_date']
        ]);

        // Verificar que el estado de la cita cambió a 'Entregado'
        $appointmentCheck = $this->getJson("/api/appointments/{$appointment->id}");
        $this->assertEquals('Entregado', $appointmentCheck->json('data.status'));
    }

    /**
     * FLUJO COMPLETO E2E - Todos los pasos en secuencia
     */
    public function test_complete_workflow_end_to_end(): void
    {
        // ========== PASO 1: LOGIN ==========
        $loginResponse = $this->postJson('/api/login', [
            'login_field' => 'recepcionista_001',
            'password' => 'password',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('access_token');
        $this->assertNotEmpty($token);

        // ========== PASO 2: CREAR PACIENTE ==========
        $patientResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/patients', [
            'rut' => '99999999-9',
            'names' => 'Carlos',
            'last_name_1' => 'González',
            'last_name_2' => 'Rodríguez',
            'email' => 'carlos.gonzalez@example.com',
            'phone' => '+56987654321',
            'birth_date' => '1992-08-10',
            'gender' => 'Masculino',
        ]);

        $patientResponse->assertStatus(201);
        $patientId = $patientResponse->json('data.id');
        $this->assertNotEmpty($patientId);

        // ========== PASO 3: AGENDAR CITA ==========
        $appointmentTime = now()->addHours(2)->format('Y-m-d H:i');
        $appointmentEndTime = now()->addHours(2)->addMinutes(30)->format('Y-m-d H:i');

        $appointmentResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentEndTime,
            'priority' => 'Alta',
            'insurance_id' => $this->insurance->id,
        ]);

        $appointmentResponse->assertStatus(201);
        $appointmentId = $appointmentResponse->json('data.id');
        $this->assertNotEmpty($appointmentId);

        // ========== PASO 4: AGREGAR EXÁMENES ==========
        $studyResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/appointments/{$appointmentId}/studies", [
            'exam_id' => $this->exam->id,
            'urgency' => 'Alta',
        ]);

        $studyResponse->assertStatus(201);
        $studyId = $studyResponse->json('data.id');
        $this->assertNotEmpty($studyId);

        // ========== PASO 5: REGISTRAR PAGO ==========
        $breakdownResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/payments/breakdown/{$appointmentId}");

        $breakdownResponse->assertStatus(200);
        $totalToPay = $breakdownResponse->json('data.resumen.total_a_pagar');

        $paymentResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/payments', [
            'appointment_id' => $appointmentId,
            'amount' => $totalToPay,
            'payment_method' => 'Tarjeta Crédito',
            'status' => 'Pagado',
            'transaction_code' => 'TRX-' . uniqid(),
        ]);

        $paymentResponse->assertStatus(200);
        $paymentId = $paymentResponse->json('data.id');
        $this->assertNotEmpty($paymentId);

        // Verificar que el pago se registró en el historial
        $historyResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/payments/history/{$appointmentId}");

        $historyResponse->assertStatus(200);
        $this->assertCount(1, $historyResponse->json('data.pagos'));
        $this->assertEquals($totalToPay, $historyResponse->json('data.resumen.total_pagado'));

        // ========== PASO 6: CAMBIAR A RADIÓLOGO Y REGISTRAR RESULTADOS ==========
        // Hacer que la cita se marque como en proceso
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'En Proceso',
        ]);

        // Cambiar a radiólogo
        $radiologistLoginResponse = $this->postJson('/api/login', [
            'login_field' => 'radiologist_001',
            'password' => 'password',
        ]);

        $radiologistToken = $radiologistLoginResponse->json('access_token');

        // Radiólogo registra reporte
        $reportResponse = $this->withHeaders([
            'Authorization' => "Bearer {$radiologistToken}",
        ])->postJson("/api/appointments/{$appointmentId}/medical-reports", [
            'appointment_study_id' => $studyId,
            'findings' => 'Hallazgos clínicos significativos: Se aprecia consolidación pulmonar en lóbulo inferior derecho. Posible infiltrado infeccioso.',
            'conclusion' => 'Radiografía de Tórax: COMPATIBLE CON NEUMONÍA',
            'recommendations' => '1. Consulta con neumólogo. 2. Considerar cultivo de expectoración. 3. Iniciar antibióticos según criterio clínico.',
            'status' => 'Completado',
        ]);

        $reportResponse->assertStatus(201);
        $reportId = $reportResponse->json('data.id');
        $this->assertNotEmpty($reportId);

        // ========== PASO 7: ENTREGAR RESULTADOS AL PACIENTE ==========
        // Cambiar cita a completada
        $this->withHeaders([
            'Authorization' => "Bearer {$radiologistToken}",
        ])->patchJson("/api/appointments/{$appointmentId}", [
            'status' => 'Completado',
        ]);

        // Volver a recepcionista para entregar
        $deliveryResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/appointments/{$appointmentId}/deliveries", [
            'delivery_method' => 'En Persona',
            'patient_signature' => true,
            'delivery_date' => now()->toDateString(),
            'notes' => 'Paciente informado de hallazgos. Se recomienda consulta inmediata con especialista. Entrega de copia de resultados.',
        ]);

        $deliveryResponse->assertStatus(201);
        $deliveryId = $deliveryResponse->json('data.id');
        $this->assertNotEmpty($deliveryId);

        // ========== VERIFICACIONES FINALES ==========
        // 1. Cita debe estar en estado "Entregado"
        $finalAppointmentCheck = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/appointments/{$appointmentId}");

        $finalAppointmentCheck->assertStatus(200);
        $this->assertEquals('Entregado', $finalAppointmentCheck->json('data.status'));
        $this->assertEquals('Pagado', $finalAppointmentCheck->json('data.payment_status'));

        // 2. Paciente debe tener la cita en su historial
        $patientHistoryResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/patients/{$patientId}/appointments");

        $patientHistoryResponse->assertStatus(200);
        $this->assertTrue(count($patientHistoryResponse->json('data')) > 0);

        // 3. Debe existir un registro de entrega
        $deliveryCheckResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/appointments/{$appointmentId}/deliveries");

        $deliveryCheckResponse->assertStatus(200);
        $this->assertTrue(count($deliveryCheckResponse->json('data')) > 0);

        // 4. Generar comprobante de pago
        $receiptResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/payments/{$paymentId}/receipt");

        $receiptResponse->assertStatus(200);
        $this->assertNotEmpty($receiptResponse->json('data.comprobante.numero'));

        // ========== RESUMEN ==========
        $this->assertTrue(true, 'FLUJO COMPLETO E2E EXITOSO:
            ✅ Login realizado
            ✅ Paciente creado (ID: ' . $patientId . ')
            ✅ Cita agendada (ID: ' . $appointmentId . ')
            ✅ Examen agregado (ID: ' . $studyId . ')
            ✅ Pago registrado (ID: ' . $paymentId . ')
            ✅ Resultados registrados (ID: ' . $reportId . ')
            ✅ Resultados entregados (ID: ' . $deliveryId . ')
        ');
    }

    /**
     * TEST: Validar que sin pago no se puede entregar
     */
    public function test_cannot_deliver_without_payment(): void
    {
        $this->actingAs($this->receptionist);

        $patient = Paciente::factory()->create([
            'laboratory_id' => $this->laboratory->id,
        ]);

        $appointment = Appointment::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'patient_id' => $patient->id,
            'machine_id' => $this->machine->id,
            'payment_status' => 'Pendiente', // Sin pago
            'status' => 'Completado',
        ]);

        // Intentar entregar sin pago
        $response = $this->postJson("/api/appointments/{$appointment->id}/deliveries", [
            'delivery_method' => 'En Persona',
            'patient_signature' => true,
            'delivery_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
    }

    /**
     * TEST: Auditoría - Registrar todos los cambios
     */
    public function test_audit_trail_complete(): void
    {
        $this->actingAs($this->receptionist);

        $patient = Paciente::factory()->create([
            'laboratory_id' => $this->laboratory->id,
        ]);

        $appointmentTime = now()->addHours(1);
        $appointment = Appointment::factory()->create([
            'laboratory_id' => $this->laboratory->id,
            'patient_id' => $patient->id,
            'machine_id' => $this->machine->id,
            'start_time' => $appointmentTime,
            'end_time' => $appointmentTime->addMinutes(30),
        ]);

        // Cambiar estado
        $this->patchJson("/api/appointments/{$appointment->id}", [
            'status' => 'En Proceso',
        ]);

        // Ver logs
        $logsResponse = $this->getJson("/api/appointments/{$appointment->id}/logs");
        $logsResponse->assertStatus(200);

        // Debe haber al menos un registro
        $logs = $logsResponse->json('data');
        $this->assertTrue(count($logs) > 0, 'Debe haber registros de auditoría');
    }
}
