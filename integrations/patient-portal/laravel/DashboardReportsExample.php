<?php

/**
 * Ejemplo para el DashboardController del portal.
 * En el método que renderiza /dashboard, agregar:
 *
 *   use App\Services\RisReportClient;
 *
 *   $rut = auth()->user()->username ?? auth()->user()->rut ?? '';
 *   $risReports = app(RisReportClient::class)->listReports($rut);
 *   return view('dashboard', compact('risReports', ...));
 *
 * En resources/views/dashboard.blade.php (o equivalente):
 *   @include('partials.ris-reports-section')
 */
namespace App\Http\Controllers;

use App\Services\RisReportClient;
use Illuminate\Http\Request;

class DashboardReportsExample
{
    public function __invoke(Request $request, RisReportClient $ris)
    {
        $user = $request->user();
        $rut = trim((string) ($user->username ?? $user->rut ?? ''));

        $risReports = $rut !== '' ? $ris->listReports($rut) : [];
        $risReportsError = null;

        if ($rut === '') {
            $risReportsError = 'No se pudo identificar su RUT en la sesión.';
        }

        return view('dashboard', compact('risReports', 'risReportsError'));
    }
}
