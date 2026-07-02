<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Http\Controllers\Concerns\FormatsAppointmentInbox;
use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TranscriptionController extends Controller
{
    use ChecksRisAuthorization;
    use FormatsAppointmentInbox;

    private const TRANSCRIPTION_ROLES = ['admin', 'sis_admin', 'transcriptor'];

    private function assertTranscriptionAccess(Request $request): void
    {
        $this->assertAnyRole($request, self::TRANSCRIPTION_ROLES);
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
        $this->assertTranscriptionAccess($request);
        $appointments = $this->applyExamDateFilter(
            $this->getSecureAppointmentQuery()
                ->with(['patient.persona', 'studies'])
                ->where('status', 'en_transcripcion'),
            $request
        )
            ->orderBy('start_time', 'asc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            // Navegación segura: Si patient o persona son nulos, no rompe el código
            $persona = optional(optional($app->patient)->persona);

            // Verifica si al menos un sub-examen tiene audio
            $hasAudio = $app->studies->contains(function ($study) {
                return !empty($study->audio_path);
            });

            return array_merge([
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'destinationDoctorId' => $app->destination_doctor_id,
                'hasAudio' => $hasAudio,
                'needsReview' => (bool) $app->needs_review,
                'returnReason' => $app->return_reason ?? '',
                'patient' => [
                    'id' => optional($app->patient)->id,
                    // Se utilizan operadores de fusión nula (??) para proveer textos por defecto
                    'name' => $persona->names ?? $persona->name ?? 'Sin nombre',
                    'lastName' => $persona->last_name_1 ?? $persona->last_name ?? 'Sin apellido',
                    'secondLastName' => $persona->last_name_2 ?? $persona->second_last_name ?? '',
                    'rut' => $persona->rut ?? 'Sin RUT',
                    // Si existe fecha de nacimiento, calcula la edad, si no, busca la columna age o devuelve N/A
                    'age' => $persona->birth_date ? \Carbon\Carbon::parse($persona->birth_date)->age : ($persona->age ?? 'N/A'),
                ],
                'studies' => $app->studies->map(function ($study) {
                    return [
                        'study_id' => $study->id,
                        'exam' => $study->exam_name,
                        'subExam' => $study->sub_exam_name,
                        // Se corrigen las variables apuntando a $study en lugar de $app
                        'reportText' => $study->getStoredReportText(),
                        'audioUrl' => $study->audio_path ? asset('storage/' . $study->audio_path) : null,
                    ];
                })
            ], $this->examInboxTimingFields($app));
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function saveDraft(Request $request, $id)
    {
        $this->assertTranscriptionAccess($request);
        $request->validate([
            'reports' => 'required|array',
            'reports.*.id' => 'required|string',
            'reports.*.text' => 'nullable|string'
        ]);

        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            foreach ($request->reports as $reportData) {
                DB::table('appointment_studies')
                    ->where('id', $reportData['id'])
                    ->where('appointment_id', $appointment->id)
                    ->update([
                        'report' => $reportData['text'] ?? '',
                        'updated_at' => now()
                    ]);
            }

            $appointment->touch();
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendToValidation(Request $request, $id)
    {
        $this->assertTranscriptionAccess($request);

        $request->validate([
            'reports' => 'required|array',
            'reports.*.id' => 'required|string',
            'reports.*.text' => 'nullable|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'para_firma';
            $appointment->needs_review = false;
            $appointment->return_reason = null;
            $appointment->save();

            foreach ($request->reports as $reportData) {
                DB::table('appointment_studies')
                    ->where('id', $reportData['id'])
                    ->where('appointment_id', $appointment->id)
                    ->update([
                        'report' => $reportData['text'] ?? '',
                        'status' => 'para_firma',
                        'updated_at' => now()
                    ]);
            }

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'TRANSCRIPTION_COMPLETED',
                'details' => json_encode(['mensaje' => 'Transcripción finalizada. Enviado a bandeja de validación y firma.']),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            $appointment->load(['patient.persona', 'studies', 'supplies']);

            // Verificamos que el job exista antes de dispararlo para evitar errores 500 adicionales
            if (class_exists('\App\Jobs\SyncEntityToCloud')) {
                \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Error en validación de transcripción: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    public function returnToDoctor(Request $request, $id)
    {
        $this->assertTranscriptionAccess($request);
        $request->validate(['reason' => 'required|string']);
        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'en_informe';
            $appointment->needs_review = true;
            $appointment->return_reason = $request->reason;
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update([
                    'status' => 'en_informe',
                    'updated_at' => now()
                ]);

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'RETURNED_TO_DOCTOR_FROM_TRANSCRIPTION',
                'details' => json_encode(['motivo' => $request->reason]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            $appointment->touch();
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}