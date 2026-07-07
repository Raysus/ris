# Instala certificado HTTPS del RIS LAN en Windows (SpeechMike / WebHID).
# Ejecutar en PowerShell como Administrador.
param(
    [string]$RisHost = "siresamatriz.healthticloud.cl",
    [string]$RisIp = "192.168.0.127"
)

$ErrorActionPreference = "Stop"
$certUrl = "https://${RisIp}/ris-lan-ca.pem"
$tempCert = Join-Path $env:TEMP "healthticloud-ris-lan-ca.pem"

Write-Host "-> Descargando $certUrl ..."
# Ignorar aviso SSL la primera vez
[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
Invoke-WebRequest -Uri $certUrl -Headers @{ Host = $RisHost } -OutFile $tempCert -UseBasicParsing

Write-Host "-> Instalando en Autoridades raíz de confianza ..."
Import-Certificate -FilePath $tempCert -CertStoreLocation Cert:\LocalMachine\Root | Out-Null

$hostsLine = "$RisIp $RisHost"
$hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
$hosts = Get-Content $hostsPath -Raw
if ($hosts -notmatch [regex]::Escape($RisHost)) {
    Add-Content -Path $hostsPath -Value "`n# healthticloud-ris-lan`n$hostsLine"
    Write-Host "-> hosts: $hostsLine"
} else {
    Write-Host "-> hosts ya contiene $RisHost"
}

Remove-Item $tempCert -Force -ErrorAction SilentlyContinue
Write-Host ""
Write-Host "Listo. Cierre Chrome/Edge y abra: https://${RisHost}/pages/radiologist.html"
Write-Host "Luego: Conectar SpeechMike (cierre SpeechControl antes)."
