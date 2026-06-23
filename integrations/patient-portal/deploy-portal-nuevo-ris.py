#!/usr/bin/env python3
"""Despliega integración RIS en portal-nuevo (170.246.172.85)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parent
HOST = "debuser@170.246.172.85"
PATCH = ROOT / "patch-portal-nuevo-ris.py"


def load_password() -> str:
    acceso = Path("/home/raul/Escritorio/acceso.txt")
    text = acceso.read_text(encoding="utf-8")
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

    print("Subiendo patch...")
    scp(PATCH, "/tmp/patch-portal-nuevo-ris.py", password)

    print("Aplicando patch en /home/debuser/infra/portal-nuevo ...")
    out = ssh("python3 /tmp/patch-portal-nuevo-ris.py", password)
    print(out)

    print("Limpiando cache Laravel...")
    clear = (
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan view:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan config:clear"
    )
    print(ssh(clear, password))

    print("Verificando métodos RIS...")
    verify = ssh(
        "grep -c buildRisReportMap /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php",
        password,
    )
    print(verify.strip())
    if "1" not in verify and "2" not in verify:
        raise SystemExit("Patch no verificado en StudyController")

    print("Despliegue portal-nuevo RIS OK.")


if __name__ == "__main__":
    main()
