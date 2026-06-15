<?php

namespace App\Http\Controllers;

use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;

class LocalAppointmentSyncController extends Controller
{
    public function receive(Request $request, CloudEntitySyncService $sync)
    {
        if (!CloudSyncMode::isLocal()) {
            return response()->json([
                'success' => false,
                'message' => 'Este endpoint solo aplica en servidores de laboratorio (RIS_CLOUD_ROLE=local).',
            ], 403);
        }

        $validated = $request->validate([
            'action' => 'sometimes|string|in:created,updated,deleted',
            'persona' => 'required_unless:action,deleted|array',
            'paciente' => 'required_unless:action,deleted|array',
            'appointment' => 'required|array',
        ]);

        $action = $validated['action'] ?? 'updated';

        try {
            if ($action === 'deleted') {
                $sync->apply('App\Models\Appointment', 'deleted', $validated['appointment']);
            } else {
                $sync->apply('App\Models\Persona', 'updated', $validated['persona']);
                $sync->apply('App\Models\Paciente', 'updated', $validated['paciente']);
                $sync->apply('App\Models\Appointment', $action, $validated['appointment']);
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Error aplicando cita desde la nube: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cita aplicada en el laboratorio local.',
            'relayed' => true,
        ]);
    }
}
