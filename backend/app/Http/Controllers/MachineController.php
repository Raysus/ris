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
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'group' => 'required|string',
            'manufacturer' => 'nullable|string',
            'model_name' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        $color = '#3788d8';
        switch (strtoupper($validated['group'])) {
            case 'RX':
                $color = '#2ec4b6';
                break;
            case 'CT':
                $color = '#3b82f6';
                break;
            case 'MRI':
                $color = '#8b5cf6';
                break;
            case 'ECO':
                $color = '#f59e0b';
                break;
            case 'MAMO':
                $color = '#ec4899';
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

            ]);

        } else {

            if (!$labId) {
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            }

            if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede crear salas en esta sucursal.'], 403);
            }

            $machine = Machine::create([
                'laboratory_id' => $labId,
                'name' => $validated['name'],
                'group' => strtoupper($validated['group']),
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model_name' => $validated['model_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'event_color' => $color,
                'is_active' => true
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
}