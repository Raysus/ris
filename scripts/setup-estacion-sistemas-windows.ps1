# Estación de deploy Windows (Fer) — HealthTiCloud RIS
# Ejecutar en PowerShell:  Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
#                         .\scripts\setup-estacion-sistemas-windows.ps1

$ErrorActionPreference = "Stop"
$SshDir = Join-Path $env:USERPROFILE ".ssh"
$Key = Join-Path $SshDir "id_ed25519_ris"
$Repo = if ($env:RIS_REPO) { $env:RIS_REPO } else { "C:\RIS" }

Write-Host "=== Estacion de sistemas (Windows) ===" -ForegroundColor Cyan
Write-Host "Equipo: $env:COMPUTERNAME"
Write-Host "Repo:   $Repo"
Write-Host ""

# SSH
New-Item -ItemType Directory -Force -Path $SshDir | Out-Null
if (-not (Test-Path $Key)) {
    ssh-keygen -t ed25519 -C "ris-sistemas-$env:COMPUTERNAME" -f $Key -N '""'
}

$config = @"
# HealthTiCloud RIS — deploy Windows
Host ris-nube
    HostName 100.104.4.114
    User userit
    IdentityFile $Key
    IdentitiesOnly yes

Host ris-siresa
    HostName 100.103.135.42
    User siresa-centro-servidor
    IdentityFile $Key
    IdentitiesOnly yes

Host github.com
    HostName github.com
    User git
    IdentityFile $Key
    IdentitiesOnly yes
"@

Set-Content -Path (Join-Path $SshDir "config") -Value $config -Encoding utf8

Write-Host "=== CLAVE PUBLICA (GitHub + servidores) ===" -ForegroundColor Yellow
Get-Content "$Key.pub"
Write-Host ""
Write-Host "En el ThinkCentre (Linux), registre esta clave en los servidores:"
Write-Host "  bash $Repo/scripts/agregar-clave-estacion.sh $Key.pub"
Write-Host ""
Write-Host "O manualmente:"
Write-Host "  type $Key.pub | ssh ris-nube `"cat >> ~/.ssh/authorized_keys`""
Write-Host "  type $Key.pub | ssh ris-siresa `"cat >> ~/.ssh/authorized_keys`""
Write-Host ""

# Tailscale
if (Get-Command tailscale -ErrorAction SilentlyContinue) {
    tailscale status
    tailscale ip -4
} else {
    Write-Host "Instale Tailscale: https://tailscale.com/download/windows" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Deploy (Git Bash o WSL, con PHP 8.5+):"
Write-Host "  cd $Repo/backend"
Write-Host "  php vendor/bin/envoy run deploy-nube"
Write-Host "  php vendor/bin/envoy run deploy-lab --lab=siresa_centro"
Write-Host ""
Write-Host "Requisitos: Git, PHP 8.5+, Tailscale conectado (misma cuenta ra.guti.el@)."
