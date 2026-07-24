<?php

namespace App\Http\Controllers;

use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Http\Request;

class LocalEntitySyncController extends Controller
{
    public function receive(Request $request, CloudEntitySyncService $sync)
    {
        if (!CloudSyncMode::isLocal()) {
            return response()->json([
                'success' => false,
                'message' => 'Este endpoint solo aplica en servidores de laboratorio (RIS_CLOUD_ROLE=local).',
            ], 403);
        }

        if ($request->has('chunks')) {
            $validated = $request->validate([
                'chunks' => 'required|array|min:1',
                'chunks.*.model' => 'required|string|max:120',
                'chunks.*.action' => 'required|string|in:created,updated,deleted',
                'chunks.*.data' => 'required|array',
            ]);

            try {
                foreach ($validated['chunks'] as $chunk) {
                    $sync->apply($chunk['model'], $chunk['action'], $chunk['data']);
                }
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => 'Error aplicando lote desde la nube: ' . $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Lote aplicado en el laboratorio local.',
                'relayed' => true,
                'count' => count($validated['chunks']),
            ]);
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
                'message' => 'Error aplicando entidad desde la nube: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Entidad aplicada en el laboratorio local.',
            'relayed' => true,
        ]);
    }
}
