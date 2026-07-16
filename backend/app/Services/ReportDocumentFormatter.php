<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Laboratory;
use App\Models\User;
use Carbon\Carbon;

class ReportDocumentFormatter
{
    /**
     * Cabecera histórica multi-sede (solo si el laboratorio la configura
     * explícitamente en settings.reportHeader.branches).
     *
     * @return list<string>
     */
    public static function defaultBranchLines(): array
    {
        return [
            'Manuel Montt Nº942, Temuco. Fono: central 452 690000. WhatsApp +569 63128654.',
            'Centro médico 452 690037/39',
            'Dinamarca Nº661, Temuco. Fono 452 888724 Cel. +5696312109',
            'Av. Arturo Prat Nº1130, Victoria. Fono 452 846 238',
            'Av. O´Higgins Nº915, Lautaro. Fono. 452 738218',
        ];
    }

    /**
     * Una sola línea de dirección para la sede actual (sin mezclar otras sedes).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function formatLabAddressLine(?string $address, ?string $city = null, array $settings = []): string
    {
        $parts = [];
        $address = trim((string) $address);
        $city = trim((string) $city);
        if ($address !== '') {
            $parts[] = $address;
        }
        if ($city !== '' && ($address === '' || !str_contains(mb_strtolower($address), mb_strtolower($city)))) {
            $parts[] = $city;
        }

        $line = implode(', ', $parts);
        $phone = trim((string) ($settings['phone'] ?? $settings['fono'] ?? $settings['telefono'] ?? ''));
        if ($phone !== '') {
            $line = $line !== '' ? ($line . '. Fono: ' . $phone) : ('Fono: ' . $phone);
        }

        return trim($line, " \t\n\r\0\x0B.");
    }

    /** @param Laboratory|object|null $lab */
    public static function labUsesReportSignature($lab): bool
    {
        $settings = [];
        if ($lab instanceof Laboratory) {
            $settings = is_array($lab->settings) ? $lab->settings : [];
        } elseif (is_object($lab)) {
            $settings = is_array($lab->settings ?? null) ? $lab->settings : [];
        }

        return (bool) ($settings['use_report_signature'] ?? false);
    }

