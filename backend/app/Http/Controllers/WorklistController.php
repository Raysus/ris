<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Supply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorklistController extends Controller
{
    private function getSecureAppointmentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

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
        $allowedLabs = config('app.allowed_lab_ids');

        $query = DB::table('appointment_studies as s')
            ->join('appointments as a', 's.appointment_id', '=', 'a.id')
            ->join('patients as p', 'a.patient_id', '=', 'p.id')
            ->join('personas as per', 'p.persona_id', '=', 'per.id')
            ->whereIn('a.status', ['confirmado', 'en_atencion', 'dicom_enviado', 'devuelto_worklist'])
            ->whereNull('a.deleted_at')
            ->select(
                's.id as study_id',
                's.exam_name',
                's.sub_exam_name',
                's.quantity',
                's.machine_id as study_machine',
                'a.id as appointment_id',
                'a.start_time',
                'a.status as appointment_status',
                'a.priority',
                'a.accession_number',
                'a.return_reason',
                'a.medical_order_path',
                'a.survey_path', // <-- NUEVOS CAMPOS
                'per.names',
                'per.last_name_1',
                'per.last_name_2',
                'per.rut'
            );

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs))
                $query->whereRaw('1 = 0');
            else
                $query->whereIn('a.laboratory_id', $allowedLabs);
        }

        $studies = $query->get();
        $formattedData = [];
        foreach ($studies as $row) {
            $formattedData[] = [
                'id' => $row->study_id,
                'exam_name' => $row->exam_name,
                'sub_exam_id' => $row->sub_exam_name,
                'quantity' => $row->quantity,
                'machine_id' => $row->study_machine,
                'appointment' => [
                    'id' => $row->appointment_id,
                    'start_time' => $row->start_time,
                    'status' => $row->appointment_status,
                    'priority' => $row->priority,
                    'accession_number' => $row->accession_number,
                    'return_reason' => $row->return_reason,
                    'medical_order_path' => $row->medical_order_path, // <-- PDF Orden
                    'survey_path' => $row->survey_path,               // <-- PDF Encuesta
                    'patient' => [
                        'persona' => [
                            'names' => $row->names,
                            'last_name_1' => $row->last_name_1,
                            'rut' => $row->rut
                        ]
                    ]
                ]
            ];
        }
        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function sendToDicom(Request $request, $appointmentId)
    {
        $userId = $request->user()->id;
        // Traemos la cita con el paciente y la máquina (para obtener su AE Title real)
        $appointment = $this->getSecureAppointmentQuery()
            ->with(['patient.persona', 'machine'])
            ->findOrFail($appointmentId);

        $accessionNumber = 'ACC-' . date('Ymd') . '-' . substr($appointment->id, 0, 5);

        try {
            $orthancUrl = env('ORTHANC_URL', 'http://127.0.0.1:8042') . '/worklists';

            // Usamos el AE Title configurado en la máquina, o un fallback si no existe
            $stationAeTitle = $appointment->machine->ae_title ?? "SALA_" . $appointment->machine_id;

            $dicomWorklistData = [
                "0010,0010" => $appointment->patient->persona->names . "^" . $appointment->patient->persona->last_name_1,
                "0010,0020" => $appointment->patient->persona->rut,
                "0008,0050" => $accessionNumber,
                "0040,0100" => [
                    [
                        "0040,0001" => $stationAeTitle, // 🔥 AHORA USA EL AE TITLE REAL DEL EQUIPO
                        "0040,0002" => \Carbon\Carbon::parse($appointment->start_time)->format('Ymd'),
                        "0040,0003" => \Carbon\Carbon::parse($appointment->start_time)->format('His'),
                        "0040,0009" => (string) $appointment->id
                    ]
                ]
            ];

            $response = Http::post($orthancUrl, $dicomWorklistData);

            if (!$response->successful()) {
                throw new \Exception("Orthanc Worklist falló: " . $response->status());
            }

            $appointment->status = 'dicom_enviado';
            $appointment->accession_number = $accessionNumber;
            $appointment->save();

            // Sincronizar a la nube
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true, 'accession' => $accessionNumber]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function complete(Request $request, $appointmentId)
    {
        $request->validate([
            'anamnesis' => 'required|string',
            'supplies' => 'array',
            'supplies.*.id' => 'required|string', // 🔥 CORRECCIÓN CRÍTICA: Ahora acepta UUIDs (string)
            'supplies.*.quantity' => 'required|integer|min:1',
            'status' => 'required|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();

        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($appointmentId);

            $appointment->status = $request->status;
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update([
                    'status' => 'pendiente_radiologo',
                    'anamnesis' => $request->anamnesis,
                    'updated_at' => now()
                ]);

            if (!empty($request->supplies)) {
                foreach ($request->supplies as $item) {
                    $supply = Supply::lockForUpdate()->findOrFail($item['id']);

                    if ($supply->stock < $item['quantity']) {
                        throw new \Exception("Stock insuficiente para: " . $supply->name);
                    }

                    $supply->stock -= $item['quantity'];
                    $supply->save();

                    DB::table('appointment_supplies')->insert([
                        'appointment_id' => $appointment->id,
                        'supply_id' => $supply->id,
                        'quantity' => $item['quantity'],
                        'price_charged' => 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'WORKLIST_COMPLETED',
                'details' => json_encode([
                    'mensaje' => 'Atención técnica finalizada, derivada al radiólogo. Anamnesis registrada en los estudios.'
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $appointment->touch();
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
            DB::commit();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function updateStatus(Request $request, $appointmentId)
    {
        // ... (Tu código actual está bien, mantén lo que tenías) ...
        try {
            $userId = $request->user()->id;

            $appointment = $this->getSecureAppointmentQuery()->findOrFail($appointmentId);

            $oldStatus = $appointment->status;

            $appointment->status = $request->status;

            if ($request->has('needs_review')) {
                $appointment->needs_review = $request->needs_review;
                $appointment->return_reason = $request->return_reason;
            }
            $appointment->save();

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'RETURNED_TO_RECEPTION',
                'details' => json_encode([
                    'from' => $oldStatus,
                    'to' => $request->status,
                    'motivo' => $request->return_reason ?? 'Sin motivo especificado'
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno en Laravel',
                'error' => $e->getMessage(),
                'linea' => $e->getLine()
            ], 500);
        }
    }
}