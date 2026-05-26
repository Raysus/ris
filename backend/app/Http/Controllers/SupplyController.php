<?php

namespace App\Http\Controllers;

use App\Models\Supply;
use Illuminate\Http\Request;

class SupplyController extends Controller
{
    private function getSecureSupplyQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Supply::query();

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
        $supplies = $this->getSecureSupplyQuery()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $supplies]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|string',
            'category' => 'required|string',
            'name' => 'required|string',
            'stock' => 'required|integer',
            'max_stock' => 'required|integer',
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        if (!empty($validated['id'])) {

            $supply = $this->getSecureSupplyQuery()->findOrFail($validated['id']);

            $supply->update([
                'category' => strtoupper($validated['category']),
                'name' => $validated['name'],
                'stock' => $validated['stock'],
                'max_stock' => $validated['max_stock'],
            ]);

        } else {

            if (!$labId) {
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            }

            if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede crear insumos en esta sucursal.'], 403);
            }

            $supply = Supply::create([
                'laboratory_id' => $labId,
                'category' => strtoupper($validated['category']),
                'name' => $validated['name'],
                'stock' => $validated['stock'],
                'max_stock' => $validated['max_stock'],
                'is_active' => true
            ]);
        }
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Supply', 'updated', $supply->toArray());
        return response()->json(['success' => true, 'supply' => $supply]);
    }

    public function destroy($id)
    {
        $supply = $this->getSecureSupplyQuery()->find($id);

        if ($supply) {
            $supply->delete();
            return response()->json(['success' => true]);
        }
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Supply', 'deleted', ['id' => $id]);
        return response()->json(['success' => false, 'message' => 'Insumo no encontrado o acceso denegado'], 404);
    }
}