$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$output = Join-Path $projectRoot 'output\live-publish-20261101'
$bundle = Join-Path $output 'bundle'
New-Item -ItemType Directory -Path $bundle -Force | Out-Null
$files = @(
    'app/Services/PlannerDataPatch.php',
    'app/Console/Commands/PublishPlannerData.php',
    'database/data/live-planner-patch-2026-11-01.json',
    'database/migrations/2026_10_31_200000_add_planner_verification_to_destinations.php',
    'database/migrations/2026_10_31_210000_add_verified_meal_venues.php',
    'database/migrations/2026_11_01_180000_expand_itinerary_item_notes.php'
)
$checksums = @()
foreach ($file in $files) {
    $target = Join-Path $bundle $file
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination $target -Force
    $checksums += ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $file)
}
$shellTarget = Join-Path $bundle 'publish-planner-data.sh'
[IO.File]::WriteAllText($shellTarget, ([IO.File]::ReadAllText((Join-Path $projectRoot 'deploy\publish-planner-data.sh')) -replace "`r`n","`n"), [Text.UTF8Encoding]::new($false))
$checksums += ((Get-FileHash -LiteralPath $shellTarget -Algorithm SHA256).Hash.ToLowerInvariant() + '  publish-planner-data.sh')
[IO.File]::WriteAllText((Join-Path $bundle 'SHA256SUMS'), (($checksums -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
& tar -czf (Join-Path $output 'planner-data-20261101.tar.gz') -C $bundle .
if ($LASTEXITCODE -ne 0) { throw 'Bundle creation failed' }
Get-FileHash -LiteralPath (Join-Path $output 'planner-data-20261101.tar.gz') -Algorithm SHA256
