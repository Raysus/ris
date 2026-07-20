#!/usr/bin/env python3
"""Diagnóstico portal: informe Cecilia Trecaman."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
ROOT = Path(__file__).resolve().parent


def load_password() -> str:
    acceso = Path("/home/raul/Escritorio/acceso.txt")
    text = acceso.read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    if not m:
        raise SystemExit("No password")
    return m.group(1).strip()


def run(cmd: str, password: str, timeout: int = 180) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {cmd!r}",
        timeout=timeout,
        encoding="utf-8",
    )
    i = child.expect(["password:", "Password:", pexpect.EOF])
    if i < 2:
        child.sendline(password)
        child.expect(pexpect.EOF, timeout=timeout)
    out = child.before or ""
    print(out)
    return out


def scp(local: Path, remote: str, password: str) -> None:
    child = pexpect.spawn(
        f"scp -o StrictHostKeyChecking=accept-new {local} {HOST}:{remote}",
        timeout=120,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=120)
    if child.exitstatus not in (0, None):
        raise SystemExit(f"scp failed: {child.before}")


def main() -> None:
    password = load_password()
    php = ROOT / "_diag_cecilia_tmp.php"
    php.write_text(
        r"""<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$acc = 'ACC-20260710-019F4C50';
$uid = '1.2.826.0.1.3680043.8.498.2913521717';
$hash = '3a6cb1d149f8224f43fafead45e962c46375b77e29e677ffbe873461993f4e20';
$studyId = '019f4c57-f6dd-7166-8789-9708c78a5dbb';
$rut = '12194141-4';

echo "=== RIS via portal ris_db ===\n";
$rows = DB::connection('ris_db')->table('appointment_studies')
    ->join('appointments', 'appointments.id', '=', 'appointment_studies.appointment_id')
    ->whereIn('appointments.status', ['entregable', 'entregado'])
    ->where(function ($q) use ($acc, $uid) {
        $q->where('appointments.accession_number', $acc)
          ->orWhere('appointments.study_instance_uid', $uid);
    })
    ->select(
        'appointment_studies.id',
        'appointment_studies.report',
        'appointment_studies.report_document_path',
        'appointments.status',
        'appointments.accession_number',
        'appointments.study_instance_uid'
    )->get();
echo 'ROWS=' . $rows->count() . "\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$p = DB::connection('ris_db')->table('personas')->where('rut_hash', $hash)->first();
echo 'PERSONA_BY_HASH=' . ($p->id ?? 'null') . "\n";

$byId = DB::connection('ris_db')->table('appointment_studies')->where('id', $studyId)->first();
echo 'BY_STUDY_ID=' . json_encode($byId, JSON_UNESCAPED_UNICODE) . "\n";

echo "=== Portal local users matching RUT ===\n";
$users = DB::table('users')->get();
$matched = 0;
foreach ($users as $u) {
    $blob = strtolower(($u->rut ?? '') . '|' . ($u->email ?? '') . '|' . ($u->name ?? '') . '|' . ($u->username ?? ''));
    if (str_contains($blob, '12194141') || str_contains($blob, 'trecaman') || str_contains($blob, 'cecilia')) {
        $matched++;
        echo json_encode([
            'id' => $u->id ?? null,
            'rut' => $u->rut ?? null,
            'email' => $u->email ?? null,
            'name' => $u->name ?? null,
            'username' => $u->username ?? null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
echo "MATCHED_USERS={$matched}\n";

echo "=== Orthanc tools/find PatientID ===\n";
$orthanc = env('ORTHANC_URL') ?: env('ORTHANC_BASE_URL') ?: 'http://orthanc:8042';
$authUser = env('ORTHANC_USERNAME') ?: env('ORTHANC_USER');
$authPass = env('ORTHANC_PASSWORD') ?: env('ORTHANC_PASS');
$ch = curl_init(rtrim($orthanc, '/') . '/tools/find');
$payload = json_encode([
    'Level' => 'Study',
    'Query' => [
        'PatientID' => $rut,
        'AccessionNumber' => '',
    ],
    'Expand' => true,
]);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
if ($authUser) {
    curl_setopt($ch, CURLOPT_USERPWD, $authUser . ':' . ($authPass ?: ''));
}
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "ORTHANC_URL={$orthanc} HTTP={$code} ERR={$err}\n";
$data = json_decode($resp ?: '[]', true);
if (!is_array($data)) {
    echo "ORTHANC_BODY=" . substr((string) $resp, 0, 500) . "\n";
} else {
    echo 'ORTHANC_STUDIES=' . count($data) . "\n";
    foreach (array_slice($data, 0, 5) as $st) {
        $mn = $st['MainDicomTags'] ?? [];
        echo json_encode([
            'ID' => $st['ID'] ?? null,
            'PatientID' => $mn['PatientID'] ?? null,
            'PatientName' => $mn['PatientName'] ?? null,
            'AccessionNumber' => $mn['AccessionNumber'] ?? null,
            'StudyInstanceUID' => $mn['StudyInstanceUID'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Also find by accession
$ch = curl_init(rtrim($orthanc, '/') . '/tools/find');
$payload = json_encode([
    'Level' => 'Study',
    'Query' => ['AccessionNumber' => $acc],
    'Expand' => true,
]);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
if ($authUser) {
    curl_setopt($ch, CURLOPT_USERPWD, $authUser . ':' . ($authPass ?: ''));
}
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($resp ?: '[]', true);
echo "ORTHANC_BY_ACC HTTP={$code} COUNT=" . (is_array($data) ? count($data) : 0) . "\n";
if (is_array($data)) {
    foreach ($data as $st) {
        $mn = $st['MainDicomTags'] ?? [];
        echo json_encode([
            'PatientID' => $mn['PatientID'] ?? null,
            'AccessionNumber' => $mn['AccessionNumber'] ?? null,
            'StudyInstanceUID' => $mn['StudyInstanceUID'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
""",
        encoding="utf-8",
    )

    print("Uploading diag script...")
    scp(php, "/tmp/diag_cecilia.php", password)
    php.unlink(missing_ok=True)

    print("Controller grep...")
    run(
        "grep -n \"function rutHash\\|str_replace\\|report_document_path\\|entregable\\|buildRisReportMap\\|Informe RIS\\|ris_study_id\" /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -80",
        password,
    )

    print("Running inside portal container...")
    run(
        f"echo {password!r} | sudo -S docker cp /tmp/diag_cecilia.php portal_app_nuevo:/tmp/diag_cecilia.php && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php /tmp/diag_cecilia.php",
        password,
        timeout=180,
    )


if __name__ == "__main__":
    main()
