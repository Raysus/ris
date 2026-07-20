#!/usr/bin/env python3
"""Despliega sync Orthanc→Keycloak (todos los pacientes) y ejecuta una pasada."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import pexpect

ROOT = Path(__file__).resolve().parent
HOST = "debuser@170.246.172.85"
PATCH = ROOT / "patch-sync-orthanc-all-patients.py"
COMPARE = ROOT / "compare-orthanc-keycloak.php"
PORTAL = "/home/debuser/infra/portal-nuevo"


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
        "scp",
        ["-o", "StrictHostKeyChecking=accept-new", str(local), f"{HOST}:{remote}"],
        timeout=120,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect(pexpect.EOF, timeout=120)
    if child.exitstatus not in (0, None):
        raise SystemExit(f"scp falló: {child.before}")


def ssh_shell(password: str):
    child = pexpect.spawn(
        "ssh",
        ["-o", "StrictHostKeyChecking=accept-new", HOST],
        timeout=900,
        encoding="utf-8",
    )
    child.expect(["password:", "Password:"])
    child.sendline(password)
    child.expect([r"[#$] "], timeout=30)
    return child


def run(child, password: str, cmd: str, timeout: int = 300) -> str:
    child.sendline(cmd)
    i = child.expect(["password for", r"[#$] ", pexpect.TIMEOUT], timeout=timeout)
    if i == 0:
        child.sendline(password)
        child.expect([r"[#$] "], timeout=timeout)
    return child.before or ""


def main() -> None:
    password = load_password()
    print("Subiendo patch...")
    scp(PATCH, "/tmp/patch-sync-orthanc-all-patients.py", password)

    child = ssh_shell(password)
    print(run(child, password, "python3 /tmp/patch-sync-orthanc-all-patients.py"))
    print(run(child, password, f"grep -n 'fetchAllOrthancPatients\\|signature\\|ensureKeycloakUser\\|subDays' {PORTAL}/app/Console/Commands/SyncOrthancPatients.php | head -20"))
    print(run(child, password, "sudo docker exec portal_app_nuevo php artisan optimize:clear"))
    print(run(child, password, "sudo docker exec portal_app_nuevo php artisan schedule:list"))

    print("--- sync full (all orthanc patients) ---")
    out = run(child, password, "sudo docker exec portal_app_nuevo php artisan orthanc:sync-patients", timeout=900)
    print(out[-5000:])

    # quick counts
    print("--- verify missing after sync ---")
    verify = r"""sudo docker exec portal_app_nuevo php -r '
require "/var/www/html/vendor/autoload.php";
$app=require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;
function fmt($r){$r=preg_replace("/[^0-9Kk]/","",$r); if(strlen($r)<2)return null; return substr($r,0,-1)."-".strtoupper(substr($r,-1));}
$o=rtrim(env("ORTHANC_URL","http://172.16.66.11:8042"),"/");
$b=rtrim(env("KEYCLOAK_BASE_URL"),"/"); $realm=env("KEYCLOAK_REALM");
$tok=Http::withoutVerifying()->asForm()->post("$b/realms/$realm/protocol/openid-connect/token",[
"grant_type"=>"client_credentials","client_id"=>env("KEYCLOAK_SERVICE_ACCOUNT_CLIENT_ID"),"client_secret"=>env("KEYCLOAK_SERVICE_ACCOUNT_CLIENT_SECRET")
])->json()["access_token"];
$kc=[]; $f=0; while(true){$batch=Http::withToken($tok)->withoutVerifying()->get("$b/admin/realms/$realm/users",["first"=>$f,"max"=>100])->json(); if(!is_array($batch)||!$batch)break; foreach($batch as $u)$kc[strtoupper($u["username"]??"")]=1; if(count($batch)<100)break; $f+=100;}
$ids=Http::withoutVerifying()->timeout(180)->get("$o/patients")->json();
$miss=0;$all=0;
foreach((array)$ids as $pid){$p=Http::withoutVerifying()->get("$o/patients/$pid")->json(); $raw=$p["MainDicomTags"]["PatientID"]??null; if(!$raw)continue; $rut=fmt(strtoupper(str_replace(["."," "],"",$raw))); if(!$rut)continue; $all++; if(!isset($kc[strtoupper($rut)])) $miss++;}
echo "orthanc_rut=$all kc=".count($kc)." missing=$miss\n";
'"""
    print(run(child, password, verify, timeout=600)[-2000:])

    child.sendline("exit")
    child.expect(pexpect.EOF, timeout=20)
    print("DONE")


if __name__ == "__main__":
    main()
