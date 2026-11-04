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
    - It never loads embedding vectors unless you ask: a normal deploy leaves destination_embeddings and
      interest_embeddings exactly as they are. Add -RefreshEmbeddings only when the package ships new vectors
      (database/data/destination-embeddings.json changed); that runs `embeddings:import` once.

  Usage (from the project folder, D:\Downloads\ExploreDVO):
    .\deploy\deploy.ps1
    .\deploy\deploy.ps1 -Key "$env:USERPROFILE\.ssh\exploredvo_teammate"
    .\deploy\deploy.ps1 -Server 209.97.174.246 -Yes        # skip the confirmation question
    .\deploy\deploy.ps1 -RefreshEmbeddings                 # code AND load the shipped embedding vectors
    .\deploy\deploy.ps1 -CheckOnly                         # only check that these scripts match origin/main

  Needs: git and OpenSSH (ssh, scp) -- both come with Windows 10/11 + Git for Windows.
#>
param(
    [string]$Server = "209.97.174.246",
    [string]$User   = "root",
    [string]$Key    = "$env:USERPROFILE\.ssh\exploredvo_do",
    [string]$Branch = "main",
    [switch]$Yes,
    [switch]$RefreshEmbeddings,
    [switch]$CheckOnly          # run the safety checks and stop; deploys nothing
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

# These scripts must be exactly the ones on origin/$Branch. A stale or locally edited copy (for example a checkout
# left on an old feature branch) once ran an older server-update.sh that imported the embedding vectors on a
# code-only deploy. This runs before anything is packed, uploaded or backed up.
Step "Checking these deploy scripts match origin/$Branch"
foreach ($f in @("deploy/deploy.ps1", "deploy/server-update.sh")) {
    $onBranch = (git show "origin/${Branch}:$f") -join "`n"
    if ($LASTEXITCODE -ne 0) { Fail "Could not read $f from origin/$Branch." }
    $onThisPc = ((Get-Content -Raw -Path $f) -replace "`r", "")
    if ($onThisPc.TrimEnd("`n") -ne $onBranch.TrimEnd("`n")) {
        Fail "$f on this PC is not the same as on origin/$Branch (a stale or edited copy). Nothing was deployed. Fix: git fetch origin; git checkout origin/$Branch -- deploy   (or deploy from a clean checkout of $Branch), then run this again."
    }
}
Write-Host "deploy.ps1 and server-update.sh are identical to origin/$Branch."
if ($CheckOnly) { Write-Host "-CheckOnly: nothing was deployed."; exit 0 }

Write-Host "Will deploy : origin/$Branch  $sha  ($subject)"
Write-Host "Your PC is  : $local" $(if ($local -ne $sha) { "(not the same as GitHub -- that is fine, GitHub is what gets deployed)" } else { "(same)" })
Write-Host "Server      : $User@$Server"
Write-Host "Embeddings  : $(if ($RefreshEmbeddings) { 'WILL BE RELOADED from the package (-RefreshEmbeddings)' } else { 'left as they are (code-only deploy)' })"

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
# Upload the server script as it is on origin/$Branch (the same commit as the package), not the working-tree copy.
$updateDir = Join-Path $env:TEMP "exploredvo-update-$sha"
New-Item -ItemType Directory -Force -Path $updateDir | Out-Null
$updateScript = Join-Path $updateDir "server-update.sh"
git show "origin/${Branch}:deploy/server-update.sh" | Out-File -FilePath $updateScript -Encoding ascii
if ($LASTEXITCODE -ne 0) { Fail "Could not read deploy/server-update.sh from origin/$Branch." }
scp @sshOpt -q $tar $updateScript "${User}@${Server}:/root/"
if ($LASTEXITCODE -ne 0) { Fail "Upload failed (wrong key, wrong IP, or server is off?)." }

Step "Running the update on the server"
$refresh = if ($RefreshEmbeddings) { " --refresh-embeddings" } else { "" }
$remote = "tr -d '\r' < /root/server-update.sh > /root/server-update.run.sh && bash /root/server-update.run.sh /root/$(Split-Path -Leaf $tar) $sha$refresh"
# 2>&1 + ToString(): show the server's messages as plain text (not as red PowerShell errors)
ssh @sshOpt "${User}@${Server}" $remote 2>&1 | ForEach-Object { "$_" } | Out-Host
$code = $LASTEXITCODE

Remove-Item $tar -Force -ErrorAction SilentlyContinue
Remove-Item $updateDir -Recurse -Force -ErrorAction SilentlyContinue

if ($code -ne 0) {
    Fail "The server reported an error (exit $code). Read the messages above. A database backup was taken before anything changed."
}

Write-Host "`nDONE. Deployed $sha to http://$Server" -ForegroundColor Green
Write-Host "Open it and press Ctrl+F5 to skip the browser's saved copy."
