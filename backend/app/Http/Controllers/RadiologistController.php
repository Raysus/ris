<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;

class RadiologistController extends Controller
{
    private function formatPatientPayload($patient): array
    {
        $persona = optional(optional($patient)->persona);

        return [
            'rut' => $persona->rut ?? 'Sin RUT',
            'name' => $persona->names ?? $persona->name ?? 'Sin nombre',
            'lastName' => $persona->last_name_1 ?? $persona->last_name ?? 'Sin apellido',
            'secondLastName' => $persona->last_name_2 ?? $persona->second_last_name ?? '',
            'age' => $persona->birth_date
                ? Carbon::parse($persona->birth_date)->age
                : ($persona->age ?? 'N/A'),
        ];
    }

    private function formatStudyPayload($study): array
    {
        return [
            'study_id' => $study->id,
            'exam' => $study->exam_name,
            'subExam' => $study->sub_exam_name,
            'reportText' => $study->getStoredReportText(),
            'audioUrl' => $study->audio_path ? asset('storage/' . $study->audio_path) : null,
        ];
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
        $appointments = $this->getSecureAppointmentQuery()
            ->with(['patient.persona', 'studies'])
            ->whereIn('status', ['en_informe', 'pendiente_radiologo'])
            ->orderBy('start_time', 'asc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            $anamnesisGlobal = $app->studies->first()->anamnesis ?? 'Sin anamnesis registrada.';

            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'studyInstanceUid' => $app->study_instance_uid,
                'anamnesis' => $anamnesisGlobal,
                'patient' => $this->formatPatientPayload($app->patient),
                'studies' => $app->studies->map(fn ($s) => $this->formatStudyPayload($s))->values(),
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function signReport(Request $request, $id)
    {
        $request->validate([
            'reports' => 'required|array|min:1',
            'reports.*.id' => 'required|string',
            'reports.*.text' => 'nullable|string',
            'dictation_method' => 'required|string',
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'entregable';
            $appointment->save();

            foreach ($request->reports as $reportData) {
                $updated = DB::table('appointment_studies')
                    ->where('id', $reportData['id'])
                    ->where('appointment_id', $appointment->id)
                    ->update([
                        'report' => $reportData['text'] ?? '',
                        'status' => 'entregable',
                        'updated_at' => now()
                    ]);

                if ($updated === 0) {
                    throw new \RuntimeException(
                        'No se encontró el estudio «' . ($reportData['id'] ?? '') . '» en la cita.'
                    );
                }
            }

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'REPORT_SIGNED',
                'details' => json_encode([
                    'metodo' => $request->dictation_method,
                    'mensaje' => 'El radiólogo firmó y liberó los informes individuales.'
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $appointment->touch();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::warning('signReport falló', [
                'appointment_id' => $id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        try {
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            if (config('hl7.enabled') && config('hl7.send_oru_on_sign')) {
                app(\App\Services\Hl7IntegrationService::class)->queueOruForAppointment($appointment);
            }
        } catch (\Throwable $e) {
            Log::warning('signReport: sync/HL7 post-firma omitido', [
                'appointment_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function saveDraft(Request $request, $id)
    {
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

    public function returnToTechnologist(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string']);
        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'devuelto_worklist';
            $appointment->return_reason = $request->reason;
            $appointment->needs_review = true;
            $appointment->save();

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update([
                    'status' => 'devuelto_worklist',
                    'updated_at' => now()
                ]);

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'RETURNED_TO_WORKLIST_BY_DOC',
                'details' => json_encode(['motivo' => $request->reason]),
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendToTranscription(Request $request, $id)
    {
        $request->validate([
            'study_id' => 'required|string',
            'audio' => 'required|file|mimes:webm,mp3,wav,ogg,mp4|max:15360',
            'reason' => 'nullable|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $audioPath = null;
            if ($request->hasFile('audio')) {
                $audioPath = $request->file('audio')->store('audios_dictados', 'public');
            }

            $appointment->update([
                'status' => 'en_transcripcion',
                'return_reason' => $request->reason,
                'needs_review' => true,
            ]);

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update([
                    'status' => 'en_transcripcion',
                    'updated_at' => now()
                ]);

            if ($audioPath) {
                DB::table('appointment_studies')
                    ->where('appointment_id', $appointment->id)
                    ->where('id', $request->study_id)
                    ->update([
                        'audio_path' => $audioPath
                    ]);
            }

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'SENT_TO_TRANSCRIPTION',
                'details' => json_encode(['motivo' => $request->reason ?? 'Derivado a transcripción con audio adjunto.']),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function validations(Request $request)
    {
        $appointments = $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies.report',
                'destinationDoctor.persona',
                'referringDoctor',
            ])
            ->where(function ($q) {
                $q->where('status', 'para_firma')
                    ->orWhereHas('studies', fn ($s) => $s->where('status', 'para_firma'));
            })
            ->orderBy('updated_at', 'asc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            $destDoctorName = null;
            $firmaUrl = null;

            if ($app->destinationDoctor && $app->destinationDoctor->persona) {
                $p = $app->destinationDoctor->persona;
                $destDoctorName = trim("{$p->names} {$p->last_name_1} {$p->last_name_2}");

                if ($p->signature_path) {
                    $firmaUrl = asset('storage/' . $p->signature_path);
                }
            }

            $refDoctorName = null;
            if ($app->referringDoctor) {
                $refDoctorName = $app->referringDoctor->name ?? $app->referringDoctor->names ?? 'Derivante Registrado';
            }

            $studies = $app->studies->filter(
                fn ($s) => $app->status === 'para_firma' || $s->status === 'para_firma'
            );

            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'studyInstanceUid' => $app->study_instance_uid,
                'start_time' => $app->start_time,
                'destinationDoctorId' => $app->destination_doctor_id,
                'referringDoctorId' => $app->referring_doctor_id,
                'destinationDoctorName' => $destDoctorName,
                'referringDoctorName' => $refDoctorName,
                'firmaUrl' => $firmaUrl,
                'patient' => $this->formatPatientPayload($app->patient),
                'studies' => $studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'subExam' => $s->sub_exam_name,
                        'reportText' => $s->getStoredReportText(),
                    ];
                })->values(),
            ];
        })->filter(fn ($row) => $row['studies']->isNotEmpty())->values();

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function rejectTranscription(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string']);
        $userId = $request->user()->id;

        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->update([
                'status' => 'en_transcripcion',
                'return_reason' => $request->reason,
                'needs_review' => true,
            ]);

            DB::table('appointment_studies')
                ->where('appointment_id', $appointment->id)
                ->update([
                    'status' => 'en_transcripcion',
                    'updated_at' => now()
                ]);

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'REJECTED_TRANSCRIPTION',
                'details' => json_encode(['motivo' => $request->reason]),
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
                'message' => 'Error en el servidor',
                'error_real' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadStudyAudio(Request $request, $studyId)
    {
        // Validar que el archivo sea un archivo de audio binario válido
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,ogg,webm|max:10240', // máx 10MB
        ]);

        try {
            // 1. Buscar el registro del sub-examen específico
            $study = DB::table('appointment_studies')->where('id', $studyId)->first();

            if (!$study) {
                return response()->json(['success' => false, 'message' => 'Estudio no encontrado.'], 404);
            }

            // 2. Procesar y almacenar el archivo físico en storage/app/public/audios
            if ($request->hasFile('audio')) {
                $file = $request->file('audio');

                // Lo guardamos con un nombre único y descriptivo usando el ID del estudio
                $fileName = "dictado_estudio_{$studyId}_" . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('audios', $fileName, 'public');

                // 3. Actualizar el registro en 'appointment_studies'
                DB::table('appointment_studies')
                    ->where('id', $studyId)
                    ->update([
                        'audio_path' => $path,
                        'status' => 'en_transcripcion',
                        'updated_at' => now()
                    ]);

                // 4. Opcional: Actualizar el estado de la cita global (appointment) a 'en_transcripcion'
                DB::table('appointments')
                    ->where('id', $study->appointment_id)
                    ->update([
                        'status' => 'en_transcripcion',
                        'updated_at' => now()
                    ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Audio de examen almacenado con éxito.',
                'audio_path' => $path
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno al procesar el audio.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}