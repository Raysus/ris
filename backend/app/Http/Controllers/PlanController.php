<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InsurancePlan;
use App\Models\Insurance;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    private function getSecurePlanQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = InsurancePlan::with('insurance');

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }
        return $query;
    }

    private function getSecureInsuranceQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Insurance::query();

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereNull('laboratory_id');
            } else {
                $query->where(function ($q) use ($allowedLabs) {
                    $q->whereNull('laboratory_id')
                        ->orWhereIn('laboratory_id', $allowedLabs);
                });
            }
        }
        return $query;
    }

    public function index(Request $request)
    {
        $planes = $this->getSecurePlanQuery()
            ->orderBy('name', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $planes]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|string', // 🔥 CORREGIDO: De integer a string (UUID)
            'insurance_id' => 'required|string', // 🔥 CORREGIDO: De integer a string (UUID)
            'name' => 'required|string|max:255',
            'percentage' => 'required|numeric|min:0|max:100',
        ]);

        $allowedLabs = config('app.allowed_lab_ids');
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        if (!empty($validated['id'])) {
            $plan = $this->getSecurePlanQuery()->findOrFail($validated['id']);
            $plan->update([
                'insurance_id' => $validated['insurance_id'],
                'name' => $validated['name'],
                'percentage' => $validated['percentage'],
            ]);
        } else {
            if (!$labId) {
                return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
            }

            if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. No puede crear planes en esta sucursal.'], 403);
            }

            $plan = \App\Models\InsurancePlan::create([
                'laboratory_id' => $labId,
                'insurance_id' => $validated['insurance_id'],
                'name' => $validated['name'],
                'percentage' => $validated['percentage'],
            ]);
        }

        $plan->load('insurance');
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\InsurancePlan', 'updated', $plan->toArray());

        return response()->json(['success' => true, 'data' => $plan]);
    }

    public function getInsurances()
    {
        $insurances = $this->getSecureInsuranceQuery()
            ->orderBy('name', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $insurances]);
    }

    public function destroy($id)
    {
        $plan = $this->getSecurePlanQuery()->find($id);

        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Plan no encontrado o acceso denegado'], 404);
        }

        $plan->delete();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\InsurancePlan', 'deleted', ['id' => $id]);

        return response()->json(['success' => true]);
    }
}