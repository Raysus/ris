<?php

/**
 * Copiar a: app/Services/RisReportClient.php (portal Laravel)
 *
 * .env del portal:
 *   RIS_API_URL=https://api.healthticloud.cl
 *   RIS_PORTAL_SECRET=...   (mismo valor que PORTAL_INTEGRATION_SECRET en el RIS)
 *   RIS_LAB_ID=               (opcional; filtra una sede)
 */
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RisReportClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('services.ris.url'), '/');
    }

    protected function secret(): ?string
    {
        return config('services.ris.portal_secret');
    }

    protected function labId(): ?string
    {
        $labId = config('services.ris.lab_id');
        return filled($labId) ? (string) $labId : null;
    }

    protected function request()
    {
        $req = Http::acceptJson()->timeout(20);

        if ($secret = $this->secret()) {
            $req = $req->withToken($secret);
        }

        if ($labId = $this->labId()) {
            $req = $req->withHeader('X-Lab-Id', $labId);
        }

        return $req;
    }

    /** @return array<int, array<string, mixed>> */
    public function listReports(string $rut): array
    {
        if (!$this->secret() || !$this->baseUrl()) {
            Log::warning('RisReportClient: RIS_API_URL o RIS_PORTAL_SECRET no configurados.');
            return [];
        }

        try {
            $response = $this->request()->get($this->baseUrl() . '/api/integrations/portal/reports', [
                'rut' => $rut,
            ]);

            if (!$response->successful()) {
                Log::warning('RisReportClient listReports', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return [];
            }

            return $response->json('data') ?? [];
        } catch (\Throwable $e) {
            Log::error('RisReportClient listReports: ' . $e->getMessage());
            return [];
        }
    }

    public function getReport(string $appointmentId, string $rut): ?array
    {
        if (!$this->secret() || !$this->baseUrl()) {
            return null;
        }

        try {
            $response = $this->request()->get(
                $this->baseUrl() . '/api/integrations/portal/reports/' . rawurlencode($appointmentId),
                ['rut' => $rut]
            );

            if (!$response->successful()) {
                return null;
            }

            return $response->json('data');
        } catch (\Throwable $e) {
            Log::error('RisReportClient getReport: ' . $e->getMessage());
            return null;
        }
    }
}
