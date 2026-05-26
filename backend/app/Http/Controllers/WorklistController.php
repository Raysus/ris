<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Supply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\DicomImportService;
use App\Services\LaboratoryProfileService;

class WorklistController extends Controller
{
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
        $query = $this->getSecureAppointmentQuery()
            ->with([
                'studies.machine',
                'patient.persona',
                'machine',
            ])
            ->whereIn('status', ['confirmado', 'en_atencion', 'dicom_enviado', 'devuelto_worklist']);

        $appointments = $query->get();
        $formattedData = [];

        foreach ($appointments as $appointment) {
            $persona = $appointment->patient?->persona;

            foreach ($appointment->studies as $study) {
                $formattedData[] = [
                    'id' => $study->id,
                    'exam_name' => $study->exam_name,
                    'sub_exam_name' => $study->sub_exam_name,
                    'quantity' => $study->quantity,
                    'machine_id' => $study->machine_id,
                    'machine_name' => $study->machine?->name ?? $appointment->machine?->name ?? 'Sala Desconocida',
                    'appointment' => [
                        'id' => $appointment->id,
                        'start_time' => $appointment->start_time?->format('Y-m-d\TH:i:s'),
                        'status' => strtolower($appointment->status),
                        'priority' => $appointment->priority,
                        'accession_number' => $appointment->accession_number,
                        'return_reason' => $appointment->return_reason,
                        'medical_order_path' => $appointment->medical_order_path,
                        'survey_path' => $appointment->survey_path,
                        'patient' => [
                            'persona' => [
                                'names' => $persona?->names,
                                'last_name_1' => $persona?->last_name_1,
                                'rut' => $persona?->rut,
                            ],
                        ],
                    ],
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $formattedData]);
    }

