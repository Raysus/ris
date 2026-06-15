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
use App\Services\LocalMwlFileWriter;
use App\Services\WorklistTagNormalizer;
use App\Models\Persona;
use App\Services\OrthancStudyLookup;
use App\Jobs\RelayWorklistToLocalLab;
use App\Support\LaboratoryMwlRelay;
use App\Support\LabTimezone;
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

        $isResend = filled($appointment->accession_number);
        $accessionNumber = $appointment->accession_number
            ?: $this->generateAccessionNumber($appointment);

        $procedureSteps = $this->buildScheduledProcedureSteps($appointment, $accessionNumber);
        if ($procedureSteps === []) {
            return response()->json([
                'success' => false,
                'message' => 'La cita no tiene sala/equipo asignado. Asigne la modalidad en Agenda antes de enviar la worklist.',
            ], 422);
        }

        $primaryMachine = $this->resolveStudyMachine($appointment->studies->first(), $appointment)
            ?? $appointment->machine;

        try {
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
            $tags['StudyInstanceUID'] = $this->resolveMwlStudyInstanceUid($appointment, $accessionNumber);
            $appointment->study_instance_uid = $tags['StudyInstanceUID'];

            $tags = app(WorklistTagNormalizer::class)->normalize(
                $tags,
                $procedureSteps,
                $accessionNumber,
                OrthancUrl::worklistProvider(),
                OrthancUrl::orthancUsesFiles(),
            );

            $mwlProvider = OrthancUrl::worklistProvider();
            $usesLocalFiles = $mwlProvider === 'wlmscpfs' || OrthancUrl::orthancUsesFiles();
            $orthancBase = $usesLocalFiles
                ? ($mwlProvider === 'wlmscpfs' ? 'wlmscpfs://local' : 'orthanc-files://local')
                : OrthancUrl::worklistBase();

            if ($usesLocalFiles) {
                app(LocalMwlFileWriter::class)->write($tags, $accessionNumber);
                if (!app(LocalMwlFileWriter::class)->verifyPresent($accessionNumber)) {
                    throw new \Exception('No se pudo escribir la worklist local (.wl).');
                }
            } else {
                $orthancUrl = $orthancBase . '/worklists/create';
                $primaryStep = $procedureSteps[0];
                $stationAe = (string) ($primaryStep['ScheduledStationAETitle'] ?? '');
                $stepDate = (string) ($primaryStep['ScheduledProcedureStepStartDate'] ?? '');
                if ($this->isFujiFcrStation($stationAe)) {
                    $this->purgeOrthancWorklistsForStation($orthancBase, $stationAe, $accessionNumber, $stepDate);
                } else {
                    $this->purgeOrthancWorklistsForAccession($orthancBase, $accessionNumber);
                }

                $response = Http::timeout(20)
                    ->acceptJson()
                    ->asJson()
                    ->post($orthancUrl, ['Tags' => $tags]);

                if (!$response->successful()) {
                    throw new \Exception(
                        'Orthanc Worklist falló (' . $response->status() . ') en ' . $orthancBase . ': '
                        . $response->body()
                    );
                }

                if (!$this->verifyOrthancWorklistPresent($orthancBase, $accessionNumber)) {
                    throw new \Exception(
                        'Orthanc aceptó la worklist pero ya no aparece en el PACS. '
                        . 'Suele ocurrir si el PACS tiene «DeleteWorklistsOnStableStudy» o «DeleteWorklistsDelay» '
                        . 'y el accession ya tiene estudio, o si la orden expiró. Pida a sistemas desactivar el borrado automático o reenvíe el mismo día del examen.'
                    );
                }
            }

            $appointment->status = 'dicom_enviado';
            $appointment->accession_number = $accessionNumber;
            $appointment->save();

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            if (LaboratoryMwlRelay::shouldRelayFromCloud($appointment->laboratory)) {
                RelayWorklistToLocalLab::dispatch($appointment->id);
            }

            $dicom = OrthancUrl::worklistDicomTarget();
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
    private function buildScheduledProcedureSteps(Appointment $appointment, string $accessionNumber): array
    {
        $dicomImport = app(DicomImportService::class);
        $start = $appointment->start_time->copy()->timezone(LabTimezone::name());
        $startDate = $start->format('Ymd');
        $startTime = $start->format('His');
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
                'ScheduledProcedureStepID' => WorklistTagNormalizer::compactStepId($accessionNumber, (string) $appointment->id),
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
                'ScheduledProcedureStepID' => WorklistTagNormalizer::compactStepId($accessionNumber, (string) $study->id),
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
     * StudyInstanceUID estable para MWL (consolas legacy exigen UID válido con último componente impar).
     */
    private function resolveMwlStudyInstanceUid(Appointment $appointment, string $accessionNumber): string
    {
        $existing = trim((string) ($appointment->study_instance_uid ?? ''));
        if ($existing !== '' && preg_match('/^1\.2\./', $existing) === 1) {
            return $existing;
        }

        $seed = strtoupper(preg_replace('/[^A-Z0-9]/', '', $accessionNumber) ?: (string) $appointment->id);
        $suffix = (int) sprintf('%u', crc32($seed . 'MWL'));
        if ($suffix % 2 === 0) {
            $suffix++;
        }

        return "1.2.826.0.1.3680043.8.498.{$suffix}";
    }

    private function isFujiFcrStation(string $stationAe): bool
    {
        return str_starts_with(strtoupper(trim($stationAe)), 'FCR_');
    }

    private function truncateDicomShortString(string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '' : substr($trimmed, 0, $maxLength);
    }

    private function mwlAccessionMatches(string $storedAccession, string $fullAccession): bool
    {
        $stored = trim($storedAccession);
        $full = trim($fullAccession);

        return $stored !== '' && ($stored === $full || $stored === WorklistTagNormalizer::compactAccession($full));
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
                if (!$this->mwlAccessionMatches($existingAccession, $accessionNumber) || empty($item['ID'])) {
                    continue;
                }

                Http::timeout(10)->acceptJson()->delete($orthancBase . '/worklists/' . $item['ID']);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo purgar worklist previa en Orthanc: ' . $e->getMessage());
        }
    }

    /**
     * Elimina worklists previas de la misma estación Fuji FCR.
     * Orthanc/Fuji CR suele devolver solo el primer Pending; entradas viejas bloquean la lista.
     *
     * @see https://groups.google.com/g/orthanc-users/c/BBlJd_o7864
     */
    private function purgeOrthancWorklistsForStation(
        string $orthancBase,
        string $stationAe,
        string $keepAccession,
        string $stepDate
    ): void {
        try {
            $response = Http::timeout(15)->acceptJson()->get($orthancBase . '/worklists');
            if (!$response->successful()) {
                return;
            }

            $station = strtoupper(trim($stationAe));
            foreach ($response->json() as $item) {
                if (empty($item['ID'])) {
                    continue;
                }

                $tags = $item['Tags'] ?? [];
                $existingAccession = (string) ($tags['AccessionNumber'] ?? '');
                $existingStation = strtoupper(trim((string) ($tags['ScheduledStationAETitle'] ?? '')));
                if ($existingStation === '' && !empty($tags['ScheduledProcedureStepSequence'][0])) {
                    $existingStation = strtoupper(trim((string) ($tags['ScheduledProcedureStepSequence'][0]['ScheduledStationAETitle'] ?? '')));
                }
                $existingDate = (string) ($tags['ScheduledProcedureStepStartDate'] ?? '');
                if ($existingDate === '' && !empty($tags['ScheduledProcedureStepSequence'][0])) {
                    $existingDate = (string) ($tags['ScheduledProcedureStepSequence'][0]['ScheduledProcedureStepStartDate'] ?? '');
                }

                if ($existingStation !== $station) {
                    continue;
                }

                $isCurrent = $this->mwlAccessionMatches($existingAccession, $keepAccession) && $existingDate === $stepDate;
                if ($isCurrent) {
                    continue;
                }

                Http::timeout(10)->acceptJson()->delete($orthancBase . '/worklists/' . $item['ID']);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo purgar worklists de estación en Orthanc: ' . $e->getMessage());
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
            ? "Use la IP DICOM «{$dicomHost}» (no «{$httpHost}») en el FCR Console."
            : "Host DICOM del FCR Console: «{$dicomHost}».";

        $dateHint = $procedureSteps[0]['ScheduledProcedureStepStartDate'] ?? '';
        $dateDisplay = $dateHint !== ''
            ? \Carbon\Carbon::createFromFormat('Ymd', $dateHint, LabTimezone::name())->format('d.m.Y')
            : '';
        $todayHint = \Carbon\Carbon::now(LabTimezone::name())->format('Ymd');
        $todayDisplay = \Carbon\Carbon::now(LabTimezone::name())->format('d.m.Y');
        $dateWarning = $dateHint !== '' && $dateHint !== $todayHint
            ? " Hoy en el centro es {$todayDisplay}: el FCR Console suele consultar la fecha del día; en el Fuji ingrésela como {$todayDisplay} (día.mes.año); si no la cambia, no verá citas del {$dateDisplay}."
            : '';

        $mwlLocal = !empty($dicom['local']);
        $provider = OrthancUrl::worklistProvider();
        $fcrHint = $this->isFujiFcrStation($stationAe)
            ? ($mwlLocal
                ? ($provider === 'wlmscpfs'
                    ? ' MWL local DCMTK (wlmscpfs): FCR Console → worklist «' . $dicom['host'] . '»:' . $dicom['port'] . ', AE destino «' . $dicom['aet'] . '». Local AE = «' . $stationAe . '». Broad Query OBLIGATORIO: estación «' . $stationAe . '», modalidad «' . $modality . '», fecha «' . ($dateDisplay !== '' ? $dateDisplay : $dateHint) . '» (día.mes.año). Si el paciente tiene otro examen el mismo día (mamo/eco), sin esos filtros el FCR recibe el estudio equivocado primero. Alternativa: Accession «' . ($procedureSteps[0]['ScheduledProcedureStepID'] ?? '') . '» o Patient ID en el FCR.'
                    : ' MWL local Orthanc LAN: worklist «' . $dicom['host'] . '»:' . $dicom['port'] . ', AE «' . $dicom['aet'] . '». Imágenes al PACS nube (HEALTHTICLOUD).')
                : ' CRÍTICO FCR Console: Local AE Title = «' . $stationAe . '» exacto (si el equipo tiene otro AE, ej. FCR, el PACS nube rechaza con Find Failed). '
                    . 'Remote AE (called) = «' . $dicom['aet'] . '». Broad Query: fecha «' . ($dateDisplay !== '' ? $dateDisplay : $dateHint) . '» (día.mes.año), modalidad «' . $modality . '», estación «' . $stationAe . '». '
                    . 'Alternativa: Patient ID + Accession en el FCR.')
            : '';

        return ($mwlLocal
            ? ($provider === 'wlmscpfs'
                ? 'La orden quedó en el servidor MWL local (DCMTK wlmscpfs). '
                : 'La orden quedó en el servidor MWL local (Orthanc LAN). ')
            : 'La orden quedó en el PACS. ')
            . 'El Fuji FCR Console la baja por DICOM MWL (C-FIND), no por la web. '
            . "{$hostHint} Puerto {$dicom['port']}, AE destino «{$dicom['aet']}». "
            . "Filtros MWL: estación «{$stationAe}», modalidad «{$modality}», fecha «" . ($dateDisplay !== '' ? $dateDisplay : $dateHint) . "» (día.mes.año en FCR; DICOM «{$dateHint}»)."
            . $dateWarning
            . $fcrHint
            . ' Reenvíe la worklist el mismo día del examen si el PACS borra órdenes antiguas.';
    }

    private function worklistTimezone(): string
    {
        return LabTimezone::name();
    }

    private function verifyOrthancWorklistPresent(string $orthancBase, string $accessionNumber): bool
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get($orthancBase . '/worklists');
            if (!$response->successful()) {
                return true;
            }

            foreach ($response->json() ?? [] as $item) {
                if ($this->mwlAccessionMatches((string) ($item['Tags']['AccessionNumber'] ?? ''), $accessionNumber)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo verificar worklist en Orthanc: ' . $e->getMessage());

            return true;
        }

        return false;
    }
}