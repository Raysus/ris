<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Insurance;

class InsuranceController extends Controller
{
    private function getSecureInsuranceQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Insurance::query();

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($allowedLabs) {
                    $q->whereIn('laboratory_id', $allowedLabs)
                        ->orWhereNull('laboratory_id');
                });
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $insurances = $this->getSecureInsuranceQuery()
            ->orderBy('name', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $insurances]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255'
        ]);

        $user = $request->user();
        $isSysAdmin = ($user->tipoUsuario->name === 'sis_admin');
        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        $insuranceId = $validated['id'] ?? null;

        if ($insuranceId) {

            $insurance = Insurance::findOrFail($insuranceId);

            if (is_null($insurance->laboratory_id) && !$isSysAdmin) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede modificar previsiones globales del sistema.'], 403);
            }

            if (!is_null($insurance->laboratory_id) && $allowedLabs !== ['*'] && !in_array($insurance->laboratory_id, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. Esta previsión pertenece a otra sucursal.'], 403);
            }

            $insurance->name = $validated['name'];
            $insurance->save();

        } else {

            $insurance = new Insurance();
            $insurance->name = $validated['name'];

            if ($isSysAdmin && (!$labId || $labId === 'ALL')) {
                $insurance->laboratory_id = null;
            } else {
                if (!$labId) {
                    return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
                }
                if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                    return response()->json(['success' => false, 'message' => 'No tiene permisos para crear en esta sucursal.'], 403);
                }

                $insurance->laboratory_id = $labId;
            }

            $insurance->save();
        }
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Insurance', 'updated', $insurance->toArray());
        return response()->json(['success' => true, 'data' => $insurance]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $isSysAdmin = ($user->tipoUsuario->name === 'sis_admin');
        $allowedLabs = config('app.allowed_lab_ids');

        $insurance = Insurance::findOrFail($id);

        if (is_null($insurance->laboratory_id) && !$isSysAdmin) {
            return response()->json(['success' => false, 'message' => 'No puede eliminar una previsión global del sistema.'], 403);
        }

        if (!is_null($insurance->laboratory_id) && $allowedLabs !== ['*'] && !in_array($insurance->laboratory_id, $allowedLabs)) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para eliminar esta previsión.'], 403);
        }

        $insurance->delete();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Insurance', 'deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }
}