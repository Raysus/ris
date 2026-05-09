<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    private function getSecureMachineQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Machine::query();

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $machines = $this->getSecureMachineQuery()
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $machines]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|string', // 🔥 CORRECCIÓN: Era integer, ahora es string (UUID)
            'name' => 'required|string|max:255',
            'group' => 'required|string',
            'manufacturer' => 'nullable|string',
            'model_name' => 'nullable|string',
            'description' => 'nullable|string',
            // === NUEVOS CAMPOS DICOM ===
            'ae_title' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer'
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        $color = '#3788d8';
        switch (strtoupper($validated['group'])) {
            case 'RX':
                $color = '#4CAF50';
                break;
            case 'SCANNER':
                $color = '#FF9800';
                break;
            case 'ECO':
                $color = '#9C27B0';
                break;
            case 'RM':
                $color = '#E91E63';
                break;
            case 'MAMO':
                $color = '#00BCD4';
                break;
            case 'DENSITO':
                $color = '#795548';
                break;
        }

        if (!empty($validated['id'])) {
            $machine = $this->getSecureMachineQuery()->findOrFail($validated['id']);
            $machine->update([
                'name' => $validated['name'],
                'group' => strtoupper($validated['group']),
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model_name' => $validated['model_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'event_color' => $color,
                'ae_title' => $validated['ae_title'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'port' => $validated['port'] ?? null,
            ]);
        } else {
            if (!$labId)
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
            }

            $machine = Machine::create([
                'laboratory_id' => $labId,
                'name' => $validated['name'],
                'group' => strtoupper($validated['group']),
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model_name' => $validated['model_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'event_color' => $color,
                'is_active' => true,
                'ae_title' => $validated['ae_title'] ?? null,
                'ip_address' => $validated['ip_address'] ?? null,
                'port' => $validated['port'] ?? null,
            ]);
        }

        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Machine', 'updated', $machine->toArray());
        return response()->json(['success' => true, 'machine' => $machine]);
    }

    public function destroy($id)
    {
        $machine = $this->getSecureMachineQuery()->find($id);

        if ($machine) {
            $machine->delete();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Sala no encontrada o acceso denegado'], 404);
    }

    // === NUEVO: PRUEBA DE CONEXIÓN DICOM (PING) ===
    public function pingDicom($id)
    {
        $machine = $this->getSecureMachineQuery()->findOrFail($id);

        if (empty($machine->ip_address) || empty($machine->port)) {
            return response()->json([
                'success' => false,
                'message' => 'Falta configurar la IP o el Puerto en esta máquina.'
            ], 400);
        }

        $waitTimeoutInSeconds = 3;

        // Intentamos abrir un socket TCP a la IP y Puerto de la máquina
        $fp = @fsockopen($machine->ip_address, $machine->port, $errCode, $errStr, $waitTimeoutInSeconds);

        if ($fp) {
            fclose($fp);
            return response()->json([
                'success' => true,
                'message' => "¡C-ECHO Exitoso! El equipo [{$machine->ae_title}] responde en la red."
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => "Fallo de conexión. El equipo está apagado o el puerto cerrado ($errStr)."
            ], 408); // 408 Request Timeout
        }
    }
}