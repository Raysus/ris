<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Services\AppointmentNotificationService;
use App\Services\ReportDocumentFormatter;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliveryController extends Controller
{
    use ChecksRisAuthorization;

    private const DELIVERY_ROLES = ['admin', 'sis_admin', 'recepcion', 'secretaria', 'secretario'];

    private function assertDeliveryAccess(Request $request): void
    {
        $this->assertAnyRole($request, self::DELIVERY_ROLES);
    }

    private function getSecureAppointmentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

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
        $this->assertDeliveryAccess($request);
        $appointments = $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies',
                'destinationDoctor.persona',
                'laboratory',
                'referringDoctor',
            ])
            ->whereIn('status', ['entregable', 'entregado'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            $p = $app->patient->persona;
            $chainMeta = ReportDocumentFormatter::appointmentChainMeta($app);
            $doctor = ReportDocumentFormatter::doctorPayload($app->destinationDoctor);

            return array_merge([
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'status' => $app->status,
                'signatureDate' => $app->updated_at->format('d/m/Y H:i'),
                'doctorName' => preg_replace('/^DR\.?\s*/i', '', $doctor['displayName']),
                'firmaUrl' => $doctor['signatureUrl'],
                'patient' => [
                    'rut' => $p->rut,
                    'name' => $p->names,
                    'lastName' => $p->last_name_1,
                    'secondLastName' => $p->last_name_2,
                ],
                'studies' => $app->studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'subExam' => $s->sub_exam_name,
                        'reportText' => $s->getStoredReportText(),
                        'reportDocumentPath' => $s->report_document_path,
                        'reportDocumentUrl' => PublicStorageUrl::from($s->report_document_path),
                    ];
                }),
            ], $chainMeta);
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function deliver(Request $request, $id)
    {
        $this->assertDeliveryAccess($request);
        $request->validate([
            'receiver_rut' => 'required|string',
            'receiver_name' => 'required|string',
            'relationship' => 'required|string',
            'delivery_method' => 'required|string',
        ]);

        $userId = $request->user()->id;

        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            if ($appointment->status === 'entregado') {
                return response()->json([
                    'success' => true,
                    'already_delivered' => true,
                    'message' => 'La entrega ya estaba registrada.',
                ]);
            }

            if ($appointment->status !== 'entregable') {
                return response()->json([
                    'success' => false,
                    'message' => 'La cita no está lista para entrega (estado: ' . $appointment->status . ').',
                ], 422);
            }
        } catch (\Throwable $e) {
            Log::warning('deliver: cita no encontrada', ['appointment_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        DB::beginTransaction();
        try {
            $appointment->status = 'entregado';
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update(['status' => 'entregado', 'updated_at' => now()]);

            \App\Models\AppointmentDelivery::create([
                'appointment_id' => $appointment->id,
                'delivered_by' => $userId,
                'receiver_rut' => $request->receiver_rut,
                'receiver_name' => $request->receiver_name,
                'relationship' => $request->relationship,
                'delivery_method' => $request->delivery_method,
            ]);

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'DELIVERED_TO_PATIENT',
                'details' => json_encode([
                    'receptor' => $request->receiver_name,
                    'parentesco' => $request->relationship,
                    'metodo' => $request->delivery_method,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::warning('deliver falló', [
                'appointment_id' => $id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        try {
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
        } catch (\Throwable $e) {
            Log::warning('deliver: sync nube post-entrega omitido', [
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function revert(Request $request, $id)
    {
        $this->assertDeliveryAccess($request);

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
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => $logAction,
                'details' => json_encode(['mensaje' => "Estado cambiado a: $newStatus"]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::warning('updateDeliveryStatus falló', [
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        try {
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
        } catch (\Throwable $e) {
            Log::warning('updateDeliveryStatus: sync nube omitido', [
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function sendEmail(Request $request, $id, AppointmentNotificationService $notifications)
    {
        $this->assertDeliveryAccess($request);
        $userId = $request->user()->id;

        try {
            $appointment = $this->getSecureAppointmentQuery()->with('patient.persona')->findOrFail($id);

            $result = $notifications->sendReportReady($appointment);
            if (!($result['sent'] ?? false)) {
                $reason = $result['reason'] ?? 'error';
                $message = match ($reason) {
                    'sin_correo' => 'El paciente no tiene un correo electrónico registrado.',
                    default => $result['message'] ?? 'No se pudo enviar el correo.',
                };

                return response()->json(['success' => false, 'message' => $message], 400);
            }

            $email = $result['email'];

            // Si no estaba entregado, lo marcamos como entregado automáticamente
            if ($appointment->status === 'entregable') {
                $appointment->status = 'entregado';
                $appointment->save();
                DB::table('appointment_studies')
                    ->where('appointment_id', $appointment->id)
                    ->update(['status' => 'entregado', 'updated_at' => now()]);
            }

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true, 'message' => "Correo enviado exitosamente a $email"]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al enviar: ' . $e->getMessage()], 500);
        }
    }

    // === NUEVO: AUDITORÍA DE IMPRESIONES ===
    public function logPrint(Request $request, $id)
    {
        $this->assertDeliveryAccess($request);
        $userId = $request->user()->id;
        try {
            DB::table('appointment_logs')->insert([
                'appointment_id' => $id,
                'user_id' => $userId,
                'action' => 'REPORT_PRINTED',
                'details' => json_encode(['mensaje' => 'Copia física impresa en recepción.']),
                'ip_address' => $request->ip(),
                'created_at' => now()
            ]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false], 500);
        }
    }
}