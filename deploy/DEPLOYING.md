# Deploying ExploreDVO

Run from PowerShell on your PC, in the project folder:

```powershell
.\deploy\deploy.ps1                      # code-only deploy (the normal case)
.\deploy\deploy.ps1 -RefreshEmbeddings   # code AND load the embedding vectors shipped in the package
```

`deploy.ps1` packs `origin/main`, uploads it and runs `deploy/server-update.sh` on the server (database backup, code copy, `composer install`, `migrate --force`, caches, PHP reload, a six-page check). It never touches `.env`, uploads, `db:seed` or `migrate:fresh`.

## Embeddings are opt-in

The similar-place swaps and theme grouping read the vectors in the database tables `destination_embeddings` and `interest_embeddings`. They are loaded from `database/data/destination-embeddings.json` by `php artisan embeddings:import`, which rewrites every stored vector.

* **A normal deploy does not run `embeddings:import`.** The vectors on the server stay exactly as they are. The deploy output says `embeddings: left as they are (code-only deploy)`.
* **`-RefreshEmbeddings`** (which sends `--refresh-embeddings` to the server script) runs `embeddings:import` once, at the same point in the sequence as before (after the migrations, before the caches are rebuilt).
* **When to use it:** only when the package ships a new `database/data/destination-embeddings.json` (a vector refresh, or new destinations that need vectors). If you deploy without the switch and the package's vector file differs from the one the server had, the output prints a `NOTE` saying the new vectors were not loaded, so a forgotten refresh is visible. Nothing is loaded automatically.
* An unknown option (for example a typo such as `--refresh-embedings` passed to `server-update.sh` by hand) stops the script before the backup, with exit code 64 and nothing changed.
* **Script safety checks (always on).** `deploy.ps1` first checks that `deploy/deploy.ps1` and `deploy/server-update.sh` on this PC are identical to the copies on `origin/main`; if not (a stale checkout or a local edit) it stops before packing or uploading anything. It then uploads `server-update.sh` from `origin/main`, not from the working folder, and the server refuses (exit 65, nothing deployed, only the backup made) if the script it is running differs from the one inside the package. `.\deploy\deploy.ps1 -CheckOnly` runs the first check and stops. If it complains, run `git fetch origin; git checkout origin/main -- deploy` and try again. A deploy script older than this check cannot protect itself, so once, after this change is merged, refresh the `deploy` folder from `main` before the next deploy.

Afterwards, the read-only coverage check is `sudo -u www-data php artisan embeddings:coverage --json` on the server.

## Before and after any deploy

* The script takes `pre-deploy-<time>-<sha>.sql.gz` in `/var/backups/exploredvo` (the latest 10 are kept) before it changes anything. Restore steps: `deploy/BACKUP-AND-RESTORE.md`.
* Check `git diff <live sha> origin/main -- database` for new migrations first.
* A deploy that must not change data of any kind (for example while a simulated-survey batch is being prepared) is the normal deploy above: migrations only add schema, and with no new migration `migrate` reports "Nothing to migrate".

## Tests

`tests/Unit/DeployEmbeddingsOptionTest.php` runs the real `server-update.sh` against a sandbox with recording stand-ins for `sudo`, `php`, `composer`, `rsync`, `pg_dump`, `curl` and `systemctl` (it needs `bash`, `tar` and `md5sum`, and skips itself without them). It proves the default deploy never calls `embeddings:import`, the refresh calls it once between the migrations and the caches, every other command is identical in both modes, and an unknown option stops before anything runs.
