# Listing photographs

68 photographs matched to named listings on 7 October 2026: 8 destinations,
44 accommodations, 8 restaurants, and 8 tour operators. Sources are the Davao
City Tourism directory and Eden Nature Park's own attractions page. These are
source-matched photographs, not generated pictures. Some source images are
small; they have not been artificially reconstructed or presented as HD.

`manifest.json` records each listing, original image URL, and source page.
The importer preserves existing non-SVG establishment uploads and is safe to
rerun without duplicate rows. Existing placeholder records are retained.

Run the source-column migration, then:

```sh
php artisan db:seed --class=VerifiedListingPhotoSeeder
```

`needs-verified-photo.csv` lists the 352 records still awaiting an actual place photo.
These now have clearly labeled category samples, installed with
`php artisan db:seed --class=SampleListingPhotoSeeder`. All 421 existing listings
have at least one picture. Samples reuse the site's existing illustrative assets
and are not evidence of a particular establishment's appearance. Real uploads and
verified imports take precedence; samples are hidden from galleries once a real
photo is available. Sample assets are in `public/images/samples`.
It includes listings outside Davao City, ambiguous branches, packages, and
places with no confidently matched photograph in the reviewed directory.
One additional listing already had an uploaded photograph and was preserved.
Logo-only images, generic stock images, and ambiguous name matches were excluded.

## Permission records (manifest v2)

Each entry in `manifest.json` carries a `verification_status`: `unverified`, `identity_checked`, `second_source`,
`approved` or `rejected`. **Only `approved` entries are imported**, and only when the record is complete:
`permission_basis`, `permission_ref` (for the owners' meeting: which meeting / attendance sheet), `permission_date`,
`credit_text` (the credit the owner agreed to), `verified_by`, `verified_at`, plus `source_url` unless the picture
was supplied by the establishment itself. The `sha256` of every file is checked, so a picture changed after approval
is caught. Check the manifest any time with:

```sh
php artisan listing-photos:validate
```

The 68 legacy entries (Davao City Tourism / Eden Nature Park, imported 7 October 2026) are `unverified` because no
permission record was kept with them. They stay on the live site until a decision is made; a fresh install will not
re-import them until each is approved. Photos already in the database keep working and show "Source: ..." as credit.

Pages show the credit under the gallery, label any sample or placeholder as "Illustration, not a photo of this
place", and show a "Report a photo" link when `LISTING_PHOTO_REPORT_EMAIL` is set in `.env`.

`DatabaseSeeder` runs `VerifiedListingPhotoSeeder` first and then `SampleListingPhotoSeeder`. Run `php artisan storage:link` once so copied files are served.
