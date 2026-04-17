<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
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
        $appointments = $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies',
                'destinationDoctor.persona'
            ])
            ->whereIn('status', ['entregable', 'entregado'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            $p = $app->patient->persona;

            $destDoctorName = "Médico Radiólogo";
            $firmaUrl = null;
            if ($app->destinationDoctor && $app->destinationDoctor->persona) {
                $doc = $app->destinationDoctor->persona;
                $destDoctorName = trim("{$doc->names} {$doc->last_name_1}");

                if ($doc->signature_path) {
                    $firmaUrl = asset('storage/' . $doc->signature_path);
                }
            }

            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'status' => $app->status,
                'signatureDate' => $app->updated_at->format('d/m/Y H:i'),
                'doctorName' => $destDoctorName,
                'firmaUrl' => $firmaUrl,
                'patient' => [
                    'rut' => $p->rut,
                    'name' => $p->names,
                    'lastName' => $p->last_name_1,
                ],
                'studies' => $app->studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'reportText' => $s->report ?? '',
                    ];
                })
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function deliver(Request $request, $id)
    {
        $request->validate([
            'receiver_rut' => 'required|string',
            'receiver_name' => 'required|string',
            'relationship' => 'required|string',
            'delivery_method' => 'required|string',
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'entregado';
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update(['status' => 'entregado', 'updated_at' => now()]);

            DB::table('appointment_deliveries')->insert([
                'appointment_id' => $appointment->id,
                'delivered_by' => $userId,
                'receiver_rut' => $request->receiver_rut,
                'receiver_name' => $request->receiver_name,
                'relationship' => $request->relationship,
                'delivery_method' => $request->delivery_method,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'DELIVERED_TO_PATIENT',
                'details' => json_encode([
                    'receptor' => $request->receiver_name,
                    'parentesco' => $request->relationship
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now()
            ]);

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function revert(Request $request, $id)
    {
        return $this->updateDeliveryStatus($request, $id, 'entregable', 'DELIVERY_REVERTED');
    }

    private function updateDeliveryStatus(Request $request, $id, $newStatus, $logAction)
    {
        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = $newStatus;
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update(['status' => $newStatus, 'updated_at' => now()]);

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => $logAction,
                'details' => json_encode(['mensaje' => "Estado cambiado a: $newStatus"]),
                'ip_address' => $request->ip(),
                'created_at' => now()
            ]);

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error en servidor', 'error' => $e->getMessage()], 500);
        }
    }
}