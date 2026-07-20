#!/usr/bin/env python3
"""Diagnostica fallo showReport Cecilia / portal."""
from __future__ import annotations

import re
from pathlib import Path

import pexpect

HOST = "debuser@170.246.172.85"
STUDY_ID = "019f4c57-f6dd-7166-8789-9708c78a5dbb"


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
    php = Path("/home/raul/Escritorio/RIS/integrations/patient-portal/_diag_showreport.php")
    php.write_text(
        f"""<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
$kernel->bootstrap();

$id = '{STUDY_ID}';
echo "RIS_PUBLIC_URL=" . env('RIS_PUBLIC_URL', '(null)') . PHP_EOL;
echo "config_services_ris=" . json_encode(config('services.ris')) . PHP_EOL;

// Find portal user for Cecilia
$user = DB::table('users')->where('rut', 'like', '%12194141%')->orWhere('rut', '12194141-4')->first();
if (!$user) {{
  $user = DB::table('users')->where('id', 1631)->first();
}}
echo "USER=" . json_encode($user ? ['id'=>$user->id,'rut'=>$user->rut,'name'=>$user->name ?? null] : null) . PHP_EOL;

try {{
  $ctrl = $app->make(App\\Http\\Controllers\\StudyController::class);
  Auth::loginUsingId($user->id);
  $req = Illuminate\\Http\\Request::create('/report/' . $id, 'GET');
  $app->instance('request', $req);
  $response = $ctrl->showReport($id);
  if (method_exists($response, 'getTargetUrl')) {{
    echo "REDIRECT=" . $response->getTargetUrl() . PHP_EOL;
    echo "STATUS=" . $response->getStatusCode() . PHP_EOL;
  }} elseif (method_exists($response, 'getContent')) {{
    $c = $response->getContent();
    echo "CONTENT_LEN=" . strlen($c) . PHP_EOL;
    echo "CONTENT_SNIP=" . substr(strip_tags($c), 0, 200) . PHP_EOL;
    if (method_exists($response, 'getSession') && $response->getSession()) {{
      echo "FLASH=" . json_encode($response->getSession()->get('error')) . PHP_EOL;
    }}
  }} else {{
    echo "RESP_CLASS=" . get_class($response) . PHP_EOL;
  }}
}} catch (Throwable $e) {{
  echo "EXCEPTION=" . $e->getMessage() . PHP_EOL;
  echo "FILE=" . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
  echo "TRACE=" . substr($e->getTraceAsString(), 0, 1500) . PHP_EOL;
}}
""",
        encoding="utf-8",
    )

    print("=== scp diag ===")
    run(f"scp -o StrictHostKeyChecking=accept-new {php} {HOST}:/tmp/diag_showreport.php", password)

    print("=== showReport method excerpt ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        "'sed -n \"1095,1250p\" /home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php'",
        password,
    )

    print("=== laravel log showReport ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker exec portal_app_nuevo sh -c "
        "\"grep -i showReport /var/www/html/storage/logs/laravel.log 2>/dev/null | tail -30 || "
        "grep -i showReport /var/www/html/storage/logs/*.log 2>/dev/null | tail -30\"'",
        password,
    )

    print("=== route list report ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker exec portal_app_nuevo php artisan route:list --path=report 2>/dev/null | head -40'",
        password,
    )

    print("=== invoke showReport ===")
    run(
        f"ssh -o StrictHostKeyChecking=accept-new {HOST} "
        f"'echo {password!r} | sudo -S docker cp /tmp/diag_showreport.php portal_app_nuevo:/tmp/diag_showreport.php && "
        f"echo {password!r} | sudo -S docker exec portal_app_nuevo php /tmp/diag_showreport.php'",
        password,
    )


if __name__ == "__main__":
    main()
