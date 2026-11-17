# Destination photo research — 17 November 2026

The local catalogue contains 167 destination records. Nine already have real cover photos and were preserved. Of the 158 missing real photos, 104 are named places and 54 are fictional records whose names begin with “Sample ”. Fictional records are excluded from photo research; they must not be given real-place photographs.

## Review results

- 83 destinations received one photo each, applied locally at the user's explicit request on 17 November 2026.
- 21 named records remain unresolved. Their exact names, slugs, addresses and reasons are in `unresolved-destinations.json`.
- 54 fictional samples are recorded in `fictional-samples-excluded.json`.
- All existing listing photos, destination fields, filters and planner records were preserved.

Open the local gallery while the application is running:
http://127.0.0.1:8000/storage/destination-photo-review-2026-11-17/index.html

Some real places still carry `[SAMPLE]` in the existing catalogue. Their photo candidates do not change that provenance label or certify the listing data. The exact catalogue names are retained in the manifest for later identity checks.

## Sources and verification

`manifest.json` is the final set: one photo per destination, with exact identity, original image URL, source page, dimensions, checksum and credit. It passes the existing ListingPhotoManifest checks. Entries are approved under `team_testing_decision`, recording the user's explicit instruction for local application. This authorizes local testing; publication rights are not established by the research.

`php artisan destinations:apply-photos --apply` inserted 83 local `listing_photos` records. The command checks exact slug, name and location, skips archived destinations and destinations with real photos, defaults to a dry run and refuses production environments. Re-running it adds nothing. Audit row IDs and paths are recorded in `storage/app/destination-photo-import-*.json`; verification is in `storage/app/destination-photo-import-verification.json`. Smaller card copies preserve the original images and do not add database photo rows.

`download-report.json` drives the gallery. It retains rejected research rows, which the gallery hides. Only final manifest entries are candidates for any later import. Do not import the entire folder: it also contains superseded or rejected research images. No cropping or watermark removal was done.

## Important unresolved records

Museo Dabawenyo's historical entrance and the National Museum building were rejected as a current Museo cover; the current venue image could not be retrieved. The named San Salvador del Mundo Church is in Caraga while one record says Mati. Rancho Palos Verdes has a second Ma-a/Riverfront address that cannot be matched to the Mandug golf-club photo. Elysia branch images were not confidently assigned. JKM's official image is a flyer rather than a photograph. Several mangrove, cave, beach and overlook searches returned a different municipality or province. These cases remain unresolved rather than receiving generic or mismatched imagery.

## Live server (added 17 November 2026)

The project owner decided to publish this batch on the live site, knowing publication rights are not established. On the server:

    php artisan destinations:apply-photos                      # dry run
    php artisan destinations:apply-photos --apply --allow-production

Oversized pictures were reduced to 1600 px wide for the deploy; the manifest's width, height and checksum describe the files as shipped.
Only the pictures named in `manifest.json` are in the repository.
