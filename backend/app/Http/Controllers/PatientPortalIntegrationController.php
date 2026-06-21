<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Services\PatientPortalReportService;
use Illuminate\Http\Request;

class PatientPortalIntegrationController extends Controller
{
    public function index(Request $request, PatientPortalReportService $reports)
    {
        $validated = $request->validate([
            'rut' => 'required|string|max:20',
        ]);

        $rut = Persona::normalizeRut($validated['rut']);
        $labId = $request->header('X-Lab-Id');

        $items = $reports->appointmentsForRut($rut, $labId)
            ->map(fn ($appointment) => $reports->formatAppointment($appointment))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'rut' => $rut,
                'count' => count($items),
                'laboratory_id' => $labId,
            ],
        ]);
    }

    public function show(Request $request, string $appointmentId, PatientPortalReportService $reports)
    {
        $validated = $request->validate([
            'rut' => 'required|string|max:20',
        ]);

        $rut = Persona::normalizeRut($validated['rut']);
        $appointment = $reports->appointmentForRut($appointmentId, $rut);

        if (!$appointment) {
            return response()->json([
                'success' => false,
                'message' => 'Informe no encontrado o no disponible para este paciente.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $reports->formatAppointment($appointment),
        ]);
    }
}
