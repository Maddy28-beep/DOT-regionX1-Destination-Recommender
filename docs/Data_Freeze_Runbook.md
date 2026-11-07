# ExploreDVO data freeze: runbook (prepared, NOT applied to production)

Prepared on a restored copy of the 2026-11-08 production database. Nothing here has run on production, and nothing is merged to `main`.

## What the freeze changes

| # | Change | File / command | Rows |
|---|---|---|---|
| 1 | Migration | `php artisan migrate`. Nothing pending: the beta migration `2026_11_08_000100` (itineraries.range_capped_places) is already on production. | 0 |
| 2 | Rename sample listings that carry a real business name | `database/data/sample-name-anonymisation-2026-11.csv` via `listings:anonymise-samples` | 16 (11 destinations, 1 accommodation, 3 restaurants, 1 souvenir centre) |
| 3 | Rewrite the templated descriptions of the sample destinations | `database/data/sample-descriptions-2026-11.csv` via `listings:apply-updates --replace-custom` | 54 (ids 98 to 151) |
| 4 | Re-embed every destination whose text changed | `php artisan embeddings:build` (needs Ollama), then `embeddings:import` | 120 stale (see below) |

- Each description keeps its closing sentence "This is a sample record for ExploreDVO beta testing…" for readers. `App\Support\EmbeddingText::meaningOnly()` removes that sentence, the "[SAMPLE]" tag and a leading "Sample" before the text is embedded. Checked on the copy: 0 of 151 destinations have a sample label left in their embedded text.
- `--replace-custom` is needed for step 3 only because the tool cannot tell our own template text from a person's writing. I checked that all 54 current descriptions are the generated template ("… Activities: …").
- **120 embeddings are stale, not 70.** The vectors on production were built from text that still contained the labels, so after the beta code (which strips them) every sample destination counts as changed. This is harmless at run time (stored vectors are used as they are) but the freeze has to rebuild all 120.
- Description of #57 ("Sample Hot Spring Mountain Resort") still reads like the real protected landscape it was renamed from. Rewrite or accept before the freeze.

## Results on the copy (database `freeze_copy`)

| Check | Result |
|---|---|
| Rename dry run, then `--apply` | 16 renamed, all-or-nothing checks passed |
| Descriptions dry run, then `--apply` | 54 ok, 0 errors, 54 values updated |
| Full PHPUnit suite (sqlite) | 1751 tests, 5 errors, all five need the PHP GD extension, which this PC lacks (same 5 as before) |
| QA sweep D1 to D15 on the copy | 290 / 290 pass |
| Trip checks (seed 20261107 x 240, seed 777 x 220) | see `docs/Prep_Evaluation_Before_Freeze.md` |

## Your steps for re-embedding (on your development machine)

1. Get the branch and a database with the same ids as production.
   ```bash
   git fetch origin
   git checkout phase2/post-trial
   composer install
   ```
   Restore a production dump into your local PostgreSQL (the dump taken before the 8 Nov deploy works; a fresh one is better), point `.env` at it, then:
   ```bash
   php artisan migrate
   ```
2. Apply the two data files to your local copy (the same commands that ran on `freeze_copy`; dry run first).
   ```bash
   php artisan listings:anonymise-samples database/data/sample-name-anonymisation-2026-11.csv
   php artisan listings:anonymise-samples database/data/sample-name-anonymisation-2026-11.csv --apply
   php artisan listings:apply-updates database/data/sample-descriptions-2026-11.csv --replace-custom
   php artisan listings:apply-updates database/data/sample-descriptions-2026-11.csv --replace-custom --apply
   ```
3. Install and start Ollama, and set the embedding model in `.env`.
   ```bash
   ollama pull nomic-embed-text
   ```
   `.env`: `EMBEDDINGS_URL=http://localhost:11434` and `EMBEDDINGS_MODEL=nomic-embed-text`. Use the same model as before, or the vectors will not be comparable.
4. Re-embed and export. It embeds only the 120 changed places and then writes `database/data/destination-embeddings.json`.
   ```bash
   php artisan embeddings:build
   ```
   Expected last lines: `Embedded 120, unchanged 31, total 151.` then `Exported 151 vectors to database/data/destination-embeddings.json`. Use `--force` to re-embed all 151.
5. Commit the file and push it to the same branch.
   ```bash
   git add database/data/destination-embeddings.json
   git commit -m "Re-embed destinations after the data freeze"
   git push origin phase2/post-trial
   ```
6. Send me the commit hash. I load it into the copy and re-run the checks:
   ```bash
   php artisan embeddings:import
   php artisan embeddings:diagnose
   php artisan embeddings:coverage
   ```

## On production, after the trial and only when you say so

1. Remove `ACCREDITATION_TEST_GRACE_UNTIL` from the server `.env` (separate decision).
2. Take a database dump first (the deploy script does).
3. On the server, in this order: `listings:anonymise-samples … --apply`, then `listings:apply-updates … --replace-custom --apply`.
4. Deploy with `.\deploy\deploy.ps1 -Branch phase2/post-trial -RefreshEmbeddings -Yes`.
5. Revert path: `listings:anonymise-samples … --revert`, the pre-deploy dump for the descriptions, and the previous `destination-embeddings.json`.
