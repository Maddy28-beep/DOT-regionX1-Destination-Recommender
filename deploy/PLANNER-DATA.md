# Selective planner data publication

The reviewed `database/data/live-planner-patch-2026-11-01.json` adds seven public attractions and nine meal venues, updates selected existing listings, and excludes sample stops from planning. Sources and expected old values are included per listing. New public venues do not claim accreditation. Existing closures, certificates, hotel prices and check-in/out times are preserved.

Build the catalogue-only bundle from the project root:

```powershell
.\tools\package-planner-data.ps1
.\deploy\publish-planner-data.ps1
```

The second command publishes to the configured production server. SSH requests the key passphrase interactively. It verifies checksums and the target database, creates a fresh production backup, refuses to replace differing deployed files, previews changes, runs only the three selected migrations, applies the data transaction, and verifies the result. It does not pull code or run seeders. A concurrent/manual data conflict rolls back all data changes; schema migrations are separate. Reports and backup are stored under `/var/backups/exploredvo/planner-data-TIMESTAMP`.

The note-column migration preserves detailed itinerary access conditions that exceed PostgreSQL's old 300-character limit. Its rollback refuses to discard longer notes.

Preview without publishing:

```powershell
php artisan planner:publish-data database/data/live-planner-patch-2026-11-01.json
```

The public catalogue patch was rehearsed on isolated PostgreSQL: 16 inserts, 43 existing listing updates, 13 tags, and zero changes on the second run. All 9,457 checked unselected listing fields stayed unchanged. Five trip profiles generated without audit failures after expanding the note column. Unknown hours, durations, some meal details and unavailable interests remain disclosed; this is not a claim of complete real-world verification.

Rehearsal helpers use a dedicated localhost PostgreSQL database named `planner_patch_rehearsal` on port 55440, with the role `planner_rehearsal`. Never point them at production. `tools/rehearse-live-planner-patch.php` parses public COPY rows from the supplied dump without executing its SQL; `tools/prepare-patch-audit.php` prepares the five-profile runner under ignored `output/`.

Publishing this data bundle alone does not deploy the complete local planner. Deploy the matching planner code separately to enable verified non-accredited venues and preserve the manual resort-classification safeguards. No private database snapshot or generated deployment archive belongs in Git.
