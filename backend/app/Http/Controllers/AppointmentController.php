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

        if (!$labId) {
            return response()->json(['success' => false, 'message' => 'Falta identificador de sucursal.'], 400);
        }

        try {
            return DB::transaction(function () use ($request, $labId) {
                $patientData = $request->patient;

                $cleanRut = strtoupper(str_replace(['.', ' '], '', $patientData['rut']));

                $persona = Persona::where('rut', $patientData['rut'])->first();

                $needsSsoAccount = !$persona || !$persona->has_sso_account;

                $passwordTemp = null;

                if ($needsSsoAccount) {
                    $passwordTemp = substr($cleanRut, 0, 4);

                    try {
                        $this->keycloakService->createUser([
                            'username' => $cleanRut,
                            'nombres' => $patientData['names'],
                            'apellidos' => trim($patientData['last_name_1'] . ' ' . ($patientData['last_name_2'] ?? '')),
                            'password' => $passwordTemp,
                            'rut' => $cleanRut,
                            'email' => $patientData['email'] ?? null,
                        ]);



                    } catch (\Exception $e) {
                        if (str_contains($e->getMessage(), 'Status 409') || str_contains($e->getMessage(), 'exists')) {
                            \Log::warning("El usuario {$cleanRut} ya existía en Keycloak, sincronizando bandera local.");
                        } else {
                            abort(422, 'Error conectando con el Portal SSO: ' . $e->getMessage());
                        }
                    }
                }

                $persona = Persona::updateOrCreate(
                    ['rut' => $patientData['rut']],
                    [
                        'names' => $patientData['names'],
                        'last_name_1' => $patientData['last_name_1'],
                        'last_name_2' => $patientData['last_name_2'] ?? null,
                        'gender' => $patientData['gender'] ?? null,
                        'birth_date' => $patientData['birth_date'] ?? null,
                        'email' => $patientData['email'] ?? null,
                        'phone' => $patientData['phone'] ?? null,
                        'has_sso_account' => true,
                    ]
                );

                $patient = Paciente::firstOrCreate([
                    'persona_id' => $persona->id,
                    'laboratory_id' => $labId
                ]);

                $ordenPath = $this->saveBase64Document($request->order_image);
                $encuestaPath = $this->saveBase64Document($request->survey_image);

                $appointment = Appointment::create([
                    'laboratory_id' => $labId,
                    'patient_id' => $patient->id,
                    'machine_id' => $request->machine_id,
                    'start_time' => Carbon::parse($request->start_time),
                    'end_time' => Carbon::parse($request->end_time),
                    'status' => strtolower($request->status),
                    'referring_doctor_id' => $request->referring_doctor_id,
                    'destination_doctor_id' => $request->destination_doctor_id,
                    'priority' => $request->priority ?? 'Normal',
                    'origin' => $request->origin ?? 'Ambulatorio',
                    // Facturación
                    'payment_method' => $request->payment_method,
                    'transaction_code' => $request->transaction_code,
                    'tipo_bono' => $request->tipo_bono ?? null,
                    'entidad_pagadora' => $request->entidad_pagadora ?? null,
                    'insurance_id' => $patientData['insurance_id'] ?? null,
                    'insurance_plan_id' => $patientData['insurance_plan_id'] ?? null,
                    // Documentos
                    'medical_order_path' => $ordenPath,
                    'survey_path' => $encuestaPath,
                ]);

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

                AppointmentLog::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => auth()->id(),
                    'action' => 'creado',
                    'details' => ['status' => $request->status],
                    'ip_address' => request()->ip()
                ]);

                if ($needsSsoAccount && $passwordTemp && !empty($patientData['email'])) {
                    try {
                        Mail::to($patientData['email'])->send(
                            new PortalCredentialsMail($patientData['names'], $cleanRut, $passwordTemp)
                        );
                    } catch (\Exception $e) {
                        \Log::error("Error enviando credenciales a {$patientData['email']}: " . $e->getMessage());
                    }
                }
                return response()->json(['success' => true, 'appointment' => $appointment->load('patient.persona')], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
        }
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $appointment = $this->getSecureQuery()
                ->with(['studies', 'supplies', 'patient.persona'])
                ->findOrFail($id);

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

                return response()->json(['success' => true]);
            }

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

        return response()->json(['success' => true]);
    }
}