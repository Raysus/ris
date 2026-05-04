<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

class TranscriptionController extends Controller
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
            ->where('status', 'en_transcripcion')
            ->orderBy('updated_at', 'desc')
            ->get();

        $formattedData = $appointments->map(function ($app) {
            return [
                'id' => $app->id,
                'accessionNumber' => $app->accession_number ?? 'ACC-' . $app->id,
                'destinationDoctorId' => $app->destination_doctor_id,
                'hasAudio' => false,
                'needsReview' => (bool) $app->needs_review,
                'returnReason' => $app->return_reason ?? '',
                'patient' => [
                    'rut' => $app->patient->persona->rut,
                    'name' => $app->patient->persona->names,
                    'lastName' => $app->patient->persona->last_name_1,
                    'secondLastName' => $app->patient->persona->last_name_2,
                ],
                'studies' => $app->studies->map(function ($s) {
                    return [
                        'study_id' => $s->id,
                        'exam' => $s->exam_name,
                        'reportText' => $s->report ?? '',
                        'audioUrl' => $s->audio_path ? asset('storage/' . $s->audio_path) : null,
                    ];
                })
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function completeTranscription(Request $request, $id)
    {
        $request->validate([
            'reports' => 'required|array',
            'reports.*.id' => 'required|integer',
            'reports.*.text' => 'nullable|string'
        ]);

        $userId = $request->user()->id;

        DB::beginTransaction();
        try {
            $appointment = $this->getSecureAppointmentQuery()
                ->where('id', $id)
                ->firstOrFail();

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
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'action' => 'TRANSCRIPTION_COMPLETED',
                'details' => json_encode(['mensaje' => 'La secretaria transcribió/corrigió los informes.']),
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
}