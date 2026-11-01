param(
    [string]$Server = 'root@209.97.174.246',
    [string]$IdentityFile = "$env:USERPROFILE\.ssh\exploredvo_teammate"
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$archive = Join-Path $projectRoot 'output\live-publish-20261101\planner-data-20261101.tar.gz'
if (!(Test-Path -LiteralPath $archive)) { throw "Reviewed bundle missing: $archive" }
$remoteArchive = '/root/planner-data-20261101.tar.gz'
# Interactive SSH can request the key passphrase in the terminal; never put it in a command or chat.
& scp -i $IdentityFile -o IdentitiesOnly=yes $archive "${Server}:$remoteArchive"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed; nothing was applied.' }
$archiveHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
$remoteCommand = "set -eu; echo '$archiveHash  $remoteArchive' | sha256sum --check; staging=`$(mktemp -d /root/planner-data-20261101.XXXXXX); tar -xzf $remoteArchive -C `"`$staging`"; bash `"`$staging/publish-planner-data.sh`""
& ssh -t -i $IdentityFile -o IdentitiesOnly=yes $Server $remoteCommand
if ($LASTEXITCODE -ne 0) { throw 'Publication stopped. Read the server output and backup reports before retrying.' }
