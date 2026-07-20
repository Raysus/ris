#!/usr/bin/env python3
"""
Corrige showReport en portal-nuevo para abrir el PDF adjunto del RIS
cuando el texto es solo el placeholder "Informe adjunto como documento.".
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
REMOTE_PATCH = "/tmp/patch_showreport_doc.py"


def load_password() -> str:
    text = Path("/home/raul/Escritorio/acceso.txt").read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    if not m:
        raise SystemExit("No se encontró contraseña de debuser")
    return m.group(1).strip()


def ssh(cmd: str, password: str, timeout: int = 180) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {cmd!r}",
        timeout=timeout,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
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
        raise SystemExit(f"scp falló: {child.before}")


REMOTE_SCRIPT = r'''#!/usr/bin/env python3
from pathlib import Path

CTRL = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
ENV = Path("/home/debuser/infra/portal-nuevo/.env")
text = CTRL.read_text(encoding="utf-8")
orig = text

old_select = """                ->select(
                    'appointment_studies.id',
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointments.start_time',
                    'appointments.accession_number',
                    'appointments.status'
                )"""
new_select = """                ->select(
                    'appointment_studies.id',
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointment_studies.report_document_path',
                    'appointments.start_time',
                    'appointments.accession_number',
                    'appointments.status'
                )"""
if old_select in text:
    text = text.replace(old_select, new_select, 1)
    print("OK select +report_document_path")
else:
    print("select: sin cambio")

redirect_block = """
            // PDF/documento adjunto desde RIS: abrir archivo real (no el placeholder de texto).
            $docPath = trim((string) ($study->report_document_path ?? ''));
            $reportText = trim((string) ($study->report ?? ''));
            $isPlaceholder = $reportText === '' || str_starts_with(mb_strtolower($reportText), 'informe adjunto');
            if ($docPath !== '' && $isPlaceholder) {
                $risPublic = rtrim((string) config('services.ris.public_url', env('RIS_PUBLIC_URL', 'https://api.healthticloud.cl')), '/');
                $rel = ltrim($docPath, '/');
                if (str_starts_with($rel, 'storage/')) {
                    $rel = substr($rel, strlen('storage/'));
                }
                return redirect($risPublic . '/storage/' . $rel);
            }

"""

needle = "$document = \\App\\Services\\ReportDocumentFormatter::buildStudyDocument("
# In the PHP source file the backslashes are single:
needle = "$document = \\App\\Services\\ReportDocumentFormatter::buildStudyDocument("
'''

# The remote script needs the actual PHP needle with single backslashes.
# When we write REMOTE_SCRIPT as a raw string and then write to file, \\ becomes \ in the file.
# For the PHP needle in the remote Python file we need: needle = "$document = \App\Services\..."
# which in a Python raw string for the outer file is tricky.

# Simpler approach: write the remote patch as a local file with Write tool, then scp it.
print("use separate patch file")
