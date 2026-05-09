<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RadiologistController extends Controller
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
            ->with(['patient.persona', 'studies'])
            ->where('status', 'en_informe')
            ->orderBy('start_time', 'asc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            $anamnesisGlobal = $app->studies->first()->anamnesis ?? 'Sin anamnesis registrada.';

            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'anamnesis' => $anamnesisGlobal,
                'patient' => [
                    'rut' => $app->patient->persona->rut,
                    'name' => $app->patient->persona->names,
                    'lastName' => $app->patient->persona->last_name_1,
                ],
                'studies' => $app->studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'subExam' => $s->sub_exam_name,
                        'reportText' => $s->report ?? '',
                        'audioUrl' => $s->audio_path ? asset('storage/' . $s->audio_path) : null,
                    ];
                })
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function signReport(Request $request, $id)
    {
        $request->validate([
            'reports' => 'required|array',
            'reports.*.id' => 'required|string',
            'reports.*.text' => 'nullable|string',
            'dictation_method' => 'required|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $appointment->status = 'entregable';
            $appointment->save();

            foreach ($request->reports as $reportData) {
                DB::table('appointment_studies')
                    ->where('id', $reportData['id'])
                    ->where('appointment_id', $appointment->id)
                    ->update([
                        'report' => $reportData['text'] ?? '',
                        'status' => 'entregable',
                        'updated_at' => now()
                    ]);
            }

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'REPORT_SIGNED',
                'details' => json_encode([
                    'metodo' => $request->dictation_method,
                    'mensaje' => 'El radiólogo firmó y liberó los informes individuales.'
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now()
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

    // === 🔥 NUEVA FUNCIÓN DE AUTOGUARDADO (BORRADOR) 🔥 ===
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

            // Opcional: Sincronizar el borrador a la nube para evitar pérdidas
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
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'RETURNED_TO_WORKLIST_BY_DOC',
                'details' => json_encode(['motivo' => $request->reason]),
                'ip_address' => $request->ip(),
                'created_at' => now()
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
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()->findOrFail($id);

            $audioPath = null;
            if ($request->hasFile('audio')) {
                $folder = 'audios/' . date('Y/m');
                $audioPath = $request->file('audio')->store($folder, 'public');
            }

            $appointment->status = 'en_transcripcion';
            $appointment->save();

            DB::table('appointment_studies')
                ->where('id', $request->study_id)
                ->where('appointment_id', $appointment->id)
                ->update([
                    'report' => $request->report_text ?? '',
                    'audio_path' => $audioPath,
                    'status' => 'en_transcripcion',
                    'updated_at' => now()
                ]);

            DB::table('appointment_logs')->insert([
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'SENT_TO_TRANSCRIPTION',
                'details' => json_encode([
                    'estudio_id' => $request->study_id,
                    'mensaje' => 'Audio grabado y enviado a digitación'
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now()
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

    public function validations(Request $request)
    {
        $appointments = $this->getSecureAppointmentQuery()
            ->with([
                'patient.persona',
                'studies',
                'destinationDoctor.persona',
                'referringDoctor'
            ])
            ->where('status', 'para_firma')
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

            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'start_time' => $app->start_time,
                'destinationDoctorId' => $app->destination_doctor_id,
                'referringDoctorId' => $app->referring_doctor_id,
                'destinationDoctorName' => $destDoctorName,
                'referringDoctorName' => $refDoctorName,
                'firmaUrl' => $firmaUrl,
                'patient' => [
                    'rut' => $app->patient->persona->rut,
                    'name' => $app->patient->persona->names,
                    'lastName' => $app->patient->persona->last_name_1,
                    'secondLastName' => $app->patient->persona->last_name_2,
                    'age' => Carbon::parse($app->patient->persona->birth_date)->age ?? null,
                ],
                'studies' => $app->studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'subExam' => $s->sub_exam_name,
                        'reportText' => $s->report ?? '',
                    ];
                }),
            ];
        });

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
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'REJECTED_TRANSCRIPTION',
                'details' => json_encode(['motivo' => $request->reason]),
                'ip_address' => $request->ip(),
                'created_at' => now()
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
}