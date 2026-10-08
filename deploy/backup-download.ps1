<#
  ExploreDVO off-server backup -- run from PowerShell on YOUR PC (not on the server).

  The server already makes a backup every night, but it keeps it on the SAME machine, so if the
  server is lost or broken the backups go with it. This script copies the latest backup to your PC.

  What it does
    1. (optional, -Fresh) asks the server to make a brand-new backup right now.
    2. Finds the newest database backup and the newest uploaded-files backup on the server.
    3. Downloads them to a folder on this PC (default: Documents\ExploreDVO-backups).
    4. Checks each download is complete by comparing its SHA-256 fingerprint with the server's.
    5. Keeps the newest 14 copies on your PC and deletes older ones from the PC only.
    It never deletes anything on the server and never changes the website or its data.

  Usage
    .\deploy\backup-download.ps1                 # download the newest nightly backup
    .\deploy\backup-download.ps1 -Fresh          # make a new backup first, then download it  (use before a defense)
    .\deploy\backup-download.ps1 -Folder D:\Backups\ExploreDVO -Keep 30
    .\deploy\backup-download.ps1 -Key "$env:USERPROFILE\.ssh\exploredvo_teammate"

  Restoring (see deploy\BACKUP-AND-RESTORE.md) -- never on the live server unless the data is truly lost.

  Needs: OpenSSH (ssh, scp) -- included with Windows 10/11.
#>
param(
    [string]$Server = "209.97.174.246",
    [string]$User   = "root",
    [string]$Key    = "$env:USERPROFILE\.ssh\exploredvo_do",
    [string]$Folder = "$env:USERPROFILE\Documents\ExploreDVO-backups",
    [int]$Keep      = 14,
    [switch]$Fresh
)

# "Continue", not "Stop": Windows PowerShell 5.1 treats anything a program prints on stderr as a fatal
# error. Every external command below is checked through $LASTEXITCODE instead.
$ErrorActionPreference = "Continue"

function Fail($msg) { Write-Host "`nSTOPPED: $msg" -ForegroundColor Red; exit 1 }
function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }

if (-not (Test-Path $Key))                                  { Fail "SSH key not found: $Key  (use -Key to point at yours)" }
if (-not (Get-Command ssh -ErrorAction SilentlyContinue))   { Fail "ssh (OpenSSH) is not installed." }

$sshOpt = @("-i", $Key, "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=20")
$remoteDir = "/var/backups/exploredvo"

function RunRemote([string]$cmd) {
    $out = ssh @sshOpt "${User}@${Server}" $cmd 2>$null
    if ($LASTEXITCODE -ne 0) { Fail "The server did not accept the command (wrong key, wrong IP, or server is off?)." }
    return ($out | ForEach-Object { "$_" })
}

if ($Fresh) {
    Step "Asking the server for a brand-new backup"
    RunRemote "/usr/local/bin/exploredvo-backup.sh" | Out-Null
    Write-Host "Done."
}

Step "Finding the newest backups on the server"
$db      = (RunRemote "ls -t $remoteDir/daily-*.sql.gz 2>/dev/null | head -1").Trim()
$uploads = (RunRemote "ls -t $remoteDir/daily-*-uploads.tar.gz 2>/dev/null | head -1").Trim()
if (-not $db) { Fail "No nightly backup exists on the server yet. Run again with -Fresh." }

$ageHours = [int](RunRemote "echo `$(( (`$(date +%s) - `$(stat -c %Y '$db')) / 3600 ))").Trim()
Write-Host ("Newest database backup: {0}  ({1} hours old)" -f (Split-Path $db -Leaf), $ageHours)
if ($ageHours -gt 36) { Write-Host "WARNING: that backup is more than 36 hours old. Run with -Fresh, and check the server's nightly job." -ForegroundColor Yellow }

New-Item -ItemType Directory -Force -Path $Folder | Out-Null

$results = @()
foreach ($remote in @($db, $uploads) | Where-Object { $_ }) {
    $name  = Split-Path $remote -Leaf
    $local = Join-Path $Folder $name

    Step "Downloading $name"
    if (-not (Test-Path $local)) {
        scp @sshOpt -q "${User}@${Server}:$remote" $local
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $local)) { Fail "Download of $name failed." }
    } else {
        Write-Host "Already on this PC, checking it again."
    }

    $want = ((RunRemote "sha256sum '$remote' | cut -d' ' -f1").Trim()).ToLower()
    $have = (Get-FileHash -Algorithm SHA256 $local).Hash.ToLower()
    if ($want -ne $have) {
        Remove-Item $local -Force -ErrorAction SilentlyContinue
        Fail "$name was damaged on the way (fingerprints differ). The bad copy was deleted. Run again."
    }
    $size = (Get-Item $local).Length
    Write-Host ("OK  {0}  {1:N0} bytes  fingerprint matches the server" -f $name, $size) -ForegroundColor Green
    $results += [pscustomobject]@{ File = $name; Bytes = $size }
}

Step "Tidying old copies on this PC (keeping the newest $Keep of each kind)"
foreach ($pattern in @("daily-*.sql.gz", "daily-*-uploads.tar.gz")) {
    $files = Get-ChildItem -Path $Folder -Filter $pattern | Sort-Object LastWriteTime -Descending
    $old = $files | Select-Object -Skip $Keep
    foreach ($f in $old) { Remove-Item $f.FullName -Force; Write-Host "removed old copy $($f.Name)" }
}

Write-Host "`nDONE. Backups are in: $Folder" -ForegroundColor Green
Write-Host "Newest: $(Split-Path $db -Leaf)"
Write-Host "Tip: copy that folder to a USB drive or cloud drive too, so the backup is not only on this PC."
