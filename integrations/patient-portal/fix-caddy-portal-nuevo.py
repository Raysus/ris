#!/usr/bin/env python3
import subprocess
from pathlib import Path

PWD = "J[[reIoR,y#Xh^"
CADDY = Path("/home/debuser/caddy/config/Caddyfile")

def sudo(cmd):
    return subprocess.run(["sudo", "-S"] + cmd, input=PWD + "\n", text=True, capture_output=True)

content = CADDY.read_text()
old = "        reverse_proxy patient-portal:83"
new = "        reverse_proxy portal_app_nuevo:8080"
if old not in content:
    if new in content:
        print("Caddy ya apunta a portal_app_nuevo")
    else:
        raise SystemExit("No se encontró reverse_proxy patient-portal:83")
else:
    CADDY.write_text(content.replace(old, new, 1))
    print("Caddyfile actualizado")

r = sudo(["docker", "exec", "sso_proxy", "caddy", "reload", "--config", "/etc/caddy/Caddyfile"])
print(r.stdout)
print(r.stderr)
print("reload exit", r.returncode)
