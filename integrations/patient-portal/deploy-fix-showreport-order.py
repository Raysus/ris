#!/usr/bin/env python3
from __future__ import annotations

import re
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
ROOT = Path(__file__).resolve().parent
PATCH = ROOT / "_fix_showreport_order_remote.py"
DIAG = ROOT / "_diag_showreport.php"


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


def run(cmd: str, password: str, timeout: int = 180) -> str:
    child = pexpect.spawn(cmd, timeout=timeout, encoding="utf-8")
    i = child.expect(["password:", "Password:", pexpect.EOF], timeout=timeout)
    if i < 2:
        child.sendline(password)
        child.expect(pexpect.EOF, timeout=timeout)
    out = child.before or ""
    print(out)
    return out


def main() -> None:
    password = load_password()
    # Update diag to catch redirect properly after Auth
    DIAG.write_text(
        """<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

$id = '019f4c57-f6dd-7166-8789-9708c78a5dbb';
$user = DB::table('users')->where('id', 1631)->first();
Auth::loginUsingId($user->id);

try {
  $ctrl = $app->make(App\\Http\\Controllers\\StudyController::class);
  $response = $ctrl->showReport($id);
  if (method_exists($response, 'getTargetUrl')) {
    echo 'REDIRECT=' . $response->getTargetUrl() . PHP_EOL;
    echo 'STATUS=' . $response->getStatusCode() . PHP_EOL;
  } else {
    echo 'CLASS=' . get_class($response) . PHP_EOL;
    if (method_exists($response, 'getContent')) {
      echo 'SNIP=' . substr(strip_tags($response->getContent()), 0, 300) . PHP_EOL;
    }
  }
} catch (Throwable $e) {
  echo 'EXCEPTION=' . $e->getMessage() . PHP_EOL;
  echo 'AT=' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
""",
        encoding="utf-8",
    )

    run(f"scp -o StrictHostKeyChecking=accept-new {PATCH} {HOST}:/tmp/fix_showreport_order.py", password)
    run(f"scp -o StrictHostKeyChecking=accept-new {DIAG} {HOST}:/tmp/diag_showreport.php", password)
    print("=== apply ===")
    run(f"ssh -o StrictHostKeyChecking=accept-new {HOST} 'python3 /tmp/fix_showreport_order.py'", password)
    print("=== clear caches ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan view:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan config:clear && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan cache:clear'",
        password,
    )
    print("=== routes report/informe ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan route:list 2>/dev/null | grep -iE \"report|informe|showReport\" | head -40'",
        password,
    )
    print("=== invoke ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker cp /tmp/diag_showreport.php portal_app_nuevo:/tmp/diag_showreport.php && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php /tmp/diag_showreport.php'",
        password,
    )


if __name__ == "__main__":
    main()
