# ExploreDVO backups and restoring

## What is backed up, and where

| What | How often | Where it is kept |
|---|---|---|
| The whole database (`daily-<time>.sql.gz`) | every night at 18:00 UTC (2:00 AM Philippine time), kept 14 days | on the server, in `/var/backups/exploredvo` |
| Uploaded photos and files (`daily-<time>-uploads.tar.gz`) | same | same |
| A database copy before every deploy and before data cleanups (`pre-deploy-...`, `pre-cleanup-...`) | each time | same |

Backups on the server protect against mistakes (a bad update, a deleted record). They do **not**
protect against losing the server itself, so copy them to your own PC:

```powershell
.\deploy\backup-download.ps1          # newest nightly backup -> Documents\ExploreDVO-backups
.\deploy\backup-download.ps1 -Fresh   # make a new one first (do this before a defense)
```

The script checks each download against the server's fingerprint, keeps the newest 14 copies, and
never deletes anything on the server. Also copy that folder to a USB drive or a cloud drive.

To run it automatically every morning at 9 AM, once, in PowerShell:

```powershell
$action  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File D:\Downloads\ExploreDVO\deploy\backup-download.ps1"
$trigger = New-ScheduledTaskTrigger -Daily -At 9am
Register-ScheduledTask -TaskName "ExploreDVO backup" -Action $action -Trigger $trigger
```

## Restoring the database

Test a backup first on your own PC, never on the live server:

1. Create an empty scratch database on your local PostgreSQL (for example `exploredvo_restore_test`).
2. Unzip `daily-XXXX.sql.gz` to `daily-XXXX.sql` (7-Zip, right-click, Extract Here), then load it:
   ```powershell
   $env:PGPASSWORD = "<your local postgres password>"
   & "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -d exploredvo_restore_test -f daily-XXXX.sql
   ```
   On a PC you will see many `role "exploredvo" does not exist` errors. They are harmless: the
   backup says "this table belongs to the server's database user", which does not exist on your PC.
   The data still loads.
3. Check the data (`select count(*) from destinations;` should show 24).

Tested 2026-10-08: a fresh server backup was downloaded, verified, and restored into a scratch
database; destinations (24) and destination_embeddings (24) came back complete.

## If the live data is truly lost

Ask for help before doing this on the server. In short: copy the chosen backup to the server, stop
the site, drop and recreate the `exploredvo` database, load the backup with `psql`, unpack the
uploads archive into `/var/www/exploredvo/storage/app/`, and start the site again. A backup taken
before a deploy (`pre-deploy-...`) is the quickest way to undo a bad update.
