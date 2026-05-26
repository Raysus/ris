<?php

namespace Tests\Feature;

use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_get_payment_breakdown(): void
    {
        $appointment = $this->createTestAppointment();

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/payments/breakdown/{$appointment->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'appointment_id',
                    'estudios',
                    'resumen' => ['total_arancel', 'copago', 'total_a_pagar'],
                ],
            ]);
    }

    public function test_register_payment_success(): void
    {
        $appointment = $this->createTestAppointment();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 50000,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pago registrado exitosamente');
    }

    public function test_payment_validation_missing_amount(): void
    {
        $appointment = $this->createTestAppointment();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_payment_validation_invalid_method(): void
    {
        $appointment = $this->createTestAppointment();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 50000,
                'payment_method' => 'Moneda de Cambio',
                'status' => 'Pagado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_get_payment_history(): void
    {
        $appointment = $this->createTestAppointment();
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pagado',
        ])->assertOk();

        $this->withHeaders($headers)->getJson("/api/payments/history/{$appointment->id}")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'pagos',
                    'resumen' => ['total_pagado', 'total_deuda', 'estado'],
                ],
            ]);
    }

    public function test_get_insurance_plans(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/payments/insurance-plans');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'name', 'percentage']],
            ]);
    }

    public function test_generate_payment_receipt(): void
    {
        $appointment = $this->createTestAppointment();

        $paymentResponse = $this->withHeaders($this->authHeaders())
            ->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 50000,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ]);

        $paymentId = $paymentResponse->json('data.id');

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/payments/{$paymentId}/receipt")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'comprobante' => ['numero', 'fecha', 'paciente', 'monto', 'metodo', 'estado'],
                ],
            ]);
    }

    public function test_get_daily_payment_report(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $appointment = $this->createTestAppointment();
            $this->withHeaders($this->authHeaders())->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 50000,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ]);
        }

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/payments/reports/daily?fecha=' . now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'pagos',
                    'resumen' => ['fecha', 'total_pagado', 'total_transacciones', 'por_metodo'],
                ],
            ]);
    }

    public function test_multiple_partial_payments(): void
    {
        $appointment = $this->createTestAppointment();
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Efectivo',
            'status' => 'Pago Parcial',
        ])->assertOk();

        $this->withHeaders($headers)->postJson('/api/payments', [
            'appointment_id' => $appointment->id,
            'amount' => 50000,
            'payment_method' => 'Tarjeta Crédito',
            'status' => 'Pagado',
        ])->assertOk();

        $history = $this->withHeaders($headers)
            ->getJson("/api/payments/history/{$appointment->id}");

        $history->assertOk();
        $this->assertEquals(100000, $history->json('data.resumen.total_pagado'));
    }
}
