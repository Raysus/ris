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

class AppointmentController extends Controller
{
    protected $keycloakService;

    public function __construct(KeycloakService $keycloakService)
    {
        $this->keycloakService = $keycloakService;
    }

    private function getSecureQuery()
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
        $appointments = $this->getSecureQuery()
            ->with([
                'patient.persona',
                'studies.machine',
                'supplies'
            ])
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $appointments
        ]);
    }

    public function store(Request $request)
    {
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId)
            return response()->json(['success' => false, 'message' => 'Falta identificador de sucursal.'], 400);

        try {
            return DB::transaction(function () use ($request, $labId) {
                // Decodificamos el JSON que viene dentro del FormData
                $data = json_decode($request->input('data'), true);

                // === 1. VALIDACIÓN DE CHOQUE DE HORARIOS EN BACKEND ===
                $start = Carbon::parse($data['start_time']);
                $end = Carbon::parse($data['end_time']);

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

                // (Mantén tu lógica de Persona y Keycloak aquí... igual que tu archivo original)
                $persona = Persona::updateOrCreate(
                    ['rut' => $patientData['rut']],
                    [
                        'names' => $patientData['names'],
                        'last_name_1' => $patientData['last_name_1'],
                        'email' => $patientData['email'] ?? null,
                        // ...
                    ]
                );
                $patient = Paciente::firstOrCreate(['persona_id' => $persona->id, 'laboratory_id' => $labId]);

                // === 2. ARCHIVOS NATIVOS (FORM DATA) ===
                $ordenPath = null;
                if ($request->hasFile('order_file')) {
                    $ordenPath = '/storage/' . $request->file('order_file')->store('documents', 'public');
                }
                $encuestaPath = null;
                if ($request->hasFile('survey_file')) {
                    $encuestaPath = '/storage/' . $request->file('survey_file')->store('documents', 'public');
                }

                $appointment = Appointment::create([
                    'laboratory_id' => $labId,
                    'patient_id' => $patient->id,
                    'machine_id' => $data['machine_id'],
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => strtolower($data['status']),
                    'referring_doctor_id' => $data['referring_doctor_id'],
                    'destination_doctor_id' => $data['destination_doctor_id'],
                    'payment_method' => $data['payment_method'],
                    'payment_status' => $data['payment_status'] ?? 'Pendiente', // NUEVO CAMPO
                    // ... otros campos
                    'medical_order_path' => $ordenPath,
                    'survey_path' => $encuestaPath,
                ]);

                // Guardar Estudios e Insumos (Misma lógica original iterando sobre $data['studies'] y $data['supplies'])

                // === 3. NOTIFICACIÓN AUTOMÁTICA ===
                if (!empty($patientData['email'])) {
                    try {
                        // Aquí enviarías tu correo con las indicaciones
                        // Mail::to($patientData['email'])->send(new AppointmentConfirmationMail($appointment));
                    } catch (\Exception $e) {
                        \Log::error("Error enviando email confirmación: " . $e->getMessage());
                    }
                }

                $appointment->load(['patient.persona', 'studies', 'supplies']);
                \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

                return response()->json(['success' => true, 'appointment' => $appointment], 201);
            });

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $appointment = $this->getSecureQuery()
                ->with(['studies', 'supplies', 'patient.persona'])
                ->findOrFail($id);

            // === LÓGICA DE DRAG & DROP ===
            if ($request->has('is_drag_and_drop')) {
                $appointment->update([
                    'machine_id' => $request->machine_id ?? $request->machine,
                    'start_time' => Carbon::parse($request->start_time ?? $request->start),
                    'end_time' => Carbon::parse($request->end_time ?? $request->end),
                ]);

                $appointment->studies()->update(['machine_id' => $request->machine_id ?? $request->machine]);

                AppointmentLog::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => auth()->id(),
                    'action' => 'reagendado (drag&drop)',
                    'ip_address' => request()->ip()
                ]);

                // === ☁️ SINCRONIZACIÓN (SOLO FECHAS) ☁️ ===
                \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());

                return response()->json(['success' => true]);
            }

            // === LÓGICA DE EDICIÓN COMPLETA ===
            $patientData = $request->patient;
            $ordenPath = $this->saveBase64Document($request->order_image);
            $encuestaPath = $this->saveBase64Document($request->survey_image);

            $appointment->update([
                'machine_id' => $request->machine_id,
                'start_time' => Carbon::parse($request->start_time),
                'end_time' => Carbon::parse($request->end_time),
                'status' => strtolower($request->status),
                'referring_doctor_id' => $request->referring_doctor_id,
                'destination_doctor_id' => $request->destination_doctor_id,
                'priority' => $request->priority ?? 'Normal',
                'origin' => $request->origin ?? 'Ambulatorio',
                'payment_method' => $request->payment_method,
                'transaction_code' => $request->transaction_code,
                'tipo_bono' => $request->tipo_bono ?? $appointment->tipo_bono,
                'entidad_pagadora' => $request->entidad_pagadora ?? $appointment->entidad_pagadora,
                'insurance_id' => $patientData['insurance_id'] ?? $appointment->insurance_id,
                'insurance_plan_id' => $patientData['insurance_plan_id'] ?? $appointment->insurance_plan_id,
                'medical_order_path' => $ordenPath ?? $appointment->medical_order_path,
                'survey_path' => $encuestaPath ?? $appointment->survey_path,
            ]);

            $appointment->studies()->delete();
            if (isset($request->studies) && is_array($request->studies)) {
                foreach ($request->studies as $studyData) {
                    AppointmentStudy::create([
                        'appointment_id' => $appointment->id,
                        'machine_id' => $studyData['machine_id'] ?? $appointment->machine_id,
                        'exam_id' => $studyData['exam_id'] ?? null,
                        'exam_name' => $studyData['exam_name'] ?? null,
                        'sub_exam_id' => $studyData['sub_exam_id'] ?? null,
                        'sub_exam_name' => $studyData['sub_exam_name'] ?? null,
                        'fonasa_code' => $studyData['fonasa_code'] ?? null,
                        'quantity' => $studyData['quantity'] ?? 1,
                        'price' => $studyData['price'] ?? 0,
                        'status' => $appointment->status
                    ]);
                }
            }

            if (isset($request->supplies) && is_array($request->supplies)) {
                foreach ($appointment->supplies as $oldSupply) {
                    $oldSupply->increment('stock', $oldSupply->pivot->quantity);
                }
                $appointment->supplies()->detach();

                foreach ($request->supplies as $sup) {
                    $appointment->supplies()->attach($sup['id'], [
                        'quantity' => $sup['quantity'],
                        'price' => $sup['price']
                    ]);
                    $supply = Supply::lockForUpdate()->find($sup['id']);
                    if ($supply) {
                        $supply->decrement('stock', $sup['quantity']);
                    }
                }
            }

            if ($patientData) {
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

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => auth()->id(),
                'action' => 'editado',
                'ip_address' => request()->ip()
            ]);

            // === ☁️ INICIO SINCRONIZACIÓN CON LA NUBE (VÍA REDIS) ☁️ ===
            // Refrescamos la memoria de Laravel para enviar los estudios y suministros nuevos
            $appointment->load(['patient.persona', 'studies', 'supplies']);
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Persona', 'updated', $appointment->patient->persona->toArray());
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'updated', $appointment->toArray());
            // === FIN SINCRONIZACIÓN ===

            return response()->json(['success' => true]);
        });
    }

    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            $appointment = $this->getSecureQuery()
                ->with('supplies')
                ->findOrFail($id);

            foreach ($appointment->supplies as $supply) {
                $supply->increment('stock', $supply->pivot->quantity);
            }

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => auth()->id(),
                'action' => 'anulado',
                'ip_address' => request()->ip()
            ]);

            $appointment->status = 'anulado';
            $appointment->save();
            $appointment->delete();

            // === ☁️ INICIO SINCRONIZACIÓN DE LA ELIMINACIÓN ☁️ ===
            \App\Jobs\SyncEntityToCloud::dispatch('App\Models\Appointment', 'deleted', ['id' => $id]);
            // === FIN SINCRONIZACIÓN ===

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
}