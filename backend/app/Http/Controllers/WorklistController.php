<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Machine;
use App\Models\Supply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\DicomImportService;
use App\Models\Persona;
use App\Services\OrthancStudyLookup;
use App\Support\ModalityCode;
use App\Support\OrthancUrl;
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
                'studies.exam',
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
                    'machine_group' => $study->machine?->group ?? $appointment->machine?->group,
                    'machine_ae_title' => $study->machine?->ae_title ?? $appointment->machine?->ae_title,
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

    public function sendToDicom(Request $request, $appointmentId, DicomImportService $dicomImport)
    {
        $profile = LaboratoryProfileService::resolve();
        if (!($profile['uses_dicom_worklist'] ?? true)) {
            return response()->json([
                'success' => false,
                'message' => 'Este centro no usa worklist DICOM. Suba el estudio con «Subir imágenes a PACS».',
            ], 422);
        }

        $appointment = $this->getSecureAppointmentQuery()
            ->with(['patient.persona', 'machine', 'studies.machine', 'studies.exam', 'laboratory'])
            ->findOrFail($appointmentId);

        $procedureSteps = $this->buildScheduledProcedureSteps($appointment);
        if ($procedureSteps === []) {
            return response()->json([
                'success' => false,
                'message' => 'La cita no tiene sala/equipo asignado. Asigne la modalidad en Agenda antes de enviar la worklist.',
            ], 422);
        }

        $primaryMachine = $this->resolveStudyMachine($appointment->studies->first(), $appointment)
            ?? $appointment->machine;

        $isResend = filled($appointment->accession_number);
        $accessionNumber = $appointment->accession_number
            ?: $this->generateAccessionNumber($appointment);

        try {
            $orthancBase = OrthancUrl::base();
            $orthancUrl = $orthancBase . '/worklists/create';

            $persona = $appointment->patient->persona;
            $study = $appointment->studies->first();
            $lab = $appointment->laboratory ?? LaboratoryProfileService::currentLaboratory();

            $tags = $this->buildWorklistTags(
                $dicomImport,
                $persona,
                $accessionNumber,
                $lab?->name ?? config('app.name', 'HealthTiCloud'),
                $study?->exam_name ?? $study?->sub_exam_name
            );

            $tags['ScheduledProcedureStepSequence'] = $procedureSteps;
            $tags = $this->shapeWorklistForFujiCfind($tags, $procedureSteps, $accessionNumber);

            $this->purgeOrthancWorklistsForAccession($orthancBase, $accessionNumber);

            $dicomWorklistData = ['Tags' => $tags];

            $response = Http::timeout(20)
                ->acceptJson()
                ->asJson()
                ->post($orthancUrl, $dicomWorklistData);

            if (!$response->successful()) {
                throw new \Exception(
                    'Orthanc Worklist falló (' . $response->status() . ') en ' . $orthancBase . ': '
                    . $response->body()
                );
            }

            $appointment->status = 'dicom_enviado';
            $appointment->accession_number = $accessionNumber;
            $appointment->save();

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            $dicom = OrthancUrl::dicomTarget();
            $primaryStep = $procedureSteps[0];
            $stationAeTitle = (string) ($primaryStep['ScheduledStationAETitle'] ?? '');
            $modality = (string) ($primaryStep['Modality'] ?? 'OT');
            $stepsSummary = collect($procedureSteps)->map(fn (array $step) => [
                'station_ae' => $step['ScheduledStationAETitle'] ?? '',
                'modality' => $step['Modality'] ?? '',
                'procedure' => $step['RequestedProcedureDescription'] ?? null,
            ])->values()->all();

            return response()->json([
                'success' => true,
                'accession' => $accessionNumber,
                'resent' => $isResend,
                'worklist' => [
                    'scheduled_station_ae' => $stationAeTitle,
                    'modality' => $modality,
                    'machine_name' => $primaryMachine?->name,
                    'steps' => $stepsSummary,
                    'pacs_http' => $orthancBase,
                    'pacs_dicom_host' => $dicom['host'],
                    'pacs_dicom_port' => $dicom['port'],
                    'pacs_dicom_aet' => $dicom['aet'],
                ],
                'modality_note' => $this->buildWorklistModalityNote($dicom, $stationAeTitle, $modality, $procedureSteps),
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con Orthanc en '
                    . OrthancUrl::base()
                    . '. Revise ORTHANC_URL en el .env del servidor (ej. https://pacs.healthticloud.cl).',
            ], 503);
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
                (string) ($persona->last_name_1 ?? ''),
                filled($persona->last_name_2) ? (string) $persona->last_name_2 : null
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

            $lookup = app(OrthancStudyLookup::class);
            $studyUid = $lookup->studyInstanceUidForAccession($result['accession'], $appointment);
            if ($studyUid) {
                $lookup->persistStudyInstanceUid($appointment, $studyUid);
            }

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

    private function generateAccessionNumber(Appointment $appointment): string
    {
        $suffix = strtoupper(substr(str_replace('-', '', (string) $appointment->id), 0, 8));

        return 'ACC-' . date('Ymd') . '-' . $suffix;
    }

    private function resolveStudyMachine(?AppointmentStudy $study, Appointment $appointment): ?Machine
    {
        if ($study?->machine) {
            return $study->machine;
        }

        return $appointment->machine;
    }

    /**
     * Modalidad DICOM para MWL: sala asignada al estudio; si la sala es RX legado, usa el catálogo del examen.
     */
    private function resolveWorklistModality(?AppointmentStudy $study, Machine $machine): string
    {
        $machineGroup = ModalityCode::normalizeGroup($machine->group);
        $examGroup = ModalityCode::normalizeGroup($study?->exam?->group_code);

        $group = match (true) {
            in_array($machineGroup, ['CR', 'DX', 'CT', 'MRI', 'US', 'MAMO', 'DEXA', 'IO', 'CBCT', 'NM', 'PT', 'RF', 'XA'], true)
                => $machineGroup,
            $examGroup !== '' && !in_array($examGroup, ['RX', 'OT', 'GENERAL'], true) => $examGroup,
            default => $machineGroup !== '' ? $machineGroup : ($examGroup !== '' ? $examGroup : 'US'),
        };

        return ModalityCode::forDicomWorklist($group, $machine->ae_title);
    }

    /**
     * Un paso MWL por estudio, con la sala y modalidad actuales (CR, DX, MAMO→MG, etc.).
     *
     * @return list<array<string, string>>
     */
    private function buildScheduledProcedureSteps(Appointment $appointment): array
    {
        $dicomImport = app(DicomImportService::class);
        $startDate = \Carbon\Carbon::parse($appointment->start_time)->format('Ymd');
        $startTime = \Carbon\Carbon::parse($appointment->start_time)->format('His');
        $steps = [];

        if ($appointment->studies->isEmpty()) {
            if (!$appointment->machine) {
                return [];
            }

            $stationAe = $appointment->machine->ae_title ?: ('SALA_' . $appointment->machine->id);
            $modality = $this->resolveWorklistModality(null, $appointment->machine);

            return [[
                'ScheduledStationAETitle' => $stationAe,
                'ScheduledStationName' => $this->truncateDicomShortString($stationAe, 16),
                'ScheduledProcedureStepStartDate' => $startDate,
                'ScheduledProcedureStepStartTime' => $startTime,
                'ScheduledProcedureStepID' => $this->fujiScheduledProcedureStepId($appointment, (string) $appointment->id),
                'ScheduledProcedureStepStatus' => 'SCHEDULED',
                'Modality' => $modality,
            ]];
        }

        foreach ($appointment->studies as $study) {
            $machine = $this->resolveStudyMachine($study, $appointment);
            if (!$machine) {
                continue;
            }

            $procedureDesc = $dicomImport->toDicomAscii((string) ($study->exam_name ?? $study->sub_exam_name ?? ''));
            $stationAe = $machine->ae_title ?: ('SALA_' . $machine->id);
            $modality = $this->resolveWorklistModality($study, $machine);

            $step = [
                'ScheduledStationAETitle' => $stationAe,
                'ScheduledStationName' => $this->truncateDicomShortString($stationAe, 16),
                'ScheduledProcedureStepStartDate' => $startDate,
                'ScheduledProcedureStepStartTime' => $startTime,
                'ScheduledProcedureStepID' => $this->fujiScheduledProcedureStepId($appointment, (string) $study->id),
                'ScheduledProcedureStepStatus' => 'SCHEDULED',
                'Modality' => $modality,
            ];

            if ($procedureDesc !== '') {
                $step['RequestedProcedureDescription'] = strtoupper($procedureDesc);
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * Tags DICOM para Orthanc /worklists/create.
     *
     * @return array<string, mixed>
     */
    private function buildWorklistTags(
        DicomImportService $dicomImport,
        Persona $persona,
        string $accessionNumber,
        string $institutionName,
        ?string $procedureDescription = null
    ): array {
        $tags = [
            'SpecificCharacterSet' => 'ISO_IR 100',
            'PatientName' => $dicomImport->formatPatientNameDicomWorklist(
                (string) ($persona->names ?? ''),
                (string) ($persona->last_name_1 ?? ''),
                filled($persona->last_name_2) ? (string) $persona->last_name_2 : null
            ),
            'PatientID' => $dicomImport->normalizePatientIdDicom((string) $persona->rut),
            'AccessionNumber' => $accessionNumber,
            'RequestedProcedureID' => $accessionNumber,
        ];

        $sex = $dicomImport->normalizePatientSex($persona->gender);
        if ($sex !== '') {
            $tags['PatientSex'] = $sex;
        }

        $birthDate = $dicomImport->formatPatientBirthDate($persona->birth_date);
        if ($birthDate !== '') {
            $tags['PatientBirthDate'] = $birthDate;
        }

        $institution = $dicomImport->toDicomAscii($institutionName);
        if ($institution !== '') {
            $tags['InstitutionName'] = $institution;
        }

        $procedure = $dicomImport->toDicomAscii((string) $procedureDescription);
        if ($procedure !== '') {
            $tags['RequestedProcedureDescription'] = $procedure;
        }

        return $tags;
    }

    /**
     * Alinea tags MWL con el *Broad Query* del Fuji FCR (estación + modalidad + fecha).
     * Orthanc solo matchea esos filtros planos si estación/fecha del paso van también a nivel raíz
     * (además de ScheduledProcedureStepSequence); Modality/StudyDate en raíz ayudan al índice C-FIND.
     *
     * @param  list<array<string, string>>  $procedureSteps
     * @return array<string, mixed>
     */
    private function shapeWorklistForFujiCfind(array $tags, array $procedureSteps, string $accessionNumber): array
    {
        if ($procedureSteps === []) {
            return $tags;
        }

        $primary = $procedureSteps[0];
        $stationAe = (string) ($primary['ScheduledStationAETitle'] ?? '');
        $modality = (string) ($primary['Modality'] ?? 'OT');
        $stepDate = (string) ($primary['ScheduledProcedureStepStartDate'] ?? '');
        $stepTime = (string) ($primary['ScheduledProcedureStepStartTime'] ?? '');

        unset($tags['StudyInstanceUID']);

        $tags['Modality'] = $modality;
        if ($stationAe !== '') {
            $tags['ScheduledStationAETitle'] = $stationAe;
        }
        if ($stepDate !== '') {
            $tags['StudyDate'] = $stepDate;
            $tags['ScheduledProcedureStepStartDate'] = $stepDate;
        }
        if ($stepTime !== '') {
            $tags['StudyTime'] = $stepTime;
            $tags['ScheduledProcedureStepStartTime'] = $stepTime;
        }

        $tags['ScheduledProcedureStepSequence'] = array_map(
            function (array $step) use ($accessionNumber, $modality): array {
                $station = (string) ($step['ScheduledStationAETitle'] ?? '');
                if ($station !== '') {
                    $step['ScheduledStationName'] = $this->truncateDicomShortString($station, 16);
                }

                $step['ScheduledProcedureStepStatus'] = $step['ScheduledProcedureStepStatus'] ?? 'SCHEDULED';
                $step['Modality'] = $step['Modality'] ?? $modality;

                if ($this->isFujiFcrStation($station)) {
                    $step['ScheduledProcedureStepID'] = $this->fujiScheduledProcedureStepIdFromAccession(
                        $accessionNumber,
                        (string) ($step['ScheduledProcedureStepID'] ?? '1')
                    );
                }

                return $step;
            },
            $procedureSteps
        );

        if ($this->isFujiFcrStation($stationAe)) {
            $tags['RequestedProcedureID'] = $this->truncateDicomShortString($accessionNumber, 16);
        }

        return $tags;
    }

    private function isFujiFcrStation(string $stationAe): bool
    {
        return str_starts_with(strtoupper(trim($stationAe)), 'FCR_');
    }

    private function fujiScheduledProcedureStepId(Appointment $appointment, string $fallbackId): string
    {
        $accession = (string) ($appointment->accession_number ?? '');

        return $accession !== ''
            ? $this->fujiScheduledProcedureStepIdFromAccession($accession, $fallbackId)
            : $this->truncateDicomShortString($fallbackId, 16);
    }

    private function fujiScheduledProcedureStepIdFromAccession(string $accessionNumber, string $fallbackId): string
    {
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $accessionNumber) ?? '');

        if ($compact !== '') {
            return $this->truncateDicomShortString($compact, 16);
        }

        return $this->truncateDicomShortString($fallbackId, 16);
    }

    private function truncateDicomShortString(string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '' : substr($trimmed, 0, $maxLength);
    }

    private function purgeOrthancWorklistsForAccession(string $orthancBase, string $accessionNumber): void
    {
        try {
            $response = Http::timeout(15)->acceptJson()->get($orthancBase . '/worklists');
            if (!$response->successful()) {
                return;
            }

            foreach ($response->json() as $item) {
                $existingAccession = (string) ($item['Tags']['AccessionNumber'] ?? '');
                if ($existingAccession !== $accessionNumber || empty($item['ID'])) {
                    continue;
                }

                Http::timeout(10)->acceptJson()->delete($orthancBase . '/worklists/' . $item['ID']);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo purgar worklist previa en Orthanc: ' . $e->getMessage());
        }
    }

    /**
     * @param  array{host: string, port: int, aet: string, http_host?: string}  $dicom
     * @param  list<array<string, string>>  $procedureSteps
     */
    private function buildWorklistModalityNote(array $dicom, string $stationAe, string $modality, array $procedureSteps): string
    {
        $httpHost = $dicom['http_host'] ?? $dicom['host'];
        $dicomHost = $dicom['host'];
        $hostHint = $dicomHost !== $httpHost
            ? "Use la IP DICOM «{$dicomHost}» (no «{$httpHost}») en el equipo."
            : "Host DICOM: «{$dicomHost}».";

        $dateHint = $procedureSteps[0]['ScheduledProcedureStepStartDate'] ?? '';

        return 'La orden quedó en el PACS (HTTP). El Fuji FCR la baja por DICOM MWL (C-FIND), no por la web. '
            . "{$hostHint} Puerto {$dicom['port']}, AE destino (called) «{$dicom['aet']}». "
            . "En el FCR configure filtro de estación «{$stationAe}», modalidad «{$modality}» "
            . "y fecha de la cita «{$dateHint}» (el FCR filtra por fecha + modalidad + estación). "
            . 'Reenvíe la worklist si cambió modalidad o estación. TCP :4242 OK no garantiza C-FIND en el PACS.';
    }
}