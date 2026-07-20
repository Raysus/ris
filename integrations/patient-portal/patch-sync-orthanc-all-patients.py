#!/usr/bin/env python3
"""Parchea SyncOrthancPatients: sincroniza TODOS los pacientes Orthanc → Keycloak."""
from pathlib import Path

CTRL = Path("/home/debuser/infra/portal-nuevo/app/Console/Commands/SyncOrthancPatients.php")

NEW = r'''<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Carbon\Carbon;

class SyncOrthancPatients extends Command
{
    protected $signature = 'orthanc:sync-patients {--days= : Solo estudios de los últimos N días (omitir = todos los pacientes Orthanc)}';
    protected $description = 'Sincroniza pacientes Orthanc → Keycloak y BD local del portal (lab_id / site_filter)';

    public function handle()
    {
        $this->info('Iniciando sincronización Orthanc → Keycloak...');

        $keycloakToken = $this->getKeycloakAdminToken();
        if (!$keycloakToken) {
            $this->error('No se pudo obtener token de Keycloak (service account).');
            return self::FAILURE;
        }
        $tokenFetchedAt = time();

        $orthancUrl = rtrim((string) env('ORTHANC_URL', 'http://172.16.66.11:8042'), '/');
        $days = $this->option('days');
        if ($days === null || $days === '') {
            $pacientesUnicos = $this->fetchAllOrthancPatients($orthancUrl);
            $this->info('Modo: todos los pacientes Orthanc (' . count($pacientesUnicos) . ').');
        } else {
            $pacientesUnicos = $this->fetchPatientsFromRecentStudies($orthancUrl, (int) $days);
            $this->info("Modo: estudios últimos {$days} días (" . count($pacientesUnicos) . ' pacientes).');
        }

        if ($pacientesUnicos === []) {
            $this->info('No hay pacientes para sincronizar.');
            return self::SUCCESS;
        }

        $created = 0;
        $updatedLocal = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar(count($pacientesUnicos));
        foreach ($pacientesUnicos as $rutRaw => $data) {
            // Client credentials tokens suelen expirar ~5 min; renovar preventivamente.
            if (time() - $tokenFetchedAt >= 240) {
                $refreshed = $this->getKeycloakAdminToken();
                if ($refreshed) {
                    $keycloakToken = $refreshed;
                    $tokenFetchedAt = time();
                }
            }

            $rutRawLimpio = strtoupper(str_replace(['.', ' '], '', (string) $rutRaw));
            $rutFormatted = $this->formatRut($rutRawLimpio);
            if (!$rutFormatted) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $fullName = $data['PatientName'] ?? 'PACIENTE DESCONOCIDO';
            $parsedName = $this->parseDicomName($fullName);

            $lastExamDate = null;
            if (!empty($data['last_exam_raw']) && strlen((string) $data['last_exam_raw']) === 8) {
                try {
                    $lastExamDate = Carbon::createFromFormat('Ymd', $data['last_exam_raw'])->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

            $formattedBirthDate = null;
            $birthDateRaw = $data['PatientBirthDate'] ?? null;
            if ($birthDateRaw && strlen((string) $birthDateRaw) === 8) {
                try {
                    $formattedBirthDate = Carbon::createFromFormat('Ymd', $birthDateRaw)->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

            $sex = $data['PatientSex'] ?? null;

            $kcResult = $this->ensureKeycloakUser($rutFormatted, $parsedName, $keycloakToken);
            if ($kcResult === 'created') {
                $created++;
            } elseif ($kcResult === 'error') {
                $errors++;
            }

            if ($this->syncLocalUser($rutFormatted, $parsedName, $formattedBirthDate, $sex, $lastExamDate, $keycloakToken)) {
                $updatedLocal++;
            } else {
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ Sync OK. Keycloak creados={$created} local={$updatedLocal} omitidos={$skipped} errores={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array> keyed by raw PatientID */
    private function fetchAllOrthancPatients(string $orthancUrl): array
    {
        $ids = Http::withoutVerifying()->timeout(180)->get("{$orthancUrl}/patients")->json();
        if (!is_array($ids)) {
            $this->error('No se pudo listar /patients en Orthanc.');
            return [];
        }

        $pacientes = [];
        foreach ($ids as $pid) {
            $p = Http::withoutVerifying()->timeout(60)->get("{$orthancUrl}/patients/{$pid}")->json();
            if (!is_array($p)) {
                continue;
            }
            $tags = $p['MainDicomTags'] ?? [];
            $rutRaw = $tags['PatientID'] ?? null;
            if (!$rutRaw) {
                continue;
            }
            $pacientes[$rutRaw] = $tags;
            $pacientes[$rutRaw]['last_exam_raw'] = null;
        }

        return $pacientes;
    }

    /** @return array<string, array> keyed by raw PatientID */
    private function fetchPatientsFromRecentStudies(string $orthancUrl, int $days): array
    {
        $fechaDesde = Carbon::now()->subDays(max(1, $days))->format('Ymd');
        $fechaHasta = Carbon::now()->addDay()->format('Ymd');

        $estudios = Http::withoutVerifying()->timeout(180)->post("{$orthancUrl}/tools/find", [
            'Level' => 'Study',
            'Query' => ['StudyDate' => "{$fechaDesde}-{$fechaHasta}"],
            'Expand' => true,
        ])->json();

        if (!is_array($estudios) || $estudios === []) {
            return [];
        }

        $pacientes = [];
        foreach ($estudios as $estudio) {
            $tags = $estudio['PatientMainDicomTags'] ?? [];
            $rutRaw = $tags['PatientID'] ?? null;
            $studyDateRaw = $estudio['MainDicomTags']['StudyDate'] ?? null;
            if (!$rutRaw) {
                continue;
            }
            if (!isset($pacientes[$rutRaw]) || ($studyDateRaw && $studyDateRaw > ($pacientes[$rutRaw]['last_exam_raw'] ?? ''))) {
                $pacientes[$rutRaw] = $tags;
                $pacientes[$rutRaw]['last_exam_raw'] = $studyDateRaw;
            }
        }

        return $pacientes;
    }

    private function syncLocalUser($rut, $name, $birthDate, $sex, $lastExamDate, $keycloakToken): bool
    {
        try {
            $fullName = trim($name['first'] . ' ' . $name['last']);
            $keycloakData = $this->getUserDataFromKeycloak($rut, $keycloakToken);
            $numeroRut = explode('-', $rut)[0];
            $passwordBase = substr($numeroRut, -4);
            $email = str_replace('-', '', strtolower($rut)) . '@paciente.healthticloud.cl';

            User::updateOrCreate(
                ['rut' => $rut],
                [
                    'name' => $fullName,
                    'role' => $keycloakData['role'],
                    'laboratory_id' => $keycloakData['lab_id'] ?? 1,
                    'site_filter' => $keycloakData['site_filter'],
                    'birth_date' => $birthDate,
                    'gender' => $sex,
                    'last_exam_date' => $lastExamDate,
                    'email' => $email,
                    'password' => Hash::make($passwordBase),
                ]
            );

            return true;
        } catch (\Exception $e) {
            $this->error("\nError sincronizando local {$rut}: " . $e->getMessage());
            Log::warning('orthanc:sync-patients local fail', ['rut' => $rut, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function getUserDataFromKeycloak($rut, $token): array
    {
        $baseUrl = env('KEYCLOAK_BASE_URL');
        $realm = env('KEYCLOAK_REALM');

        $response = Http::withToken($token)->withoutVerifying()
            ->get("{$baseUrl}/admin/realms/{$realm}/users", ['username' => $rut, 'exact' => 'true']);

        $userData = $response->json();
        $data = [
            'role' => 'paciente',
            'lab_id' => null,
            'site_filter' => 'ALL',
        ];

        if (empty($userData) || !isset($userData[0]['id'])) {
            return $data;
        }

        $user = $userData[0];
        $userId = $user['id'];

        if (isset($user['attributes'])) {
            $data['lab_id'] = $user['attributes']['lab_id'][0] ?? null;
            $data['site_filter'] = $user['attributes']['site_filter'][0] ?? 'ALL';
        }

        $rolesResponse = Http::withToken($token)->withoutVerifying()
            ->get("{$baseUrl}/admin/realms/{$realm}/users/{$userId}/role-mappings/realm");
        $roles = collect($rolesResponse->json());

        if ($roles->contains('name', 'super_admin')) {
            $data['role'] = 'super_admin';
        } elseif ($roles->contains('name', 'admin_siresa')) {
            $data['role'] = 'admin_siresa';
        } elseif ($roles->contains('name', 'secretaria')) {
            $data['role'] = 'secretaria';
        } elseif ($roles->contains('name', 'medico_solicitante')) {
            $data['role'] = 'medico_solicitante';
        }

        return $data;
    }

    private function formatRut($rut): ?string
    {
        $rut = preg_replace('/[^0-9Kk]/', '', (string) $rut);
        if (strlen($rut) < 2) {
            return null;
        }
        $dv = substr($rut, -1);
        $numero = substr($rut, 0, -1);

        return $numero . '-' . strtoupper($dv);
    }

    private function parseDicomName($name): array
    {
        $clean = str_replace('^', ' ', (string) $name);
        $firstName = 'Paciente';
        $lastName = '';
        if (str_contains($clean, ',')) {
            $parts = explode(',', $clean);
            $lastName = trim($parts[0]);
            $firstName = trim($parts[1] ?? 'Paciente');
        } else {
            $parts = explode(' ', trim($clean));
            if (count($parts) > 1) {
                $firstName = $parts[0];
                unset($parts[0]);
                $lastName = implode(' ', $parts);
            } else {
                $firstName = $clean;
            }
        }

        return ['first' => Str::title($firstName), 'last' => Str::title($lastName)];
    }

    /** @return 'exists'|'created'|'error' */
    private function ensureKeycloakUser($rut, $name, $token): string
    {
        $baseUrl = rtrim((string) env('KEYCLOAK_BASE_URL'), '/');
        $realm = (string) env('KEYCLOAK_REALM', 'patient-portal');

        $lookup = Http::withToken($token)->withoutVerifying()
            ->get("{$baseUrl}/admin/realms/{$realm}/users", ['username' => $rut, 'exact' => 'true']);
        if ($lookup->successful()) {
            $existing = $lookup->json();
            if (is_array($existing) && isset($existing[0]['id'])) {
                return 'exists';
            }
        } elseif (in_array($lookup->status(), [401, 403], true)) {
            // Token vencido: renovar una vez e intentar de nuevo.
            $token = $this->getKeycloakAdminToken() ?? $token;
            $lookup = Http::withToken($token)->withoutVerifying()
                ->get("{$baseUrl}/admin/realms/{$realm}/users", ['username' => $rut, 'exact' => 'true']);
            if ($lookup->successful()) {
                $existing = $lookup->json();
                if (is_array($existing) && isset($existing[0]['id'])) {
                    return 'exists';
                }
            }
        }

        $numeroRut = explode('-', $rut)[0];
        $password = substr($numeroRut, -4);
        $email = str_replace('-', '', strtolower($rut)) . '@paciente.healthticloud.cl';

        $response = Http::withToken($token)->withoutVerifying()->post("{$baseUrl}/admin/realms/{$realm}/users", [
            'username' => $rut,
            'enabled' => true,
            'firstName' => $name['first'],
            'lastName' => $name['last'],
            'email' => $email,
            'attributes' => ['rut' => [$rut]],
            'credentials' => [['type' => 'password', 'value' => $password, 'temporary' => false]],
        ]);

        if ($response->status() === 201) {
            return 'created';
        }

        if (in_array($response->status(), [401, 403], true)) {
            $token = $this->getKeycloakAdminToken() ?? $token;
            $response = Http::withToken($token)->withoutVerifying()->post("{$baseUrl}/admin/realms/{$realm}/users", [
                'username' => $rut,
                'enabled' => true,
                'firstName' => $name['first'],
                'lastName' => $name['last'],
                'email' => $email,
                'attributes' => ['rut' => [$rut]],
                'credentials' => [['type' => 'password', 'value' => $password, 'temporary' => false]],
            ]);
            if ($response->status() === 201) {
                return 'created';
            }
        }

        // Conflict: username/email already used — treat as exists if username search finds it
        if ($response->status() === 409) {
            $again = Http::withToken($token)->withoutVerifying()
                ->get("{$baseUrl}/admin/realms/{$realm}/users", ['username' => $rut, 'exact' => 'true']);
            if ($again->successful() && isset($again->json()[0]['id'])) {
                return 'exists';
            }
            $this->warn("\nKeycloak 409 para {$rut}: " . substr($response->body(), 0, 200));
            Log::warning('orthanc:sync-patients keycloak 409', ['rut' => $rut, 'body' => $response->body()]);

            return 'error';
        }

        $this->error("\nKeycloak create fail {$rut}: HTTP " . $response->status() . ' ' . substr($response->body(), 0, 200));
        Log::warning('orthanc:sync-patients keycloak create fail', [
            'rut' => $rut,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return 'error';
    }

    private function getKeycloakAdminToken(): ?string
    {
        $response = Http::withoutVerifying()->asForm()->post(
            env('KEYCLOAK_BASE_URL') . '/realms/' . env('KEYCLOAK_REALM') . '/protocol/openid-connect/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID'),
                'client_secret' => env('KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET'),
            ]
        );

        return $response->json()['access_token'] ?? null;
    }
}
'''


def main() -> None:
    CTRL.write_text(NEW, encoding='utf-8')
    print(f'Patched {CTRL}')


if __name__ == '__main__':
    main()
