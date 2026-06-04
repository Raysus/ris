<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Support\ModalityCode;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    private function getSecureMachineQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Machine::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
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
            'ip_address' => 'nullable|string|max:45',
            'port' => 'nullable|integer'
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        $group = ModalityCode::normalizeGroup($validated['group']);

        $color = '#3788d8';
        switch ($group) {
            case 'RX':
                $color = '#4CAF50';
                break;
            case 'SCANNER':
                $color = '#FF9800';
                break;
            case 'US':
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
                'group' => $group,
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
            if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
            }

            $machine = Machine::create([
                'laboratory_id' => $labId,
                'name' => $validated['name'],
                'group' => $group,
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

        $action = !empty($validated['id']) ? 'updated' : 'created';
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Machine', $action, $machine->fresh()->toArray());
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

    /**
     * Prueba TCP desde el servidor RIS hacia el equipo adquisidor (IP + puerto de la sala).
     * No es un C-ECHO DICOM completo. No valida ORTHANC_URL del .env (PACS en nube es otro destino).
     */
    public function pingDicom($id)
    {
        $machine = $this->getSecureMachineQuery()->findOrFail($id);

        if (empty($machine->ip_address) || empty($machine->port)) {
            return response()->json([
                'success' => false,
                'message' => 'Falta configurar la IP o el Puerto en esta máquina.'
            ], 400);
        }

        $host = trim((string) $machine->ip_address);
        $port = (int) $machine->port;

        if ($port < 1 || $port > 65535) {
            return response()->json([
                'success' => false,
                'message' => 'Puerto inválido. Use el puerto DICOM del equipo (p. ej. 104, 4242), no la URL web del PACS.',
            ], 400);
        }

        $errCode = 0;
        $errStr = '';
        $fp = @fsockopen($host, $port, $errCode, $errStr, 3);

        if ($fp) {
            fclose($fp);

            return response()->json([
                'success' => true,
                'message' => "Puerto TCP abierto en {$host}:{$port} ({$machine->ae_title}). "
                    . 'El equipo acepta conexiones; esto no garantiza C-ECHO DICOM.',
            ]);
        }

        $hint = match (true) {
            in_array($port, [80, 443, 8042], true) => ' El PACS en la nube se configura en ORTHANC_URL del .env, no en la IP de la sala.',
            $errCode === 111 => ' Connection refused: no hay servicio escuchando en ese puerto o el equipo está apagado.',
            default => ' Revise que el servidor RIS alcance esa red (misma VLAN), firewall y el puerto DICOM correcto.',
        };

        return response()->json([
            'success' => false,
            'message' => "Sin conexión TCP a {$host}:{$port} ({$errStr}).{$hint}",
        ], 408);
    }
}