    public static function signaturePublicUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (class_exists(\App\Support\PublicStorageUrl::class)) {
            return \App\Support\PublicStorageUrl::from($path);
        }

        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }

        $risPublic = rtrim((string) config('services.ris.public_url', env('RIS_PUBLIC_URL', '')), '/');
        if ($risPublic !== '') {
            return $risPublic . '/storage/' . $relative;
        }

        return asset('storage/' . $relative);
    }

    /** @param Laboratory|object|null $lab */
    public static function headerLines($lab): array
    {
        $settings = [];
        if ($lab instanceof Laboratory) {
            $settings = is_array($lab->settings) ? $lab->settings : [];
            $labName = $lab->name;
            $labAddress = $lab->address;
        } elseif (is_object($lab)) {
            $settings = is_array($lab->settings ?? null) ? $lab->settings : [];
            $labName = $lab->name ?? null;
            $labAddress = $lab->address ?? null;
        } else {
            $labName = null;
            $labAddress = null;
        }
        $reportHeader = is_array($settings['reportHeader'] ?? null) ? $settings['reportHeader'] : [];

        $labCity = null;
        if ($lab instanceof Laboratory) {
            $labCity = $lab->city;
        } elseif (is_object($lab)) {
            $labCity = $lab->city ?? null;
        }

        $legalName = trim((string) ($reportHeader['legalName'] ?? $labName ?? 'Centro de Diagnóstico y Tratamiento Ltda.'));
        $branches = $reportHeader['branches'] ?? null;

        // Sin branches explícitas: solo la sede actual (nunca el listado multi-sede por defecto).
        if (!is_array($branches) || $branches === []) {
            $branches = [];
            $ownLine = self::formatLabAddressLine(
                $labAddress !== null ? (string) $labAddress : null,
                $labCity !== null ? (string) $labCity : null,
                $settings
            );
            if ($ownLine !== '') {
                $branches[] = $ownLine;
            }
        }

        $lines = [];
        if ($legalName !== '') {
            $lines[] = $legalName;
            $lines[] = $legalName;
        }
        foreach ($branches as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public static function formatSpanishDate(?Carbon $date, ?string $city = 'Temuco'): string
    {
        $date = $date ? $date->copy() : Carbon::now();
        $date->locale('es');
        $cityLabel = trim((string) $city) ?: 'Temuco';

        return $cityLabel . ', ' . $date->translatedFormat('j \d\e F \d\e Y') . '.';
    }

    /** @param array<string, mixed>|null $patient */
    public static function formatPatientName(?array $patient): string
    {
        if (!$patient) {
            return 'Paciente';
        }

        $parts = array_filter([
            trim((string) ($patient['name'] ?? $patient['names'] ?? '')),
            trim((string) ($patient['lastName'] ?? $patient['last_name_1'] ?? '')),
            trim((string) ($patient['secondLastName'] ?? $patient['last_name_2'] ?? '')),
        ]);

        return trim(implode(' ', $parts)) ?: 'Paciente';
    }

    public static function formatExamTitle(?string $exam, ?string $subExam = null): string
    {
        $label = trim((string) $exam);
        if ($subExam) {
            $sub = trim((string) $subExam);
            if ($sub !== '' && stripos($label, $sub) === false) {
                $label = $label === '' ? $sub : "{$label} - {$sub}";
            }
        }

        $label = mb_strtoupper($label, 'UTF-8');
        if ($label === '') {
            return 'EXAMEN:';
        }

        if (!preg_match('/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\./u', $label)) {
            if (preg_match('/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\s+/u', $label)) {
                $label = preg_replace('/^(RX|TC|RM|US|MG|DX|CR|MR|CT|ECO)\s+/u', '$1. ', $label, 1);
            } elseif (preg_match('/\b(RX|TORAX|TÓRAX|TORAX|COLUMNA|ABDOMEN|CRÁNEO|CRANEO)\b/u', $label)) {
                $label = 'RX. ' . preg_replace('/^RX\.?\s*/u', '', $label);
            }
        }

        return rtrim($label, ':') . ':';
    }

    /**
     * @param User|object|null $user
     * @param Laboratory|object|bool|null $labOrIncludeSignature laboratorio o flag explícito
     */
    public static function doctorPayload($user, $labOrIncludeSignature = null): array
    {
        if (!$user) {
            return [
                'displayName' => 'DR. MÉDICO RADIÓLOGO',
                'initials' => '',
                'registration' => '',
                'signatureUrl' => null,
            ];
        }

        if ($user instanceof User) {
            $persona = $user->persona;
            $settings = is_array($user->settings) ? $user->settings : [];
            $username = $user->username;
            $signaturePath = $user->signature_path ?? null;
        } else {
            $persona = $user->persona ?? null;
            $settings = is_array($user->settings ?? null) ? $user->settings : [];
            $username = $user->username ?? null;
            $signaturePath = $user->signature_path ?? null;
        }

        $nameParts = array_filter([
            trim((string) ($persona?->last_name_1 ?? '')),
            trim((string) ($persona?->last_name_2 ?? '')),
            trim((string) ($persona?->names ?? '')),
        ]);
        $fullName = trim(implode(' ', $nameParts));
        if ($fullName === '') {
            $fullName = trim((string) ($username ?? 'Médico Radiólogo'));
        }

        $dragonProfile = $user instanceof User ? $user->dragon_profile : ($user->dragon_profile ?? null);

        $includeSignature = is_bool($labOrIncludeSignature)
            ? $labOrIncludeSignature
            : self::labUsesReportSignature($labOrIncludeSignature);

        return [
            'displayName' => 'DR. ' . mb_strtoupper($fullName, 'UTF-8'),
            'initials' => trim((string) ($settings['inicialesInforme'] ?? $dragonProfile ?? '')),
            'registration' => trim((string) ($settings['registroMedico'] ?? '')),
            // Firma visual solo si el laboratorio lo activa (users.signature_path).
            'signatureUrl' => $includeSignature ? self::signaturePublicUrl($signaturePath) : null,
        ];
    }

    /** @param array<string, mixed> $study */
    public static function buildStudyDocument(
        object $appointment,
        array $study,
        ?object $lab = null,
        ?object $destinationDoctor = null,
    ): array {
        $lab = $lab ?: ($appointment->laboratory ?? null);
        $city = trim((string) ($lab?->city ?? 'Temuco'));

        if (!empty($study['patient']) && is_array($study['patient'])) {
            $patient = $study['patient'];
        } else {
            $patient = [
                'name' => $appointment->patient?->persona?->names ?? null,
                'lastName' => $appointment->patient?->persona?->last_name_1 ?? null,
                'secondLastName' => $appointment->patient?->persona?->last_name_2 ?? null,
            ];
        }

        $doctorUser = $destinationDoctor ?: ($appointment->destinationDoctor ?? null);

        return [
            'headerLines' => self::headerLines($lab instanceof Laboratory || is_object($lab) ? $lab : null),
            'dateLine' => self::formatSpanishDate(
                $appointment->start_time instanceof Carbon
                    ? $appointment->start_time
                    : ($appointment->start_time ? Carbon::parse($appointment->start_time) : null),
                $city
            ),
            'patientName' => self::formatPatientName($patient),
            'examTitle' => self::formatExamTitle($study['exam'] ?? null, $study['subExam'] ?? null),
            'reportBody' => trim((string) ($study['reportText'] ?? $study['report'] ?? '')),
            'doctor' => self::doctorPayload($doctorUser, $lab),
        ];
    }

    /** @param array<string, mixed> $document */
    public static function buildPlainText(array $document): string
    {
        $lines = $document['headerLines'] ?? [];
        $lines[] = '';
        $lines[] = $document['dateLine'] ?? '';
        $lines[] = '';
        $lines[] = 'Estimado Doctor:';
        $lines[] = '';
        $lines[] = 'El examen realizado a su paciente Sr(a) ' . ($document['patientName'] ?? 'Paciente') . ', ha dado el siguiente resultado:';
        $lines[] = '';
        $lines[] = $document['examTitle'] ?? 'EXAMEN:';
        $lines[] = '';
        $lines[] = $document['reportBody'] ?? '';

        // Firma del médico: va en el texto de transcripción (no se agrega pie automático).

        return trim(implode("\n", $lines));
    }

    /** @param array<string, mixed> $document */
    public static function buildHtml(array $document, string $textColor = '#111111'): string
    {
        $header = collect($document['headerLines'] ?? [])
            ->map(fn ($line) => '<div class="ris-report-header-line">' . e($line) . '</div>')
            ->implode('');

        $body = nl2br(e($document['reportBody'] ?? ''));

        $dateLine = e($document['dateLine'] ?? '');
        $patientName = e($document['patientName'] ?? '');
        $examTitle = e($document['examTitle'] ?? 'EXAMEN:');

        $signatureBlock = '';
        $signatureUrl = trim((string) ($document['doctor']['signatureUrl'] ?? ''));
        if ($signatureUrl !== '') {
            $safeUrl = e($signatureUrl);
            $signatureBlock = <<<HTML
    <div class="ris-report-signature" style="margin-top: 28px;">
        <img src="{$safeUrl}" alt="Firma" style="max-height: 90px; max-width: 280px;">
    </div>
HTML;
        }

        return <<<HTML
<div class="ris-report-page" style="color: {$textColor}; font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.45;">
    <div class="ris-report-header" style="text-align:center; margin-bottom: 18px;">{$header}</div>
    <div style="margin-bottom: 14px;">{$dateLine}</div>
    <div style="margin-bottom: 10px;">Estimado Doctor:</div>
    <div style="margin-bottom: 14px; text-align: justify;">
        El examen realizado a su paciente Sr(a) {$patientName}, ha dado el siguiente resultado:
    </div>
    <div style="font-weight: bold; margin-bottom: 10px;">{$examTitle}</div>
    <div class="ris-report-body" style="white-space: pre-wrap; text-align: justify; margin-bottom: 24px;">{$body}</div>
{$signatureBlock}</div>
HTML;
    }

    /** @param list<array<string, mixed>> $studies */
    public static function buildMultiStudyHtml(object $appointment, array $studies, string $textColor = '#111111'): string
    {
        $chunks = [];
        foreach ($studies as $index => $study) {
            $document = self::buildStudyDocument($appointment, $study);
            $page = self::buildHtml($document, $textColor);
            if ($index > 0) {
                $page = '<div style="page-break-before: always;"></div>' . $page;
            }
            $chunks[] = $page;
        }

        return implode("\n", $chunks);
    }

    /** Metadatos de cadena para el frontend (validación / entrega). */
    public static function appointmentChainMeta(Appointment $appointment): array
    {
        $appointment->loadMissing(['destinationDoctor.persona', 'laboratory', 'referringDoctor']);
        $lab = $appointment->laboratory;
        $doctor = self::doctorPayload($appointment->destinationDoctor, $lab);
        $settings = is_array($lab?->settings) ? $lab->settings : [];

        $refDoctorName = null;
        if ($appointment->referringDoctor) {
            $ref = $appointment->referringDoctor;
            $refDoctorName = trim((string) ($ref->name ?? $ref->names ?? ''));
        }

        return [
            'start_time' => $appointment->start_time?->toIso8601String(),
            'destinationDoctorName' => preg_replace('/^DR\.?\s*/i', '', $doctor['displayName']),
            'destinationDoctorInitials' => $doctor['initials'],
            'destinationDoctorRegistration' => $doctor['registration'],
            'firmaUrl' => $doctor['signatureUrl'],
            'referringDoctorName' => $refDoctorName,
            'laboratory' => [
                'name' => $lab?->name,
                'address' => $lab?->address,
                'city' => $lab?->city,
                'settings' => $settings,
            ],
        ];
    }
}
