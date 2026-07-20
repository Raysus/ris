#!/usr/bin/env python3
import re
from pathlib import Path
import pexpect

HOST = "debuser@170.246.172.85"
pw = re.search(
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
    child.sendline(pw)
    child.expect(pexpect.EOF, timeout=timeout)
    print(child.before or "")
    return child.before or ""


# showReport function
ssh("grep -n 'function showReport\\|rut_hash\\|report_document\\|Informe RIS\\|ris_study_id\\|route(' /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -80")
ssh("sed -n '1080,1250p' /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
# dashboard blade button
ssh("grep -n 'ris_study_id\\|Informe RIS\\|showReport\\|report' /home/debuser/infra/portal-nuevo/resources/views/dashboard.blade.php | head -40")
