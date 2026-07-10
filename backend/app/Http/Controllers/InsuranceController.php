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

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
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
            'id' => 'nullable|string',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'type' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
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

            if (!is_null($insurance->laboratory_id) && !\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($insurance->laboratory_id, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado. Esta previsión pertenece a otra sucursal.'], 403);
            }

            $insurance->name = $validated['name'];
            if (!empty($validated['code'])) {
                $insurance->code = strtoupper(trim((string) $validated['code']));
            } elseif (blank($insurance->code)) {
                $insurance->code = $this->generateInsuranceCode(
                    $validated['name'],
                    $insurance->laboratory_id,
                    $insurance->id
                );
            }
            if (array_key_exists('type', $validated)) {
                $insurance->type = $validated['type'];
            }
            if (array_key_exists('is_active', $validated)) {
                $insurance->is_active = (bool) $validated['is_active'];
            }
            $insurance->save();

        } else {

            $insurance = new Insurance();
            $insurance->name = $validated['name'];
            $insurance->type = $validated['type'] ?? null;
            $insurance->is_active = array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true;

            if ($isSysAdmin && (!$labId || $labId === 'ALL')) {
                $insurance->laboratory_id = null;
            } else {
                if (!$labId) {
                    return response()->json(['success' => false, 'message' => 'Debe seleccionar un laboratorio.'], 400);
                }
                if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($labId, $allowedLabs)) {
                    return response()->json(['success' => false, 'message' => 'No tiene permisos para crear en esta sucursal.'], 403);
                }

                $insurance->laboratory_id = $labId;
            }

            $insurance->code = !empty($validated['code'])
                ? strtoupper(trim((string) $validated['code']))
                : $this->generateInsuranceCode($validated['name'], $insurance->laboratory_id);

            $insurance->save();
        }
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Insurance', 'updated', $insurance->toArray());
        return response()->json(['success' => true, 'data' => $insurance]);
    }

    /**
     * Genera un código corto único a partir del nombre (la columna code es NOT NULL).
     */
    private function generateInsuranceCode(string $name, ?string $laboratoryId, ?string $excludeId = null): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?? '');
        $base = substr($base !== '' ? $base : 'PREV', 0, 12);

        $candidate = $base;
        $suffix = 1;
        while (
            Insurance::query()
                ->when(
                    $laboratoryId === null,
                    fn ($q) => $q->whereNull('laboratory_id'),
                    fn ($q) => $q->where('laboratory_id', $laboratoryId)
                )
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->where('code', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = substr($base, 0, 10) . $suffix;
            if ($suffix > 99) {
                $candidate = 'P' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                break;
            }
        }

        return $candidate;
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

        if (!is_null($insurance->laboratory_id) && !\App\Models\Laboratory::allowsAllLabs($allowedLabs) && !in_array($insurance->laboratory_id, $allowedLabs)) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para eliminar esta previsión.'], 403);
        }

        $insurance->delete();
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Insurance', 'deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }
}