#!/usr/bin/env python3
"""Corrige nombres placeholder Fix/Probe Sync en Keycloak y BD portal."""
from __future__ import annotations

import re
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
SCRIPT = Path(__file__).resolve().parent / "fix-patient-names-from-orthanc.php"


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


def main() -> None:
    password = load_password()
    child = pexpect.spawn(
        "scp",
        ["-o", "StrictHostKeyChecking=accept-new", str(SCRIPT), f"{HOST}:/tmp/fix-patient-names-from-orthanc.php"],
        timeout=120,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=120)

    ssh = pexpect.spawn("ssh", ["-o", "StrictHostKeyChecking=accept-new", HOST], timeout=600, encoding="utf-8")
    ssh.expect(["password:", "Password:"])
    ssh.sendline(password)
    ssh.expect([r"[#$] "], timeout=30)

    for cmd in [
        "sudo docker cp /tmp/fix-patient-names-from-orthanc.php portal_app_nuevo:/tmp/fix-patient-names-from-orthanc.php",
        "sudo docker exec portal_app_nuevo php /tmp/fix-patient-names-from-orthanc.php",
        "sudo docker exec portal_app_nuevo php -r 'require \"/var/www/html/vendor/autoload.php\"; $app=require \"/var/www/html/bootstrap/app.php\"; $app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); echo App\\\\Models\\\\User::where(\"name\",\"like\",\"%Fix Sync%\")->orWhere(\"name\",\"like\",\"%Probe Sync%\")->count().\" placeholder_local\\n\";'",
    ]:
        ssh.sendline(cmd)
        i = ssh.expect(["password for", r"[#$] ", pexpect.TIMEOUT], timeout=420)
        if i == 0:
            ssh.sendline(password)
            ssh.expect([r"[#$] "], timeout=420)
        print((ssh.before or "")[-8000:])

    ssh.sendline("exit")
    ssh.expect(pexpect.EOF, timeout=20)


if __name__ == "__main__":
    main()
