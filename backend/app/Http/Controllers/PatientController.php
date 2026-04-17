<?php

namespace App\Http\Controllers;

use App\Models\Paciente;
use App\Models\Persona;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    private function getSecurePatientQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Paciente::with('persona');

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
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $patients
        ]);
    }

    public function store(Request $request)
    {
    }
    public function show($id)
    {
    }
    public function update(Request $request, $id)
    {
    }
    public function destroy($id)
    {
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

            $persona = Persona::where('rut', $rut)->first();

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