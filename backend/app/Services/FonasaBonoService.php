<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\FonasaBono;
use App\Support\RisHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FonasaBonoService
{
    public function calculateAmounts(Appointment $appointment): array
    {
        LaboratoryProfileService::assertUsesFonasa();

        $appointment->loadMissing(['studies.exam', 'insurancePlan.insurance']);

        $totalArancel = $appointment->studies->sum(
            fn ($s) => ($s->price_charged ?? $s->price ?? 0) * ($s->quantity ?? 1)
        );

        $plan = $appointment->insurancePlan;
        $copagoPct = $plan?->percentage ?? 0;
        $isFonasa = $this->isFonasaInsurance($appointment);

        if ($isFonasa && config('fonasa.default_bonificacion_pct')) {
            $bonificacionPct = config('fonasa.default_bonificacion_pct');
            $montoBonificacion = round($totalArancel * $bonificacionPct / 100, 0);
            $montoCopago = max(0, $totalArancel - $montoBonificacion);
        } else {
            $montoCopago = round($totalArancel * $copagoPct / 100, 0);
            $montoBonificacion = max(0, $totalArancel - $montoCopago);
        }

        $prestacion = $appointment->studies->first();

        return [
            'total_arancel' => $totalArancel,
            'prestacion_codigo' => $prestacion?->fonasa_code ?? $prestacion?->exam?->fonasa_code,
            'monto_bonificacion' => $montoBonificacion,
            'monto_copago' => $montoCopago,
            'monto_total' => $totalArancel,
            'es_fonasa' => $isFonasa,
        ];
    }

    public function registerBono(Appointment $appointment, array $data, ?string $userId = null): FonasaBono
    {
        $amounts = $this->calculateAmounts($appointment);

        $bono = FonasaBono::updateOrCreate(
            [
                'appointment_id' => $appointment->id,
                'folio' => $data['folio'],
            ],
            [
                'laboratory_id' => $appointment->laboratory_id,
                'tipo' => $data['tipo'] ?? 'Electronico',
                'estado' => 'registrado',
                'rut_beneficiario' => $data['rut_beneficiario'] ?? null,
                'prestacion_codigo' => $data['prestacion_codigo'] ?? $amounts['prestacion_codigo'],
                'monto_bonificacion' => $data['monto_bonificacion'] ?? $amounts['monto_bonificacion'],
                'monto_copago' => $data['monto_copago'] ?? $amounts['monto_copago'],
                'monto_total' => $data['monto_total'] ?? $amounts['monto_total'],
                'registered_by' => $userId,
            ]
        );

        $appointment->update([
            'tipo_bono' => $bono->tipo,
            'entidad_pagadora' => 'FONASA',
            'transaction_code' => $bono->folio,
        ]);

        return $bono;
    }

    public function validateBono(FonasaBono $bono): FonasaBono
    {
        $apiUrl = config('fonasa.api_url');

        if ($apiUrl && config('fonasa.enabled')) {
            $response = $this->callExternalApi($bono);
            $valid = $response['valid'] ?? false;
            $bono->update([
                'estado' => $valid ? 'validado' : 'rechazado',
                'validation_response' => $response,
                'validated_at' => now(),
            ]);
        } elseif (config('fonasa.simulate_when_no_api')) {
            $valid = $this->simulateValidation($bono);
            $bono->update([
                'estado' => $valid ? 'validado' : 'rechazado',
                'validation_response' => [
                    'mode' => 'simulated',
                    'valid' => $valid,
                    'message' => $valid ? 'Bono validado (simulación local).' : 'Folio o RUT inválido.',
                ],
                'validated_at' => now(),
            ]);
        } else {
            throw new \RuntimeException('Validación FONASA no configurada (FONASA_API_URL).');
        }

        if ($bono->estado === 'validado') {
            $bono->appointment?->update([
                'payment_status' => $bono->monto_copago > 0 ? 'Parcial' : 'Pagado',
                'tipo_bono' => $bono->tipo,
                'transaction_code' => $bono->folio,
            ]);
        }

        return $bono->fresh();
    }

    protected function callExternalApi(FonasaBono $bono): array
    {
        $http = RisHttp::client(20)->withToken(config('fonasa.api_token'));

        $response = $http->post(config('fonasa.api_url'), [
            'folio' => $bono->folio,
            'rut' => $bono->rut_beneficiario,
            'prestacion' => $bono->prestacion_codigo,
            'monto_total' => $bono->monto_total,
        ]);

        if ($response->failed()) {
            return ['valid' => false, 'error' => $response->body()];
        }

        return $response->json();
    }

    protected function simulateValidation(FonasaBono $bono): bool
    {
        if (strlen($bono->folio) < 6) {
            return false;
        }

        if ($bono->rut_beneficiario && !preg_match('/^\d{7,8}-[\dkK]$/', $bono->rut_beneficiario)) {
            return false;
        }

        return !Str::startsWith(strtoupper($bono->folio), 'INVALID');
    }

    protected function isFonasaInsurance(Appointment $appointment): bool
    {
        $name = $appointment->insurance?->name
            ?? $appointment->insurancePlan?->insurance?->name
            ?? '';

        return str_contains(strtoupper($name), 'FONASA');
    }
}
