#!/usr/bin/env python3
"""Sube y aplica parche de formato carta CDT en portal-nuevo."""
from __future__ import annotations

import re
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parent
RIS = ROOT.parent.parent
HOST = "debuser@170.246.172.85"


def load_password() -> str:
    text = Path("/home/raul/Escritorio/acceso.txt").read_text(encoding="utf-8")
    m = re.search(
        r"Servidor Portal de pacientes.*?debuser\s*\n([^\n]+)",
        text,
        re.DOTALL | re.IGNORECASE,
    )
    if not m:
        raise SystemExit("No se encontró contraseña de debuser en acceso.txt")
    return m.group(1).strip()


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


def ssh(cmd: str, password: str, timeout: int = 180) -> str:
    child = pexpect.spawn(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} {repr(cmd)}",
        timeout=timeout,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=timeout)
    if child.exitstatus not in (0, None):
        raise SystemExit(f"ssh falló ({child.exitstatus}): {child.before}")
    return child.before or ""


def main() -> None:
    password = load_password()
    formatter = RIS / "backend/app/Services/ReportDocumentFormatter.php"
    blade = ROOT / "laravel/report.blade.php"
    patch = ROOT / "patch-portal-report-format-remote.py"

    print("Subiendo archivos...")
    scp(formatter, "/tmp/ReportDocumentFormatter.php", password)
    scp(blade, "/tmp/report.blade.php", password)
    scp(patch, "/tmp/patch-portal-report-format-remote.py", password)

    print("Aplicando parche...")
    print(ssh("python3 /tmp/patch-portal-report-format-remote.py", password))

    print("Limpiando cache...")
    clear = (
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan view:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan config:clear"
    )
    print(ssh(clear, password))

    verify = ssh(
        "grep -c ReportDocumentFormatter /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php",
        password,
    )
    print("Verificación:", verify.strip())
    print("Parche portal informe OK.")


if __name__ == "__main__":
    main()
