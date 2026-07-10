<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class AppointmentReceiptService
{
    public function __construct(
        private readonly ThermalEscPosPrinter $printer,
    ) {}

    public function isPrinted(Appointment $appointment): bool
    {
        return (bool) $appointment->receipt_printed;
    }

    public function markPrinted(Appointment $appointment): Appointment
    {
        if ($appointment->receipt_printed) {
            return $appointment;
        }

        $appointment->receipt_printed = true;
        $appointment->receipt_printed_at = now();
        $appointment->save();

        return $appointment;
    }

    /**
     * Imprime en Epson si aún no se imprimió. No lanza: el flujo DICOM no debe fallar por la térmica.
     *
     * @return array{attempted: bool, printed: bool, skipped: bool, message: ?string}
     */
    public function printIfNeeded(Appointment $appointment): array
    {
        if ($this->isPrinted($appointment)) {
            return [
                'attempted' => false,
                'printed' => true,
                'skipped' => true,
                'message' => 'Comprobante ya estaba marcado como impreso.',
            ];
        }

        if (!$this->printer->isConfigured()) {
            return [
                'attempted' => false,
                'printed' => false,
                'skipped' => true,
                'message' => 'Impresora térmica no configurada en el servidor.',
            ];
        }

        try {
            $appointment->loadMissing([
                'patient.persona',
                'studies',
                'insurance',
                'insurancePlan',
                'referringDoctor',
                'destinationDoctor.persona',
                'laboratory',
            ]);

            $ticket = $this->buildTicket($appointment);
            $this->printer->printTicket($ticket);
            $this->markPrinted($appointment);

            return [
                'attempted' => true,
                'printed' => true,
                'skipped' => false,
                'message' => 'Comprobante impreso en Epson térmica.',
            ];
        } catch (Throwable $e) {
            Log::warning('thermal_receipt.print_failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'attempted' => true,
                'printed' => false,
                'skipped' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTicket(Appointment $appointment): array
    {
        $ancho = (int) (config('services.thermal_printer.width_chars') ?? 42);
        $persona = $appointment->patient?->persona;
        $nombrePaciente = mb_strtoupper(trim(implode(' ', array_filter([
            $persona?->names,
            $persona?->last_name_1,
            $persona?->last_name_2,
        ]))));

        $marca = mb_strtoupper((string) ($appointment->laboratory?->name ?: 'SIRESA'));

        /** @var User|null $user */
        $user = Auth::user();
        $usuario = mb_strtoupper((string) ($user?->username ?? $user?->email ?? 'WORKLIST'));

        $ahora = now();
        $inicio = $appointment->start_time instanceof Carbon
            ? $appointment->start_time
            : Carbon::parse($appointment->start_time);

        $total = 0.0;
        $filas = [];
        foreach ($appointment->studies as $study) {
            $qty = (int) ($study->quantity ?: 1);
            $price = (float) ($study->price ?: 0);
            $lineTotal = $price * $qty;
            $total += $lineTotal;

            $filas[] = $this->formatStudyRow(
                (string) ($study->fonasa_code ?: ''),
                (string) ($study->exam_name ?: $study->sub_exam_name ?: 'EXAMEN'),
                $qty,
                $price,
                $ancho
            );
        }

        $prevision = (string) ($appointment->insurance?->name ?: 'SIN PREVISION');
        $convenio = (string) ($appointment->insurancePlan?->name ?: 'SIN CONVENIO');
        $ref = $appointment->referringDoctor;
        $medSolc = $ref
            ? trim(implode(' ', array_filter([$ref->names, $ref->last_name_1, $ref->last_name_2])))
            : 'SIN ORDEN';
        if ($medSolc === '') {
            $medSolc = 'SIN ORDEN';
        }
        $destPersona = $appointment->destinationDoctor?->persona;
        $medExam = $destPersona
            ? trim(implode(' ', array_filter([$destPersona->names, $destPersona->last_name_1, $destPersona->last_name_2])))
            : 'SIN ASIGNAR';
        if ($medExam === '') {
            $medExam = 'SIN ASIGNAR';
        }

        return [
            'sections' => [
                [
                    'align' => 'left',
                    'lines' => [
                        'ODT. NUMERO:',
                        ['text' => $this->odtNumber($appointment), 'style' => 'large'],
                        '',
                        $this->labelLine('RUT', mb_strtoupper((string) ($persona?->rut ?: '')), $ancho),
                        $this->labelLine('PACIENTE', $nombrePaciente, $ancho),
                        $this->labelLine('EDAD', $this->age($persona?->birth_date), $ancho),
                        $this->labelLine('FONO', (string) ($persona?->phone ?: ''), $ancho, true),
                        $this->labelLine('FECHA NAC', $this->formatDate($persona?->birth_date), $ancho),
                        '',
                        $this->labelLine('UNIDAD', $marca, $ancho),
                        $this->labelLine('USUARIO', $usuario, $ancho),
                        $this->labelLine('FECHA', $this->formatDate($ahora), $ancho),
                        $this->twoCols(
                            'HORA ING.: ' . $this->formatTime($ahora),
                            'HORA CITA: ' . $this->formatTime($inicio),
                            $ancho
                        ),
                        $this->labelLine('PREVISION', $prevision, $ancho),
                        $this->labelLine('MED.SOLC.', $medSolc, $ancho),
                        $this->labelLine('CONVENIO', $convenio, $ancho),
                        $this->labelLine('MED.EXAM.', $medExam, $ancho),
                    ],
                    'blank_after' => true,
                ],
            ],
            'separator' => '-',
            'table_header' => ['Cod.     Examen                    Cant.  Valor'],
            'table_rows' => $filas,
            'separator_after_table' => '-',
            'total_line' => 'TOTAL : $ ' . $this->formatMoney($total),
            'obs_label' => 'OBS:',
            'obs_text' => '',
            'footer' => '- COPIA MEDICO -',
            'copies' => 3,
        ];
    }

    private function formatStudyRow(string $code, string $name, int $qty, float $unitPrice, int $width): string
    {
        $digits = preg_replace('/\D+/', '', $code) ?: '';
        $cod = str_pad(mb_substr($digits !== '' ? $digits : $code, 0, 7), 7, ' ', STR_PAD_RIGHT);
        $valor = $this->formatMoney((int) round($unitPrice * $qty));
        $tail = ' ' . $qty . ' ' . $valor;
        $nameWidth = max(1, $width - mb_strlen($cod) - 1 - mb_strlen($tail));
        $nombre = mb_substr(trim($name), 0, $nameWidth);

        return mb_substr($cod . ' ' . str_pad($nombre, $nameWidth, ' ', STR_PAD_RIGHT) . $tail, 0, $width);
    }

    private function odtNumber(Appointment $appointment): string
    {
        if ($appointment->accession_number) {
            $digits = preg_replace('/\D+/', '', (string) $appointment->accession_number) ?: '';
            if (strlen($digits) >= 4) {
                return substr($digits, -8);
            }
        }

        $id = str_replace('-', '', (string) $appointment->id);
        $hash = 0;
        foreach (str_split($id) as $ch) {
            $hash = ($hash * 31 + ord($ch)) % 1000000;
        }

        return str_pad((string) $hash, 6, '0', STR_PAD_LEFT);
    }

    private function age(mixed $birthDate): string
    {
        if (!$birthDate) {
            return '';
        }
        try {
            $dt = $birthDate instanceof Carbon ? $birthDate : Carbon::parse($birthDate);

            return (string) $dt->age;
        } catch (Throwable) {
            return '';
        }
    }

    private function formatDate(mixed $value): string
    {
        if (!$value) {
            return '';
        }
        try {
            $dt = $value instanceof Carbon ? $value : Carbon::parse($value);

            return $dt->format('d-m-Y');
        } catch (Throwable) {
            return '';
        }
    }

    private function formatTime(mixed $value): string
    {
        if (!$value) {
            return '00:00:00';
        }
        try {
            $dt = $value instanceof Carbon ? $value : Carbon::parse($value);

            return $dt->format('H:i:s');
        } catch (Throwable) {
            return '00:00:00';
        }
    }

    private function formatMoney(float $valor): string
    {
        return number_format((int) round($valor), 0, ',', '.');
    }

    private function labelLine(string $label, string $value, int $width, bool $spaceBeforeColon = false): string
    {
        $lab = rtrim(trim($label), ':');
        $lab = $spaceBeforeColon ? $lab . ' :' : $lab . ':';
        $val = trim($value);
        $maxVal = max(1, $width - mb_strlen($lab) - 1);

        return mb_substr($lab . ' ' . mb_substr($val, 0, $maxVal), 0, $width);
    }

    private function twoCols(string $left, string $right, int $width): string
    {
        $r = (string) $right;
        $space = max(1, $width - mb_strlen($r) - 1);
        $l = mb_substr($left, 0, $space);
        $l = $l . str_repeat(' ', max(0, $space - mb_strlen($l)));

        return mb_substr($l . ' ' . $r, 0, $width);
    }
}
