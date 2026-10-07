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

Source attribution is stored with each imported photo for internal reference.
Public availability and attribution do not establish a reuse license; no license
claim is made for these source images.

## Included in the repository

The photographs in this folder are committed, so a fresh clone can run `php artisan migrate --seed` and get them. `DatabaseSeeder` runs `VerifiedListingPhotoSeeder` first and then `SampleListingPhotoSeeder`, which fills only the listings that still have no real photo. Run `php artisan storage:link` once so the copied files are served. No source credit is shown on the public pages.
