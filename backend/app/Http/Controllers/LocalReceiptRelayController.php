<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\AppointmentReceiptService;
use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;

class LocalReceiptRelayController extends Controller
{
    public function receive(Request $request, CloudEntitySyncService $sync, AppointmentReceiptService $receipts)
    {
        if (!CloudSyncMode::isLocal()) {
            return response()->json([
                'success' => false,
                'message' => 'Este servidor no es un laboratorio local.',
            ], 403);
        }

        $validated = $request->validate([
            'persona' => 'required|array',
            'paciente' => 'required|array',
            'appointment' => 'required|array',
        ]);

        try {
            $sync->apply('App\Models\Persona', 'updated', $validated['persona']);
            $sync->apply('App\Models\Paciente', 'updated', $validated['paciente']);
            $sync->apply('App\Models\Appointment', 'updated', $validated['appointment']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Error aplicando cita para impresión local: ' . $e->getMessage(),
            ], 422);
        }

        $appointmentId = $validated['appointment']['id'] ?? null;
        if (!$appointmentId) {
            return response()->json([
                'success' => false,
                'message' => 'Falta id de la cita.',
            ], 422);
        }

        config(['app.allowed_lab_ids' => ['*']]);

        $appointment = Appointment::query()
            ->with([
                'patient.persona',
                'studies',
                'insurance',
                'insurancePlan',
                'referringDoctor',
                'destinationDoctor.persona',
                'laboratory',
            ])
            ->findOrFail($appointmentId);

        $receipt = $receipts->printIfNeeded($appointment);

        return response()->json([
            'success' => (bool) $receipt['printed'],
            'receipt' => $receipt,
            'relayed' => true,
            'local_receipt' => true,
        ], $receipt['printed'] ? 200 : 422);
    }
}
