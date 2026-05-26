<?php

namespace App\Services;

class Hl7Parser
{
    public function parse(string $raw): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($raw));
        $lines = array_filter(explode("\n", $normalized));

        $segments = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line);
            $name = $parts[0] ?? '';
            if ($name === '') {
                continue;
            }
            $segments[$name][] = $parts;
        }

        $msh = $segments['MSH'][0] ?? [];
        $pid = $segments['PID'][0] ?? [];
        $orc = $segments['ORC'][0] ?? [];
        $obr = $segments['OBR'][0] ?? [];

        $messageType = $this->field($msh, 8);
        $messageControlId = $this->field($msh, 9);

        $patientId = $this->component($this->field($pid, 3), 0);
        $nameField = $this->field($pid, 5);
        $lastName = $this->component($nameField, 0);
        $firstName = $this->component($nameField, 1);

        $placerOrderId = $this->field($orc, 2) ?: $this->field($obr, 2);
        $serviceField = $this->field($obr, 4);
        $serviceCode = $this->component($serviceField, 0);
        $serviceName = $this->component($serviceField, 1);

        $scheduleRaw = $this->field($orc, 7) ?: $this->field($obr, 7);

        return [
            'message_type' => $messageType,
            'message_control_id' => $messageControlId ?: uniqid('HL7_'),
            'patient_id' => $patientId,
            'patient' => [
                'rut' => $patientId,
                'last_name_1' => $lastName,
                'names' => $firstName,
                'birth_date' => $this->parseDate($this->field($pid, 7)),
                'gender' => $this->mapGender($this->field($pid, 8)),
            ],
            'order' => [
                'placer_order_id' => $placerOrderId,
                'service_code' => $serviceCode,
                'service_name' => $serviceName,
                'scheduled_at' => $this->parseDateTime($scheduleRaw),
            ],
            'segments' => $segments,
        ];
    }

    public function buildOru(array $data): string
    {
        $now = now()->format('YmdHis');
        $controlId = $data['message_control_id'] ?? uniqid('ORU_');
        $app = config('hl7.sending_application');
        $facility = config('hl7.sending_facility');

        $lines = [
            "MSH|^~\\&|{$app}|{$facility}|HIS|HOSP|{$now}||ORU^R01|{$controlId}|P|2.5",
            'PID|1||' . ($data['patient_id'] ?? '') . '^^^RUT||' . ($data['patient_name'] ?? '') . '||',
            'OBR|1|' . ($data['placer_order_id'] ?? '') . '||' . ($data['service_code'] ?? '') . '^' . ($data['service_name'] ?? '') . '|||||||||||||||||||||F',
            'OBX|1|TX|REPORT^Informe||' . $this->escapeText($data['report_text'] ?? '') . '||||||F',
        ];

        return implode("\r", $lines) . "\r";
    }

    private function field(array $segment, int $index): string
    {
        return trim($segment[$index] ?? '');
    }

    private function component(string $value, int $index): string
    {
        $parts = explode('^', $value);

        return trim($parts[$index] ?? '');
    }

    private function parseDate(?string $value): ?string
    {
        if (!$value || strlen($value) < 8) {
            return null;
        }

        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }

    private function parseDateTime(?string $value): ?string
    {
        if (!$value || strlen($value) < 8) {
            return null;
        }

        $date = $this->parseDate($value);
        if (strlen($value) < 12) {
            return $date . ' 09:00:00';
        }

        $hour = substr($value, 8, 2);
        $min = substr($value, 10, 2);

        return "{$date} {$hour}:{$min}:00";
    }

    private function mapGender(?string $code): ?string
    {
        return match (strtoupper((string) $code)) {
            'M' => 'Masculino',
            'F' => 'Femenino',
            default => null,
        };
    }

    private function escapeText(string $text): string
    {
        return str_replace(["\r", "\n"], [' ', ' '], $text);
    }
}
