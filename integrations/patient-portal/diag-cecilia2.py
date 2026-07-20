#!/usr/bin/env python3
"""Diagnóstico + fix portal para informe Cecilia."""
from __future__ import annotations

import re
from pathlib import Path
import pexpect

HOST = "debuser@170.246.172.85"
ROOT = Path(__file__).resolve().parent


def password() -> str:
    text = Path("/home/raul/Escritorio/acceso.txt").read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    if not m:
        raise SystemExit("no password")
    return m.group(1).strip()


def ssh(cmd: str, pw: str, timeout: int = 180) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {cmd!r}",
        timeout=timeout,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(pw)
    child.expect(pexpect.EOF, timeout=timeout)
    out = child.before or ""
    print(out)
    return out


def scp(local: Path, remote: str, pw: str) -> None:
    child = pexpect.spawn(
        f"scp -o StrictHostKeyChecking=accept-new {local} {HOST}:{remote}",
        timeout=120,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(pw)
    child.expect(pexpect.EOF, timeout=120)


def main() -> None:
    pw = password()
    php = ROOT / "_diag_cecilia2.php"
    php.write_text(
        r"""<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = DB::table('users')->where('rut', '12194141-4')->first();
echo "USER=" . json_encode([
    'id' => $user->id ?? null,
    'rut' => $user->rut ?? null,
    'name' => $user->name ?? null,
    'site_filter' => $user->site_filter ?? null,
    'laboratory_id' => $user->laboratory_id ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";

$orthancUrl = env('ORTHANC_URL', 'http://172.16.66.11:8042');
echo "ORTHANC_URL={$orthancUrl}\n";

$rut = '12194141-4';
$rutLimpio = strtoupper(str_replace(['.', '-', ' '], '', $rut));
$cuerpo = substr($rutLimpio, 0, -1);
$dv = substr($rutLimpio, -1);
$rutConGuion = $cuerpo . '-' . $dv;

function orthancFind($url, $query) {
    $ch = curl_init(rtrim($url, '/') . '/tools/find');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['Level' => 'Study', 'Query' => $query, 'Expand' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $user = env('ORTHANC_USERNAME') ?: env('ORTHANC_USER');
    $pass = env('ORTHANC_PASSWORD') ?: env('ORTHANC_PASS');
    if ($user) curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . ($pass ?: ''));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '[]', true);
    echo 'FIND HTTP=' . $code . ' ERR=' . $err . ' Q=' . json_encode($query) . ' COUNT=' . (is_array($data) ? count($data) : 0) . "\n";
    if (!is_array($data)) {
        echo substr((string)$resp, 0, 300) . "\n";
        return [];
    }
    foreach ($data as $st) {
        $mn = $st['MainDicomTags'] ?? [];
        $pn = $st['PatientMainDicomTags'] ?? [];
        echo json_encode([
            'ID' => $st['ID'] ?? null,
            'PatientID' => $pn['PatientID'] ?? null,
            'AccessionNumber' => $mn['AccessionNumber'] ?? null,
            'StudyInstanceUID' => $mn['StudyInstanceUID'] ?? null,
            'InstitutionName' => $mn['InstitutionName'] ?? null,
            'Labels' => $st['Labels'] ?? [],
        ], JSON_UNESCAPED_UNICODE) . "\n";
    }
    return $data;
}

$studies = [];
$studies = array_merge($studies, orthancFind($orthancUrl, ['PatientID' => $rutConGuion]));
$studies = array_merge($studies, orthancFind($orthancUrl, ['PatientID' => $rutLimpio]));

$uid = '1.2.826.0.1.3680043.8.498.2913521717';
$accOrthanc = '20260710019F4C50';
$accRis = 'ACC-20260710-019F4C50';

$mapRows = DB::connection('ris_db')->table('appointment_studies')
    ->join('appointments', 'appointment_studies.appointment_id', '=', 'appointments.id')
    ->whereIn('appointments.status', ['entregable', 'entregado'])
    ->where(function ($q) use ($uid, $accOrthanc, $accRis) {
        $q->where('appointments.study_instance_uid', $uid)
          ->orWhereIn('appointments.accession_number', [$accOrthanc, $accRis]);
    })
    ->select('appointment_studies.id', 'appointments.accession_number', 'appointments.study_instance_uid', 'appointment_studies.report_document_path')
    ->get();
echo "MAP_ROWS=" . $mapRows->count() . "\n";
foreach ($mapRows as $r) echo json_encode($r) . "\n";

$ver = Cache::get('portal_orthanc_dashboard_version', 1);
echo "CACHE_VERSION={$ver}\n";
Cache::forever('portal_orthanc_dashboard_version', ((int)$ver) + 1);
echo "CACHE_BUMPED_TO=" . Cache::get('portal_orthanc_dashboard_version') . "\n";
""",
        encoding="utf-8",
    )
    scp(php, "/tmp/diag_cecilia2.php", pw)
    php.unlink(missing_ok=True)
    ssh(
        f"echo {pw!r} | sudo -S docker cp /tmp/diag_cecilia2.php portal_app_nuevo:/tmp/diag_cecilia2.php && "
        f"echo {pw!r} | sudo -S docker exec portal_app_nuevo php /tmp/diag_cecilia2.php",
        pw,
    )


if __name__ == "__main__":
    main()
