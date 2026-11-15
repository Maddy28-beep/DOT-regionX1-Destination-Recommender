# Simulated Amenity Batch: 15 November 2026

The project owner explicitly selected clearly labeled simulated amenities for sample records that use real place names. These are invented testing attributes, not researched facilities or verified accessibility information.

## Applied Live

- 28 sample destinations: 112 amenity tags.
- Two sample restaurants: eight amenity entries.
- One sample souvenir center: four amenity entries.
- Total: 31 previously empty listings, 124 entries.
- Every entry has a display name ending in `(simulated)`, rendered by the existing live amenity component and gallery metadata.
- No accessibility attributes were invented. Existing amenities, accreditation, survey transactions, and listing descriptions were preserved.

`deploy/fill-empty-sample-amenities-2026-11-15.php` contains the fixed target IDs, venue-type profiles, active/sample guards, live display-name schema checks, preview mode, and transactional insertion. It refuses to overwrite existing entries. Each insertion is logged under `simulated-amenities-2026-11-15`. A database backup completed before application.

## Verification

Live queries after application found zero active sample destinations, accommodations, restaurants, or souvenir centers with no recognized amenities. The database contains 112 new simulated destination tags and 12 other simulated amenities. Rendering the live Blade component confirmed `Parking Area (simulated)` appears in the output. The survey transaction count remains 1,000.

The amenity scoring code reads canonical amenity values; the simulated display name does not change the matching vocabulary. Thus the simulated destination amenities participate in amenity matching. This is demonstration behavior, not independent evidence of recommendation accuracy.

## Embedding Refresh Limitation

An incremental `php artisan embeddings:build --no-export` was attempted after the additions. It failed because the configured Ollama endpoint at localhost:11434 is unavailable. Existing embeddings remain stored and semantic features retain their previous vectors. The new amenity tags are available to direct amenity matching, but are not yet incorporated into destination vectors. Refresh embeddings when the model service is available; no model service was installed or application code deployed in this batch.
