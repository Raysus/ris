<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Supply;
use Illuminate\Support\Facades\DB;

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
            ->whereIn('a.status', ['confirmado', 'dicom_enviado', 'devuelto_worklist'])
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
                'per.names',
                'per.last_name_1',
                'per.last_name_2',
                'per.rut'
            );

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('a.laboratory_id', $allowedLabs);
            }
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

        $appointment = $this->getSecureAppointmentQuery()->findOrFail($appointmentId);

        $accessionNumber = 'ACC-' . date('Ymd') . '-' . $appointment->id;

        $appointment->accession_number = $accessionNumber;
        $appointment->status = 'dicom_enviado';
        $appointment->save();

        DB::table('appointment_logs')->insert([
            'appointment_id' => $appointment->id,
            'user_id' => $userId,
            'action' => 'DICOM_SENT',
            'details' => json_encode(['accession_number' => $accessionNumber]),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $appointment->load(['patient.persona', 'studies', 'supplies']);
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
        return response()->json([
            'success' => true,
            'accession_number' => $accessionNumber
        ]);
    }

    public function complete(Request $request, $appointmentId)
    {
        $request->validate([
            'anamnesis' => 'required|string',
            'supplies' => 'array',
            'supplies.*.id' => 'required|integer',
            'supplies.*.quantity' => 'required|integer|min:1',
            'status' => 'required|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();

        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($appointmentId);

            $appointment->status = $request->status; // 'en_informe'
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