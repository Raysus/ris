#!/usr/bin/env python3
import re
from pathlib import Path
import pexpect

HOST = "debuser@170.246.172.85"
password = re.search(
    r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
    Path("/home/raul/Escritorio/acceso.txt").read_text(),
    re.DOTALL | re.IGNORECASE,
).group(1).strip()


def ssh(cmd: str, timeout: int = 120) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {cmd!r}",
        timeout=timeout,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=timeout)
    print(child.before or "")
    return child.before or ""


ssh(
    "grep -n 'function fetchPatientStudiesFromOrthanc\\|PatientID\\|orthancUrl\\|ORTHANC_URL' "
    "/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -50"
)
ssh("sed -n '560,700p' /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
ssh(
    f"echo {password!r} | sudo -S bash -lc "
    "'grep -iE \"^ORTHANC|^PACS\" /home/debuser/infra/portal-nuevo/.env | sed -E \"s/(PASSWORD|PASS|TOKEN)=.*/\\\\1=***/I\"'"
)
ssh("sed -n '500,580p' /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
