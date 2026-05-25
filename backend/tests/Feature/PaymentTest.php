<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Appointment;
use App\Models\User;
use App\Models\Exam;
use App\Models\Insurance;

class PaymentTest extends TestCase
{
    private $user;
    private $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Crear usuario autenticado
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Test obtener desglose de precios
     */
    public function test_get_payment_breakdown(): void
    {
        // Crear una cita con estudios
        $appointment = Appointment::factory()->create();

        $response = $this->getJson("/api/payments/breakdown/{$appointment->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'appointment_id',
                'estudios' => [
                    '*' => ['id', 'nombre', 'precio']
                ],
                'resumen' => [
                    'total_arancel',
                    'copago',
                    'total_a_pagar'
                ]
            ]
        ]);
    }

    /**
     * Test registrar pago exitoso
     */
    public function test_register_payment_success(): void
    {
        $appointment = Appointment::factory()->create();

        $response = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Pago registrado exitosamente');
    }

    /**
     * Test validación de pago - monto faltante
     */
    public function test_payment_validation_missing_amount(): void
    {
        $appointment = Appointment::factory()->create();

        $response = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }

    /**
     * Test validación de pago - método inválido
     */
    public function test_payment_validation_invalid_method(): void
    {
        $appointment = Appointment::factory()->create();

        $response = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Moneda de Cambio', // Inválido
            'status' => 'Pagado',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('payment_method');
    }

    /**
     * Test obtener historial de pagos
     */
    public function test_get_payment_history(): void
    {
        $appointment = Appointment::factory()->create();

        // Registrar un pago
        $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
        ]);

        // Obtener historial
        $response = $this->getJson("/api/payments/history/{$appointment->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'pagos' => [
                    '*' => ['id', 'amount', 'payment_method', 'status']
                ],
                'resumen' => [
                    'total_pagado',
                    'total_deuda',
                    'estado'
                ]
            ]
        ]);
    }

    /**
     * Test obtener planes de salud
     */
    public function test_get_insurance_plans(): void
    {
        $response = $this->getJson('/api/payments/insurance-plans');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'percentage']
            ]
        ]);
    }

    /**
     * Test generar comprobante de pago
     */
    public function test_generate_payment_receipt(): void
    {
        $appointment = Appointment::factory()->create();

        // Registrar pago
        $paymentResponse = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
        ]);

        $paymentId = $paymentResponse->json('data.id');

        // Generar comprobante
        $response = $this->getJson("/api/payments/{$paymentId}/receipt");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'comprobante' => [
                    'numero',
                    'fecha',
                    'paciente',
                    'monto',
                    'metodo',
                    'estado'
                ]
            ]
        ]);
    }

    /**
     * Test reporte diario de pagos
     */
    public function test_get_daily_payment_report(): void
    {
        // Crear varios pagos
        for ($i = 0; $i < 3; $i++) {
            $appointment = Appointment::factory()->create();
            $this->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 50000,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ]);
        }

        $response = $this->getJson('/api/payments/reports/daily?fecha=' . now()->toDateString());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'pagos',
                'resumen' => [
                    'fecha',
                    'total_pagado',
                    'total_transacciones',
                    'por_metodo'
                ]
            ]
        ]);
    }

    /**
     * Test múltiples pagos parciales
     */
    public function test_multiple_partial_payments(): void
    {
        $appointment = Appointment::factory()->create();
        $totalExpected = 100000;

        // Primer pago: $50.000
        $response1 = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pago Parcial',
        ]);
        $response1->assertStatus(200);

        // Segundo pago: $50.000
        $response2 = $this->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Tarjeta Crédito',
            'status' => 'Pagado',
        ]);
        $response2->assertStatus(200);

        // Verificar historial
        $historyResponse = $this->getJson("/api/payments/history/{$appointment->id}");
        $historyResponse->assertStatus(200);
        $this->assertEquals($totalExpected, $historyResponse->json('data.resumen.total_pagado'));
    }
}
