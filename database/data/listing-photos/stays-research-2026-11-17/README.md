# Stay photo research — 17 November 2026

This batch covers accommodations without a real cover photo in the local catalogue. The user authorized local application on 17 November 2026. It does not replace existing photos or change listing, search, pricing, or planner data.

## Coverage

- 155 stays lacked real cover photos at the start.
- 114 stays have one staged photo candidate each: 100 property photos and 14 shared condominium-complex exteriors.
- 41 stays remain unresolved. See `unresolved-stays.json` for their exact names, slugs and addresses.
- Existing 74 real covers were preserved. Existing image paths were checked and none were broken.

Open the local review gallery at http://127.0.0.1:8000/storage/stay-photo-review-2026-11-17/index.html while the application is running. It supports desktop and mobile and includes an unresolved-stays view.

## Condominium photos

The user explicitly accepted a complex exterior when a verified unit photo was unavailable. Those 14 candidates are labeled **Complex exterior · Unit not shown**. They show Centro Spatial, 8 Spatial, One Oasis or Verdon Parc, and must not be described as a photo of the rental unit.

## Source records and import state

`manifest.json` records one photo per listing, its exact slug/name/location, original image URL, source page, checksum, dimensions and credit text. It passes the existing ListingPhotoManifest checks. Entries are approved under `team_testing_decision`, recording the user's explicit instruction to apply this batch locally. This is local-test authorization; publication rights are not established by source links or attribution.

`download-report.json` powers the preview gallery. `candidates-*.json`, retry files, and `source-search-*.json` retain the research trail, including rejected matches. Only `manifest.json` identifies the final review set. Do not import every image file in this folder: superseded or unused research files may also exist.

The local public gallery remains a separate review copy. `php artisan stays:apply-photos --apply` inserted 114 `listing_photos` rows locally, one per eligible stay. All original listing fields and existing photo records were preserved. The command checks exact slug, name and address, skips any stay with an existing real photo, defaults to a dry run, and refuses production environments. Re-running it adds no duplicate photos. The audit and rollback row IDs are recorded under `storage/app/stay-photo-import-*.json`; verification is in `storage/app/stay-photo-import-verification.json`. Complex exterior labels appear on cards, galleries, itinerary cards and recap cards.

## Live server (added 17 November 2026)

The project owner decided to publish this batch on the live site, knowing the sources include booking and review sites and that
publication rights are not established. On the server the commands need an explicit opt-in:

    php artisan stays:apply-photos                      # dry run: what would be added
    php artisan stays:apply-photos --apply --allow-production

Oversized pictures (28 of them, up to 6000 px wide) were reduced to 1600 px wide for the deploy; the manifest's width, height and
checksum describe the files as shipped. Only the pictures named in `manifest.json` are in the repository, not the research trail.
