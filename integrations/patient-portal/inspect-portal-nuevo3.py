#!/usr/bin/env python3
import re
from pathlib import Path
import pexpect

HOST = "debuser@170.246.172.85"
CTRL = "/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php"


def load_password() -> str:
    text = Path("/home/raul/Escritorio/acceso.txt").read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    return m.group(1).strip()


def ssh(cmd: str, password: str) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {repr(cmd)}",
        timeout=120,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=120)
    return child.before or ""


def main() -> None:
    pwd = load_password()
    for c in [
        f"grep -n 'private function buildRisReportMap' {CTRL}",
        f"grep -n 'private function rutHash' {CTRL}",
        f"grep -n risReportMap {CTRL}",
        f"grep -n ris_study_id {CTRL}",
        "grep -n 'Informe RIS' /home/debuser/infra/portal-nuevo/resources/views/dashboard.blade.php",
        "grep -n 'report.show' /home/debuser/infra/portal-nuevo/routes/web.php",
    ]:
        print("===", c, "===")
        print(ssh(c, pwd))


if __name__ == "__main__":
    main()
