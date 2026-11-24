# Restaurant photo import — 24 November 2026

Added 13 reviewed photographs to the local database, one per restaurant without a real approved photo. Existing listing records, all earlier photo records, approval behaviour and planner code remain unchanged. Nothing was deployed.

Restaurants: Kublai Khan, Blue Post, Axl Dave Bibingka, Braytside, Garden By The Bay, Kapehan sa Tambacan, Leonora's, Parola-Baywalk, Tsuru Juna, Tsuru Camus, Tsuru Abreeza, Vikings SM Lanang and Matina Swiss Deli.

Original image bytes and watermarks are preserved. Photo credits retain the source and available dates, including historical imagery. Permission metadata records the user's local testing decision; no reuse licence or owner permission is claimed. Accreditation fields were not changed.

## Reproducible import

`php artisan restaurants:apply-photos` performs a dry run. Add `--apply` to install the reviewed batch locally. Production is refused. The importer checks exact listing name, slug and location, skips archived records and restaurants with real approved photos, validates file hashes, rejects duplicate images and refuses to overwrite different file contents. Repeating the import added zero photos.

Manifest and original assets: `database/data/listing-photos/restaurants-missing-2026-11-24/`.

## Validation

- 70 tests, 341 assertions passed across restaurant photo import, photo credits, verified photos and trip planner tests. PHPUnit reported two deprecations.
- All 13 local detail routes returned HTTP 200 with their correct photo path and credit. Each target has exactly one real approved photograph.
- Snapshot comparison preserved 17,866 pre-existing non-volatile rows; only 13 photo rows were added. Sessions, cache/queue tables and search telemetry are outside this comparison.
- Desktop listing inspected at 1366 CSS px; phone detail inspected at 390 CSS px and phone listing at 429 CSS px. New images loaded and document width stayed within the viewport. Temporary viewport override reset afterward.
- 24 public restaurants remain without a real photo. Research candidates were not substituted for these unresolved entries.

Screenshots and machine-readable route/data verification are under `storage/app/remaining-restaurant-photos-2026-11-24/`; the data audit is `storage/app/restaurant-photo-import-verification-2026-11-24.json`.
