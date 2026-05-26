<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePatientRequest;
use App\Models\Paciente;
use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    private function getSecurePatientQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Paciente::with([
            'persona',
            'appointments.machine',
            'appointments.studies.exam'
        ]);

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
        $patients = $this->getSecurePatientQuery()
            ->with([
                'persona',
                'appointments.machine',
                'appointments.studies.exam'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $patients]);
    }

    public function store(StorePatientRequest $request)
    {
        // Validación automática via StorePatientRequest

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        return DB::transaction(function () use ($request, $labId) {
            $persona = Persona::upsertByRut($request->rut, [
                    'names' => $request->names,
                    'last_name_1' => $request->last_name_1,
                    'last_name_2' => $request->last_name_2,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'gender' => $request->gender,
                    'birth_date' => $request->birth_date
            ]);

            $patient = Paciente::updateOrCreate(
                ['persona_id' => $persona->id, 'laboratory_id' => $labId],
                ['insurance_id' => $request->insurance_id]
            );

            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Persona', 'updated', $persona->toArray());
            \App\Jobs\SyncEntityToCloud::dispatch('App\\Models\\Paciente', 'updated', $patient->toArray());

            return response()->json(['success' => true, 'data' => $patient->load('persona')]);
        });
    }

    public function show($id)
    {
        $patient = $this->getSecurePatientQuery()->findOrFail($id);
        return response()->json(['success' => true, 'data' => $patient]);
    }

    public function update(Request $request, $id)
    {
        $patient = $this->getSecurePatientQuery()->findOrFail($id);

        $request->validate([
            'names' => 'required|string|max:255',
            'last_name_1' => 'required|string|max:255',
            'email' => 'nullable|email'
        ]);

        DB::transaction(function () use ($request, $patient) {
            $patient->persona->update([
                'names' => $request->names,
                'last_name_1' => $request->last_name_1,
                'last_name_2' => $request->last_name_2,
                'email' => $request->email,
                'phone' => $request->phone,
                'gender' => $request->gender,
                'birth_date' => $request->birth_date
            ]);

            $patient->update(['insurance_id' => $request->insurance_id]);

            \App\Jobs\SyncEntityToCloud::dispatch('App\\Models\\Persona', 'updated', $patient->persona->toArray());
            \App\Jobs\SyncEntityToCloud::dispatch('App\\Models\\Paciente', 'updated', $patient->toArray());
        });

        return response()->json(['success' => true, 'data' => $patient->load('persona')]);
    }

    public function destroy($id)
    {
        $patient = $this->getSecurePatientQuery()->findOrFail($id);
        $patient->delete();

        \App\Jobs\SyncEntityToCloud::dispatch('App\\Models\\Paciente', 'deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }

    public function searchByRut(Request $request)
    {
        try {
            $rut = $request->query('rut');

            $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
            $allowedLabs = config('app.allowed_lab_ids');

            if ($allowedLabs !== ['*'] && !in_array($labId, $allowedLabs)) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado a esta sucursal.'], 403);
            }

            $persona = Persona::where('rut_hash', Persona::hashRut($rut))->first();

            if (!$persona) {
                return response()->json(['success' => false, 'message' => 'Paciente no encontrado'], 404);
            }

            $patient = Paciente::where('persona_id', $persona->id)
                ->where('laboratory_id', $labId)
                ->first();

            $responseData = $persona->toArray();

            if ($patient) {
                $responseData['insurance_id'] = $patient->insurance_id ?? null;
            }

            return response()->json([
                'success' => true,
                'data' => $responseData
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fatal en Laravel',
                'error_real' => $e->getMessage(),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile()
            ], 500);
        }
    }
}