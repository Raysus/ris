#!/usr/bin/env python3
"""Despliega parche de roles staff (secretaria) en portal-nuevo."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parent
HOST = "debuser@170.246.172.85"
PATCH = ROOT / "patch-portal-staff-roles.py"
PORTAL = "/home/debuser/infra/portal-nuevo"


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
    print("Subiendo patch staff roles...")
    scp(PATCH, "/tmp/patch-portal-staff-roles.py", password)
    print(ssh("python3 /tmp/patch-portal-staff-roles.py", password))
    print(
        ssh(
            "grep -n \"hasRole('secretaria')\\|secretaria\" "
            f"{PORTAL}/app/Http/Controllers/StudyController.php "
            f"{PORTAL}/app/Http/Controllers/AuthController.php | head -40",
            password,
        )
    )
    print(ssh(f"cd {PORTAL} && php artisan optimize:clear", password))
    print("Portal parcheado OK")


if __name__ == "__main__":
    main()
