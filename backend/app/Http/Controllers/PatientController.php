<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksPatientMutationAccess;
use App\Http\Requests\StorePatientRequest;
use App\Models\Appointment;
use App\Models\Paciente;
use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    use ChecksPatientMutationAccess;
    private function getSecurePatientQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Paciente::with([
            'persona',
            'appointments.machine',
            'appointments.studies.exam'
        ]);

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
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $patients = $this->getSecurePatientQuery()
            ->with([
                'persona',
                'appointments.machine',
                'appointments.studies.exam'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

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
        if (!$this->userCanMutatePatients($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Su perfil no puede editar pacientes en esta sede.',
            ], 403);
        }

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
        if (!$this->userCanMutatePatients(request())) {
            return response()->json([
                'success' => false,
                'message' => 'Su perfil no puede eliminar pacientes.',
            ], 403);
        }

        $patient = $this->getSecurePatientQuery()->findOrFail($id);
        $patient->delete();

        \App\Jobs\SyncEntityToCloud::dispatch('App\\Models\\Paciente', 'deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }

    public function searchByRut(Request $request)
    {
        try {
            $rut = trim((string) $request->query('rut', ''));
            if ($rut === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Indique un RUT o documento.',
                ], 422);
            }

            $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
            $allowedLabs = config('app.allowed_lab_ids');

            if (!$labId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seleccione un laboratorio en la barra superior.',
                ], 400);
            }

            if (
                !\App\Models\Laboratory::allowsAllLabs($allowedLabs)
                && !in_array($labId, $allowedLabs, true)
            ) {
                return response()->json(['success' => false, 'message' => 'Acceso denegado a esta sucursal.'], 403);
            }

            // Registro global: identidad siempre desde tabla personas (no depende de patients por sede).
            $persona = Persona::findByRut($rut);

            if (!$persona) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay persona registrada con ese documento.',
                ], 404);
            }

            $patientInLab = Paciente::where('persona_id', $persona->id)
                ->where('laboratory_id', $labId)
                ->first();

            $patientIds = Paciente::where('persona_id', $persona->id)->pluck('id');

            $historyInLab = 0;
            $lastWithInsurance = null;

            if ($patientIds->isNotEmpty()) {
                $historyInLab = Appointment::query()
                    ->where('laboratory_id', $labId)
                    ->whereIn('patient_id', $patientIds)
                    ->count();

                $lastWithInsurance = Appointment::query()
                    ->whereIn('patient_id', $patientIds)
                    ->where('laboratory_id', $labId)
                    ->whereNotNull('insurance_id')
                    ->orderByDesc('start_time')
                    ->first();

                if (!$lastWithInsurance) {
                    $lastWithInsurance = Appointment::query()
                        ->whereIn('patient_id', $patientIds)
                        ->whereNotNull('insurance_id')
                        ->orderByDesc('start_time')
                        ->first();
                }
            }

            $personaPayload = [
                'id' => $persona->id,
                'rut' => $persona->rut,
                'names' => $persona->names,
                'last_name_1' => $persona->last_name_1,
                'last_name_2' => $persona->last_name_2,
                'gender' => $persona->gender,
                'birth_date' => $persona->birth_date?->format('Y-m-d'),
                'email' => $persona->email,
                'phone' => $persona->phone,
                'address' => $persona->address,
                'city' => $persona->city,
            ];

            $responseData = [
                'persona' => $personaPayload,
                'persona_id' => $persona->id,
                'patient_id' => $patientInLab?->id,
                'has_ficha_in_lab' => $patientInLab !== null,
                'history_count' => $historyInLab,
                'had_prior_appointment_in_lab' => $historyInLab > 0,
            ];

            if ($lastWithInsurance) {
                $responseData['insurance_id'] = $lastWithInsurance->insurance_id;
                $responseData['insurance_plan_id'] = $lastWithInsurance->insurance_plan_id;
            }

            // Compatibilidad con código que lee campos en la raíz de data.
            $responseData = array_merge($personaPayload, $responseData);

            return response()->json([
                'success' => true,
                'data' => $responseData,
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