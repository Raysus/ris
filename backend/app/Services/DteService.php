<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ElectronicDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DteService
{
    public function buildPayload(Appointment $appointment, string $documentType = 'boleta'): array
    {
        $appointment->loadMissing(['patient.persona', 'studies.exam', 'laboratory']);

        $persona = $appointment->patient?->persona;
        $lineas = [];
        $montoExento = 0;

        foreach ($appointment->studies as $study) {
            $precio = ($study->price_charged ?? $study->price ?? 0) * ($study->quantity ?? 1);
            $lineas[] = [
                'nombre' => $study->exam_name ?? $study->exam?->name ?? 'Prestación',
                'cantidad' => $study->quantity ?? 1,
                'precio' => $precio,
                'codigo_fonasa' => $study->fonasa_code,
            ];
            $montoExento += $precio;
        }

        $montoNeto = 0;
        $montoIva = 0;
        $montoTotal = $montoExento;

        if ($documentType === 'factura') {
            $montoNeto = round($montoExento / 1.19, 0);
            $montoIva = $montoExento - $montoNeto;
            $montoExento = 0;
            $montoTotal = $montoNeto + $montoIva;
        }

        return [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => $documentType === 'factura' ? 33 : 39,
                    'Folio' => 0,
                    'FchEmis' => Carbon::now()->format('Y-m-d'),
                ],
                'Emisor' => [
                    'RUTEmisor' => config('dte.emisor_rut'),
                    'RznSoc' => config('dte.emisor_razon_social'),
                    'GiroEmis' => 'Servicios de salud / diagnóstico por imágenes',
                    'Acteco' => config('dte.acteco'),
                ],
                'Receptor' => [
                    'RUTRecep' => $this->formatRutForDte($persona?->rut ?? '66666666-6'),
                    'RznSocRecep' => trim(($persona?->names ?? 'Paciente') . ' ' . ($persona?->last_name_1 ?? '')),
                ],
            ],
            'Detalle' => $lineas,
            'Totales' => [
                'MntExe' => $montoExento,
                'MntNeto' => $montoNeto,
                'IVA' => $montoIva,
                'MntTotal' => $montoTotal,
            ],
            'Referencia' => [
                'appointment_id' => $appointment->id,
                'accession_number' => $appointment->accession_number,
            ],
        ];
    }

    public function emit(Appointment $appointment, string $documentType, ?string $userId = null): ElectronicDocument
    {
        $payload = $this->buildPayload($appointment, $documentType);
        $totales = $payload['Totales'];
        $persona = $appointment->patient?->persona;

        $doc = ElectronicDocument::create([
            'appointment_id' => $appointment->id,
            'laboratory_id' => $appointment->laboratory_id,
            'document_type' => $documentType,
            'status' => 'borrador',
            'receptor_rut' => $persona?->rut,
            'receptor_name' => trim(($persona?->names ?? '') . ' ' . ($persona?->last_name_1 ?? '')),
            'monto_neto' => $totales['MntNeto'],
            'monto_iva' => $totales['IVA'],
            'monto_exento' => $totales['MntExe'],
            'monto_total' => $totales['MntTotal'],
            'payload' => $payload,
            'created_by' => $userId,
        ]);

        return $this->sendToProvider($doc);
    }

    protected function sendToProvider(ElectronicDocument $doc): ElectronicDocument
    {
        $url = config('dte.provider_url');

        if ($url && config('dte.enabled')) {
            $http = Http::timeout(30)->withToken(config('dte.provider_token'));

            if (app()->environment('local', 'testing')) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post($url, ['document' => $doc->payload, 'type' => $doc->document_type]);

            if ($response->successful()) {
                $data = $response->json();
                $doc->update([
                    'status' => 'emitido',
                    'folio' => $data['folio'] ?? $data['Folio'] ?? null,
                    'provider_reference' => $data['track_id'] ?? $data['id'] ?? null,
                    'provider_response' => $data,
                    'emitted_at' => now(),
                ]);
            } else {
                $doc->update([
                    'status' => 'error',
                    'provider_response' => ['error' => $response->body()],
                ]);
            }

            return $doc->fresh();
        }

        if (!config('dte.simulate_when_no_provider')) {
            throw new \RuntimeException('Emisor DTE no configurado (DTE_PROVIDER_URL).');
        }

        $folio = 'SIM-' . strtoupper(Str::substr($doc->id, 0, 8));
        $jsonPath = 'dte/' . $doc->id . '.json';
        Storage::disk('public')->put($jsonPath, json_encode($doc->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $doc->update([
            'status' => 'emitido',
            'folio' => $folio,
            'provider_reference' => 'LOCAL-SIM',
            'provider_response' => ['mode' => 'simulated', 'message' => 'DTE simulado localmente.'],
            'pdf_path' => '/storage/' . $jsonPath,
            'emitted_at' => now(),
        ]);

        return $doc->fresh();
    }

    protected function formatRutForDte(?string $rut): string
    {
        if (!$rut) {
            return '66666666-6';
        }

        return strtoupper(str_replace(['.', ' '], '', $rut));
    }
}
