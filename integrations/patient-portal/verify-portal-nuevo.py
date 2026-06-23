#!/usr/bin/env python3
import re
from pathlib import Path
import pexpect

HOST = "debuser@170.246.172.85"


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
    sudo = f"echo {pwd!r} | sudo -S"
    checks = [
        f"{sudo} docker exec portal_app_nuevo php artisan tinker --execute=\"echo DB::connection('ris_db')->selectOne('select 1 as ok')->ok;\"",
        f"{sudo} docker exec portal_app_nuevo php artisan tinker --execute=\"echo DB::connection('ris_db')->table('appointments')->whereIn('status',['entregable','entregado'])->whereNotNull('accession_number')->count();\"",
        "grep -n 'report' /home/debuser/infra/portal-nuevo/resources/views/report.blade.php | head -15",
        "grep -n 'reverse_proxy' /home/debuser/caddy/config/Caddyfile | head -10",
    ]
    for c in checks:
        print("===", c[:80], "===")
        print(ssh(c, pwd))


if __name__ == "__main__":
    main()
