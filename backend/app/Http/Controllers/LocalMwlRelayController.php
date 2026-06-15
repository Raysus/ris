<?php

namespace App\Http\Controllers;

use App\Services\CloudEntitySyncService;
use App\Services\DicomImportService;
use App\Support\CloudSyncMode;
use App\Support\OrthancUrl;
use Illuminate\Http\Request;

class LocalMwlRelayController extends Controller
{
    public function receive(Request $request, CloudEntitySyncService $sync, WorklistController $worklist, DicomImportService $dicom)
    {
        if (!CloudSyncMode::isLocal() || !OrthancUrl::usesLocalWorklist()) {
            return response()->json([
                'success' => false,
                'message' => 'Este servidor no tiene MWL local configurado.',
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
                'message' => 'Error aplicando cita para MWL local: ' . $e->getMessage(),
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

        $response = $worklist->sendToDicom(new Request(), $appointmentId, $dicom);
        $data = $response->getData(true);
        $status = $response->getStatusCode();

        return response()->json(array_merge($data, [
            'relayed' => true,
            'local_mwl' => true,
        ]), $status);
    }
}
