<#
  ExploreDVO deploy script -- run from PowerShell on YOUR PC (not on the server).

  What it does
    1. Fetches GitHub and picks the latest commit of origin/main (what your team merged).
    2. Packs exactly that commit, uploads it to the server, and runs deploy/server-update.sh there.
    3. The server backs up the database, copies the new code, runs migrations,
       rebuilds caches, reloads PHP, and checks that the main pages answer.

  What it never does
    - It never touches .env, uploaded files, or the database contents (only runs `migrate`).
    - It never runs `db:seed` or `migrate:fresh`. Do those by hand, only when you mean to.

  Usage (from the project folder, D:\Downloads\ExploreDVO):
    .\deploy\deploy.ps1
    .\deploy\deploy.ps1 -Key "$env:USERPROFILE\.ssh\exploredvo_teammate"
    .\deploy\deploy.ps1 -Server 209.97.174.246 -Yes        # skip the confirmation question

  Needs: git and OpenSSH (ssh, scp) -- both come with Windows 10/11 + Git for Windows.
#>
param(
    [string]$Server = "209.97.174.246",
    [string]$User   = "root",
    [string]$Key    = "$env:USERPROFILE\.ssh\exploredvo_do",
    [string]$Branch = "main",
    [switch]$Yes
)

# "Continue", not "Stop": Windows PowerShell 5.1 turns anything a program prints on stderr
# (git's harmless "From https://..." line, ssh warnings) into a fatal error. Every
# external command below is checked through $LASTEXITCODE instead.
$ErrorActionPreference = "Continue"

function Fail($msg) { Write-Host "`nSTOPPED: $msg" -ForegroundColor Red; exit 1 }
function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

# Always work from the repo root (the folder above /deploy)
Set-Location (Split-Path -Parent $PSScriptRoot)

if (-not (Test-Path $Key))                          { Fail "SSH key not found: $Key  (use -Key to point at yours)" }
if (-not (Test-Path ".\deploy\server-update.sh"))   { Fail "deploy\server-update.sh is missing. Pull the latest main first." }
if (-not (Get-Command git -ErrorAction SilentlyContinue)) { Fail "git is not installed or not in PATH." }
if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) { Fail "ssh (OpenSSH) is not installed." }

Step "Fetching GitHub"
git fetch origin 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) { Fail "git fetch failed. Check your internet / GitHub login." }

$sha     = (git rev-parse --short "origin/$Branch").Trim()
$subject = (git log -1 --format="%s" "origin/$Branch").Trim()
$local   = (git rev-parse --short HEAD).Trim()

Write-Host "Will deploy : origin/$Branch  $sha  ($subject)"
Write-Host "Your PC is  : $local" $(if ($local -ne $sha) { "(not the same as GitHub -- that is fine, GitHub is what gets deployed)" } else { "(same)" })
Write-Host "Server      : $User@$Server"

if (-not $Yes) {
    $a = Read-Host "`nDeploy this to the live server? (y/N)"
    if ($a -notmatch '^(y|yes)$') { Write-Host "Cancelled."; exit 0 }
}

$stamp  = Get-Date -Format "yyyyMMdd-HHmmss"
$tar    = Join-Path $env:TEMP "exploredvo-$sha.tar.gz"
$sshOpt = @("-i", $Key, "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=20")

Step "Packing $sha"
git archive --format=tar.gz -o $tar "origin/$Branch"
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $tar)) { Fail "git archive failed." }
Write-Host ("Package: {0:N1} MB" -f ((Get-Item $tar).Length / 1MB))

Step "Uploading to the server"
scp @sshOpt -q $tar ".\deploy\server-update.sh" "${User}@${Server}:/root/"
if ($LASTEXITCODE -ne 0) { Fail "Upload failed (wrong key, wrong IP, or server is off?)." }

Step "Running the update on the server"
$remote = "tr -d '\r' < /root/server-update.sh > /root/server-update.run.sh && bash /root/server-update.run.sh /root/$(Split-Path -Leaf $tar) $sha"
# 2>&1 + ToString(): show the server's messages as plain text (not as red PowerShell errors)
ssh @sshOpt "${User}@${Server}" $remote 2>&1 | ForEach-Object { "$_" } | Out-Host
$code = $LASTEXITCODE

Remove-Item $tar -Force -ErrorAction SilentlyContinue

if ($code -ne 0) {
    Fail "The server reported an error (exit $code). Read the messages above. A database backup was taken before anything changed."
}

Write-Host "`nDONE. Deployed $sha to http://$Server" -ForegroundColor Green
Write-Host "Open it and press Ctrl+F5 to skip the browser's saved copy."
