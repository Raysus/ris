<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Models\Appointment;
use App\Models\ElectronicDocument;
use App\Services\DteService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ChecksRisAuthorization;
    public function indexForAppointment(string $appointmentId)
    {
        $appointment = $this->secureAppointment($appointmentId);
        $docs = ElectronicDocument::where('appointment_id', $appointment->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $docs]);
    }

    public function emit(Request $request, string $appointmentId, DteService $dte)
    {
        $request->validate([
            'document_type' => 'required|string|in:boleta,factura',
        ]);

        if (!config('dte.enabled')) {
            return response()->json(['success' => false, 'message' => 'DTE deshabilitado.'], 503);
        }

        $appointment = $this->secureAppointment($appointmentId);
        $doc = $dte->emit($appointment, $request->document_type, $request->user()->id);

        return response()->json([
            'success' => $doc->status === 'emitido',
            'message' => $doc->status === 'emitido' ? 'Documento emitido.' : 'Error al emitir documento.',
            'data' => $doc,
        ], $doc->status === 'emitido' ? 201 : 422);
    }

    public function preview(string $appointmentId, DteService $dte)
    {
        $appointment = $this->secureAppointment($appointmentId);

        return response()->json([
            'success' => true,
            'data' => $dte->buildPayload($appointment, 'boleta'),
        ]);
    }

    public function show(string $id)
    {
        $doc = ElectronicDocument::findOrFail($id);

        return response()->json(['success' => true, 'data' => $doc]);
    }

    public function list(Request $request)
    {
        $this->assertAnyRole($request, ['admin', 'sis_admin', 'contador']);

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        $limit = min((int) $request->query('limit', 50), 200);

        $docs = ElectronicDocument::query()
            ->when($labId, fn ($q) => $q->where('laboratory_id', $labId))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $docs]);
    }

    protected function secureAppointment(string $id): Appointment
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::with(['patient.persona', 'studies.exam', 'laboratory']);

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            $query->whereIn('laboratory_id', $allowedLabs ?: ['00000000-0000-0000-0000-000000000000']);
        }

        return $query->findOrFail($id);
    }

    protected function assertAdmin(Request $request): void
    {
        $this->assertAnyRole($request, ['admin', 'sis_admin']);
    }
}
