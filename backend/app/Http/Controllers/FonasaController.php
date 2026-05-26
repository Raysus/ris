<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\FonasaBono;
use App\Services\FonasaBonoService;
use App\Services\LaboratoryProfileService;
use Illuminate\Http\Request;

class FonasaController extends Controller
{
    private function ensureFonasaEnabled(): void
    {
        LaboratoryProfileService::assertUsesFonasa();
    }

    public function show(string $appointmentId)
    {
        $this->ensureFonasaEnabled();
        $appointment = $this->secureAppointment($appointmentId);
        $bonos = FonasaBono::where('appointment_id', $appointment->id)->orderByDesc('created_at')->get();
        $amounts = app(FonasaBonoService::class)->calculateAmounts($appointment);

        return response()->json([
            'success' => true,
            'data' => [
                'amounts' => $amounts,
                'bonos' => $bonos,
            ],
        ]);
    }

    public function store(Request $request, string $appointmentId, FonasaBonoService $fonasa)
    {
        $this->ensureFonasaEnabled();
        $request->validate([
            'folio' => 'required|string|max:50',
            'tipo' => 'nullable|string|in:Manual,Electronico',
            'rut_beneficiario' => 'nullable|string|max:20',
        ]);

        $appointment = $this->secureAppointment($appointmentId);
        $bono = $fonasa->registerBono($appointment, $request->only([
            'folio', 'tipo', 'rut_beneficiario', 'prestacion_codigo',
            'monto_bonificacion', 'monto_copago', 'monto_total',
        ]), $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Bono registrado.',
            'data' => $bono,
        ], 201);
    }

    public function validateBono(Request $request, string $appointmentId, string $bonoId, FonasaBonoService $fonasa)
    {
        $this->ensureFonasaEnabled();
        $appointment = $this->secureAppointment($appointmentId);
        $bono = FonasaBono::where('appointment_id', $appointment->id)->findOrFail($bonoId);

        $bono = $fonasa->validateBono($bono);

        return response()->json([
            'success' => $bono->estado === 'validado',
            'message' => $bono->validation_response['message'] ?? ($bono->estado === 'validado' ? 'Bono validado.' : 'Bono rechazado.'),
            'data' => $bono,
        ]);
    }

    public function preview(string $appointmentId, FonasaBonoService $fonasa)
    {
        $this->ensureFonasaEnabled();
        $appointment = $this->secureAppointment($appointmentId);

        return response()->json([
            'success' => true,
            'data' => $fonasa->calculateAmounts($appointment),
        ]);
    }

    protected function secureAppointment(string $id): Appointment
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            $query->whereIn('laboratory_id', $allowedLabs ?: ['00000000-0000-0000-0000-000000000000']);
        }

        return $query->with(['studies.exam', 'insurancePlan.insurance', 'insurance'])->findOrFail($id);
    }
}
