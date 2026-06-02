<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\AppointmentLog;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Supply;
use App\Services\KeycloakService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\PortalCredentialsMail;
use App\Observers\AppointmentObserver;
use App\Services\AppointmentInstructionMailService;
use App\Services\AppointmentNotificationService;

class AppointmentController extends Controller
{
    protected $keycloakService;

    public function __construct(
        KeycloakService $keycloakService,
        protected AppointmentInstructionMailService $instructionMailService,
        protected AppointmentNotificationService $notificationService,
    ) {
        $this->keycloakService = $keycloakService;
    }

    private function getSecureQuery()
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
        $appointments = $this->getSecureQuery()
            ->with([
                'patient.persona',
                'machine',
                'studies.exam',
                'studies.machine',
                'studies.radiologist',
                'referringDoctor',
                'destinationDoctor.persona',
                'supplies',
                'insurance',
                'insurancePlan'
            ])
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $appointments
        ]);
    }

    public function show(string $id)
    {
        $appointment = $this->getSecureQuery()
            ->with([
                'patient.persona',
                'machine',
                'studies.exam',
                'studies.machine',
                'studies.radiologist',
                'referringDoctor',
                'destinationDoctor.persona',
                'supplies',
                'insurance',
                'insurancePlan',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $appointment,
        ]);
    }

    public function store(Request $request)
    {
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId)
            return response()->json(['success' => false, 'message' => 'Falta identificador de sucursal.'], 400);

        AppointmentObserver::$suppressRelatedSync = true;

        try {
            return DB::transaction(function () use ($request, $labId) {
                // Decodificamos el JSON que viene dentro del FormData
                $data = json_decode($request->input('data'), true);

                // === 1. VALIDACIÓN DE CHOQUE DE HORARIOS EN BACKEND ===
                $start = \Carbon\Carbon::parse($data['start_time']);
                $end = \Carbon\Carbon::parse($data['end_time']);

                $choque = Appointment::where('machine_id', $data['machine_id'])
                    ->whereIn('status', ['agendado', 'confirmado', 'espera'])
                    ->where(function ($q) use ($start, $end) {
                        $q->where('start_time', '<', $end)->where('end_time', '>', $start);
                    })->exists();

                if ($choque) {
                    throw new \Exception("La sala ya tiene una reserva confirmada en ese horario. Por favor actualice su calendario.");
                }

                $patientData = $data['patient'];
                $cleanRut = strtoupper(str_replace(['.', ' '], '', $patientData['rut']));

                $persona = \App\Models\Persona::upsertByRut($cleanRut, [
                    'names' => $patientData['names'],
                    'last_name_1' => $patientData['last_name_1'],
                    'last_name_2' => $patientData['last_name_2'] ?? null,
                    'gender' => $patientData['gender'] ?? null,
                    'birth_date' => $patientData['birth_date'] ?? null,
                    'email' => $patientData['email'] ?? null,
                    'phone' => $patientData['phone'] ?? null,
                ]);

                $patient = \App\Models\Paciente::firstOrCreate(
                    ['persona_id' => $persona->id, 'laboratory_id' => $labId],
                    ['persona_id' => $persona->id, 'laboratory_id' => $labId]
                );

                // === 2. ARCHIVOS NATIVOS (FORM DATA) ===
                $ordenPath = null;
                if ($request->hasFile('order_file')) {
                    $ordenPath = '/storage/' . $request->file('order_file')->store('documents', 'public');
                }
                $encuestaPath = null;
                if ($request->hasFile('survey_file')) {
                    $encuestaPath = '/storage/' . $request->file('survey_file')->store('documents', 'public');
                }

                // === 3. CREACIÓN DE LA CITA ===
                $appointment = Appointment::create([
                    'laboratory_id' => $labId,
                    'patient_id' => $patient->id,
                    'machine_id' => $data['machine_id'],
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => strtolower($data['status']),
                    'referring_doctor_id' => $this->nullableUuid($data['referring_doctor_id'] ?? null),
                    'destination_doctor_id' => $this->nullableUuid($data['destination_doctor_id'] ?? null),
                    'payment_method' => $data['payment_method'] ?? null,
                    'payment_status' => $data['payment_status'] ?? 'Pendiente',
                    'transaction_code' => $data['transaction_code'] ?? null,
                    'tipo_bono' => $data['tipo_bono'] ?? null,
                    'entidad_pagadora' => $data['entidad_pagadora'] ?? null,
                    'insurance_id' => $this->nullableUuid($patientData['insurance_id'] ?? null),
                    'insurance_plan_id' => $this->nullableUuid($patientData['insurance_plan_id'] ?? null),
                    'priority' => $data['priority'] ?? 'Normal',
                    'origin' => $data['origin'] ?? 'Ambulatorio',
                    'medical_order_path' => $ordenPath,
                    'survey_path' => $encuestaPath,
                ]);

                // === 🔥 4. GUARDADO DE ESTUDIOS (¡Lo que faltaba!) 🔥 ===
                if (isset($data['studies']) && is_array($data['studies'])) {
                    foreach ($data['studies'] as $studyData) {
                        \App\Models\AppointmentStudy::create([
                            'appointment_id' => $appointment->id,
                            'machine_id' => $studyData['machine_id'] ?? $appointment->machine_id,
                            'exam_id' => $studyData['exam_id'],
                            'exam_name' => $studyData['exam_name'],
                            'sub_exam_id' => $studyData['sub_exam_id'] ?? null,
                            'sub_exam_name' => $studyData['sub_exam_name'] ?? null,
                            'fonasa_code' => $studyData['fonasa_code'] ?? null,
                            'quantity' => $studyData['quantity'] ?? 1,
                            'price_charged' => $studyData['price'] ?? 0,
                            'status' => strtolower($data['status'])
                        ]);
                    }
                }

                // === 🔥 5. GUARDADO DE INSUMOS Y DESCUENTO DE STOCK 🔥 ===
                if (isset($data['supplies']) && is_array($data['supplies'])) {
                    foreach ($data['supplies'] as $sup) {
                        $appointment->supplies()->attach($sup['id'], [
                            'quantity' => $sup['quantity'],
                            'price_charged' => $sup['price'] ?? 0
                        ]);
                        $supply = \App\Models\Supply::lockForUpdate()->find($sup['id']);
                        if ($supply) {
                            $supply->decrement('stock', $sup['quantity']);
                        }
                    }
                }

                $appointment->load(['patient.persona', 'studies', 'supplies']);

                $mailResult = $this->instructionMailService->sendIfApplicable($appointment);
                $confirmationResult = $this->notificationService->sendConfirmation($appointment);

                return response()->json([
                    'success' => true,
                    'appointment' => $appointment,
                    'instructions_email' => $mailResult,
                    'confirmation_email' => $confirmationResult,
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        } finally {
            AppointmentObserver::$suppressRelatedSync = false;
        }
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            // Usa tu propia función o Appointment::findOrFail
            $appointment = Appointment::with(['studies', 'supplies', 'patient.persona'])->findOrFail($id);

            // === LÓGICA DE DRAG & DROP (El JS lo manda como JSON directo) ===
            if ($request->has('is_drag_and_drop')) {
                $appointment->update([
                    'machine_id' => $request->machine_id ?? $request->machine,
                    'start_time' => \Carbon\Carbon::parse($request->start_time ?? $request->start),
                    'end_time' => \Carbon\Carbon::parse($request->end_time ?? $request->end),
                ]);

                $appointment->studies()->update(['machine_id' => $request->machine_id ?? $request->machine]);

                \App\Models\AppointmentLog::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => auth()->id(),
                    'action' => 'reagendado (drag&drop)',
                    'ip_address' => request()->ip()
                ]);

                \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
                return response()->json(['success' => true]);
            }

            // === LÓGICA DE EDICIÓN COMPLETA (FormData) ===
            // Decodificamos el string JSON a un array de PHP
            $data = json_decode($request->input('data'), true);
            $patientData = $data['patient'] ?? [];

            // Manejo de archivos (mantiene el viejo si no se sube uno nuevo)
            $ordenPath = $appointment->medical_order_path;
            if ($request->hasFile('order_file')) {
                $ordenPath = '/storage/' . $request->file('order_file')->store('documents', 'public');
            }
            $encuestaPath = $appointment->survey_path;
            if ($request->hasFile('survey_file')) {
                $encuestaPath = '/storage/' . $request->file('survey_file')->store('documents', 'public');
            }

            $appointment->update([
                'machine_id' => $data['machine_id'],
                'start_time' => \Carbon\Carbon::parse($data['start_time']),
                'end_time' => \Carbon\Carbon::parse($data['end_time']),
                'status' => strtolower($data['status']),
                'referring_doctor_id' => $this->nullableUuid($data['referring_doctor_id'] ?? null),
                'destination_doctor_id' => $this->nullableUuid($data['destination_doctor_id'] ?? null),
                'priority' => $data['priority'] ?? 'Normal',
                'origin' => $data['origin'] ?? 'Ambulatorio',
                'payment_method' => $data['payment_method'] ?? null,
                'transaction_code' => $data['transaction_code'] ?? null,
                'payment_status' => $data['payment_status'] ?? $appointment->payment_status,
                'tipo_bono' => $data['tipo_bono'] ?? $appointment->tipo_bono,
                'entidad_pagadora' => $data['entidad_pagadora'] ?? $appointment->entidad_pagadora,
                'insurance_id' => $this->nullableUuid($patientData['insurance_id'] ?? null) ?? $appointment->insurance_id,
                'insurance_plan_id' => $this->nullableUuid($patientData['insurance_plan_id'] ?? null) ?? $appointment->insurance_plan_id,
                'medical_order_path' => $ordenPath,
                'survey_path' => $encuestaPath,
            ]);

            // Recreamos los estudios
            $appointment->studies()->delete();
            if (isset($data['studies']) && is_array($data['studies'])) {
                foreach ($data['studies'] as $studyData) {
                    \App\Models\AppointmentStudy::create([
                        'appointment_id' => $appointment->id,
                        'machine_id' => $studyData['machine_id'] ?? $appointment->machine_id,
                        'exam_id' => $studyData['exam_id'],
                        'exam_name' => $studyData['exam_name'],
                        'sub_exam_id' => $studyData['sub_exam_id'] ?? null,
                        'sub_exam_name' => $studyData['sub_exam_name'] ?? null,
                        'fonasa_code' => $studyData['fonasa_code'] ?? null,
                        'quantity' => $studyData['quantity'] ?? 1,
                        'price_charged' => $studyData['price'] ?? 0,
                        'status' => strtolower($data['status'])
                    ]);
                }
            }

            // Recreamos los insumos
            if (isset($data['supplies']) && is_array($data['supplies'])) {
                foreach ($appointment->supplies as $oldSupply) {
                    $oldSupply->increment('stock', $oldSupply->pivot->quantity);
                }
                $appointment->supplies()->detach();

                foreach ($data['supplies'] as $sup) {
                    $appointment->supplies()->attach($sup['id'], [
                        'quantity' => $sup['quantity'],
                        'price_charged' => $sup['price'] ?? 0
                    ]);
                    $supply = \App\Models\Supply::lockForUpdate()->find($sup['id']);
                    if ($supply) {
                        $supply->decrement('stock', $sup['quantity']);
                    }
                }
            }

            if (!empty($patientData)) {
                $appointment->patient->persona->update([
                    'names' => $patientData['names'],
                    'last_name_1' => $patientData['last_name_1'],
                    'last_name_2' => $patientData['last_name_2'] ?? null,
                    'gender' => $patientData['gender'] ?? null,
                    'birth_date' => $patientData['birth_date'] ?? null,
                    'email' => $patientData['email'] ?? null,
                    'phone' => $patientData['phone'] ?? null,
                ]);
            }

            \App\Models\AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => auth()->id(),
                'action' => 'editado',
                'ip_address' => request()->ip()
            ]);

            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Persona', 'updated', $appointment->patient->persona->toArray());
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

            return response()->json(['success' => true]);
        });
    }

    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            // Usa tu propia función o Appointment::findOrFail
            $appointment = Appointment::with('supplies')->findOrFail($id);

            foreach ($appointment->supplies as $supply) {
                $supply->increment('stock', $supply->pivot->quantity);
            }

            \App\Models\AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => auth()->id(),
                'action' => 'anulado',
                'ip_address' => request()->ip()
            ]);

            \App\Services\AuditLogger::record(
                'appointment.cancelled',
                'Appointment',
                $appointment->id,
                ['status' => $appointment->status],
            );

            $appointment->status = 'anulado';
            $appointment->save();
            $appointment->delete();

            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'deleted', ['id' => $id]);

            return response()->json(['success' => true]);
        });
    }

    private function saveBase64Document($base64String, $folder = 'documents')
    {
        if (!$base64String || !str_starts_with($base64String, 'data:')) {
            return null;
        }

        $parts = explode(';', $base64String);
        if (count($parts) < 2)
            return null;

        $mimePart = explode(':', $parts[0]);
        $mimeType = $mimePart[1] ?? '';

        $dataPart = explode(',', $parts[1]);
        $fileData = isset($dataPart[1]) ? base64_decode($dataPart[1]) : null;

        if (!$fileData)
            return null;

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf'
        ];

        if (!array_key_exists($mimeType, $allowedMimes)) {
            abort(422, 'Tipo de archivo no permitido. Solo se aceptan JPG, PNG y PDF.');
        }

        $extension = $allowedMimes[$mimeType];
        $fileName = Str::uuid() . '.' . $extension;
        $path = $folder . '/' . $fileName;

        Storage::disk('public')->put($path, $fileData);

        return '/storage/' . $path;
    }

    public function clearReview(Request $request, $id)
    {
        $appointment = $this->getSecureQuery()->findOrFail($id);

        $appointment->needs_review = false;
        $appointment->return_reason = null;
        $appointment->save();

        // ☁️ Sincronizar el cambio rápido a la nube
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

        return response()->json(['success' => true]);
    }

    /** Convierte placeholders ("-", vacío) en null para columnas UUID. */
    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        return (string) $value;
    }
}