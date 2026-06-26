<?php

namespace App\Services;

use App\Jobs\SendHl7OruJob;
use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Exam;
use App\Models\Hl7Message;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\RisHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Hl7IntegrationService
{
    public function __construct(protected Hl7Parser $parser)
    {
    }

    public function processInbound(string $raw, string $labId): array
    {
        $parsed = $this->parser->parse($raw);
        $messageType = strtoupper($parsed['message_type'] ?? '');

        $hl7 = Hl7Message::create([
            'laboratory_id' => $labId,
            'message_control_id' => $parsed['message_control_id'],
            'message_type' => $messageType ?: 'UNKNOWN',
            'direction' => 'inbound',
            'raw_message' => $raw,
            'status' => 'recibido',
            'placer_order_id' => $parsed['order']['placer_order_id'] ?? null,
        ]);

        if (!str_contains($messageType, 'ORM')) {
            $hl7->update([
                'status' => 'ignorado',
                'error_log' => 'Tipo de mensaje no soportado para auto-agenda.',
            ]);

            return [
                'success' => true,
                'message' => 'Mensaje almacenado (tipo no procesado automáticamente).',
                'hl7_message_id' => $hl7->id,
            ];
        }

        try {
            $appointment = DB::transaction(function () use ($parsed, $labId, $hl7) {
                return $this->createAppointmentFromOrder($parsed, $labId, $hl7);
            });

            $hl7->update([
                'status' => 'procesado',
                'appointment_id' => $appointment->id,
            ]);

            return [
                'success' => true,
                'message' => 'Orden HL7 procesada.',
                'appointment_id' => $appointment->id,
                'hl7_message_id' => $hl7->id,
            ];
        } catch (\Throwable $e) {
            $hl7->update([
                'status' => 'error',
                'error_log' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function queueOruForAppointment(Appointment $appointment): void
    {
        if (!config('hl7.send_oru_on_sign')) {
            return;
        }

        SendHl7OruJob::dispatch($appointment->id);
    }

    public function sendOruForAppointment(string $appointmentId): ?Hl7Message
    {
        $appointment = Appointment::with(['patient.persona', 'studies.exam'])->findOrFail($appointmentId);
        $study = $appointment->studies->first();
        $persona = $appointment->patient?->persona;

        $parsedName = trim(($persona?->last_name_1 ?? '') . '^' . ($persona?->names ?? ''));

        $raw = $this->parser->buildOru([
            'message_control_id' => 'ORU' . Str::upper(Str::substr($appointment->id, 0, 8)),
            'patient_id' => $persona?->rut ?? '',
            'patient_name' => $parsedName,
            'placer_order_id' => $appointment->accession_number ?? $appointment->id,
            'service_code' => $study?->fonasa_code ?? $study?->exam?->fonasa_code ?? '',
            'service_name' => $study?->exam_name ?? $study?->exam?->name ?? 'Estudio',
            'report_text' => $study?->report ?? '',
        ]);

        $hl7 = Hl7Message::create([
            'laboratory_id' => $appointment->laboratory_id,
            'appointment_id' => $appointment->id,
            'message_control_id' => 'ORU-' . $appointment->id,
            'message_type' => 'ORU^R01',
            'direction' => 'outbound',
            'raw_message' => $raw,
            'status' => 'pendiente_envio',
            'placer_order_id' => $appointment->accession_number,
        ]);

        $url = config('hl7.outbound_url');
        if (!$url) {
            $hl7->update(['status' => 'almacenado']);

            return $hl7;
        }

        $http = RisHttp::client(15)->withHeaders([
            'Content-Type' => 'application/hl7-v2',
            'X-HL7-Secret' => config('hl7.outbound_secret'),
        ]);

        $response = $http->withBody($raw, 'application/hl7-v2')->post($url);

        $hl7->update([
            'status' => $response->successful() ? 'enviado' : 'error',
            'error_log' => $response->successful() ? null : $response->body(),
        ]);

        return $hl7;
    }

    protected function createAppointmentFromOrder(array $parsed, string $labId, Hl7Message $hl7): Appointment
    {
        $patientData = $parsed['patient'] ?? [];
        $order = $parsed['order'] ?? [];

        if (empty($patientData['rut'])) {
            throw new \InvalidArgumentException('PID-3 (RUT/ID paciente) es obligatorio.');
        }

        $persona = Persona::upsertByRut($patientData['rut'], [
            'names' => $patientData['names'] ?? 'Paciente',
            'last_name_1' => $patientData['last_name_1'] ?? 'HL7',
            'last_name_2' => null,
            'gender' => $patientData['gender'] ?? null,
            'birth_date' => $patientData['birth_date'] ?? null,
        ]);

        $patient = Paciente::firstOrCreate(
            ['persona_id' => $persona->id, 'laboratory_id' => $labId],
            ['persona_id' => $persona->id, 'laboratory_id' => $labId]
        );

        $machine = Machine::where('laboratory_id', $labId)
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        if (!$machine) {
            throw new \RuntimeException('No hay salas activas en el laboratorio.');
        }

        $start = $order['scheduled_at']
            ? Carbon::parse($order['scheduled_at'])
            : now()->addHour()->startOfHour();

        $end = $start->copy()->addMinutes(30);

        $exam = null;
        if (!empty($order['service_code'])) {
            $exam = Exam::where('laboratory_id', $labId)
                ->where(function ($q) use ($order) {
                    $q->where('fonasa_code', $order['service_code'])
                        ->orWhere('group_code', $order['service_code']);
                })
                ->first();
        }

        $accession = $order['placer_order_id'] ?: ('HL7-' . Str::upper(Str::substr($hl7->id, 0, 8)));

        $appointment = Appointment::create([
            'laboratory_id' => $labId,
            'patient_id' => $patient->id,
            'machine_id' => $machine->id,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'agendado',
            'origin' => 'Hospitalizado',
            'priority' => 'Normal',
            'payment_status' => 'Pendiente',
            'accession_number' => $accession,
        ]);

        AppointmentStudy::create([
            'appointment_id' => $appointment->id,
            'machine_id' => $machine->id,
            'exam_id' => $exam?->id,
            'exam_name' => $exam?->name ?? ($order['service_name'] ?: 'Orden HL7'),
            'fonasa_code' => $exam?->fonasa_code ?? $order['service_code'],
            'quantity' => 1,
            'price' => $exam?->price ?? 0,
            'status' => 'agendado',
        ]);

        return $appointment->load(['patient.persona', 'studies']);
    }
}
