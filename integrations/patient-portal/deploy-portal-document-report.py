#!/usr/bin/env python3
"""Despliega parche showReport PDF en portal-nuevo."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
ROOT = Path(__file__).resolve().parent
PATCH = ROOT / "_patch_showreport_doc_remote.py"


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
    if child.exitstatus not in (0, None):
        print(f"EXIT={child.exitstatus}", file=sys.stderr)
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


def main() -> None:
    password = load_password()
    print("Subiendo parche...")
    scp(PATCH, "/tmp/patch_showreport_doc.py", password)
    print("Aplicando...")
    ssh("python3 /tmp/patch_showreport_doc.py", password)
    print("Limpiando caches...")
    ssh(
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan view:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan config:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan cache:clear",
        password,
    )
    print("Verificando redirect en showReport...")
    ssh(
        "grep -n 'PDF/documento adjunto\\|report_document_path\\|RIS_PUBLIC_URL' "
        "/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php | head -30",
        password,
    )
    print("Listo.")


if __name__ == "__main__":
    main()
