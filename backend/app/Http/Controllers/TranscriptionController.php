<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksRisAuthorization;
use App\Http\Controllers\Concerns\FormatsAppointmentInbox;
use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\PublicStorageUrl;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TranscriptionController extends Controller
{
    use ChecksRisAuthorization;
    use FormatsAppointmentInbox;

    private const TRANSCRIPTION_ROLES = ['admin', 'sis_admin', 'transcriptor'];

    /** Quién puede adjuntar/reemplazar el PDF de informe (incluye corrección post-firma). */
    private const REPORT_DOCUMENT_ROLES = ['admin', 'sis_admin', 'transcriptor', 'radiologo'];

    private function assertTranscriptionAccess(Request $request): void
    {
        $this->assertAnyRole($request, self::TRANSCRIPTION_ROLES);
    }

    private function assertReportDocumentAccess(Request $request): void
    {
        $this->assertAnyRole($request, self::REPORT_DOCUMENT_ROLES);
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
                        'audioUrl' => PublicStorageUrl::from($study->audio_path),
                        'reportDocumentPath' => $study->report_document_path,
                        'reportDocumentUrl' => PublicStorageUrl::from($study->report_document_path),
                    ];
                })
            ], $this->examInboxTimingFields($app));
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    /** Estados en los que se puede adjuntar/reemplazar el documento de informe. */
    private const REPORT_DOCUMENT_STATUSES = [
        'en_transcripcion',
        'para_firma',
        'entregable',
        'entregado',
        'en_informe',
    ];

    public function uploadReportDocument(Request $request, $id)
    {
        $this->assertReportDocumentAccess($request);
        $request->validate([
            'study_id' => 'required|string',
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:20480',
        ]);

        $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);
        if (!in_array((string) $appointment->status, self::REPORT_DOCUMENT_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede adjuntar o corregir el informe en el estado actual de la cita (' . $appointment->status . ').',
            ], 422);
        }

        $study = DB::table('appointment_studies')
            ->where('id', $request->input('study_id'))
            ->where('appointment_id', $appointment->id)
            ->first();

        if (!$study) {
            return response()->json([
                'success' => false,
                'message' => 'Examen no encontrado en esta cita.',
            ], 404);
        }

        $file = $request->file('document');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'pdf');
        $relative = 'documents/informe_' . Str::uuid() . '.' . $ext;
        Storage::disk('public')->put($relative, file_get_contents($file->getRealPath()));
        $storagePath = '/storage/' . $relative;

        if (!empty($study->report_document_path)) {
            $old = ltrim((string) $study->report_document_path, '/');
            if (str_starts_with($old, 'storage/')) {
                $old = substr($old, strlen('storage/'));
            }
            if ($old !== '' && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
        }

        $reportText = trim((string) ($study->report ?? ''));
        if ($reportText === '') {
            $reportText = 'Informe adjunto como documento.';
        }

        DB::table('appointment_studies')
            ->where('id', $study->id)
            ->update([
                'report_document_path' => $storagePath,
                'report' => $reportText,
                'updated_at' => now(),
            ]);

        // Si se corrige un informe ya firmado, dejar constancia en el log.
        if (in_array((string) $appointment->status, ['entregable', 'entregado', 'para_firma'], true)) {
            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $request->user()->id,
                'action' => 'REPORT_DOCUMENT_REPLACED',
                'details' => json_encode([
                    'study_id' => $study->id,
                    'mensaje' => 'Se reemplazó el documento de informe (corrección).',
                    'status' => $appointment->status,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $appointment->touch();
        $appointment->load(['patient.persona', 'studies', 'supplies']);
        if (class_exists('\App\Jobs\SyncEntityToCloud')) {
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
        }

        return response()->json([
            'success' => true,
            'path' => $storagePath,
            'url' => PublicStorageUrl::from($storagePath),
            'reportText' => $reportText,
        ]);
    }

    public function deleteReportDocument(Request $request, $id, $studyId = null)
    {
        $this->assertReportDocumentAccess($request);
        $studyId = $studyId
            ?: $request->input('study_id')
            ?: $request->query('study_id');
        if (!$studyId) {
            return response()->json([
                'success' => false,
                'message' => 'Debe indicar study_id.',
            ], 422);
        }

        $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);
        if (!in_array((string) $appointment->status, self::REPORT_DOCUMENT_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede quitar o corregir el informe en el estado actual de la cita (' . $appointment->status . ').',
            ], 422);
        }

        $study = DB::table('appointment_studies')
            ->where('id', $studyId)
            ->where('appointment_id', $appointment->id)
            ->first();

        if (!$study) {
            return response()->json([
                'success' => false,
                'message' => 'Examen no encontrado en esta cita.',
            ], 404);
        }

        $this->deleteStoredReportDocumentFile($study->report_document_path ?? null);

        $reportText = trim((string) ($study->report ?? ''));
        $clearText = $request->boolean('clear_text', true);
        // Placeholder de adjunto, o borrado explícito del informe generado/adjunto.
        if (
            $clearText
            || $reportText === ''
            || $reportText === 'Informe adjunto como documento.'
        ) {
            $reportText = null;
        }

        DB::table('appointment_studies')
            ->where('id', $study->id)
            ->update([
                'report_document_path' => null,
                'report' => $reportText,
                'updated_at' => now(),
            ]);

        DB::table('appointment_logs')->insert([
            'id' => (string) Str::orderedUuid(),
            'appointment_id' => $appointment->id,
            'user_id' => $request->user()->id,
            'action' => 'REPORT_DOCUMENT_REMOVED',
            'details' => json_encode([
                'study_id' => $study->id,
                'mensaje' => 'Se eliminó el documento/informe de informe.',
                'status' => $appointment->status,
                'cleared_text' => $reportText === null,
            ]),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $appointment->touch();
        $appointment->load(['patient.persona', 'studies', 'supplies']);
        if (class_exists('\App\Jobs\SyncEntityToCloud')) {
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
        }

        return response()->json(['success' => true]);
    }

    private function deleteStoredReportDocumentFile(?string $path): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        $old = ltrim((string) $path, '/');
        if (str_starts_with($old, 'storage/')) {
            $old = substr($old, strlen('storage/'));
        }
        if ($old === '') {
            return;
        }

        try {
            if (Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }
        } catch (\Throwable $e) {
            // No bloquear el borrado lógico si el archivo ya no está en disco.
        }

        $absolute = storage_path('app/public/' . $old);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
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
                $study = DB::table('appointment_studies')
                    ->where('id', $reportData['id'])
                    ->where('appointment_id', $appointment->id)
                    ->first();

                if (!$study) {
                    continue;
                }

                $text = trim((string) ($reportData['text'] ?? ''));
                if ($text === '' && empty($study->report_document_path)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Cada examen debe tener texto de informe o un documento adjunto.',
                    ], 422);
                }

                if ($text === '' && !empty($study->report_document_path)) {
                    $text = 'Informe adjunto como documento.';
                }

                DB::table('appointment_studies')
                    ->where('id', $study->id)
                    ->update([
                        'report' => $text,
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