    public function sendToDicom(Request $request, $appointmentId)
    {
        $profile = LaboratoryProfileService::resolve();
        if (!($profile['uses_dicom_worklist'] ?? true)) {
            return response()->json([
                'success' => false,
                'message' => 'Este centro no usa worklist DICOM. Suba el estudio con «Subir imágenes a PACS».',
            ], 422);
        }

        $userId = $request->user()->id;
        $appointment = $this->getSecureAppointmentQuery()
            ->with(['patient.persona', 'machine'])
            ->findOrFail($appointmentId);

        $accessionNumber = 'ACC-' . date('Ymd') . '-' . substr($appointment->id, 0, 5);

        try {
            // 🔥 CORRECCIÓN 1: El endpoint correcto para crear es /worklists/create
            $orthancUrl = env('ORTHANC_URL', 'http://127.0.0.1:8042') . '/worklists/create';

            $stationAeTitle = $appointment->machine->ae_title ?? "SALA_" . $appointment->machine_id;

            // 🔥 CORRECCIÓN 2: El formato exacto que pide el plugin envuelto en "Tags"
            $dicomWorklistData = [
                "Tags" => [
                    "PatientName" => $appointment->patient->persona->names . "^" . $appointment->patient->persona->last_name_1,
                    "PatientID" => $appointment->patient->persona->rut,
                    "AccessionNumber" => $accessionNumber,
                    "ScheduledProcedureStepSequence" => [
                        [
                            "ScheduledStationAETitle" => $stationAeTitle,
                            "ScheduledProcedureStepStartDate" => \Carbon\Carbon::parse($appointment->start_time)->format('Ymd'),
                            "ScheduledProcedureStepStartTime" => \Carbon\Carbon::parse($appointment->start_time)->format('His'),
                            "ScheduledProcedureStepID" => (string) $appointment->id,
                            "Modality" => $appointment->machine->group ?? 'US'
                        ]
                    ]
                ]
            ];

            // Enviamos el POST a Orthanc
            $response = Http::post($orthancUrl, $dicomWorklistData);

            if (!$response->successful()) {
                throw new \Exception("Orthanc Worklist falló: " . $response->status() . " - " . $response->body());
            }

            $appointment->status = 'dicom_enviado';
            $appointment->accession_number = $accessionNumber;
            $appointment->save();

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true, 'accession' => $accessionNumber]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Subida manual de DICOM (ZIP o .dcm) para centros sin MWL — p. ej. laboratorios dentales.
     */
    public function uploadDicomStudy(Request $request, $appointmentId, DicomImportService $dicomImport)
    {
        $profile = LaboratoryProfileService::resolve();
        if ($profile['uses_dicom_worklist'] ?? true) {
            return response()->json([
                'success' => false,
                'message' => 'Este centro usa worklist DICOM. Use «Enviar a equipos».',
            ], 422);
        }

        $request->validate([
            'dicom_file' => 'required|file|max:512000',
        ]);

        $ext = strtolower($request->file('dicom_file')->getClientOriginalExtension());
        if (!in_array($ext, ['zip', 'dcm', ''], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato no soportado. Use archivo .dcm o .zip con estudios DICOM.',
            ], 422);
        }

        $appointment = $this->getSecureAppointmentQuery()
            ->with(['patient.persona', 'machine', 'laboratory'])
            ->findOrFail($appointmentId);

        $persona = $appointment->patient?->persona;
        if (!$persona) {
            return response()->json(['success' => false, 'message' => 'La cita no tiene paciente asociado.'], 422);
        }

        try {
            $accession = $appointment->accession_number
                ?: ('ACC-' . date('Ymd') . '-' . substr($appointment->id, 0, 8));

            $lab = $appointment->laboratory ?? LaboratoryProfileService::currentLaboratory();
            $institution = $lab?->name ?? config('app.name', 'HealthTiCloud');

            $patientName = $dicomImport->formatPatientNameDicom(
                (string) ($persona->names ?? ''),
                (string) ($persona->last_name_1 ?? '')
            );

            $result = $dicomImport->uploadAndTag(
                $request->file('dicom_file'),
                (string) $persona->rut,
                $patientName,
                $accession,
                $institution
            );

            $appointment->accession_number = $result['accession'];
            $appointment->status = 'dicom_enviado';
            $appointment->images_received_at = now();
            $appointment->save();

            DB::table('appointment_logs')->insert([
                'id' => (string) Str::orderedUuid(),
                'appointment_id' => $appointment->id,
                'user_id' => $request->user()->id,
                'action' => 'DICOM_MANUAL_UPLOAD',
                'details' => json_encode([
                    'accession' => $result['accession'],
                    'studies' => $result['study_ids'],
                    'instances' => $result['instances_count'],
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json([
                'success' => true,
                'accession' => $result['accession'],
                'studies' => count($result['study_ids']),
                'message' => 'Estudio subido y etiquetado en PACS.',
            ]);
        } catch (\Exception $e) {
            Log::error("uploadDicomStudy cita {$appointmentId}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Marca citas adicionales de la misma cadena como DICOM recibido (mismo accession, sin re-subir archivo).
     */
    public function markDicomReceived(Request $request, $appointmentId)
    {
        $request->validate(['accession' => 'required|string|max:64']);

        $appointment = $this->getSecureAppointmentQuery()->findOrFail($appointmentId);

        $appointment->accession_number = $request->input('accession');
        $appointment->status = 'dicom_enviado';
        $appointment->images_received_at = now();
        $appointment->save();

        $appointment->load(['patient.persona', 'studies', 'supplies']);
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

        return response()->json(['success' => true, 'accession' => $appointment->accession_number]);
    }

    public function complete(Request $request, $appointmentId)
    {
        $request->validate([
            'anamnesis' => 'required|string',
            'supplies' => 'array',
            'supplies.*.id' => 'required',
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
                'id' => (string) Str::orderedUuid(),
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
            Log::error("Error completando Worklist (Cita {$appointmentId}): " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function updateStatus(Request $request, $appointmentId)
    {
        $request->validate([
            'status' => 'required|string',
            'needs_review' => 'nullable|boolean',
            'return_reason' => 'nullable|string'
        ]);

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
                'id' => (string) Str::orderedUuid(),
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