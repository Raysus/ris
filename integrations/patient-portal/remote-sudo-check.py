#!/usr/bin/env python3
import subprocess
PWD = "J[[reIoR,y#Xh^"

def sudo(cmd):
    p = subprocess.run(["sudo", "-S"] + cmd, input=PWD + "\n", text=True, capture_output=True)
    print(p.stdout, end="")
    if p.stderr:
        print(p.stderr, end="")
    return p.returncode

print("=== RIS DB test ===")
sudo(["docker", "exec", "portal_app_nuevo", "php", "artisan", "tinker", "--execute=echo DB::connection('ris_db')->selectOne('select 1 as ok')->ok;"])
print("\n=== Entregables ===")
sudo(["docker", "exec", "portal_app_nuevo", "php", "artisan", "tinker", "--execute=echo DB::connection('ris_db')->table('appointments')->whereIn('status',['entregable','entregado'])->count();"])
