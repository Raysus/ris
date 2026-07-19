# Registra el RIS Local Bridge para que inicie AUTOMATICAMENTE al iniciar sesion
# en esta PC, de forma OCULTA (sin ventana negra) y con REINICIO AUTOMATICO si se cae.
#
# Ejecutar en PowerShell (una sola vez por PC con escaner):
#   Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force
#   .\install-windows-startup.ps1
#
# Nota: el escaner (NAPS2 / TWAIN) necesita una sesion de usuario activa, por eso la
# tarea arranca "al iniciar sesion" y no "al encender" antes del login. Si quiere que
# quede 100% automatico al prender el equipo, active ademas el inicio de sesion
# automatico de Windows (autologin); vea la guia del laboratorio.

$BridgeDir = $PSScriptRoot
$TaskName = "HealthTiCloud-RIS-Local-Bridge"
$VbsPath = Join-Path $BridgeDir "start-bridge-hidden.vbs"

if (-not (Test-Path $VbsPath)) {
    Write-Error "No se encuentra start-bridge-hidden.vbs en $BridgeDir"
    exit 1
}

# wscript.exe lanza el bridge sin ventana visible y espera a que termine (la tarea
# queda "en ejecucion" mientras el bridge vive; si node cae, la tarea lo reinicia).
$Action = New-ScheduledTaskAction -Execute "wscript.exe" `
    -Argument ('"{0}"' -f $VbsPath) `
    -WorkingDirectory $BridgeDir

$Trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME

$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 5 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)   # sin limite de tiempo (servicio permanente)

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Force | Out-Null

# Arrancar ya (no esperar al próximo login)
try {
    Start-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    Start-Sleep -Seconds 2
    Write-Host "Tarea iniciada ahora."
} catch {
    Write-Host "Tarea registrada; no se pudo iniciar ya: $($_.Exception.Message)"
    Write-Host "Pruebe: Start-ScheduledTask -TaskName '$TaskName'"
}

Write-Host ""
Write-Host "Listo. Tarea programada: $TaskName"
Write-Host "El bridge arrancara OCULTO al iniciar sesion y se reiniciara solo si se cae."
Write-Host "Para verificar:     Invoke-WebRequest http://127.0.0.1:8181/health"
Write-Host "Para quitar:        Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
