#!/usr/bin/env python3
"""Verifica parche showReport y datos Cecilia."""
from __future__ import annotations

import re
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
ROOT = Path(__file__).resolve().parent


def load_password() -> str:
    text = Path("/home/raul/Escritorio/acceso.txt").read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    if not m:
        raise SystemExit("No password")
    return m.group(1).strip()


def run(cmd: str, password: str, timeout: int = 120) -> str:
    child = pexpect.spawn(cmd, timeout=timeout, encoding="utf-8")
    i = child.expect(["password:", "Password:", pexpect.EOF], timeout=timeout)
    if i < 2:
        child.sendline(password)
        child.expect(pexpect.EOF, timeout=timeout)
    out = child.before or ""
    print(out)
    return out


def main() -> None:
    password = load_password()
    php = ROOT / "_check_cecilia.php"
    php.write_text(
        """<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

$hash = hash('sha256', '12194141-4');
$rows = DB::connection('ris_db')->table('appointment_studies')
  ->join('appointments', 'appointments.id', '=', 'appointment_studies.appointment_id')
  ->join('patients', 'patients.id', '=', 'appointments.patient_id')
  ->where('patients.rut_hash', $hash)
  ->select(
    'appointment_studies.id',
    'appointment_studies.report',
    'appointment_studies.report_document_path',
    'appointments.accession_number',
    'appointments.study_instance_uid',
    'appointments.status'
  )->get();
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
$doc = $rows[0]->report_document_path ?? null;
if ($doc) {
  $rel = ltrim(preg_replace('#^storage/#', '', $doc), '/');
  $url = 'https://api.healthticloud.cl/storage/' . $rel;
  echo "PDF_URL=$url\\n";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  curl_exec($ch);
  echo 'PDF_HTTP=' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\\n";
  curl_close($ch);
}
""",
        encoding="utf-8",
    )

    print("=== scp check php ===")
    run(f"scp -o StrictHostKeyChecking=accept-new {php} {HOST}:/tmp/check_cecilia.php", password)

    print("=== grep patch ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        "'grep -n \"PDF/documento adjunto\\|report_document_path\\|RIS_PUBLIC_URL\" "
        "/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -40'",
        password,
    )

    print("=== env ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        "'grep RIS_PUBLIC_URL /home/debuser/infra/portal-nuevo/.env'",
        password,
    )

    print("=== docker check ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker cp /tmp/check_cecilia.php portal_app_nuevo:/tmp/check_cecilia.php && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php /tmp/check_cecilia.php'",
        password,
    )


if __name__ == "__main__":
    main()
