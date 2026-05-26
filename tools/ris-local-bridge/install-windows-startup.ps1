# Registra el RIS Local Bridge para que inicie al iniciar sesion (Windows).
# Ejecutar en PowerShell:  Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force
#                        .\install-windows-startup.ps1

$BridgeDir = $PSScriptRoot
$TaskName = "HealthTiCloud-RIS-Local-Bridge"
$BatPath = Join-Path $BridgeDir "start-bridge.bat"

if (-not (Test-Path $BatPath)) {
    Write-Error "No se encuentra start-bridge.bat en $BridgeDir"
    exit 1
}

$Action = New-ScheduledTaskAction -Execute $BatPath -WorkingDirectory $BridgeDir
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Force | Out-Null

Write-Host "Listo. Tarea programada: $TaskName"
Write-Host "El bridge arrancara al iniciar sesion en esta PC."
Write-Host "Para probar ahora: Start-ScheduledTask -TaskName '$TaskName'"
Write-Host "Para quitar: Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
