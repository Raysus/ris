# Configura la impresora térmica en config.json del RIS Local Bridge (Windows).
# Uso: powershell -ExecutionPolicy Bypass -File configure-printer-windows.ps1
#      powershell -ExecutionPolicy Bypass -File configure-printer-windows.ps1 -PrinterName "\\localhost\imprayos"

param(
    [string]$PrinterName = '',
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'config.json')
)

function Resolve-ThermalPrinter {
    param([string]$Preferred)

    if ($Preferred) { return $Preferred }

    $candidates = @(
        '\\localhost\imprayos',
        'imprayos'
    )

    foreach ($name in $candidates) {
        try {
            if (Get-Printer -Name $name -ErrorAction Stop) {
                return $name
            }
        } catch { }
    }

    $epson = Get-Printer -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match 'TM-m30|TM-T20|TM-T88|imprayos' } |
        Select-Object -First 1
    if ($epson) { return $epson.Name }

    $shared = Get-Printer -ErrorAction SilentlyContinue |
        Where-Object { $_.Shared -and $_.ShareName -eq 'imprayos' } |
        Select-Object -First 1
    if ($shared) {
        $server = $env:COMPUTERNAME
        return "\\$server\$($shared.ShareName)"
    }

    return $null
}

$printer = Resolve-ThermalPrinter -Preferred $PrinterName
if (-not $printer) {
    Write-Host 'No se detectó impresora térmica. Indique el nombre:' -ForegroundColor Yellow
    Write-Host '  powershell -File configure-printer-windows.ps1 -PrinterName "\\localhost\imprayos"'
    exit 1
}

$config = @{
    viewer = 'radiant'
    paths = @{
        radiant = 'C:\Program Files\RadiAntViewer64bit\RadiAntViewer.exe'
        horos = 'C:\Program Files\Horos\Horos.exe'
        osirix = ''
        weasis = 'C:\Program Files\Weasis\Weasis.exe'
    }
    scanner = @{
        naps2_path = 'C:\Program Files\NAPS2\NAPS2.Console.exe'
        profile = 'Default'
    }
    printer = @{
        enabled = $true
        interface = $printer
        width_chars = 42
        copies = 2
        cut_feed_lines = 10
    }
}

if (Test-Path $ConfigPath) {
    try {
        $existing = Get-Content $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($existing.viewer) { $config.viewer = $existing.viewer }
        if ($existing.paths) { $config.paths = $existing.paths }
        if ($existing.scanner) { $config.scanner = $existing.scanner }
    } catch {
        Write-Warning "No se pudo leer $ConfigPath; se creará uno nuevo."
    }
}

$config | ConvertTo-Json -Depth 5 | Set-Content -Path $ConfigPath -Encoding UTF8
Write-Host "Impresora configurada: $printer" -ForegroundColor Green
Write-Host "Archivo: $ConfigPath"
Write-Host 'Reinicie el bridge: start-bridge.bat o reinicie la tarea programada HealthTiCloud-RIS-Local-Bridge'
