# Limpia y reinstala el RIS Local Bridge en esta PC (Windows).
# - Libera el puerto 8181
# - Detiene procesos node / tarea programada vieja
# - Reinstala dependencias npm
# - Registra autoinicio y arranca el bridge
# - Verifica http://127.0.0.1:8181/health
#
# Uso (doble clic en Limpiar-Reinstalar-Bridge.bat, o):
#   powershell -ExecutionPolicy Bypass -File .\limpiar-reinstalar-bridge-windows.ps1

$ErrorActionPreference = 'Stop'
$BridgeDir = $PSScriptRoot
$TaskName = 'HealthTiCloud-RIS-Local-Bridge'
$Port = 8181

function Write-Step($msg) {
    Write-Host ""
    Write-Host "=== $msg ===" -ForegroundColor Cyan
}

function Test-Command($name) {
    return [bool](Get-Command $name -ErrorAction SilentlyContinue)
}

Write-Host "============================================================"
Write-Host " RIS Local Bridge — limpiar y reinstalar (Windows)"
Write-Host " Carpeta: $BridgeDir"
Write-Host "============================================================"

if (-not (Test-Path (Join-Path $BridgeDir 'server.js'))) {
    Write-Error "No se encuentra server.js en $BridgeDir. Ejecute este script dentro de la carpeta del bridge."
    exit 1
}

# --- 1) Detener tarea programada ---
Write-Step "1/6 Detener tarea programada (si existe)"
try {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($task) {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
        Write-Host "Tarea $TaskName detenida y eliminada."
    } else {
        Write-Host "No habia tarea $TaskName."
    }
} catch {
    Write-Host "Aviso al quitar tarea: $($_.Exception.Message)"
}

# --- 2) Liberar puerto 8181 ---
Write-Step "2/6 Liberar puerto $Port"
$pids = @()
try {
    $conns = Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue
    if ($conns) {
        $pids = @($conns | Select-Object -ExpandProperty OwningProcess -Unique | Where-Object { $_ -gt 0 })
    }
} catch {
    # Fallback netstat (Windows antiguos)
    $lines = netstat -ano | Select-String ":$Port\s"
    foreach ($line in $lines) {
        $parts = ($line.ToString() -split '\s+') | Where-Object { $_ -ne '' }
        if ($parts.Count -ge 5) {
            $pidVal = 0
            [void][int]::TryParse($parts[-1], [ref]$pidVal)
            if ($pidVal -gt 0) { $pids += $pidVal }
        }
    }
    $pids = @($pids | Select-Object -Unique)
}

# Tambien matar node.exe que este corriendo server.js del bridge
Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
    $cmd = [string]$_.CommandLine
    if ($cmd -match 'server\.js' -or $cmd -match [regex]::Escape($BridgeDir)) {
        $pids += $_.ProcessId
    }
}
$pids = @($pids | Select-Object -Unique | Where-Object { $_ -gt 0 })

if ($pids.Count -eq 0) {
    Write-Host "Puerto $Port libre (ningun proceso)."
} else {
    foreach ($procId in $pids) {
        try {
            $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
            Write-Host "Deteniendo PID $procId ($($p.ProcessName))..."
            Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
        } catch {
            Write-Host "No se pudo detener PID $procId"
        }
    }
    Start-Sleep -Seconds 1
    Write-Host "Procesos detenidos."
}

# --- 3) Node.js ---
Write-Step "3/6 Comprobar Node.js"
if (-not (Test-Command 'node')) {
    Write-Host "ERROR: Node.js no esta en PATH." -ForegroundColor Red
    Write-Host "Instale Node LTS desde https://nodejs.org y vuelva a ejecutar este script."
    exit 1
}
$nodeVer = (node -v)
$npmVer = if (Test-Command 'npm') { npm -v } else { 'no-npm' }
Write-Host "node $nodeVer | npm $npmVer"

# --- 4) config.json + npm install ---
Write-Step "4/6 Dependencias y config"
Set-Location $BridgeDir

if (-not (Test-Path (Join-Path $BridgeDir 'config.json'))) {
    $example = Join-Path $BridgeDir 'config.example.json'
    if (Test-Path $example) {
        Copy-Item $example (Join-Path $BridgeDir 'config.json') -Force
        Write-Host "Creado config.json desde config.example.json"
    } else {
        Write-Host "AVISO: no hay config.json (el bridge usara defaults)."
    }
} else {
    Write-Host "config.json presente (se conserva)."
}

if (Test-Path (Join-Path $BridgeDir 'node_modules')) {
    Write-Host "Reinstalando node_modules (limpieza)..."
    Remove-Item -Recurse -Force (Join-Path $BridgeDir 'node_modules') -ErrorAction SilentlyContinue
}

if (-not (Test-Command 'npm')) {
    Write-Error "npm no disponible."
    exit 1
}

npm install --no-fund --no-audit
if ($LASTEXITCODE -ne 0) {
    Write-Error "npm install fallo (codigo $LASTEXITCODE)."
    exit $LASTEXITCODE
}
Write-Host "Dependencias OK."

# --- 5) Autoinicio + arranque ---
Write-Step "5/6 Registrar autoinicio y arrancar"
$installPs1 = Join-Path $BridgeDir 'install-windows-startup.ps1'
$startBat = Join-Path $BridgeDir 'start-bridge.bat'
$hiddenVbs = Join-Path $BridgeDir 'start-bridge-hidden.vbs'

if (Test-Path $installPs1) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $installPs1
} elseif (Test-Path $hiddenVbs) {
    Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$hiddenVbs`"" -WorkingDirectory $BridgeDir
    Write-Host "Bridge iniciado oculto (sin tarea programada)."
} elseif (Test-Path $startBat) {
    Start-Process -FilePath $startBat -WorkingDirectory $BridgeDir
    Write-Host "Bridge iniciado en ventana (start-bridge.bat)."
} else {
    Start-Process -FilePath 'node' -ArgumentList 'server.js' -WorkingDirectory $BridgeDir -WindowStyle Hidden
    Write-Host "Bridge iniciado con node server.js."
}

# --- 6) Health check ---
Write-Step "6/6 Verificar http://127.0.0.1:$Port/health"
$ok = $false
for ($i = 1; $i -le 12; $i++) {
    Start-Sleep -Seconds 1
    try {
        $r = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$Port/health" -TimeoutSec 3
        Write-Host $r.Content
        $ok = $true
        break
    } catch {
        Write-Host "Intento $i/12: aun no responde..."
    }
}

Write-Host ""
if ($ok) {
    Write-Host "LISTO. Bridge limpio y escuchando en 127.0.0.1:$Port" -ForegroundColor Green
    Write-Host "Prueba en el navegador: http://127.0.0.1:$Port/health"
    exit 0
}

Write-Host "El puerto $Port aun no responde." -ForegroundColor Yellow
Write-Host "Pruebe abrir una ventana y ejecutar: start-bridge.bat"
Write-Host "Revise si el firewall o antivirus bloquea Node."
Write-Host "Diagnostico rapido:"
try {
    Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue |
        Format-Table LocalAddress, LocalPort, State, OwningProcess -AutoSize
} catch {
    netstat -ano | Select-String ":$Port\s"
}
exit 2
