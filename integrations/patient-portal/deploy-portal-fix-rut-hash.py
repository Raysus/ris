#!/usr/bin/env python3
"""Despliega corrección rut_hash + documentos de informe al portal-nuevo."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parent
HOST = "debuser@170.246.172.85"
FIX = ROOT / "fix-portal-rut-hash.py"


def load_password() -> str:
    acceso = Path("/home/raul/Escritorio/acceso.txt")
    if not acceso.exists():
        # Fallback local si existe en el repo/host
        for candidate in (
            Path.home() / "Escritorio/acceso.txt",
            Path("/opt/RIS/docs/acceso.txt"),
        ):
            if candidate.exists():
                acceso = candidate
                break
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
    print("Subiendo fix-portal-rut-hash.py...")
    scp(FIX, "/tmp/fix-portal-rut-hash.py", password)

    print("Aplicando corrección...")
    print(ssh("python3 /tmp/fix-portal-rut-hash.py", password))

    print("Limpiando cache Laravel...")
    clear = (
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan view:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan config:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan route:clear"
    )
    print(ssh(clear, password))

    print("Verificando rutHash...")
    verify = ssh(
        "grep -n \"str_replace\" /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -20",
        password,
    )
    print(verify)
    if " '-'," in verify or '", "-"' in verify or "'-'" in verify:
        # still might match other code; check specifically rutHash body
        body = ssh(
            "python3 - <<'PY'\n"
            "from pathlib import Path\n"
            "t=Path('/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php').read_text()\n"
            "i=t.find('function rutHash')\n"
            "print(t[i:i+220])\n"
            "PY",
            password,
        )
        print(body)
        if " '-'," in body or "'-', ''" in body or '", "-"' in body:
            raise SystemExit("rutHash sigue eliminando el guión")

    print("OK portal corregido.")


if __name__ == "__main__":
    main()
