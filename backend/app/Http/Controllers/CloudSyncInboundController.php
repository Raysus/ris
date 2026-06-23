<?php

namespace App\Http\Controllers;

use App\Services\CloudCatalogExportService;
use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;

class CloudSyncInboundController extends Controller
{
    public function receive(Request $request, CloudEntitySyncService $sync)
    {
        if (!CloudSyncMode::acceptsInbound()) {
            return response()->json([
                'success' => false,
                'message' => 'Este servidor no está configurado como receptor cloud (RIS_CLOUD_ROLE / CLOUD_INBOUND_ENABLED).',
            ], 403);
        }

        $validated = $request->validate([
            'model' => 'required|string|max:120',
            'action' => 'required|string|in:created,updated,deleted',
            'data' => 'required|array',
        ]);

        try {
            $sync->apply($validated['model'], $validated['action'], $validated['data']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Error aplicando sync: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Registro aplicado.',
        ]);
    }

    public function export(Request $request, CloudCatalogExportService $exporter)
    {
        if (!CloudSyncMode::acceptsInbound()) {
            return response()->json([
                'success' => false,
                'message' => 'Exportación solo disponible en el servidor central.',
            ], 403);
        }

        $laboratoryId = $request->query('laboratory_id');
        $includePatients = filter_var($request->query('include_patients', false), FILTER_VALIDATE_BOOL);
        $includeUsers = filter_var($request->query('include_users', false), FILTER_VALIDATE_BOOL);

        return response()->json([
            'success' => true,
            'data' => $exporter->export($laboratoryId, $includePatients, $includeUsers),
        ]);
    }
}
